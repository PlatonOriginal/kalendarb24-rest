/**
 * Вкладка «Доступ»: роли с произвольными названиями. Роль открывает вкладки приложения и задаёт, заявки каких отделов
 * видны. Что можно сделать с заявкой, зависит от участия в ней (автор, согласующий, получатель результата), а не от роли.
 * Сервер хранит только ID сотрудников: ФИО запрашивает браузер.
 */
window.Kb24Access = (function () {
    'use strict';

    var LEVELS = { none: 'Скрыта', view: 'Просмотр', edit: 'Изменение' };

    var ctx = null;
    var view = { data: null };

    function root() { return document.getElementById('access'); }
    function esc(text) { return ctx.esc(text); }

    function departmentName(id) {
        var found = view.data.departments.filter(function (d) { return d.id === id; })[0];
        return found ? found.name : 'отдел ' + id;
    }

    function show() {
        root().innerHTML = '<p class="hint">Загрузка…</p>';
        ctx.api('/api/access').then(function (data) {
            view.data = data;
            var ids = [];
            data.roles.forEach(function (role) { ids = ids.concat(role.userIds); });
            return ctx.loadNames(ids);
        }).then(draw).catch(function (error) {
            root().innerHTML = '';
            ctx.showError(error);
        });
    }

    function members(role) {
        if (role.base) {
            return '<span class="hint">все сотрудники</span>';
        }
        var chips = role.userIds.map(function (id) { return '<span class="chip">' + esc(ctx.name(id)) + '</span>'; })
            .concat(role.departmentIds.map(function (id) { return '<span class="chip accent">' + esc(departmentName(id)) + ' с подотделами</span>'; }));
        return chips.length ? chips.join(' ') : '<span class="hint">никого</span>';
    }

    function tabsText(role) {
        var tabs = view.data.tabs;
        var open = Object.keys(tabs).filter(function (tab) { return role.tabs[tab] !== 'none'; }).map(function (tab) {
            return tabs[tab].name + (role.tabs[tab] === 'edit' ? ' (изменение)' : '');
        });
        return open.length ? open.join(', ') : 'нет';
    }

    function draw() {
        var data = view.data;
        root().innerHTML = (data.canEdit ? '<p><button type="button" class="primary" data-role="add">+ Новая роль</button></p>'
                : '<p class="hint">Вам доступен только просмотр ролей.</p>')
            + '<p class="hint">Роль открывает вкладки приложения и задаёт, заявки каких отделов видны в календаре и в карточках. '
            + '«Мои заявки», «На согласовании» и «На ознакомлении» открыты всем: там только заявки, в которых сотрудник участвует. '
            + 'Что можно сделать с заявкой, зависит от участия в ней: автор подаёт и отзывает, согласующий согласует или отклоняет, получатель результата подтверждает ознакомление. '
            + 'Руководитель всегда видит заявки отделов, которыми руководит. '
            + 'Если сотрудник в нескольких ролях, по каждой вкладке и по видимости берётся более широкое право. '
            + 'Администратор портала может всё. Роли можно указывать в шагах маршрутов на вкладке «Маршруты»; названия ролей и шагов не обязаны совпадать.</p>'
            + '<div class="routes">' + data.roles.map(function (role, index) {
                return '<div class="route"><div class="route-head"><b>' + esc(role.name) + '</b>'
                    + (role.base ? ' <span class="chip">есть у всех</span>' : '')
                    + (data.canEdit ? ' <button type="button" data-edit="' + index + '">Изменить</button>' : '') + '</div>'
                    + '<div>' + members(role) + '</div>'
                    + '<div class="hint">Вкладки: ' + esc(tabsText(role)) + ' · Заявки и календарь: ' + esc(data.scopes[role.scope]) + '</div></div>';
            }).join('') + '</div>';

        if (data.canEdit) {
            root().querySelector('[data-role="add"]').addEventListener('click', function () {
                var tabs = {};
                Object.keys(data.tabs).forEach(function (tab) { tabs[tab] = tab === 'calendar' ? 'view' : 'none'; });
                openEditor({ id: 0, name: '', base: false, legacy: '', userIds: [], departmentIds: [], tabs: tabs, scope: 'dept' });
            });
        }
        root().querySelectorAll('[data-edit]').forEach(function (button) {
            button.addEventListener('click', function () {
                openEditor(JSON.parse(JSON.stringify(data.roles[Number(button.dataset.edit)])));
            });
        });
        BX24.fitWindow();
    }

    function openEditor(role) {
        var data = view.data;
        root().innerHTML = '<h2>' + (role.id ? 'Роль «' + esc(role.name) + '»' : 'Новая роль') + '</h2>'
            + '<div class="form route-form"><label>Название <input name="name" maxlength="100" size="40" value="' + esc(role.name) + '" placeholder="Например, «Кадровая служба»"></label></div>'
            + (role.base ? '<p class="hint">Эта роль есть у всех сотрудников, состав у неё не задаётся.</p>' : '<div class="route-cols">'
                + '<div><h3>Сотрудники</h3><p data-role="users"></p><p><button type="button" data-role="pick">Выбрать сотрудников</button></p></div>'
                + '<div><h3>Отделы</h3><p class="hint">Все сотрудники отдела и его подотделов. Несколько — с Ctrl или ⌘.</p>'
                + '<select name="departments" multiple size="8">' + data.departments.map(function (d) {
                    return '<option value="' + esc(d.id) + '"' + (role.departmentIds.indexOf(d.id) !== -1 ? ' selected' : '') + '>'
                        + new Array(d.depth + 1).join('   ') + esc(d.name) + '</option>';
                }).join('') + '</select></div></div>')
            + '<h3>Вкладки</h3><table class="route-table"><thead><tr><th>Вкладка</th><th>Доступ</th></tr></thead><tbody>'
            + Object.keys(data.tabs).map(function (tab) {
                return '<tr><td>' + esc(data.tabs[tab].name) + '</td><td><select name="tab-' + esc(tab) + '">' + data.tabs[tab].levels.map(function (level) {
                    return '<option value="' + level + '"' + (role.tabs[tab] === level ? ' selected' : '') + '>' + esc(LEVELS[level]) + '</option>';
                }).join('') + '</select></td></tr>';
            }).join('') + '</tbody></table>'
            + '<h3>Чьи заявки видны</h3><p class="hint">Кроме своих и тех, в которых сотрудник участвует. Так же ограничены отделы в календаре.</p>'
            + '<div class="form"><select name="scope">' + Object.keys(data.scopes).map(function (code) {
                return '<option value="' + code + '"' + (role.scope === code ? ' selected' : '') + '>' + esc(data.scopes[code]) + '</option>';
            }).join('') + '</select></div>'
            + '<div class="actions"><button type="button" class="primary" data-role="save">Сохранить</button>'
            + '<button type="button" data-role="close">Отмена</button>'
            + (role.id && !role.base ? '<button type="button" class="danger" data-role="delete">Удалить роль</button>' : '') + '</div>';

        function drawUsers() {
            var holder = root().querySelector('[data-role="users"]');
            if (holder) {
                holder.innerHTML = role.userIds.length
                    ? role.userIds.map(function (id) { return '<span class="chip">' + esc(ctx.name(id)) + '</span>'; }).join(' ')
                    : '<span class="hint">не выбраны</span>';
            }
            BX24.fitWindow();
        }

        var pickButton = root().querySelector('[data-role="pick"]');
        if (pickButton) {
            pickButton.addEventListener('click', function () {
                var selected = role.userIds.map(function (id) { return { id: id, name: ctx.name(id) }; });
                window.Kb24Picker.open({ title: 'Сотрудники роли', multiple: true, selected: selected, anchor: pickButton.parentNode }).then(function (users) {
                    if (users) {
                        users.forEach(function (user) { ctx.rememberName(user.id, user.name); });
                        role.userIds = users.map(function (user) { return user.id; });
                    }
                    drawUsers();
                });
            });
        }
        root().querySelector('[data-role="close"]').addEventListener('click', function () {
            ctx.showMessage('');
            draw();
        });
        root().querySelector('[data-role="save"]').addEventListener('click', function (e) {
            var form = root();
            var tabs = {};
            Object.keys(data.tabs).forEach(function (tab) { tabs[tab] = form.querySelector('[name="tab-' + tab + '"]').value; });
            var departments = form.querySelector('[name="departments"]');
            var payload = {
                id: role.id,
                name: form.querySelector('[name="name"]').value,
                userIds: role.userIds,
                departmentIds: departments ? Array.prototype.map.call(departments.selectedOptions, function (option) { return Number(option.value); }) : [],
                tabs: tabs,
                scope: form.querySelector('[name="scope"]').value
            };
            e.target.disabled = true;
            ctx.api('/api/access/save', { role: JSON.stringify(payload) }).then(function () {
                // Права текущего сотрудника могли измениться: вкладки пересчитаются при следующем открытии приложения
                ctx.showMessage('Роль сохранена. Изменения доступа к вкладкам сотрудники увидят, когда заново откроют приложение');
                show();
            }).catch(function (error) {
                e.target.disabled = false;
                ctx.showError(error);
            });
        });
        var deleteButton = root().querySelector('[data-role="delete"]');
        if (deleteButton) {
            deleteButton.addEventListener('click', function () {
                if (deleteButton.dataset.confirm !== 'Y') {
                    deleteButton.dataset.confirm = 'Y';
                    deleteButton.textContent = 'Точно удалить?';
                    return;
                }
                ctx.api('/api/access/delete', { id: role.id }).then(function () {
                    ctx.showMessage('Роль удалена');
                    show();
                }).catch(ctx.showError);
            });
        }
        drawUsers();
    }

    return {
        init: function (context) { ctx = context; },
        show: show
    };
})();
