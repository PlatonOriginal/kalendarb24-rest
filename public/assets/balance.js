/**
 * Остатки отпуска и даты приёма, как в коробке: таблица всех сотрудников, правка по строке, CSV-шаблон и загрузка.
 * Сервер хранит и отдаёт только ID сотрудников: список сотрудников, ФИО и отделы браузер берёт из портала сам,
 * он же находит сотрудника по ФИО при загрузке CSV без ID.
 */
window.Kb24Balance = (function () {
    'use strict';

    // Порядок колонок CSV: такой же в шаблоне и при загрузке
    var CSV_COLUMNS = [
        ['userId', 'ID'],
        ['name', 'Сотрудник'],
        ['department', 'Отдел'],
        ['hireDate', 'Дата приёма'],
        ['days', 'Остаток, дн.'],
        ['asOf', 'На дату'],
        ['comment', 'Комментарий']
    ];

    var ctx = null;
    var view = { users: null, records: {}, canEdit: false, annualDays: 28, departmentId: 0, query: '', onlyEmpty: false };

    function root() { return document.getElementById('balances'); }
    function esc(text) { return ctx.esc(text); }
    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function today() { var d = new Date(); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function human(s) { var p = String(s).split('-'); return p.length === 3 ? p[2] + '.' + p[1] + '.' + p[0] : s; }

    function formatDays(n) {
        return String(Math.round(n * 100) / 100).replace('.', ',');
    }

    function departments() {
        return ctx.me().calendar.departments;
    }

    function departmentName(id) {
        var found = departments().filter(function (d) { return d.id === id; })[0];
        return found ? found.name : '';
    }

    // Отдел и его подотделы: в списке отделы идут в порядке дерева, подотделы сразу за родителем и глубже его
    function subtree(id) {
        var list = departments();
        var ids = {};
        for (var i = 0; i < list.length; i++) {
            if (list[i].id !== id) { continue; }
            ids[id] = true;
            for (var j = i + 1; j < list.length && list[j].depth > list[i].depth; j++) {
                ids[list[j].id] = true;
            }
            break;
        }
        return ids;
    }

    // Все сотрудники портала: ID, ФИО и основной отдел. Запрашивает браузер
    function loadUsers() {
        if (view.users) {
            return Promise.resolve(view.users);
        }
        return new Promise(function (resolve, reject) {
            var users = [];
            BX24.callMethod('user.get', { filter: { ACTIVE: true, USER_TYPE: 'employee' }, sort: 'LAST_NAME', order: 'ASC' }, function (result) {
                if (result.error()) {
                    reject({ message: 'Не удалось получить список сотрудников: ' + result.error() });
                    return;
                }
                result.data().forEach(function (user) {
                    var id = Number(user.ID);
                    var fullName = [user.LAST_NAME, user.NAME].join(' ').trim() || user.EMAIL || ('сотрудник ' + id);
                    ctx.rememberName(id, fullName);
                    users.push({ id: id, name: fullName, departmentId: Number((user.UF_DEPARTMENT || [])[0] || 0) });
                });
                if (result.more()) {
                    result.next();
                } else {
                    view.users = users;
                    resolve(users);
                }
            });
        });
    }

    function load() {
        root().innerHTML = '<p class="hint">Загрузка…</p>';
        Promise.all([ctx.api('/api/balances'), loadUsers()]).then(function (result) {
            var data = result[0];
            view.records = {};
            data.items.forEach(function (item) { view.records[item.userId] = item; });
            view.canEdit = data.canEdit;
            view.annualDays = data.annualDays;
            var updatedBy = data.items.map(function (item) { return item.balance ? item.balance.updatedBy : 0; });
            return ctx.loadNames(updatedBy).then(draw);
        }).catch(function (error) {
            root().innerHTML = '<p class="fail">' + esc(error && error.message ? error.message : 'Не удалось загрузить остатки') + '</p>';
            BX24.fitWindow();
        });
    }

    function draw() {
        var options = '<option value="0">Все отделы</option>' + departments().map(function (d) {
            return '<option value="' + d.id + '"' + (d.id === view.departmentId ? ' selected' : '') + '>'
                + new Array(d.depth + 1).join('  ') + esc(d.name) + '</option>';
        }).join('');

        root().innerHTML = '<div class="kb-toolbar">'
            + '<div class="kb-control"><span class="kb-label">Отдел</span><select data-role="department">' + options + '</select></div>'
            + '<div class="kb-control"><span class="kb-label">Сотрудник</span><input data-role="query" placeholder="Фамилия или имя" value="' + esc(view.query) + '"></div>'
            + '<label class="check"><input type="checkbox" data-role="onlyEmpty"' + (view.onlyEmpty ? ' checked' : '') + '> только без остатка</label>'
            + '<div class="kb-control"><span class="kb-label">CSV</span><div class="kb-nav">'
            + '<button type="button" data-role="template" title="Файл с сотрудниками и текущими данными: заполните и загрузите обратно">Скачать шаблон</button>'
            + (view.canEdit ? '<button type="button" data-role="import">Загрузить CSV</button><input type="file" accept=".csv,text/csv" data-role="file" hidden>' : '')
            + '</div></div></div>'
            + '<p class="hint">Остаток ежегодного отпуска на дату, например из 1С, и дата приёма на работу. '
            + 'В каждую годовщину приёма после даты остатка добавляется ' + esc(view.annualDays) + ' дн. (норма в настройках). '
            + 'Заявки с началом в дату остатка или позже его уменьшают, считаются и утверждённые, и ещё не согласованные. Праздники в отпуск не входят. '
            + 'Подать заявку больше доступного к началу отпуска нельзя; если остаток не указан, ограничения нет. '
            + (view.canEdit ? 'CSV можно сохранять из Excel или Calc с любым разделителем («;», «,», табуляция), даты в виде 31.12.2026 или 31.12.26; сотрудник ищется по ID, а без него по ФИО.' : 'Править данные может только отдел кадров.') + '</p>'
            + '<div data-role="report" hidden></div>'
            + '<div data-role="table"></div>';

        var box = root();
        box.querySelector('[data-role="department"]').addEventListener('change', function (event) {
            view.departmentId = +event.target.value;
            drawTable();
        });
        box.querySelector('[data-role="query"]').addEventListener('input', function (event) {
            view.query = event.target.value;
            drawTable();
        });
        box.querySelector('[data-role="onlyEmpty"]').addEventListener('change', function (event) {
            view.onlyEmpty = event.target.checked;
            drawTable();
        });
        box.querySelector('[data-role="template"]').addEventListener('click', downloadTemplate);
        var file = box.querySelector('[data-role="file"]');
        if (file) {
            box.querySelector('[data-role="import"]').addEventListener('click', function () { file.click(); });
            file.addEventListener('change', function () {
                if (file.files[0]) { importFile(file.files[0]); }
                file.value = '';
            });
        }
        drawTable();
    }

    function visibleUsers() {
        var query = view.query.trim().toLowerCase();
        var inDepartments = view.departmentId ? subtree(view.departmentId) : null;
        return view.users.filter(function (user) {
            var record = view.records[user.id];
            return (!inDepartments || inDepartments[user.departmentId])
                && (!query || user.name.toLowerCase().indexOf(query) !== -1)
                && (!view.onlyEmpty || !record || !record.balance);
        });
    }

    function drawTable() {
        var holder = root().querySelector('[data-role="table"]');
        var users = visibleUsers();
        if (!users.length) {
            holder.innerHTML = '<p class="hint">Сотрудники не найдены</p>';
            BX24.fitWindow();
            return;
        }
        holder.innerHTML = '<table class="balances"><thead><tr>'
            + '<th>Сотрудник</th><th>Отдел</th><th>Дата приёма</th><th>Остаток, дн.</th><th>На дату</th><th>Комментарий</th>'
            + '<th class="num">Начислено</th><th class="num">В заявках</th><th class="num">Доступно</th><th>Изменил</th>' + (view.canEdit ? '<th></th>' : '')
            + '</tr></thead><tbody>' + users.map(row).join('') + '</tbody></table>';
        holder.querySelectorAll('tr[data-user]').forEach(bindRow);
        BX24.fitWindow();
    }

    function row(user) {
        var record = view.records[user.id] || { hireDate: '', balance: null };
        var b = record.balance;
        var cells = view.canEdit
            ? '<td><input type="date" name="hireDate" value="' + esc(record.hireDate) + '"></td>'
              + '<td><input class="days" name="days" inputmode="decimal" value="' + (b ? esc(formatDays(b.days)) : '') + '" placeholder="не указан"></td>'
              + '<td><input type="date" name="asOf" value="' + esc(b ? b.asOf : today()) + '"></td>'
              + '<td><input name="comment" value="' + esc(b ? b.comment : '') + '"></td>'
            : '<td>' + (record.hireDate ? human(record.hireDate) : '—') + '</td>'
              + '<td>' + (b ? esc(formatDays(b.days)) : '—') + '</td><td>' + (b ? human(b.asOf) : '—') + '</td><td>' + (b ? esc(b.comment) : '') + '</td>';

        return '<tr data-user="' + user.id + '">'
            + '<td><a href="https://' + esc(BX24.getDomain()) + '/company/personal/user/' + user.id + '/" target="_blank">' + esc(user.name) + '</a></td>'
            + '<td>' + esc(departmentName(user.departmentId)) + '</td>' + cells + computed(b)
            + (view.canEdit ? '<td><button type="button" data-role="save" disabled>Сохранить</button></td>' : '')
            + '</tr>';
    }

    function computed(b) {
        return '<td class="num" data-role="accrued">' + (b ? esc(b.accrued ? '+' + formatDays(b.accrued) : '0') : '—') + '</td>'
            + '<td class="num" data-role="used">' + (b ? esc(formatDays(b.used)) : '—') + '</td>'
            + '<td class="num" data-role="available">' + available(b) + '</td>'
            + '<td class="hint" data-role="updated">' + (b && b.updatedBy ? esc(ctx.name(b.updatedBy)) + '<br>' + esc(human(b.updatedAt.slice(0, 10)) + b.updatedAt.slice(10)) : '') + '</td>';
    }

    function available(b) {
        if (!b) { return '<span class="hint">без ограничения</span>'; }
        return '<b' + (b.available <= 0 ? ' class="fail"' : '') + '>' + esc(formatDays(b.available)) + '</b>';
    }

    function bindRow(tr) {
        var button = tr.querySelector('[data-role="save"]');
        if (!button) { return; }
        var userId = +tr.dataset.user;
        tr.querySelectorAll('input').forEach(function (input) {
            input.addEventListener('input', function () {
                button.disabled = false;
                button.textContent = 'Сохранить';
            });
        });
        button.addEventListener('click', function () {
            button.disabled = true;
            var fields = {
                userId: userId,
                hireDate: tr.querySelector('[name="hireDate"]').value,
                days: tr.querySelector('[name="days"]').value,
                asOf: tr.querySelector('[name="asOf"]').value,
                comment: tr.querySelector('[name="comment"]').value
            };
            ctx.api('/api/balances/save', fields).then(function (item) {
                view.records[userId] = item;
                return ctx.loadNames([item.balance ? item.balance.updatedBy : 0]).then(function () {
                    var fresh = document.createElement('tr');
                    fresh.innerHTML = computed(item.balance);
                    tr.querySelectorAll('[data-role="accrued"], [data-role="used"], [data-role="available"], [data-role="updated"]').forEach(function (cell, i) {
                        cell.innerHTML = fresh.children[i].innerHTML;
                    });
                    button.textContent = 'Сохранено';
                });
            }).catch(function (error) {
                button.disabled = false;
                ctx.showError(error);
            });
        });
    }

    // ---------- CSV ----------

    function csvCell(value) {
        value = String(value === undefined || value === null ? '' : value);
        return /[";\n\r]/.test(value) ? '"' + value.replace(/"/g, '""') + '"' : value;
    }

    /**
     * Шаблон: сотрудники выбранного отдела с текущими данными, его можно заполнить и загрузить обратно.
     */
    function downloadTemplate() {
        var lines = [CSV_COLUMNS.map(function (c) { return c[1]; }).join(';')];
        visibleUsers().forEach(function (user) {
            var record = view.records[user.id] || { hireDate: '', balance: null };
            var b = record.balance;
            lines.push([
                user.id, user.name, departmentName(user.departmentId), record.hireDate ? human(record.hireDate) : '',
                b ? formatDays(b.days) : '', b ? human(b.asOf) : '', b ? b.comment : ''
            ].map(csvCell).join(';'));
        });
        // BOM нужен, чтобы Excel открыл UTF-8 с кириллицей
        var blob = new Blob(['﻿' + lines.join('\r\n') + '\r\n'], { type: 'text/csv;charset=utf-8' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'otpuska-ostatki.csv';
        document.body.appendChild(link);
        link.click();
        setTimeout(function () { URL.revokeObjectURL(link.href); link.remove(); }, 1000);
    }

    // Разделитель по первой строке: тот из «;», табуляции и «,», которого вне кавычек больше всего.
    // Табуляцию ставят Calc и Excel, если сохранить как «текст»
    function guessDelimiter(line) {
        var counts = { ';': 0, '\t': 0, ',': 0 };
        var quoted = false;
        for (var i = 0; i < line.length; i++) {
            if (line[i] === '"') { quoted = !quoted; }
            else if (!quoted && line[i] in counts) { counts[line[i]]++; }
        }
        return [';', '\t', ','].reduce(function (best, c) { return counts[c] > counts[best] ? c : best; }, ';');
    }

    function parseCsv(text) {
        var rows = [], row = [], cell = '', quoted = false;
        text = text.replace(/^﻿/, '');
        var delimiter = guessDelimiter(text.split(/\r?\n/)[0]);
        for (var i = 0; i < text.length; i++) {
            var c = text[i];
            if (quoted) {
                if (c === '"' && text[i + 1] === '"') { cell += '"'; i++; }
                else if (c === '"') { quoted = false; }
                else { cell += c; }
            } else if (c === '"') { quoted = true; }
            else if (c === delimiter) { row.push(cell); cell = ''; }
            else if (c === '\n' || c === '\r') {
                if (c === '\r' && text[i + 1] === '\n') { i++; }
                row.push(cell); rows.push(row); row = []; cell = '';
            } else { cell += c; }
        }
        if (cell !== '' || row.length) { row.push(cell); rows.push(row); }
        return rows;
    }

    // Сотрудник по ID из файла, а без него по ФИО: ФИО знает только браузер
    function findUser(item, byId, byName) {
        var id = Number(item.userId || 0);
        if (id && byId[id]) {
            return { id: id };
        }
        var matches = byName[String(item.name || '').trim().toLowerCase().replace(/\s+/g, ' ')] || [];
        if (matches.length === 1) {
            return { id: matches[0] };
        }
        return { error: matches.length ? 'несколько сотрудников с таким ФИО, укажите ID' : 'сотрудник не найден' };
    }

    // Дата из файла в виде Y-m-d: Excel пересохраняет 31.12.2026 как 31.12.26
    function normalizeDate(value) {
        var m = String(value).trim().match(/^(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})$/);
        if (m) {
            var year = m[3].length === 2 ? '20' + m[3] : m[3];
            return year + '-' + pad(+m[2]) + '-' + pad(+m[1]);
        }
        return String(value).trim();
    }

    function normalizeDays(value) {
        var text = String(value).trim().replace(',', '.');
        return text === '' || isNaN(Number(text)) ? text : String(Number(text));
    }

    // Совпадает ли строка файла с тем, что уже сохранено: такие строки не отправляем
    function unchanged(item, userId) {
        var record = view.records[userId] || { hireDate: '', balance: null };
        var b = record.balance;
        var same = function (key, current, normalize) {
            return !(key in item) || normalize(item[key]) === normalize(current);
        };
        var text = function (value) { return String(value).trim(); };
        return same('hireDate', record.hireDate, normalizeDate)
            && same('days', b ? String(b.days) : '', normalizeDays)
            && same('asOf', b ? b.asOf : '', normalizeDate)
            && same('comment', b ? b.comment : '', text);
    }

    // Excel сохраняет CSV в кодировке Windows (1251), а наш шаблон в UTF-8: пробуем UTF-8, иначе 1251
    function decode(buffer) {
        var bytes = new Uint8Array(buffer);
        // «Юникод» в Calc и Excel — это UTF-16 с отметкой порядка байтов в начале
        if (bytes[0] === 0xFF && bytes[1] === 0xFE) {
            return new TextDecoder('utf-16le').decode(buffer);
        }
        if (bytes[0] === 0xFE && bytes[1] === 0xFF) {
            return new TextDecoder('utf-16be').decode(buffer);
        }
        try {
            return new TextDecoder('utf-8', { fatal: true }).decode(buffer);
        } catch (e) {
            return new TextDecoder('windows-1251').decode(buffer);
        }
    }

    function importFile(file) {
        var report = root().querySelector('[data-role="report"]');
        var show = function (html, isError) {
            report.hidden = false;
            report.className = 'message' + (isError ? ' error' : '');
            report.innerHTML = html;
            BX24.fitWindow();
        };
        var reader = new FileReader();
        reader.onload = function () {
            var rows = parseCsv(decode(reader.result));
            var header = (rows.shift() || []).map(function (h) { return h.trim().toLowerCase().replace(/\s+/g, ' '); });
            // Колонки ищем по заголовку, чтобы порядок в файле был не важен
            var index = {};
            CSV_COLUMNS.forEach(function (c) { index[c[0]] = header.indexOf(c[1].toLowerCase()); });
            if (index.userId === -1 && index.name === -1) {
                show('В файле нет колонки «ID» или «Сотрудник». Скачайте шаблон и заполните его.'
                    + '<br>Первая строка файла: ' + esc(header.join(' | ').slice(0, 200)), true);
                return;
            }
            // Без колонок с данными все строки показались бы «без изменений»: так было с файлом в неизвестной кодировке
            if (['hireDate', 'days', 'asOf', 'comment'].every(function (key) { return index[key] === -1; })) {
                show('В файле не найдены колонки «Дата приёма», «Остаток, дн.», «На дату», «Комментарий». '
                    + 'Проверьте первую строку файла: она должна быть как в шаблоне.'
                    + '<br>Первая строка файла: ' + esc(header.join(' | ').slice(0, 200)), true);
                return;
            }
            var byId = {};
            var byName = {};
            view.users.forEach(function (user) {
                byId[user.id] = true;
                var key = user.name.toLowerCase().replace(/\s+/g, ' ');
                (byName[key] = byName[key] || []).push(user.id);
            });

            var payload = [];
            var errors = [];
            var skipped = 0;
            rows.forEach(function (cells, i) {
                if (!cells.join('').trim()) { return; }
                var item = { line: i + 2 };
                CSV_COLUMNS.forEach(function (c) {
                    if (index[c[0]] !== -1 && c[0] !== 'department') { item[c[0]] = (cells[index[c[0]]] || '').trim(); }
                });
                var found = findUser(item, byId, byName);
                if (found.error) {
                    errors.push('Строка ' + item.line + ': ' + found.error);
                    return;
                }
                if (unchanged(item, found.id)) {
                    skipped++;
                    return;
                }
                item.userId = found.id;
                delete item.name;
                payload.push(item);
            });
            if (!payload.length) {
                show('Изменений нет' + (skipped ? ', строк без изменений: ' + skipped : '') + '.' + errorsHtml(errors), errors.length > 0);
                return;
            }
            show('Загрузка… строк: ' + payload.length);
            ctx.api('/api/balances/import', { rows: JSON.stringify(payload) }).then(function (result) {
                errors = errors.concat(result.errors);
                view.query = '';
                return ctx.api('/api/balances').then(function (data) {
                    view.records = {};
                    data.items.forEach(function (item) { view.records[item.userId] = item; });
                    return ctx.loadNames(data.items.map(function (item) { return item.balance ? item.balance.updatedBy : 0; }));
                }).then(function () {
                    draw();
                    report = root().querySelector('[data-role="report"]');
                    show('Загружено строк: ' + result.saved + (skipped ? ', без изменений: ' + skipped : '') + '.' + errorsHtml(errors), errors.length > 0);
                });
            }).catch(function (error) {
                show(esc(error && error.message ? error.message : 'Не удалось загрузить файл'), true);
            });
        };
        reader.readAsArrayBuffer(file);
    }

    function errorsHtml(errors) {
        if (!errors.length) { return ''; }
        return '<br>' + errors.slice(0, 20).map(esc).join('<br>') + (errors.length > 20 ? '<br>…и ещё ' + (errors.length - 20) : '');
    }

    return {
        /**
         * @param {object} context api, esc, me, loadNames, name, rememberName, showError
         */
        init: function (context) {
            ctx = context;
        },
        show: load,
        formatDays: formatDays
    };
})();
