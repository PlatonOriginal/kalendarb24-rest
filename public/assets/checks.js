(function () {
    'use strict';

    function row(table, title, ok, details) {
        var tr = document.createElement('tr');
        var status = ok ? '<span class="ok">✓</span> ' : '<span class="fail">✗</span> ';
        var text = typeof details === 'string' ? details : JSON.stringify(details, null, 2);
        tr.innerHTML = '<td>' + title + '</td><td>' + status + '<pre></pre></td>';
        tr.querySelector('pre').textContent = text;
        document.querySelector('#' + table + ' tbody').appendChild(tr);
        BX24.fitWindow();
    }

    // Запрос к нашему серверу с токеном текущего пользователя
    function api(path, method) {
        var auth = BX24.getAuth();
        return fetch(path, {
            method: method || 'GET',
            headers: {
                'X-B24-Auth': auth.access_token,
                'X-B24-Member': auth.member_id
            }
        }).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok) {
                    throw data;
                }
                return data;
            });
        });
    }

    function call(method, params) {
        return new Promise(function (resolve) {
            BX24.callMethod(method, params, function (result) {
                resolve(result.error() ? { error: result.error().toString() } : { data: result.data() });
            });
        });
    }

    // Где на самом деле оказалось событие заявки: чей календарь, кто организатор и участники
    function block5() {
        document.getElementById('block5-run').onclick = function () {
            var id = document.getElementById('block5-id').value;
            api('/api/requests/' + id).then(function (card) {
                var request = card.request;
                if (!request.calEventId) {
                    row('block5', 'Заявка ' + id + ': событие', false, request.calError || 'у заявки нет события');
                    return;
                }
                call('calendar.event.getbyid', { id: request.calEventId }).then(function (event) {
                    if (event.error) {
                        row('block5', 'calendar.event.getbyid ' + request.calEventId, false, event.error);
                        return;
                    }
                    var e = event.data || {};
                    var keys = ['ID', 'PARENT_ID', 'CAL_TYPE', 'OWNER_ID', 'SECTION_ID', 'SECT_ID', 'CREATED_BY', 'MEETING_HOST',
                        'IS_MEETING', 'ACCESSIBILITY', 'ATTENDEES_CODES', 'MEETING_STATUS', 'DATE_FROM', 'DATE_TO'];
                    var picked = { 'автор заявки': request.userId };
                    keys.forEach(function (k) { if (k in e) { picked[k] = e[k]; } });
                    if (Array.isArray(e.ATTENDEE_LIST)) {
                        picked.ATTENDEE_LIST = e.ATTENDEE_LIST.map(function (a) { return a.id + ' ' + a.status; });
                    }
                    row('block5', 'calendar.event.getbyid ' + request.calEventId, String(e.OWNER_ID) === String(request.userId), picked);
                    call('calendar.section.get', { type: 'user', ownerId: request.userId }).then(function (sections) {
                        row('block5', 'calendar.section.get: календари автора заявки', !sections.error,
                            sections.error || (sections.data || []).map(function (sec) {
                                return sec.ID + ' «' + sec.NAME + '» владелец ' + sec.OWNER_ID + ' PERM.add=' + !!(sec.PERM && sec.PERM.add);
                            }).join('\n'));
                    });
                });
            }).catch(function (error) {
                row('block5', 'Заявка ' + id, false, error);
            });
        };
    }

    // Можно ли читать «График отсутствий» через lists.*: он лежит в типе инфоблоков оргструктуры (structure),
    // которого нет в документированных значениях IBLOCK_TYPE_ID. Только чтение, ничего не записывает
    function block5Chart() {
        document.getElementById('block5-chart').onclick = function () {
            // Если весь тип закрыт, пробуем обратиться к графику по коду и проверяем, работает ли lists.* вообще
            var variants = [
                ['lists.get (lists): обычные списки, работает ли право «Списки»', 'lists.get', { IBLOCK_TYPE_ID: 'lists' }],
                ['lists.get (structure, absence): график по коду', 'lists.get', { IBLOCK_TYPE_ID: 'structure', IBLOCK_CODE: 'absence' }],
                ['lists.element.get (structure, absence): записи графика по коду', 'lists.element.get', { IBLOCK_TYPE_ID: 'structure', IBLOCK_CODE: 'absence' }]
            ];
            // Поля графика коротко: код, тип и значения списка (по ним приложение выбирает тип отсутствия)
            call('lists.field.get', { IBLOCK_TYPE_ID: 'structure', IBLOCK_CODE: 'absence' }).then(function (fields) {
                row('block5', 'lists.field.get (structure, absence): поля графика', !fields.error, fields.error || Object.keys(fields.data || {}).map(function (id) {
                    var f = fields.data[id];
                    return id + ' ' + (f.CODE || '') + ' (' + f.TYPE + ') «' + f.NAME + '»'
                        + (f.DISPLAY_VALUES_FORM ? '\n  ' + JSON.stringify(f.DISPLAY_VALUES_FORM) : '');
                }).join('\n'));
            });
            variants.forEach(function (v) {
                call(v[1], v[2]).then(function (r) {
                    row('block5', v[0], !r.error, r.error || JSON.stringify(r.data, null, 2).slice(0, 1500));
                });
            });
            call('lists.get', { IBLOCK_TYPE_ID: 'structure' }).then(function (lists) {
                if (lists.error) {
                    row('block5', 'lists.get (structure): инфоблоки оргструктуры', false, lists.error);
                    return;
                }
                var items = lists.data || [];
                row('block5', 'lists.get (structure): инфоблоки оргструктуры', items.length > 0,
                    items.map(function (b) { return b.ID + ' ' + b.CODE + ' «' + b.NAME + '»'; }).join('\n') || 'пусто');
                var chart = items.filter(function (b) { return b.CODE === 'absence'; })[0] || items[0];
                if (!chart) {
                    return;
                }
                call('lists.field.get', { IBLOCK_TYPE_ID: 'structure', IBLOCK_ID: chart.ID }).then(function (fields) {
                    row('block5', 'lists.field.get ' + chart.ID + ': поля графика', !fields.error, fields.error || Object.keys(fields.data || {}).map(function (code) {
                        var f = fields.data[code];
                        var values = f.DISPLAY_VALUES_FORM ? ' = ' + JSON.stringify(f.DISPLAY_VALUES_FORM) : '';
                        return code + ' (' + f.TYPE + ') «' + f.NAME + '»' + values;
                    }).join('\n'));
                });
                call('lists.element.get', { IBLOCK_TYPE_ID: 'structure', IBLOCK_ID: chart.ID }).then(function (elements) {
                    row('block5', 'lists.element.get ' + chart.ID + ': записи графика', !elements.error,
                        elements.error || JSON.stringify((elements.data || []).slice(0, 3), null, 2));
                });
            });
        };
    }

    // Проверки запускает вкладка «Проверки» (vacation.js), уже после BX24.init
    window.Kb24Checks = {
        run: function () {
            block5();
            block5Chart();
        }
    };
})();
