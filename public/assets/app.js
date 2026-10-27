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
            row('block3', 'entity.item.update меняет только переданные свойства', data.updateKeepsOtherProperties,
                data.updateKeepsOtherProperties
                    ? 'да, KIND сохранился после изменения STATUS'
                    : 'нет, после изменения STATUS пропали другие свойства: при обновлении передаём все');
        }).catch(function (error) {
            row('block3', 'Сервер: пробная запись', false, error);
        });

        // Под токеном самого сотрудника данные приложения должны быть закрыты
        var who = me.isAdmin ? 'вы администратор, проверьте под обычным сотрудником' : 'обычный сотрудник';
        call('entity.item.get', { ENTITY: 'kbreq' }).then(function (read) {
            row('block3', 'Браузер: чтение заявок токеном сотрудника (' + who + ')', me.isAdmin || !!read.error,
                read.error ? 'закрыто: ' + read.error : 'открыто, записей: ' + read.data.length);
        });
        call('entity.item.add', { ENTITY: 'kbreq', NAME: 'browser_probe' }).then(function (write) {
            if (!write.error) {
                call('entity.item.delete', { ENTITY: 'kbreq', ID: write.data });
            }
            row('block3', 'Браузер: запись токеном сотрудника (' + who + ')', me.isAdmin || !!write.error,
                write.error ? 'закрыто: ' + write.error : 'открыто, запись ' + write.data + ' создана и удалена');
        });
    }

    BX24.init(function () {
        block1();
        block2();
        block3();
    });
})();
