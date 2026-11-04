/**
 * Вкладка «Доступ»: роли с произвольными названиями. Роль открывает вкладки приложения и задаёт, заявки каких отделов
 * видны. Что можно сделать с заявкой, зависит от участия в ней (автор, согласующий, получатель результата), а не от роли.
 * Роли правятся прямо в списке, каждая карточка сохраняется своей кнопкой.
 * Сервер хранит только ID сотрудников: ФИО запрашивает браузер.
 */
window.Kb24Access = (function () {
    'use strict';

    var LEVELS = { none: 'Скрыта', view: 'Просмотр', edit: 'Изменение' };

    var ctx = null;
    // roles — правки в карточках до сохранения, dirty — какие карточки изменены
    var view = { data: null, roles: [], dirty: {} };

    function root() { return document.getElementById('access'); }
    function esc(text) { return ctx.esc(text); }
    function copy(value) { return JSON.parse(JSON.stringify(value)); }

    function departmentName(id) {
        var found = view.data.departments.filter(function (d) { return d.id === id; })[0];
        return found ? found.name : 'отдел ' + id;
    }

    function show() {
        root().innerHTML = '<p class="hint">Загрузка…</p>';
        ctx.api('/api/access').then(function (data) {
            view.data = data;
            view.roles = copy(data.roles);
            view.dirty = {};
            var ids = [];
            data.roles.forEach(function (role) { ids = ids.concat(role.userIds); });
            return ctx.loadNames(ids);
        }).then(draw).catch(function (error) {
            root().innerHTML = '';
            ctx.showError(error);
        });
    }

    function draw() {
        var data = view.data;
        root().innerHTML = '<p class="hint">«Мои заявки», «На согласовании» и «На ознакомлении» открыты всем. Участник заявки видит её всегда, '
            + 'руководитель — заявки и календарь отделов, которыми руководит, с подотделами. Администратор портала может всё. '
            + 'Если у сотрудника несколько ролей, по каждому праву берётся самое широкое.</p>'
            + '<details class="acc-more"><summary>Как устроен доступ</summary><p class="hint">Роль открывает вкладки приложения и задаёт, заявки каких отделов видны в календаре, '
            + 'в карточках и на дашборде. Что можно сделать с заявкой, зависит от участия в ней: автор подаёт и отзывает, согласующий согласует или отклоняет, '
            + 'получатель результата подтверждает ознакомление. Роли указываются в шагах маршрутов на вкладке «Маршруты»; названия ролей и шагов не обязаны совпадать. '
            + 'Роль, указанную в маршрутах, удалить нельзя. Изменения доступа к вкладкам сотрудники увидят, когда заново откроют приложение.</p></details>'
            + (data.canEdit ? '' : '<p class="hint">Вам доступен только просмотр ролей.</p>')
            + '<div data-role="list">' + view.roles.map(function (role, index) { return '<div class="acc-role" data-index="' + index + '"></div>'; }).join('') + '</div>'
            + (data.canEdit ? '<p><button type="button" data-role="add">+ Новая роль</button></p>' : '');
        view.roles.forEach(function (role, index) { drawRole(index); });
        var add = root().querySelector('[data-role="add"]');
        if (add) {
            add.addEventListener('click', function () {
                var tabs = {};
                Object.keys(data.tabs).forEach(function (tab) { tabs[tab] = tab === 'calendar' ? 'view' : 'none'; });
                view.roles.push({ id: 0, name: '', base: false, legacy: '', userIds: [], departmentIds: [], tabs: tabs, scope: 'dept' });
                view.dirty[view.roles.length - 1] = true;
                draw();
                var cards = root().querySelectorAll('.acc-role');
                cards[cards.length - 1].querySelector('[name="name"]').focus();
            });
        }
        BX24.fitWindow();
    }

    function select(name, options, value, disabled) {
        return '<select name="' + esc(name) + '"' + (disabled ? ' disabled' : '') + '>' + options.map(function (o) {
            return '<option value="' + esc(o[0]) + '"' + (o[0] === value ? ' selected' : '') + '>' + esc(o[1]) + '</option>';
        }).join('') + '</select>';
    }

    function drawRole(index) {
        var data = view.data;
        var role = view.roles[index];
        var edit = data.canEdit;
        var card = root().querySelector('.acc-role[data-index="' + index + '"]');
        var chip = function (text, attr) {
            return '<span class="chip">' + esc(text) + (edit ? ' <button type="button" class="chip-x" ' + attr + ' title="Убрать">×</button>' : '') + '</span>';
        };
        var departments = data.departments.filter(function (d) { return role.departmentIds.indexOf(d.id) === -1; });

        card.innerHTML = '<div class="acc-head">'
            + (edit ? '<input name="name" maxlength="100" value="' + esc(role.name) + '" placeholder="Название, например «Кадровая служба»">' : '<b>' + esc(role.name) + '</b>')
            + (role.base ? '<span class="hint">есть у каждого сотрудника портала; удалить нельзя, можно переименовать и расширить</span>' : '')
            + (edit ? '<span class="acc-actions">'
                + '<button type="button" class="primary" data-role="save"' + (view.dirty[index] ? '' : ' disabled') + '>Сохранить</button>'
                + (view.dirty[index] && role.id ? '<button type="button" data-role="reset">Отменить</button>' : '')
                + (!role.base ? '<button type="button" class="danger" data-role="delete">' + (role.id ? 'Удалить' : 'Убрать') + '</button>' : '')
                + '</span>' : '')
            + '</div>'
            + (role.base ? '' : '<div class="acc-members">'
                + '<div><span class="acc-label">Сотрудники</span><div class="acc-chips">'
                + role.userIds.map(function (id) { return chip(ctx.name(id), 'data-user="' + esc(id) + '"'); }).join(' ')
                + (edit ? ' <button type="button" class="kb-link" data-role="pick">+ сотрудник</button>' : (role.userIds.length ? '' : '<span class="hint">нет</span>'))
                + '</div></div>'
                + '<div><span class="acc-label">Отделы (все сотрудники отдела и подотделов)</span><div class="acc-chips">'
                + role.departmentIds.map(function (id) { return chip(departmentName(id), 'data-dept="' + esc(id) + '"'); }).join(' ')
                + (edit && departments.length ? ' <select name="add-dept" class="acc-add"><option value="">+ отдел</option>' + departments.map(function (d) {
                    return '<option value="' + esc(d.id) + '">' + new Array(d.depth + 1).join('   ') + esc(d.name) + '</option>';
                }).join('') + '</select>' : (role.departmentIds.length || edit ? '' : '<span class="hint">нет</span>'))
                + '</div></div></div>')
            + '<div class="acc-grid">' + Object.keys(data.tabs).map(function (tab) {
                return '<label><span class="acc-label">' + esc(data.tabs[tab].name) + '</span>'
                    + select('tab-' + tab, data.tabs[tab].levels.map(function (level) { return [level, LEVELS[level]]; }), role.tabs[tab], !edit) + '</label>';
            }).join('')
            + '<label><span class="acc-label">Чьи заявки и какой календарь видит</span>'
            + select('scope', Object.keys(data.scopes).map(function (code) { return [code, data.scopes[code]]; }), role.scope, !edit) + '</label>'
            + '</div>';

        if (!edit) {
            return;
        }
        var changed = function () {
            if (!view.dirty[index]) {
                view.dirty[index] = true;
                drawRole(index);
            }
            BX24.fitWindow();
        };
        card.querySelector('[name="name"]').addEventListener('input', function (e) {
            role.name = e.target.value;
            if (!view.dirty[index]) {
                view.dirty[index] = true;
                var save = card.querySelector('[data-role="save"]');
                save.disabled = false;
            }
        });
        card.querySelectorAll('.acc-grid select').forEach(function (field) {
            field.addEventListener('change', function () {
                if (field.name === 'scope') {
                    role.scope = field.value;
                } else {
                    role.tabs[field.name.replace('tab-', '')] = field.value;
                }
                changed();
            });
        });
        card.querySelectorAll('[data-user]').forEach(function (button) {
            button.addEventListener('click', function () {
                role.userIds = role.userIds.filter(function (id) { return id !== Number(button.dataset.user); });
                view.dirty[index] = true;
                drawRole(index);
            });
        });
        card.querySelectorAll('[data-dept]').forEach(function (button) {
            button.addEventListener('click', function () {
                role.departmentIds = role.departmentIds.filter(function (id) { return id !== Number(button.dataset.dept); });
                view.dirty[index] = true;
                drawRole(index);
            });
        });
        var addDept = card.querySelector('[name="add-dept"]');
        if (addDept) {
            addDept.addEventListener('change', function () {
                if (addDept.value) {
                    role.departmentIds.push(Number(addDept.value));
                    view.dirty[index] = true;
                    drawRole(index);
                }
            });
        }
        var pick = card.querySelector('[data-role="pick"]');
        if (pick) {
            pick.addEventListener('click', function () {
                var selected = role.userIds.map(function (id) { return { id: id, name: ctx.name(id) }; });
                window.Kb24Picker.open({ title: 'Сотрудники роли', multiple: true, selected: selected, anchor: card }).then(function (users) {
                    if (users) {
                        users.forEach(function (user) { ctx.rememberName(user.id, user.name); });
                        role.userIds = users.map(function (user) { return user.id; });
                        view.dirty[index] = true;
                    }
                    drawRole(index);
                    BX24.fitWindow();
                });
            });
        }
        var reset = card.querySelector('[data-role="reset"]');
        if (reset) {
            reset.addEventListener('click', function () {
                var saved = view.data.roles.filter(function (r) { return r.id === role.id; })[0];
                view.roles[index] = copy(saved);
                delete view.dirty[index];
                drawRole(index);
            });
        }
        card.querySelector('[data-role="save"]').addEventListener('click', function (e) {
            e.target.disabled = true;
            var payload = { id: role.id, name: role.name, userIds: role.userIds, departmentIds: role.departmentIds, tabs: role.tabs, scope: role.scope };
            ctx.api('/api/access/save', { role: JSON.stringify(payload) }).then(function () {
                // Права текущего сотрудника могли измениться: вкладки пересчитаются при следующем открытии приложения
                ctx.showMessage('Роль «' + role.name + '» сохранена');
                keepEditsAndReload(index);
            }).catch(function (error) {
                e.target.disabled = false;
                ctx.showError(error);
            });
        });
        var remove = card.querySelector('[data-role="delete"]');
        if (remove) {
            remove.addEventListener('click', function () {
                if (!role.id) {
                    view.roles.splice(index, 1);
                    reindexDirty(index);
                    draw();
                    return;
                }
                if (remove.dataset.confirm !== 'Y') {
                    remove.dataset.confirm = 'Y';
                    remove.textContent = 'Точно удалить?';
                    return;
                }
                ctx.api('/api/access/delete', { id: role.id }).then(function () {
                    ctx.showMessage('Роль «' + role.name + '» удалена');
                    keepEditsAndReload(index);
                }).catch(ctx.showError);
            });
        }
    }

    function reindexDirty(removed) {
        var dirty = {};
        Object.keys(view.dirty).forEach(function (key) {
            var i = Number(key);
            if (i !== removed) {
                dirty[i > removed ? i - 1 : i] = true;
            }
        });
        view.dirty = dirty;
    }

    // После сохранения одной карточки несохранённые правки в остальных остаются
    function keepEditsAndReload(doneIndex) {
        var pending = view.roles.filter(function (role, i) { return i !== doneIndex && view.dirty[i]; });
        ctx.api('/api/access').then(function (data) {
            view.data = data;
            view.roles = copy(data.roles);
            view.dirty = {};
            pending.forEach(function (edited) {
                var at = edited.id ? view.roles.map(function (r) { return r.id; }).indexOf(edited.id) : -1;
                if (at === -1) {
                    view.roles.push(edited);
                    at = view.roles.length - 1;
                } else {
                    view.roles[at] = edited;
                }
                view.dirty[at] = true;
            });
            draw();
        }).catch(ctx.showError);
    }

    return {
        init: function (context) { ctx = context; },
        show: show
    };
})();
