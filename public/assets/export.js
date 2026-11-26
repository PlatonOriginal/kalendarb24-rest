/**
 * Выгрузка графика отпусков в Excel для кадровой подшивки. Файл собирается в браузере:
 * сервер отдаёт только ID сотрудников, отделов и согласующих, ФИО и должности браузер
 * запрашивает из портала сам, поэтому персональные данные на сервер приложения не попадают.
 * Кнопка «Выгрузить в Excel» на вкладках «Календарь» и «Дашборд».
 */
window.Kb24Export = (function () {
    'use strict';

    // Названия статусов для файла: только те, что попадают в выгрузку
    var STATUS = {
        approved: 'Утверждено',
        cancelling: 'Отменяется',
        head_review: 'У руководителя',
        hr_review: 'В отделе кадров',
        final_approval: 'У директора',
        route_review: 'На согласовании'
    };
    var HEADERS = ['№ п/п', 'Подразделение', 'ФИО сотрудника', 'Должность', 'Вид отсутствия', 'Дата начала',
        'Дата окончания', 'Количество дней', 'Статус заявки', 'Кем согласовано', 'Дата утверждения',
        'Подпись сотрудника / примечание'];
    // Ширины колонок листа, в символах
    var WIDTHS = [6, 24, 32, 26, 30, 13, 13, 11, 16, 34, 14, 30];

    var ctx = null;
    // Данные сотрудников портала по ID: ФИО со вторым именем и должность. Нужен для файла
    var users = {};

    function esc(text) { return ctx.esc(text); }
    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    // ДД.ММ.ГГГГ из Y-m-d: текстом, чтобы Excel не превращал в дату и не менял вид
    function human(s) { var p = String(s).split('-'); return p.length === 3 ? p[2] + '.' + p[1] + '.' + p[0] : s; }
    function nowStamp() {
        var d = new Date();
        return pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear() + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }
    function today() {
        var d = new Date();
        return pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear();
    }
    function safeName(text) { return String(text || '').replace(/[\\\/:*?"<>|]+/g, ' ').replace(/\s+/g, '_'); }

    /**
     * Сотрудники портала по ID: ФИО целиком и должность. В общий кэш имён (без отчества)
     * попадают и они: в списках приложения отчество не показывают.
     */
    function loadUsers(ids) {
        var missing = ids.filter(function (id) { return id && !users[id]; });
        if (!missing.length) {
            return Promise.resolve();
        }
        return new Promise(function (resolve, reject) {
            BX24.callMethod('user.get', {
                filter: { ID: missing },
                select: ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'WORK_POSITION']
            }, function (result) {
                if (result.error()) {
                    reject({ message: 'Не удалось получить сотрудников: ' + result.error() });
                    return;
                }
                result.data().forEach(function (user) {
                    var id = Number(user.ID);
                    ctx.rememberName(id, [user.LAST_NAME, user.NAME].join(' ').trim());
                    users[id] = {
                        name: [user.LAST_NAME, user.NAME, user.SECOND_NAME].join(' ').trim() || ('сотрудник ' + id),
                        position: String(user.WORK_POSITION || '').trim()
                    };
                });
                if (result.more()) {
                    result.next();
                } else {
                    resolve();
                }
            });
        });
    }

    // ---------- форма выгрузки ----------

    /**
     * Окно настроек выгрузки. payload: { subtitle, kinds, pending?, onExport(options) }.
     */
    function open(payload) {
        var kinds = payload.kinds || {};
        var overlay = document.createElement('div');
        overlay.className = 'kb-overlay';
        overlay.innerHTML = '<div class="kb-modal"><h3>Выгрузить в Excel</h3>'
            + '<p class="hint">' + esc(payload.subtitle) + '</p>'
            + '<label class="check"><input type="checkbox" data-role="pending"> Включить заявки на согласовании</label>'
            + '<p class="hint">Виды отсутствий:</p>'
            + Object.keys(kinds).map(function (code) {
                return '<label class="check"><input type="checkbox" data-kind="' + esc(code) + '" checked> ' + esc(kinds[code]) + '</label>';
            }).join('')
            + '<div class="actions"><button type="button" class="primary" data-role="download">Скачать .xlsx</button>'
            + '<button type="button" data-role="close">Отмена</button></div>'
            + '<p class="message" data-role="note" hidden></p></div>';
        document.body.appendChild(overlay);
        var note = overlay.querySelector('[data-role="note"]');
        var download = overlay.querySelector('[data-role="download"]');
        var say = function (text, isError) {
            note.hidden = !text;
            note.textContent = text || '';
            note.className = 'message' + (isError ? ' error' : '');
        };
        overlay.addEventListener('click', function (event) { if (event.target === overlay) { overlay.remove(); } });
        overlay.querySelector('[data-role="close"]').addEventListener('click', function () { overlay.remove(); });
        download.addEventListener('click', function () {
            var selected = Array.prototype.map.call(overlay.querySelectorAll('[data-kind]:checked'), function (box) { return box.dataset.kind; });
            if (!selected.length) {
                say('Отметьте хотя бы один вид отсутствия', true);
                return;
            }
            download.disabled = true;
            say('Готовим файл…');
            Promise.resolve(payload.onExport({ pending: overlay.querySelector('[data-role="pending"]').checked, kinds: selected }))
                .then(function (saved) {
                    if (saved === false) {
                        say('Нет заявок для выгрузки по выбранным параметрам');
                        return;
                    }
                    overlay.remove();
                })
                .catch(function (error) {
                    say(error && error.message ? error.message : 'Не удалось выгрузить файл', true);
                })
                .then(function () {
                    download.disabled = false;
                    if (typeof BX24 !== 'undefined') { BX24.fitWindow(); }
                });
        });
        if (typeof BX24 !== 'undefined') { BX24.fitWindow(); }
    }

    // ---------- вкладка «Календарь» ----------

    /**
     * Выгрузка из календаря: один отдел, текущий период и выбранный сотрудник.
     * payload: { departmentId, departmentName, employeeId, from, to, period }
     */
    function fromCalendar(payload) {
        var me = ctx.me();
        var employee = payload.employeeId ? ' · сотрудник: ' + ctx.name(payload.employeeId) : '';
        open({
            subtitle: 'Отдел: ' + payload.departmentName + ' · ' + payload.period + employee,
            kinds: me.kinds,
            onExport: function (options) {
                return download('calendar', {
                    from: payload.from,
                    to: payload.to,
                    departments: [payload.departmentId],
                    employee: payload.employeeId,
                    // Названия отделов для сортировки и колонки «Подразделение»
                    departmentsTree: me.calendar.departments,
                    departmentLabel: payload.departmentName,
                    periodLabel: payload.period
                }, options);
            }
        });
    }

    // ---------- вкладка «Дашборд» ----------

    /**
     * Сводный график за год по всем доступным отделам или выбранному с подотделами.
     * payload: { year, departmentId, departments: [{id, name}] — отделы в области выгрузки, в порядке дерева }
     */
    function fromDashboard(payload) {
        var me = ctx.me();
        var departmentLabel = payload.departmentId
            ? (payload.departments.filter(function (d) { return d.id === payload.departmentId; })[0] || {}).name || 'Вся компания'
            : 'Вся компания';
        open({
            subtitle: 'Отдел: ' + departmentLabel + ' · ' + payload.year + ' год',
            kinds: me.kinds,
            onExport: function (options) {
                return download('dashboard', {
                    from: payload.year + '-01-01',
                    to: payload.year + '-12-31',
                    departments: payload.departments.map(function (d) { return d.id; }),
                    departmentsTree: payload.departments,
                    departmentLabel: departmentLabel,
                    periodLabel: payload.year + ' год'
                }, options);
            }
        });
    }

    // ---------- сборка файла ----------

    /**
     * Заявки с сервера, ФИО и должности из портала, файл XLSX локально.
     * Возвращает false, когда заявок нет: файл не создаём, в окне показываем сообщение,
     * что заявок по выбранным параметрам нет (окно остаётся открытым).
     */
    function download(scope, filter, options) {
        return ctx.api('/api/export', {
            scope: scope,
            from: filter.from,
            to: filter.to,
            departments: JSON.stringify(filter.departments),
            pending: options.pending ? '1' : '0',
            kinds: JSON.stringify(options.kinds),
            employee: filter.employee || 0
        }).then(function (data) {
            if (!data.items.length) {
                return false;
            }
            var rows = sortRows(data.items, filter.departmentsTree);
            var ids = [];
            rows.forEach(function (r) {
                ids.push(r.userId);
                r.approvers.forEach(function (id) { ids.push(id); });
            });
            return loadUsers(ids).then(function () {
                buildWorkbook(rows, filter, options);
                return true;
            });
        });
    }

    // Сортировка по структуре отделов, внутри отдела — по алфавиту
    function sortRows(items, departmentsTree) {
        var order = {};
        (departmentsTree || []).forEach(function (d, i) { order[d.id] = i; });
        return items.slice().sort(function (a, b) {
            var left = order[a.deptId], right = order[b.deptId];
            if (left === undefined) { left = 9999; }
            if (right === undefined) { right = 9999; }
            return left !== right
                ? left - right
                : userName(a.userId).localeCompare(userName(b.userId), 'ru');
        });
    }

    function userName(id) {
        return users[id] ? users[id].name : ctx.name(id);
    }

    function departmentName(id, tree) {
        var found = (tree || []).filter(function (d) { return d.id === id; })[0];
        return found ? found.name : 'Отдел ' + id;
    }

    // Согласовавшие через запятую по именам
    function approverNames(ids) {
        return ids.map(function (id) { return userName(id); }).join(', ');
    }

    /**
     * Лист с шапкой для подшивки (строки 1–3), таблицей со строки 5, строкой «Итого дней»
     * и местом для визы ответственного. Даты пишем текстом: Excel не меняет их вид.
     */
    function buildWorkbook(rows, filter, options) {
        var kinds = ctx.me().kinds;
        var statusLine = options.pending ? 'Утверждено + на согласовании' : 'Утверждено';
        var header = [
            ['График отпусков и отсутствий сотрудников'],
            ['Подразделение: ' + filter.departmentLabel + ' | Период: ' + filter.periodLabel],
            ['Сформировано: ' + nowStamp() + ' | Статус заявок: ' + statusLine],
            [],
            HEADERS
        ];
        var table = rows.map(function (r, i) {
            return [
                i + 1,
                departmentName(r.deptId, filter.departmentsTree),
                userName(r.userId),
                users[r.userId] && users[r.userId].position ? users[r.userId].position : '—',
                kinds[r.kind] || r.kind,
                human(r.dateFrom),
                human(r.dateTo),
                r.days,
                STATUS[r.status] || r.status,
                r.approvers.length ? approverNames(r.approvers) : '—',
                r.approvedAt ? human(r.approvedAt) : '—',
                ''
            ];
        });
        var total = rows.reduce(function (sum, r) { return sum + (Number(r.days) || 0); }, 0);
        var footer = [
            [],
            [],
            ['Ответственный за ведение графика: _______________________ / _____________________ / «___» __________ 202_ г.']
        ];
        // Пустые ячейки дописываем, чтобы «Итого дней» встал в колонку дней
        var totalRow = [];
        for (var c = 0; c < 6; c++) { totalRow.push(''); }
        totalRow.push('Итого дней:');
        totalRow.push(total);

        var sheet = XLSX.utils.aoa_to_sheet([].concat(header, table, [totalRow], footer));
        // Три строки шапки, строка «Итого дней» и строка визы — на всю ширину таблицы
        var lastColumn = HEADERS.length - 1;
        var totalRowIndex = header.length + table.length;
        sheet['!merges'] = [
            { s: { r: 0, c: 0 }, e: { r: 0, c: lastColumn } },
            { s: { r: 1, c: 0 }, e: { r: 1, c: lastColumn } },
            { s: { r: 2, c: 0 }, e: { r: 2, c: lastColumn } },
            { s: { r: totalRowIndex, c: 0 }, e: { r: totalRowIndex, c: 6 } },
            { s: { r: totalRowIndex + 3, c: 0 }, e: { r: totalRowIndex + 3, c: lastColumn } }
        ];
        sheet['!cols'] = WIDTHS.map(function (width) { return { wch: width }; });

        var workbook = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(workbook, sheet, 'График отпусков');
        XLSX.writeFile(workbook, 'График_отпусков_' + safeName(filter.departmentLabel) + '_' + safeName(filter.periodLabel) + '_' + today() + '.xlsx');
    }

    return {
        /**
         * @param {object} context api, esc, me, name, rememberName, showError
         */
        init: function (context) { ctx = context; },
        fromCalendar: fromCalendar,
        fromDashboard: fromDashboard
    };
})();
