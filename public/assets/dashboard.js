/**
 * Вкладка «Дашборд», как в коробке: кто отдыхал в году, нагрузка по месяцам, скорость согласования.
 * Отделы и заявки — в пределах видимости ролей. Сервер отдаёт только ID сотрудников: ФИО запрашивает браузер.
 */
window.Kb24Dashboard = (function () {
    'use strict';

    var MONTHS = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
    var MONTHS_SHORT = ['янв', 'фев', 'мар', 'апр', 'май', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
    var MONTHS_IN = ['январе', 'феврале', 'марте', 'апреле', 'мае', 'июне', 'июле', 'августе', 'сентябре', 'октябре', 'ноябре', 'декабре'];
    // Последовательная шкала одного оттенка: чем темнее, тем больше сотрудников отдела отсутствует
    var RAMP = ['#cde2fb', '#b7d3f6', '#9ec5f4', '#86b6ef', '#6da7ec', '#5598e7', '#3987e5', '#2a78d6', '#256abf', '#1c5cab', '#184f95', '#104281'];
    var RAMP_MAX_SHARE = 0.5;
    var REST = {
        rested: 'Уже отдыхали',
        planned: 'Не отдыхали, отпуск запланирован',
        none: 'Не отдыхали и не подали заявку',
        short: 'Нет части отпуска от 14 дней'
    };

    var ctx = null;
    var view = { year: new Date().getFullYear(), departmentId: 0, approverId: 0, group: '', data: null, sickBy: 'employees', dayoffBy: 'employees', zero: '' };

    function root() { return document.getElementById('dashboard'); }
    function esc(text) { return ctx.esc(text); }
    function holder(role) { return root().querySelector('[data-role="' + role + '"]'); }
    function human(s) { var p = String(s).split('-'); return p[2] + '.' + p[1] + '.' + p[0]; }

    function show() {
        root().innerHTML = '<p class="hint">Загрузка…</p>';
        ctx.api('/api/dashboard', { year: view.year }).then(function (data) {
            view.data = data;
            if (view.departmentId && !data.departments.some(function (d) { return d.id === view.departmentId; })) {
                view.departmentId = 0;
            }
            var ids = data.employees.map(function (e) { return e.id; });
            (data.speed ? data.speed.steps : []).forEach(function (s) { ids.push(s.userId, s.approverId); });
            return ctx.loadNames(ids);
        }).then(draw).catch(function (error) {
            root().innerHTML = '';
            ctx.showError(error);
        });
    }

    // ---------- помощники ----------

    // Выбранный отдел с подотделами: { id: true }; null — все видимые отделы
    function inScope() {
        if (!view.departmentId) {
            return null;
        }
        var scope = {};
        scope[view.departmentId] = true;
        var added = true;
        while (added) {
            added = false;
            view.data.departments.forEach(function (d) {
                if (!scope[d.id] && scope[d.parentId]) {
                    scope[d.id] = true;
                    added = true;
                }
            });
        }
        return scope;
    }

    function employees() {
        var scope = inScope();
        return view.data.employees.filter(function (e) { return !scope || scope[e.departmentId]; });
    }

    function departments() {
        var scope = inScope();
        return view.data.departments.filter(function (d) { return d.headcount > 0 && (!scope || scope[d.id]); });
    }

    function departmentName(id) {
        var found = view.data.departments.filter(function (d) { return d.id === id; })[0];
        return found ? found.name : '';
    }

    function percent(part, total) {
        return total ? Math.round(part / total * 100) + '%' : '—';
    }

    function hours(h) {
        if (h === null || h === undefined) { return '—'; }
        if (h < 1) { return 'меньше часа'; }
        if (h < 24) { return Math.round(h) + ' ч'; }
        return ctx.formatDays(Math.round(h / 24 * 10) / 10) + ' дн.';
    }

    function shade(share) {
        if (share <= 0) { return null; }
        return Math.min(RAMP.length - 1, Math.floor(share / RAMP_MAX_SHARE * RAMP.length));
    }

    function section(title, hint, content) {
        return '<section class="kb-dash-section"><h3>' + esc(title) + '</h3>'
            + (hint ? '<p class="kb-hint">' + hint + '</p>' : '') + content + '</section>';
    }

    function profileUrl(id) {
        return 'https://' + BX24.getDomain() + '/company/personal/user/' + id + '/';
    }

    // ---------- разметка ----------

    function draw() {
        var data = view.data;
        var options = (data.departments.length > 1 ? '<option value="0">Все доступные отделы</option>' : '')
            + data.departments.map(function (d) {
                return '<option value="' + d.id + '"' + (d.id === view.departmentId ? ' selected' : '') + '>'
                    + new Array(d.depth + 1).join('   ') + esc(d.name) + '</option>';
            }).join('');

        root().innerHTML = '<div class="form kb-dash-toolbar">'
            + '<label>Год <span class="kb-nav"><button type="button" data-role="prev" title="Предыдущий год">←</button>'
            + '<b>' + data.year + '</b><button type="button" data-role="next" title="Следующий год">→</button></span></label>'
            + (data.departments.length ? '<label>Отдел <select data-role="department">' + options + '</select></label>' : '')
            + '</div>'
            + (data.departments.length
                ? '<div data-role="rest"></div><div data-role="load"></div>' + (data.speed ? '<div data-role="speed"></div>' : '')
                  + '<div class="kb-dash-pair"><div data-role="sick"></div><div data-role="dayoff"></div></div>'
                : '<p class="hint">Нет доступных отделов. Какие отделы видны, задают роли на вкладке «Доступ».</p>')
            + '<div class="kb-tip" data-role="tip" hidden></div>';

        holder('prev').addEventListener('click', function () { view.year--; view.group = ''; view.zero = ''; show(); });
        holder('next').addEventListener('click', function () { view.year++; view.group = ''; view.zero = ''; show(); });
        var select = holder('department');
        if (select) {
            select.addEventListener('change', function () {
                view.departmentId = +select.value;
                view.group = '';
                view.zero = '';
                drawSections();
            });
            bindTooltip();
            drawSections();
        }
        BX24.fitWindow();
    }

    function drawSections() {
        drawRest();
        drawLoad();
        if (view.data.speed) { drawSpeed(); }
        drawAbsence('sick', 'Больничные', 'Без больничных');
        drawAbsence('dayoff', 'Отгулы', 'Без отгулов');
        BX24.fitWindow();
    }

    function bindTooltip() {
        var tip = holder('tip');
        root().addEventListener('mousemove', function (e) {
            var target = e.target.closest('[data-tip]');
            if (!target) { tip.hidden = true; return; }
            tip.innerHTML = target.getAttribute('data-tip');
            tip.hidden = false;
            var x = Math.min(e.clientX + 14, window.innerWidth - tip.offsetWidth - 8);
            var y = e.clientY + 16 + tip.offsetHeight > window.innerHeight ? e.clientY - tip.offsetHeight - 10 : e.clientY + 16;
            tip.style.left = x + 'px';
            tip.style.top = y + 'px';
        });
        root().addEventListener('mouseleave', function () { tip.hidden = true; });
    }

    // ---------- ежегодный отпуск в году ----------

    function drawRest() {
        var list = employees();
        var groups = { rested: [], planned: [], none: [], short: [] };
        list.forEach(function (e) {
            groups[e.rest].push(e);
            if (e.rest !== 'none' && !e.longPart) { groups.short.push(e); }
        });

        var tiles = ['rested', 'planned', 'none', 'short'].map(function (key) {
            var count = groups[key].length;
            var note = key === 'short'
                ? 'из тех, у кого есть отпуск в году (ст. 125 ТК РФ)'
                : percent(count, list.length) + ' сотрудников';
            return '<button type="button" class="kb-tile' + (key === 'none' && count ? ' is-attention' : '') + (view.group === key ? ' is-active' : '')
                + '" data-group="' + key + '"' + (count ? '' : ' disabled') + '>'
                + '<span class="kb-tile-label">' + (key === 'none' && count ? '<i class="kb-icon-warn" aria-hidden="true">!</i>' : '') + esc(REST[key]) + '</span>'
                + '<span class="kb-tile-value">' + count + '</span>'
                + '<span class="kb-tile-note">' + esc(note) + '</span></button>';
        }).join('');

        holder('rest').innerHTML = section(
            'Ежегодный отпуск в ' + view.year + ' году',
            'Считаются заявки на ежегодный отпуск: утверждённые и те, что ещё на согласовании. «Отдыхали» значит, что утверждённый отпуск уже начался. '
            + 'Всего сотрудников: ' + list.length + '. Нажмите на плитку, чтобы увидеть список.',
            '<div class="kb-tiles">' + tiles + '</div><div data-role="people"></div>'
        );
        holder('rest').querySelectorAll('[data-group]').forEach(function (tile) {
            tile.addEventListener('click', function () {
                view.group = view.group === tile.dataset.group ? '' : tile.dataset.group;
                drawRest();
                BX24.fitWindow();
            });
        });
        if (view.group) {
            drawPeople(REST[view.group], groups[view.group]);
        }
    }

    function drawPeople(title, people) {
        people = people.slice().sort(function (a, b) { return ctx.name(a.id).localeCompare(ctx.name(b.id)); });
        var rows = people.map(function (e) {
            return '<tr><td><a href="' + esc(profileUrl(e.id)) + '" target="_blank">' + esc(ctx.name(e.id)) + '</a></td>'
                + '<td>' + esc(departmentName(e.departmentId)) + '</td>'
                + '<td class="kb-num">' + esc(ctx.formatDays(e.vacationDays)) + '</td>'
                + '<td class="kb-num">' + (e.available === null ? '<span class="kb-muted">не указан</span>' : esc(ctx.formatDays(e.available))) + '</td></tr>';
        }).join('');
        holder('people').innerHTML = '<h4 class="kb-dash-sub">' + esc(title) + ' · ' + people.length
            + ' <button type="button" class="kb-link" data-role="hide-people">скрыть</button></h4>'
            + '<table class="kb-list"><thead><tr><th>Сотрудник</th><th>Отдел</th>'
            + '<th class="kb-num">Дней отпуска в заявках за год</th><th class="kb-num">Доступный остаток</th></tr></thead><tbody>' + rows + '</tbody></table>';
        holder('hide-people').addEventListener('click', function () {
            view.group = '';
            drawRest();
            BX24.fitWindow();
        });
    }

    // ---------- нагрузка ----------

    function drawLoad() {
        var data = view.data;
        var list = departments();
        var scope = inScope();

        // График по всем видимым отделам; при выбранном отделе остаётся только таблица отделов
        var company = data.load.company;
        var max = Math.max.apply(null, company.map(function (m) { return m.peak; }).concat([1]));
        var top = company.reduce(function (best, m, i) { return m.peak > company[best].peak ? i : best; }, 0);

        var bars = company.map(function (m, i) {
            var height = Math.round(m.peak / max * 100);
            return '<div class="kb-bar-col" data-tip="' + esc('<b>' + MONTHS[i] + '</b><br>'
                + 'В самый загруженный день отсутствуют: ' + m.peak + ' из ' + data.load.headcount
                + (m.date ? ' (' + human(m.date) + ')' : '')) + '">'
                + '<span class="kb-bar-value">' + (i === top && m.peak ? m.peak : '') + '</span>'
                + '<span class="kb-bar" style="height:' + height + '%"></span>'
                + '<span class="kb-bar-label">' + MONTHS_SHORT[i] + '</span></div>';
        }).join('');

        var canOpen = ctx.calendarDepartments();
        var head = MONTHS_SHORT.map(function (m) { return '<th>' + m + '</th>'; }).join('');
        var rows = list.map(function (d) {
            var months = data.load.departments[d.id] || [];
            var clickable = canOpen.indexOf(d.id) !== -1;
            return '<tr><th class="kb-heat-name">' + esc(d.name) + ' <span class="kb-muted">' + d.headcount + ' чел.</span></th>'
                + months.map(function (m, i) {
                    var step = shade(m.peak / d.headcount);
                    var style = step === null ? '' : ' style="background:' + RAMP[step] + (step >= 6 ? ';color:#fff' : '') + '"';
                    var tip = '<b>' + esc(d.name) + ', ' + MONTHS[i].toLowerCase() + '</b><br>' + (m.peak
                        ? 'В самый загруженный день (' + human(m.date) + ') отсутствуют ' + m.peak + ' из ' + d.headcount + ', на месте ' + m.present
                        : 'Отсутствий нет') + (clickable ? '<br><span class="kb-tip-muted">Нажмите, чтобы открыть календарь отдела</span>' : '');
                    return '<td class="kb-heat-cell' + (clickable ? ' is-clickable' : '') + '"' + (clickable ? ' data-department="' + d.id + '" data-month="' + i + '"' : '')
                        + ' data-tip="' + esc(tip) + '"' + style + '>' + (m.peak ? m.present + '/' + d.headcount : '·') + '</td>';
                }).join('') + '</tr>';
        }).join('');

        var legend = [0, 0.1, 0.2, 0.3, 0.4, 0.5].map(function (share) {
            var step = shade(share);
            return '<span><i style="background:' + (step === null ? '#fff' : RAMP[step]) + '"></i>'
                + (share ? Math.round(share * 100) + '%' + (share === 0.5 ? ' и больше' : '') : 'никого') + '</span>';
        }).join('');

        holder('load').innerHTML = section(
            'Нагрузка по месяцам',
            'Сколько сотрудников отсутствует одновременно в самый загруженный день месяца. Учитываются все виды отсутствий: утверждённые и на согласовании. '
            + (!scope && company[top].peak ? 'Больше всего отсутствующих в ' + MONTHS_IN[top] + '.' : ''),
            (scope ? '' : '<h4 class="kb-dash-sub">Все доступные отделы: отсутствуют в самый загруженный день</h4><div class="kb-bars">' + bars + '</div>')
            + (rows
                ? '<div class="kb-heat-wrap"><table class="kb-heat"><thead><tr><th class="kb-heat-name">Отдел</th>' + head + '</tr></thead><tbody>' + rows + '</tbody></table></div>'
                  + '<div class="kb-heat-legend"><span class="kb-muted">Доля отсутствующих в отделе:</span>' + legend
                  + '<span class="kb-muted">В ячейке: на месте / всего в отделе</span></div>'
                : '<p class="hint">В выбранных отделах нет сотрудников</p>')
        );

        holder('load').querySelectorAll('[data-department]').forEach(function (cell) {
            cell.addEventListener('click', function () {
                holder('tip').hidden = true;
                ctx.openCalendar(+cell.dataset.department, view.year, +cell.dataset.month);
            });
        });
    }

    // ---------- скорость согласования ----------

    function drawSpeed() {
        var all = view.data.speed.steps;
        var scope = inScope();
        var inDepartments = all.filter(function (s) { return !scope || scope[s.departmentId]; });
        var steps = inDepartments.filter(function (s) { return !view.approverId || s.approverId === view.approverId; });
        var done = steps.filter(function (s) { return s.hours !== null; });
        var waiting = steps.filter(function (s) { return s.hours === null; }).sort(function (a, b) { return b.waitingHours - a.waitingHours; });

        // Шаги называются так, как в маршрутах: плитка на каждое название
        var stepNames = [];
        done.forEach(function (s) { if (stepNames.indexOf(s.step) === -1) { stepNames.push(s.step); } });

        var tiles = stepNames.map(function (step) {
            var stat = summarize(done.filter(function (s) { return s.step === step; }).map(function (s) { return s.hours; }));
            return '<div class="kb-tile kb-tile-static"><span class="kb-tile-label">' + esc(step) + '</span>'
                + '<span class="kb-tile-value">' + esc(hours(stat.median)) + '</span>'
                + '<span class="kb-tile-note">медиана · в среднем ' + esc(hours(stat.avg)) + ' · решений: ' + stat.count + '</span></div>';
        }).join('')
            + '<div class="kb-tile kb-tile-static' + (waiting.length ? ' is-attention' : '') + '"><span class="kb-tile-label">'
            + (waiting.length ? '<i class="kb-icon-warn" aria-hidden="true">!</i>' : '') + 'Ждут решения сейчас</span>'
            + '<span class="kb-tile-value">' + waiting.length + '</span>'
            + '<span class="kb-tile-note">' + (waiting.length ? 'дольше всех ' + esc(hours(waiting[0].waitingHours)) : 'очереди нет') + '</span></div>';

        var byApprover = {};
        done.forEach(function (s) { (byApprover[s.approverId] = byApprover[s.approverId] || []).push(s.hours); });
        var ranking = Object.keys(byApprover).map(function (id) {
            return { id: +id, stat: summarize(byApprover[id]), waiting: waiting.filter(function (s) { return s.approverId === +id; }).length };
        }).sort(function (a, b) { return b.stat.avg - a.stat.avg; });
        var maxAvg = Math.max.apply(null, ranking.map(function (r) { return r.stat.avg; }).concat([1]));

        var bars = ranking.map(function (r) {
            var who = esc(ctx.name(r.id));
            return '<div class="kb-hbar" data-tip="' + esc('<b>' + who + '</b><br>В среднем ' + hours(r.stat.avg) + ', медиана ' + hours(r.stat.median)
                + ', дольше всего ' + hours(r.stat.max) + '<br>Решений: ' + r.stat.count + (r.waiting ? ', ждут сейчас: ' + r.waiting : '')) + '">'
                + '<span class="kb-hbar-name">' + who + '</span>'
                + '<span class="kb-hbar-track"><span class="kb-hbar-fill" style="width:' + Math.max(2, Math.round(r.stat.avg / maxAvg * 100)) + '%"></span></span>'
                + '<span class="kb-hbar-value">' + esc(hours(r.stat.avg)) + ' <span class="kb-muted">· ' + r.stat.count + '</span></span></div>';
        }).join('');

        var queue = waiting.slice(0, 10).map(function (s) {
            return '<tr data-request="' + s.requestId + '"><td>' + esc(ctx.name(s.userId)) + '</td><td>' + esc(s.step) + '</td>'
                + '<td>' + esc(ctx.name(s.approverId)) + '</td><td class="kb-num">' + esc(hours(s.waitingHours)) + '</td></tr>';
        }).join('');

        var approvers = [];
        inDepartments.forEach(function (s) { if (approvers.indexOf(s.approverId) === -1) { approvers.push(s.approverId); } });
        approvers.sort(function (a, b) { return ctx.name(a).localeCompare(ctx.name(b)); });
        var approverOptions = '<option value="0">Все согласующие</option>' + approvers.map(function (id) {
            return '<option value="' + id + '"' + (id === view.approverId ? ' selected' : '') + '>' + esc(ctx.name(id)) + '</option>';
        }).join('');

        holder('speed').innerHTML = section(
            'Скорость согласования',
            'Сколько заявка ждёт решения на каждом шаге, по заявкам, поданным в ' + view.year + ' году. '
            + 'Время календарное, с момента, когда заявка пришла согласующему.',
            (approvers.length ? '<div class="form"><label>Согласующий <select data-role="approver">' + approverOptions + '</select></label></div>' : '')
            + '<div class="kb-tiles">' + tiles + '</div>'
            + (bars ? '<h4 class="kb-dash-sub">Среднее время решения по согласующим <span class="kb-muted">(после точки: число решений)</span></h4><div class="kb-hbars">' + bars + '</div>' : '')
            + (queue ? '<h4 class="kb-dash-sub">Дольше всех ждут решения</h4><table class="kb-list list"><thead><tr><th>Сотрудник</th><th>Шаг</th><th>Согласующий</th><th class="kb-num">Ждёт</th></tr></thead><tbody>' + queue + '</tbody></table>' : '')
        );

        var select = holder('approver');
        if (select) {
            select.addEventListener('change', function () {
                view.approverId = +select.value;
                drawSpeed();
                BX24.fitWindow();
            });
        }
        holder('speed').querySelectorAll('[data-request]').forEach(function (tr) {
            tr.addEventListener('click', function () { ctx.openCard(+tr.dataset.request); });
        });
    }

    // ---------- больничные и отгулы ----------

    function drawAbsence(kind, title, zeroLabel) {
        var by = view[kind + 'By'];
        var people = employees();
        var daysKey = kind + 'Days';
        var casesKey = kind + 'Cases';
        var content;

        if (by === 'employees') {
            var ranked = people.filter(function (e) { return e[daysKey] > 0; })
                .sort(function (a, b) { return b[daysKey] - a[daysKey] || b[casesKey] - a[casesKey]; });
            var zero = people.filter(function (e) { return !e[daysKey]; });
            content = (ranked.length > 10 ? '<p class="kb-hint">Показаны 10 сотрудников, у кого больше всего дней.</p>' : '')
                + (ranked.length
                    ? hbars(ranked.slice(0, 10).map(function (e) {
                        return { name: ctx.name(e.id), value: e[daysKey], label: e[daysKey] + ' дн.',
                            tip: esc(ctx.name(e.id)) + '<br>' + e[daysKey] + ' дн., случаев: ' + e[casesKey] + '<br>' + esc(departmentName(e.departmentId)) };
                    }))
                    : '<p class="hint">Нет ни одного случая</p>')
                + (zero.length ? '<button type="button" class="kb-link" data-role="zero">' + esc(zeroLabel) + ': ' + zero.length + ' сотр.</button>' : '')
                + (view.zero === kind ? '<div class="kb-zero">' + zero.map(function (e) { return esc(ctx.name(e.id)); })
                    .sort(function (a, b) { return a.localeCompare(b); }).join(', ') + '</div>' : '');
        } else {
            var stats = departments().map(function (d) {
                var members = people.filter(function (e) { return e.departmentId === d.id; });
                var days = members.reduce(function (sum, e) { return sum + e[daysKey]; }, 0);
                var cases = members.reduce(function (sum, e) { return sum + e[casesKey]; }, 0);
                return { name: d.name, days: days, cases: cases, perHead: d.headcount ? days / d.headcount : 0, headcount: d.headcount };
            }).sort(function (a, b) { return b.perHead - a.perHead; });
            var items = function (list) {
                return list.map(function (st) {
                    return { name: st.name, value: st.perHead, label: ctx.formatDays(Math.round(st.perHead * 10) / 10) + ' дн./чел.',
                        tip: esc(st.name) + '<br>' + st.days + ' дн. на ' + st.headcount + ' чел., случаев: ' + st.cases };
                });
            };
            var max = stats.length ? stats[0].perHead : 0;
            content = '<p class="kb-hint">Дни на одного сотрудника, чтобы сравнивать отделы разного размера.</p>'
                + (!stats.length ? '<p class="hint">Нет отделов</p>'
                    : stats.length <= 10 ? hbars(items(stats), max)
                    : '<h4 class="kb-dash-sub">Больше всего</h4>' + hbars(items(stats.slice(0, 5)), max)
                      + '<h4 class="kb-dash-sub">Меньше всего</h4>' + hbars(items(stats.slice(-5)), max));
        }

        var toggle = '<div class="kb-segmented">' + [['employees', 'По сотрудникам'], ['departments', 'По отделам']].map(function (v) {
            return '<button type="button" class="kb-seg' + (by === v[0] ? ' is-active' : '') + '" data-by="' + v[0] + '">' + v[1] + '</button>';
        }).join('') + '</div>';

        holder(kind).innerHTML = section(title + ' в ' + view.year + ' году', 'По утверждённым заявкам, дни внутри года.', toggle + '<div class="kb-dash-gap"></div>' + content);
        holder(kind).querySelectorAll('[data-by]').forEach(function (button) {
            button.addEventListener('click', function () {
                view[kind + 'By'] = button.dataset.by;
                drawAbsence(kind, title, zeroLabel);
                BX24.fitWindow();
            });
        });
        var zeroButton = holder(kind).querySelector('[data-role="zero"]');
        if (zeroButton) {
            zeroButton.addEventListener('click', function () {
                view.zero = view.zero === kind ? '' : kind;
                drawAbsence(kind, title, zeroLabel);
                BX24.fitWindow();
            });
        }
    }

    function hbars(items, max) {
        max = max || Math.max.apply(null, items.map(function (i) { return i.value; }).concat([1]));
        return '<div class="kb-hbars">' + items.map(function (i) {
            return '<div class="kb-hbar" data-tip="' + esc(i.tip) + '"><span class="kb-hbar-name">' + esc(i.name) + '</span>'
                + '<span class="kb-hbar-track"><span class="kb-hbar-fill" style="width:' + (i.value ? Math.max(2, Math.round(i.value / max * 100)) : 0) + '%"></span></span>'
                + '<span class="kb-hbar-value">' + esc(i.label) + '</span></div>';
        }).join('') + '</div>';
    }

    function summarize(values) {
        if (!values.length) { return { count: 0, avg: null, median: null, max: null }; }
        var sorted = values.slice().sort(function (a, b) { return a - b; });
        var middle = Math.floor(sorted.length / 2);
        return {
            count: sorted.length,
            avg: sorted.reduce(function (sum, v) { return sum + v; }, 0) / sorted.length,
            median: sorted.length % 2 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2,
            max: sorted[sorted.length - 1]
        };
    }

    return {
        /**
         * @param {object} context api, esc, loadNames, name, formatDays, showError, openCard, openCalendar, calendarDepartments
         */
        init: function (context) { ctx = context; },
        show: show
    };
})();
