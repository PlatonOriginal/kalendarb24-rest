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

    // ФИО по ID запрашивает браузер, сервер их не видит
    function userNames(ids) {
        return new Promise(function (resolve) {
            if (!ids.length) {
                resolve({});
                return;
            }
            BX24.callMethod('user.get', { filter: { ID: ids }, select: ['ID', 'NAME', 'LAST_NAME'] }, function (result) {
                var names = {};
                (result.error() ? [] : result.data()).forEach(function (user) {
                    names[user.ID] = [user.LAST_NAME, user.NAME].join(' ').trim();
                });
                resolve(names);
            });
        });
    }

    function label(names, id) {
        return id ? id + ': ' + (names[id] || '?') : '—';
    }

    function block1() {
        var auth = BX24.getAuth();
        row('block1', 'BX24.init', !!auth, { domain: auth.domain, member_id: auth.member_id });

        BX24.callMethod('user.current', {}, function (result) {
            if (result.error()) {
                row('block1', 'user.current в браузере', false, result.error().toString());
                return;
            }
            var user = result.data();
            row('block1', 'user.current в браузере', true, user.ID + ': ' + [user.LAST_NAME, user.NAME].join(' ').trim());
        });

        api('/api/me')
            .then(function (data) { row('block1', '/api/me: сервер проверил токен пользователя', true, data); })
            .catch(function (error) { row('block1', '/api/me: сервер проверил токен пользователя', false, error); });

        api('/api/check')
            .then(function (data) { row('block1', '/api/check: app.info с сервера по токену приложения', true, data); })
            .catch(function (error) { row('block1', '/api/check: app.info с сервера по токену приложения', false, error); });
    }

    function block2() {
        api('/api/structure').then(function (data) {
            var ids = data.headChain.slice();
            data.departments.forEach(function (d) {
                if (d.headUserId && ids.indexOf(d.headUserId) < 0) {
                    ids.push(d.headUserId);
                }
            });
            return userNames(ids).then(function (names) {
                var byId = {};
                data.departments.forEach(function (d) { byId[d.id] = d; });

                row('block2', 'department.get: отделы и руководители (UF_HEAD)', data.departments.length > 0,
                    data.departments.map(function (d) {
                        return d.id + ' ' + d.name + (d.parentId ? ' (в ' + d.parentId + ')' : '') + ', руководитель ' + label(names, d.headUserId);
                    }).join('\n'));

                row('block2', 'user.get: мои отделы (только ID и UF_DEPARTMENT)', data.myDepartments.length > 0,
                    data.myDepartments.map(function (id) { return id + ' ' + (byId[id] ? byId[id].name : '?'); }).join('\n'));

                row('block2', 'Цепочка согласования: руководитель → вышестоящий → директор', true,
                    data.headChain.length
                        ? data.headChain.map(function (id) { return label(names, id); }).join(' → ')
                        : 'руководителей выше нет (вы директор или руководители не назначены)');
            });
        }).catch(function (error) {
            row('block2', '/api/structure', false, error);
        });

        api('/api/hr-compare').then(function (data) {
            row('block2', 'humanresources (REST 3.0): совпадают ли руководители с department.get', true,
                data.nodes.map(function (n) {
                    return n.accessCode + ' ' + n.name + ': heads ' + JSON.stringify(n.heads)
                        + ', department.get #' + n.departmentGetId + ' head ' + n.departmentGetHead
                        + (n.headsMatch ? ' ✓' : ' ✗');
                }).join('\n') || 'узлов нет');
        }).catch(function (error) {
            row('block2', 'humanresources (REST 3.0): нужен scope humanresources', false, error);
        });
    }

    function call(method, params) {
        return new Promise(function (resolve) {
            BX24.callMethod(method, params, function (result) {
                resolve(result.error() ? { error: result.error().toString() } : { data: result.data() });
            });
        });
    }

    function block3() {
        api('/api/me').then(function (me) {
            // Сначала администратор создаёт недостающие хранилища, остальные проверки идут после этого
            var ready = me.isAdmin ? api('/api/storage').then(function (data) {
                var expected = 'U' + data.installedBy;
                var closed = data.storages.every(function (s) {
                    var codes = Object.keys(s.rights);
                    return codes.length === 1 && codes[0] === expected && s.rights[expected] === 'X';
                });
                row('block3', 'entity.add / entity.item.property.add: хранилища и свойства', true, {
                    createdStorages: data.createdStorages,
                    addedProperties: data.addedProperties,
                    storages: data.storages.map(function (s) { return s.entity + ': ' + s.properties.join(', '); })
                });
                row('block3', 'entity.rights: управление только у ' + expected + ' (установил приложение)', closed,
                    data.storages.map(function (s) { return s.entity + ' ' + JSON.stringify(s.rights); }).join('\n'));
            }).catch(function (error) {
                row('block3', '/api/storage: нужно право «Хранилище данных (entity)»', false, error);
            }) : Promise.resolve();

            return ready.then(function () { return checkStorageAccess(me); });
        });
    }

    function checkStorageAccess(me) {
        api('/api/storage/probe', 'POST').then(function (data) {
            row('block3', 'Сервер: запись, изменение, чтение, удаление (' + data.milliseconds + ' мс)', data.deleted, data);
            checkBrowserRead(me, data.canaryId);
            row('block3', 'entity.item.update меняет только переданные свойства', data.updateKeepsOtherProperties,
                data.updateKeepsOtherProperties
                    ? 'да, KIND сохранился после изменения STATUS'
                    : 'нет, после изменения STATUS пропали другие свойства: при обновлении передаём все');
        }).catch(function (error) {
            row('block3', 'Сервер: пробная запись', false, error);
        });

        // Под токеном самого сотрудника данные приложения должны быть закрыты
        var who = me.isAdmin ? 'вы администратор, проверьте под обычным сотрудником' : 'обычный сотрудник';
        call('entity.item.add', { ENTITY: 'kbreq', NAME: 'browser_probe' }).then(function (write) {
            if (!write.error) {
                call('entity.item.delete', { ENTITY: 'kbreq', ID: write.data });
            }
            row('block3', 'Браузер: запись токеном сотрудника (' + who + ')', me.isAdmin || !!write.error,
                write.error ? 'закрыто: ' + write.error : 'открыто, запись ' + write.data + ' создана и удалена');
        });
    }

    // В истории всегда есть контрольная запись сервера, поэтому пустой ответ значит, что чтение закрыто
    function checkBrowserRead(me, canaryId) {
        var who = me.isAdmin ? 'вы администратор, проверьте под обычным сотрудником' : 'обычный сотрудник';
        call('entity.item.get', { ENTITY: 'kbhist', FILTER: { ID: canaryId } }).then(function (read) {
            var closed = !!read.error || read.data.length === 0;
            row('block3', 'Браузер: чтение контрольной записи ' + canaryId + ' токеном сотрудника (' + who + ')', me.isAdmin || closed,
                read.error ? 'закрыто: ' + read.error : (closed ? 'закрыто: запись не видна' : 'открыто: запись видна'));
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
                ['lists.element.get (structure, absence): записи графика по коду', 'lists.element.get', { IBLOCK_TYPE_ID: 'structure', IBLOCK_CODE: 'absence' }],
                ['lists.field.get (structure, absence): поля графика по коду', 'lists.field.get', { IBLOCK_TYPE_ID: 'structure', IBLOCK_CODE: 'absence' }]
            ];
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
            block1();
            block2();
            block3();
            block5();
            block5Chart();
        }
    };
})();
