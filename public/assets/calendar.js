/**
 * Календарь отдела, как в коробке: месяц, квартал или год, цвет по виду отсутствия, заливка по статусу,
 * красным дни, когда лимит отдела исчерпан. Сервер отдаёт заявки отдела по ID сотрудников,
 * сотрудников отдела и их ФИО браузер запрашивает сам через user.get.
 */
window.Kb24Calendar = (function () {
    'use strict';

    var TYPE_COLORS = { vacation: '#16a34a', unpaid: '#f59e0b', study: '#9333ea' };
    // Статус показывается не оттенком, а способом заливки: контур, штриховка, сплошная заливка
    var STATUS_CLASSES = {
        head_review: 'st-pending',
        hr_review: 'st-progress',
        final_approval: 'st-progress',
        route_review: 'st-progress',
        approved: 'st-approved',
        cancelling: 'st-approved'
    };
    var MONTHS = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
    var WEEKDAYS = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
    var VIEWS = [['month', 'Месяц'], ['quarter', 'Квартал'], ['year', 'Год']];

    var ctx = null;
    var state = { view: 'month', anchor: startOfMonth(new Date()), departmentId: 0, employeeId: 0, data: null, users: [], days: {}, employees: {} };

    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function parse(s) { var p = s.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
    function human(s) { var p = s.split('-'); return p[2] + '.' + p[1] + '.' + p[0]; }
    function startOfMonth(d) { return new Date(d.getFullYear(), d.getMonth(), 1); }
    function addMonths(d, n) { return new Date(d.getFullYear(), d.getMonth() + n, 1); }
    function addDays(d, n) { return new Date(d.getFullYear(), d.getMonth(), d.getDate() + n); }
    function root() { return document.getElementById('calendar'); }
    function esc(text) { return ctx.esc(text); }

    function periodBounds() {
        var a = state.anchor;
        if (state.view === 'year') {
            return [new Date(a.getFullYear(), 0, 1), new Date(a.getFullYear(), 11, 31)];
        }
        if (state.view === 'quarter') {
            var q = Math.floor(a.getMonth() / 3) * 3;
            return [new Date(a.getFullYear(), q, 1), new Date(a.getFullYear(), q + 3, 0)];
        }
        return [new Date(a.getFullYear(), a.getMonth(), 1), new Date(a.getFullYear(), a.getMonth() + 1, 0)];
    }

    function periodTitle() {
        var a = state.anchor;
        if (state.view === 'year') { return a.getFullYear() + ' год'; }
        if (state.view === 'quarter') { return (Math.floor(a.getMonth() / 3) + 1) + ' квартал ' + a.getFullYear(); }
        return MONTHS[a.getMonth()] + ' ' + a.getFullYear();
    }

    function shift(direction) {
        var step = state.view === 'year' ? 12 : state.view === 'quarter' ? 3 : 1;
        state.anchor = addMonths(state.anchor, step * direction);
        render();
    }

    function colorFor(r) { return TYPE_COLORS[r.kind] || '#64748b'; }
    function statusClass(r) { return STATUS_CLASSES[r.status] || 'st-approved'; }
    function kindName(r) { return ctx.me().kinds[r.kind] || r.kind; }
    function rangeText(r) { return r.dateFrom === r.dateTo ? human(r.dateFrom) : human(r.dateFrom) + ' – ' + human(r.dateTo); }

    function departmentOptions() {
        return ctx.me().calendar.departments.map(function (d) {
            return '<option value="' + d.id + '"' + (d.id === state.departmentId ? ' selected' : '') + '>'
                + new Array(d.depth + 1).join('  ') + esc(d.name) + '</option>';
        }).join('');
    }

    function legend() {
        var kinds = ctx.me().kinds;
        var types = Object.keys(kinds).map(function (code) {
            return '<li><i class="st-approved" style="--c:' + (TYPE_COLORS[code] || '#64748b') + '"></i>' + esc(kinds[code]) + '</li>';
        }).join('');
        var sample = '#64748b';

        return '<div class="kb-legend">'
            + '<div><b>Вид отсутствия</b><ul>' + types + '</ul></div>'
            + '<div><b>Статус заявки</b><ul>'
            + '<li><i class="st-pending" style="--c:' + sample + '"></i>у руководителя</li>'
            + '<li><i class="st-progress" style="--c:' + sample + '"></i>в отделе кадров или у директора</li>'
            + '<li><i class="st-approved" style="--c:' + sample + '"></i>утверждено</li>'
            + '<li><i class="kb-legend-full"></i>лимит отдела исчерпан</li>'
            + '</ul></div></div>';
    }

    function render() {
        var departments = ctx.me().calendar.departments;
        var views = VIEWS.map(function (v) {
            return '<button type="button" class="kb-seg' + (state.view === v[0] ? ' is-active' : '') + '" data-view="' + v[0] + '">' + v[1] + '</button>';
        }).join('');

        root().innerHTML = '<div class="kb-top"><div class="kb-toolbar">'
            + '<div class="kb-control"><span class="kb-label">Отдел</span>'
            + (departments.length > 1
                ? '<select data-role="department">' + departmentOptions() + '</select>'
                : '<span class="kb-static">' + esc((departments[0] || {}).name || '—') + '</span>') + '</div>'
            + '<div class="kb-control"><span class="kb-label">Сотрудник</span>'
            + '<select data-role="employee" disabled><option value="0">Все сотрудники</option></select></div>'
            + '<div class="kb-control"><span class="kb-label">Масштаб</span><div class="kb-segmented">' + views + '</div></div>'
            + '<div class="kb-control"><span class="kb-label">Период</span><div class="kb-nav">'
            + '<button type="button" data-role="prev" title="Назад">←</button>'
            + '<span class="kb-period">' + esc(periodTitle()) + '</span>'
            + '<button type="button" data-role="next" title="Вперёд">→</button>'
            + '<button type="button" data-role="today">Сегодня</button></div></div>'
            + '</div>' + legend() + '</div>'
            + '<p class="hint">Нажмите на день, чтобы увидеть, кто отсутствует, и подать заявку с этой даты. '
            + 'Красным отмечены дни, когда лимит отдела уже исчерпан.</p>'
            + '<div data-role="limit" class="hint"></div>'
            + '<div data-role="grid"><p class="hint">Загрузка…</p></div>';

        var box = root();
        box.querySelectorAll('[data-view]').forEach(function (button) {
            button.addEventListener('click', function () { state.view = button.dataset.view; render(); });
        });
        box.querySelector('[data-role="prev"]').addEventListener('click', function () { shift(-1); });
        box.querySelector('[data-role="next"]').addEventListener('click', function () { shift(1); });
        box.querySelector('[data-role="today"]').addEventListener('click', function () { state.anchor = startOfMonth(new Date()); render(); });
        var select = box.querySelector('[data-role="department"]');
        if (select) {
            select.addEventListener('change', function () {
                state.departmentId = +select.value;
                state.employeeId = 0;
                render();
            });
        }
        box.querySelector('[data-role="employee"]').addEventListener('change', function (event) {
            state.employeeId = +event.target.value;
            drawMonths();
        });

        if (!state.departmentId) {
            box.querySelector('[data-role="grid"]').innerHTML = '<p class="hint">Вы не привязаны к отделу в структуре компании</p>';
            BX24.fitWindow();
            return;
        }

        var bounds = periodBounds();
        var departmentId = state.departmentId;
        Promise.all([
            ctx.api('/api/calendar', { department: departmentId, from: ymd(bounds[0]), to: ymd(bounds[1]) }),
            employees(departmentId)
        ]).then(function (result) {
            var data = result[0];
            if (departmentId !== state.departmentId) {
                return null;
            }
            return ctx.loadNames(data.requests.map(function (r) { return r.userId; }).concat([data.headId])).then(function () {
                state.data = data;
                state.bounds = bounds;
                fillEmployees(result[1], data);
                showLimit(data.limit);
                drawMonths();
            });
        }).catch(function (error) {
            box.querySelector('[data-role="grid"]').innerHTML = '<p class="fail">' + esc(error && error.message ? error.message : 'Не удалось загрузить календарь') + '</p>';
            BX24.fitWindow();
        });
    }

    // Сотрудники отдела из портала: запрашивает браузер, на сервер ФИО не попадают
    function employees(departmentId) {
        if (state.employees[departmentId]) {
            return Promise.resolve(state.employees[departmentId]);
        }
        return new Promise(function (resolve) {
            var users = [];
            BX24.callMethod('user.get', { filter: { UF_DEPARTMENT: departmentId, ACTIVE: true } }, function handle(result) {
                if (result.error()) {
                    resolve(users);
                    return;
                }
                result.data().forEach(function (user) {
                    var id = Number(user.ID);
                    ctx.rememberName(id, [user.LAST_NAME, user.NAME].join(' ').trim());
                    users.push(id);
                });
                if (result.more()) {
                    result.next();
                } else {
                    state.employees[departmentId] = users;
                    resolve(users);
                }
            });
        });
    }

    function fillEmployees(userIds, data) {
        // Сотрудники отдела и те, у кого в отделе есть заявки (например, переведённые позже)
        var ids = userIds.slice();
        data.requests.forEach(function (r) {
            if (ids.indexOf(r.userId) < 0) {
                ids.push(r.userId);
            }
        });
        ids.sort(function (a, b) { return ctx.name(a).localeCompare(ctx.name(b)); });
        if (ids.indexOf(state.employeeId) < 0) {
            state.employeeId = 0;
        }
        var select = root().querySelector('[data-role="employee"]');
        select.innerHTML = '<option value="0">Все сотрудники</option>' + ids.map(function (id) {
            return '<option value="' + id + '"' + (id === state.employeeId ? ' selected' : '') + '>'
                + esc(ctx.name(id)) + (id === data.headId ? ' (руководитель)' : '') + '</option>';
        }).join('');
        select.disabled = !ids.length;
    }

    function showLimit(limit) {
        var parts = [];
        if (limit.maxConcurrent !== null) {
            parts.push('в один день отсутствуют не больше ' + limit.maxConcurrent);
        }
        if (limit.maxOverlap !== null) {
            parts.push('два отсутствия пересекаются не больше чем на ' + limit.maxOverlap + ' дн.');
        }
        root().querySelector('[data-role="limit"]').textContent = parts.length
            ? 'Лимит отдела: ' + parts.join(', ') + (limit.withSub ? ', с подотделами' : '') + (limit.withHead ? ', с руководителем' : '') + '.'
            : '';
    }

    function drawMonths() {
        var data = state.data;
        var grid = root().querySelector('[data-role="grid"]');
        var full = {};
        data.fullDays.forEach(function (day) { full[day] = true; });

        var byDay = {};
        data.requests.forEach(function (r) {
            if (state.employeeId && r.userId !== state.employeeId) { return; }
            for (var d = parse(r.dateFrom); ymd(d) <= r.dateTo; d = addDays(d, 1)) {
                (byDay[ymd(d)] = byDay[ymd(d)] || []).push(r);
            }
        });
        state.days = byDay;

        var html = '';
        for (var m = new Date(state.bounds[0]); m <= state.bounds[1]; m = addMonths(m, 1)) {
            html += monthBlock(m, byDay, full);
        }
        grid.innerHTML = '<div class="kb-months kb-view-' + state.view + '">' + html + '</div>';

        grid.querySelectorAll('[data-request]').forEach(function (chip) {
            chip.addEventListener('click', function (event) {
                event.stopPropagation();
                ctx.openCard(+chip.dataset.request);
            });
        });
        grid.querySelectorAll('.kb-day[data-date]').forEach(function (cell) {
            cell.addEventListener('click', function () { openDay(cell.dataset.date, full[cell.dataset.date]); });
        });
        BX24.fitWindow();
    }

    function title(r) {
        return ctx.name(r.userId) + ' — ' + kindName(r) + ', ' + rangeText(r) + ', ' + ctx.statusName(r.status);
    }

    function monthBlock(month, byDay, full) {
        var today = ymd(new Date());
        var first = new Date(month.getFullYear(), month.getMonth(), 1);
        var offset = (first.getDay() + 6) % 7;
        var daysInMonth = new Date(month.getFullYear(), month.getMonth() + 1, 0).getDate();
        var compact = state.view === 'year';

        var cells = WEEKDAYS.map(function (w, i) {
            return '<div class="kb-weekday' + (i > 4 ? ' is-weekend' : '') + '">' + w + '</div>';
        }).join('');
        for (var i = 0; i < offset; i++) { cells += '<div class="kb-day is-out"></div>'; }

        for (var day = 1; day <= daysInMonth; day++) {
            var date = new Date(month.getFullYear(), month.getMonth(), day);
            var key = ymd(date);
            var items = byDay[key] || [];
            var cls = ['kb-day'];
            if (date.getDay() === 0 || date.getDay() === 6) { cls.push('is-weekend'); }
            if (full[key]) { cls.push('is-full'); }
            if (key === today) { cls.push('is-today'); }
            if (compact && items.some(function (r) { return statusClass(r) === 'st-approved'; })) { cls.push('is-dark'); }

            var titles = items.map(title);
            if (full[key]) { titles.unshift('Лимит отдела исчерпан'); }

            cells += '<div class="' + cls.join(' ') + '" data-date="' + key + '"'
                + (titles.length ? ' title="' + esc(titles.join('\n')) + '"' : '') + '>'
                + '<span class="kb-day-num">' + day + '</span>'
                + (compact ? fill(items) : chips(items)) + '</div>';
        }

        return '<section class="kb-month"><h4>' + MONTHS[month.getMonth()] + ' ' + month.getFullYear() + '</h4>'
            + '<div class="kb-month-grid">' + cells + '</div></section>';
    }

    function chipAttrs(r) {
        return 'class="kb-chip ' + statusClass(r) + '" style="--c:' + colorFor(r) + '"';
    }

    function chips(items) {
        var shortName = state.view !== 'month';
        return items.map(function (r) {
            var full = ctx.name(r.userId);
            return '<a ' + chipAttrs(r) + ' data-request="' + r.id + '" title="' + esc(title(r)) + '">'
                + esc(shortName ? full.split(' ')[0] : full) + '</a>';
        }).join('');
    }

    // Годовой вид: день закрашивается целиком, при нескольких отсутствиях делится на полосы
    function fill(items) {
        if (!items.length) { return ''; }
        return '<span class="kb-fill">' + items.map(function (r) {
            return '<i class="' + statusClass(r) + '" style="--c:' + colorFor(r) + '"></i>';
        }).join('') + '</span>';
    }

    function openDay(date, isFull) {
        var items = state.days[date] || [];
        var list = items.length
            ? '<ul class="kb-day-list">' + items.map(function (r) {
                return '<li><a ' + chipAttrs(r) + ' data-request="' + r.id + '">' + esc(ctx.name(r.userId)) + '</a> '
                    + esc(kindName(r)) + ', ' + esc(rangeText(r)) + ' · ' + ctx.status(r.status) + '</li>';
            }).join('') + '</ul>'
            : '<p class="hint">В этот день из отдела никто не отсутствует.</p>';

        var overlay = document.createElement('div');
        overlay.className = 'kb-overlay';
        overlay.innerHTML = '<div class="kb-modal"><h3>' + human(date) + '</h3>'
            + (isFull ? '<p class="message error">Лимит одновременных отсутствий в отделе на этот день исчерпан.</p>' : '')
            + list
            + '<div class="actions"><button type="button" data-role="close">Закрыть</button>'
            + '<button type="button" class="primary" data-role="create">Подать заявку с этой даты</button></div></div>';
        document.body.appendChild(overlay);
        overlay.addEventListener('click', function (event) { if (event.target === overlay) { overlay.remove(); } });
        overlay.querySelector('[data-role="close"]').addEventListener('click', function () { overlay.remove(); });
        overlay.querySelectorAll('[data-request]').forEach(function (chip) {
            chip.addEventListener('click', function () { overlay.remove(); ctx.openCard(+chip.dataset.request); });
        });
        overlay.querySelector('[data-role="create"]').addEventListener('click', function () {
            overlay.remove();
            ctx.createFrom(date);
        });
    }

    return {
        /**
         * @param {object} context api, esc, me, loadNames, name, rememberName, status, statusName, openCard, createFrom
         */
        init: function (context) {
            ctx = context;
            var calendar = ctx.me().calendar;
            var own = calendar.departments.some(function (d) { return d.id === calendar.myDepartment; });
            state.departmentId = own ? calendar.myDepartment : ((calendar.departments[0] || {}).id || 0);
        },
        show: function () {
            render();
        }
    };
})();
