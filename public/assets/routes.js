/**
 * Вкладка «Маршруты», как в коробке: список маршрутов, редактор шагов и кто ещё настраивает маршруты.
 * Сервер хранит только ID сотрудников: ФИО запрашивает браузер.
 */
window.Kb24Routes = (function () {
    'use strict';

    var MAX_STEPS = 10;
    var KINDS = [
        ['head', 'Руководитель по структуре'],
        ['hr', 'Отдел кадров (по очереди)'],
        ['director', 'Директор из настроек'],
        ['user', 'Конкретный сотрудник']
    ];

    var EVENTS = [['approved', 'Утверждение'], ['cancelled', 'Отмена утверждённого']];
    var TASKS = [['none', 'Не ставить'], ['auto', 'Ставить сразу'], ['ask', 'Спросить при ознакомлении']];
    var MAX_WATCHERS = 10;

    var ctx = null;
    var view = { data: null };

    function root() { return document.getElementById('routes'); }
    function esc(text) { return ctx.esc(text); }

    function kindNames(codes) {
        var kinds = ctx.me().kinds;
        return codes.map(function (code) { return kinds[code] || code; });
    }

    function departmentNames(ids) {
        return view.data.departments.filter(function (d) { return ids.indexOf(d.id) !== -1; }).map(function (d) { return d.name; });
    }

    function who(step) {
        if (step.kind === 'user') {
            return step.userId ? ctx.name(step.userId) : 'сотрудник не выбран';
        }
        var kind = KINDS.filter(function (k) { return k[0] === step.kind; })[0];
        return kind ? kind[1] : '';
    }

    function userIds() {
        var ids = view.data.editorIds.slice();
        view.data.routes.forEach(function (route) {
            ids.push(route.updatedBy);
            route.steps.forEach(function (step) { ids.push(step.userId); });
            route.watchers.forEach(function (watcher) { ids.push(watcher.userId); });
        });
        return ids;
    }

    function show() {
        root().innerHTML = '<p class="hint">Загрузка…</p>';
        ctx.api('/api/routes').then(function (data) {
            view.data = data;
            return ctx.loadNames(userIds());
        }).then(draw).catch(function (error) {
            root().innerHTML = '';
            ctx.showError(error);
        });
    }

    function draw() {
        var data = view.data;
        root().innerHTML = '<p><button type="button" class="primary" data-role="add">+ Новый маршрут</button></p>'
            + '<p class="hint">Маршрут задаёт шаги согласования для видов отсутствия и отделов. Из подходящих маршрутов срабатывает самый точный: '
            + 'закреплённый за видом как единственный вариант, затем маршрут отдела сотрудника (ближайшего вверх по структуре) с этим видом, отдела без вида, '
            + 'вида без отдела и общий. Если не подошёл ни один, работает маршрут «По умолчанию». '
            + 'Шаг пропускается, если согласующий на нём — автор заявки или тот, кто уже согласовал её на одном из прошлых шагов. '
            + 'Если на шаге согласующего нет (например, у отдела нет руководителя), заявка уходит в отдел кадров. '
            + 'Выключенный шаг пропускается и в уже поданных заявках, если они до него ещё не дошли. '
            + 'Поданные заявки идут по маршруту, каким он был при подаче. '
            + 'Получатели из «Уведомить о результате» не согласуют, а узнают об утверждении или отмене и подтверждают ознакомление; '
            + 'пока не подтвердят, каждый рабочий день до начала отсутствия им приходит напоминание.</p>'
            + '<div class="routes">' + data.routes.map(card).join('') + '</div>'
            + editorsBlock();

        root().querySelector('[data-role="add"]').addEventListener('click', function () {
            openEditor({ id: 0, isDefault: false, name: '', active: true, exclusive: false, kinds: [], departmentIds: [], steps: [newStep('Руководитель отдела', 'head')], watchers: [] });
        });
        // По номеру в списке: у маршрута «По умолчанию», пока его не сохраняли, ID нет
        root().querySelectorAll('[data-route]').forEach(function (button) {
            button.addEventListener('click', function () {
                openEditor(JSON.parse(JSON.stringify(data.routes[Number(button.dataset.route)])));
            });
        });
        bindEditors();
        BX24.fitWindow();
    }

    function watcherText(watcher) {
        var events = EVENTS.filter(function (e) { return watcher.events.indexOf(e[0]) !== -1; }).map(function (e) { return e[1].toLowerCase(); });
        var task = { none: '', auto: ', задача сразу', ask: ', задача по выбору' }[watcher.task] || '';
        return who(watcher) + ': ' + events.join(' и ') + task + (watcher.task !== 'none' ? ' (срок за ' + watcher.taskDays + ' дн. до начала)' : '');
    }

    function card(route, index) {
        var kinds = kindNames(route.kinds);
        var departments = departmentNames(route.departmentIds);
        var minDays = route.steps.reduce(function (max, s) { return s.active ? Math.max(max, s.submitDays) : max; }, 0);
        return '<div class="route' + (route.active ? '' : ' is-off') + '">'
            + '<div class="route-head"><b>' + esc(route.name) + '</b>'
            + (route.active ? '' : ' <span class="chip">выключен</span>')
            + (route.exclusive ? ' <span class="chip accent">единственный для своих видов</span>' : '')
            + ' <button type="button" data-route="' + index + '">Изменить</button></div>'
            + '<div class="hint">' + (route.isDefault ? 'Когда не подошёл ни один другой маршрут'
                : 'Виды: ' + esc(kinds.length ? kinds.join(', ') : 'все') + ' · Отделы: ' + esc(departments.length ? departments.join(', ') + ' (с подотделами)' : 'все'))
            + ' · Подача не позже чем за ' + esc(minDays) + ' дн.</div>'
            + '<ol class="route-steps">' + route.steps.map(function (step) {
                return '<li class="' + (step.active ? '' : 'is-off') + '"><b>' + esc(step.name) + '</b> — ' + esc(who(step))
                    + (step.rejectDays ? ', отклонить не позже чем за ' + esc(step.rejectDays) + ' дн. до начала' : '')
                    + (step.submitDays ? ', подача за ' + esc(step.submitDays) + ' дн.' : '')
                    + (step.active ? '' : ', выключен') + '</li>';
            }).join('') + '</ol>'
            + (route.watchers.length ? '<div class="hint">Уведомить о результате: ' + route.watchers.map(function (w) { return esc(watcherText(w)); }).join('; ') + '</div>' : '')
            + (route.updatedBy ? '<div class="hint">Изменил: ' + esc(ctx.name(route.updatedBy)) + ', ' + esc(route.updatedAt) + '</div>' : '')
            + '</div>';
    }

    function editorsBlock() {
        var ids = view.data.editorIds;
        return '<h2>Кто настраивает маршруты</h2>'
            + '<p class="hint">Администратор портала может всегда. Здесь добавьте сотрудников, которым тоже можно менять маршруты.</p>'
            + '<p>' + (ids.length ? ids.map(function (id) { return '<span class="chip">' + esc(ctx.name(id)) + '</span>'; }).join(' ')
                : '<span class="hint">пока только администратор</span>')
            + ' <button type="button" data-role="editors">Выбрать</button></p>';
    }

    function bindEditors() {
        var button = root().querySelector('[data-role="editors"]');
        button.addEventListener('click', function () {
            pick('Кто настраивает маршруты', true, view.data.editorIds, button.parentNode).then(function (users) {
                if (!users) {
                    return;
                }
                ctx.api('/api/routes/editors', { ids: users.map(function (u) { return u.id; }).join(',') }).then(function (saved) {
                    view.data.editorIds = saved.editorIds;
                    draw();
                    ctx.showMessage('Список сохранён');
                }).catch(ctx.showError);
            });
        });
    }

    function pick(title, multiple, ids, anchor) {
        var selected = ids.filter(Boolean).map(function (id) { return { id: id, name: ctx.name(id) }; });
        return window.Kb24Picker.open({ title: title, multiple: multiple, selected: selected, anchor: anchor }).then(function (users) {
            (users || []).forEach(function (user) { ctx.rememberName(user.id, user.name); });
            return users;
        });
    }

    function newStep(name, kind) {
        return { id: 0, name: name || '', kind: kind || 'head', userId: 0, submitDays: 0, rejectDays: 0, active: true };
    }

    function openEditor(route) {
        var kinds = ctx.me().kinds;
        root().innerHTML = '<h2>' + (route.id || route.isDefault ? 'Маршрут «' + esc(route.name) + '»' : 'Новый маршрут') + '</h2>'
            + '<div class="form route-form">'
            + '<label>Название <input name="name" maxlength="100" size="40" value="' + esc(route.name) + '" placeholder="Например, «Отпуска производства»"></label>'
            + (route.isDefault ? '' : '<label class="check"><input type="checkbox" name="active"' + (route.active ? ' checked' : '') + '> маршрут включён</label>')
            + '</div>'
            + (route.isDefault ? '<p class="hint">Действует на все виды отсутствия и отделы, когда не подошёл ни один другой маршрут. Выключить или удалить его нельзя.</p>' : '<div class="route-cols">'
            + '<div><h3>Виды отсутствия</h3><p class="hint">Ничего не выбрано — все виды.</p>'
            + Object.keys(kinds).map(function (code) {
                return '<label class="check"><input type="checkbox" name="kind" value="' + esc(code) + '"'
                    + (route.kinds.indexOf(code) !== -1 ? ' checked' : '') + '> ' + esc(kinds[code]) + '</label>';
            }).join('')
            + '<label class="check"><input type="checkbox" name="exclusive"' + (route.exclusive ? ' checked' : '') + '> единственный вариант для этих видов: действует поверх маршрутов отделов</label></div>'
            + '<div><h3>Отделы</h3><p class="hint">Ничего не выбрано — все отделы; подотделы входят. Несколько — с Ctrl или ⌘.</p>'
            + '<select name="departments" multiple size="8">' + view.data.departments.map(function (d) {
                return '<option value="' + esc(d.id) + '"' + (route.departmentIds.indexOf(d.id) !== -1 ? ' selected' : '') + '>'
                    + new Array(d.depth + 1).join('   ') + esc(d.name) + '</option>';
            }).join('') + '</select></div>'
            + '</div>')
            + '<h3>Шаги <span class="hint">(от 1 до ' + MAX_STEPS + ')</span></h3>'
            + '<table class="route-table"><thead><tr><th>№</th><th>Роль</th><th>Кто согласует</th>'
            + '<th title="Заявку подают не позже чем за столько дней до начала">Подача за, дн.</th>'
            + '<th title="Отклонить на этом шаге можно не позже чем за столько дней до начала">Отклонить до, дн.</th>'
            + '<th>Включён</th><th></th></tr></thead><tbody data-role="steps"></tbody></table>'
            + '<p><button type="button" data-role="add-step">+ Шаг</button></p>'
            + '<p class="hint">«Подача за» — за сколько дней до начала отсутствия нужно подать заявку, чтобы этот шаг успел её рассмотреть; '
            + 'по маршруту берётся наибольший срок среди включённых шагов. «Отклонить до» — позже этого срока шаг может только согласовать; 0 — без ограничения.</p>'
            + '<h3>Уведомить о результате</h3>'
            + '<p class="hint">Эти сотрудники не согласуют заявку, а получают уведомление об утверждении или отмене отсутствия и подтверждают ознакомление в карточке заявки. '
            + 'Задачу ставим получателю, постановщик — автор заявки, срок — за указанное число дней до начала отсутствия. «Спросить» — получатель сам решает при ознакомлении и может поменять срок.</p>'
            + '<table class="route-table"><thead><tr><th>Кого уведомить</th><th>О чём</th><th>Задача</th><th>Срок задачи: за, дн. до начала</th><th></th></tr></thead>'
            + '<tbody data-role="watchers"></tbody></table>'
            + '<p><button type="button" data-role="add-watcher">+ Получатель</button></p>'
            + '<div class="actions"><button type="button" class="primary" data-role="save">Сохранить</button>'
            + '<button type="button" data-role="close">Отмена</button>'
            + (route.id && !route.isDefault ? '<button type="button" class="danger" data-role="delete">Удалить маршрут</button>' : '') + '</div>';

        var tbody = root().querySelector('[data-role="steps"]');
        var wbody = root().querySelector('[data-role="watchers"]');

        function readWatchers() {
            // Пустой список показан строкой-подсказкой: читать нечего
            if (!route.watchers.length) {
                return;
            }
            route.watchers = Array.prototype.map.call(wbody.querySelectorAll('tr'), function (tr, index) {
                var kind = tr.querySelector('[name="watchKind"]').value;
                return {
                    kind: kind,
                    userId: kind === 'user' ? route.watchers[index].userId : 0,
                    events: Array.prototype.map.call(tr.querySelectorAll('[name="watchEvent"]:checked'), function (input) { return input.value; }),
                    task: tr.querySelector('[name="watchTask"]').value,
                    taskDays: Number(tr.querySelector('[name="taskDays"]').value) || 0
                };
            });
        }

        function drawWatchers() {
            wbody.innerHTML = route.watchers.length ? route.watchers.map(function (w) {
                return '<tr><td><select name="watchKind">' + KINDS.map(function (k) {
                        return '<option value="' + k[0] + '"' + (w.kind === k[0] ? ' selected' : '') + '>' + esc(k[1]) + '</option>';
                    }).join('') + '</select>'
                    + (w.kind === 'user' ? '<br><button type="button" data-pick>' + esc(w.userId ? ctx.name(w.userId) : 'Выбрать сотрудника') + '</button>' : '')
                    + '</td><td>' + EVENTS.map(function (e) {
                        return '<label class="check"><input type="checkbox" name="watchEvent" value="' + e[0] + '"' + (w.events.indexOf(e[0]) !== -1 ? ' checked' : '') + '> ' + esc(e[1]) + '</label>';
                    }).join('<br>') + '</td>'
                    + '<td><select name="watchTask">' + TASKS.map(function (t) {
                        return '<option value="' + t[0] + '"' + (w.task === t[0] ? ' selected' : '') + '>' + esc(t[1]) + '</option>';
                    }).join('') + '</select></td>'
                    + '<td><input type="number" min="0" max="365" name="taskDays" value="' + esc(w.taskDays) + '"' + (w.task === 'none' ? ' disabled' : '') + '></td>'
                    + '<td><button type="button" class="danger" data-remove title="Убрать">×</button></td></tr>';
            }).join('') : '<tr><td colspan="5" class="hint">Никого: о результате узнаёт только автор заявки</td></tr>';
            root().querySelector('[data-role="add-watcher"]').disabled = route.watchers.length >= MAX_WATCHERS;
            Array.prototype.forEach.call(wbody.querySelectorAll('tr'), function (tr, index) {
                if (!route.watchers.length) {
                    return;
                }
                tr.querySelector('[data-remove]').addEventListener('click', function () {
                    readWatchers();
                    route.watchers.splice(index, 1);
                    drawWatchers();
                });
                ['watchKind', 'watchTask'].forEach(function (field) {
                    tr.querySelector('[name="' + field + '"]').addEventListener('change', function () {
                        readWatchers();
                        drawWatchers();
                    });
                });
                var pickButton = tr.querySelector('[data-pick]');
                if (pickButton) {
                    pickButton.addEventListener('click', function () {
                        readWatchers();
                        pick('Кого уведомить', false, [route.watchers[index].userId], tr).then(function (users) {
                            if (users) {
                                route.watchers[index].userId = users.length ? users[0].id : 0;
                            }
                            drawWatchers();
                        });
                    });
                }
            });
            BX24.fitWindow();
        }

        root().querySelector('[data-role="add-watcher"]').addEventListener('click', function () {
            readWatchers();
            route.watchers.push({ kind: 'hr', userId: 0, events: ['approved', 'cancelled'], task: 'none', taskDays: 3 });
            drawWatchers();
        });

        function readSteps() {
            route.steps = Array.prototype.map.call(tbody.querySelectorAll('tr'), function (tr, index) {
                var kind = tr.querySelector('[name="who"]').value;
                return {
                    id: route.steps[index].id,
                    name: tr.querySelector('[name="stepName"]').value,
                    kind: kind,
                    userId: kind === 'user' ? route.steps[index].userId : 0,
                    submitDays: Number(tr.querySelector('[name="submitDays"]').value) || 0,
                    rejectDays: Number(tr.querySelector('[name="rejectDays"]').value) || 0,
                    active: tr.querySelector('[name="stepActive"]').checked
                };
            });
        }

        function drawSteps() {
            tbody.innerHTML = route.steps.map(function (step, index) {
                return '<tr' + (step.active ? '' : ' class="is-off"') + '><td>' + (index + 1) + '</td>'
                    + '<td><input name="stepName" maxlength="100" value="' + esc(step.name) + '" placeholder="Например, HR"></td>'
                    + '<td><select name="who">' + KINDS.map(function (k) {
                        return '<option value="' + k[0] + '"' + (step.kind === k[0] ? ' selected' : '') + '>' + esc(k[1]) + '</option>';
                    }).join('') + '</select>'
                    + (step.kind === 'user' ? '<br><button type="button" data-pick>' + esc(step.userId ? ctx.name(step.userId) : 'Выбрать сотрудника') + '</button>' : '')
                    + '</td>'
                    + '<td><input type="number" min="0" max="365" name="submitDays" value="' + esc(step.submitDays) + '"></td>'
                    + '<td><input type="number" min="0" max="365" name="rejectDays" value="' + esc(step.rejectDays) + '"></td>'
                    + '<td><input type="checkbox" name="stepActive"' + (step.active ? ' checked' : '') + '></td>'
                    + '<td class="nowrap"><button type="button" data-move="-1" title="Выше"' + (index ? '' : ' disabled') + '>↑</button>'
                    + '<button type="button" data-move="1" title="Ниже"' + (index < route.steps.length - 1 ? '' : ' disabled') + '>↓</button>'
                    + '<button type="button" class="danger" data-remove title="Убрать шаг"' + (route.steps.length > 1 ? '' : ' disabled') + '>×</button></td></tr>';
            }).join('');
            root().querySelector('[data-role="add-step"]').disabled = route.steps.length >= MAX_STEPS;

            tbody.querySelectorAll('tr').forEach(function (tr, index) {
                tr.querySelectorAll('[data-move]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        readSteps();
                        var moved = route.steps.splice(index, 1)[0];
                        route.steps.splice(index + Number(button.dataset.move), 0, moved);
                        drawSteps();
                    });
                });
                tr.querySelector('[data-remove]').addEventListener('click', function () {
                    readSteps();
                    route.steps.splice(index, 1);
                    drawSteps();
                });
                tr.querySelector('[name="who"]').addEventListener('change', function () {
                    readSteps();
                    drawSteps();
                });
                tr.querySelector('[name="stepActive"]').addEventListener('change', function (e) {
                    tr.classList.toggle('is-off', !e.target.checked);
                });
                var pickButton = tr.querySelector('[data-pick]');
                if (pickButton) {
                    pickButton.addEventListener('click', function () {
                        readSteps();
                        pick('Кто согласует на шаге ' + (index + 1), false, [route.steps[index].userId], tr).then(function (users) {
                            if (users) {
                                route.steps[index].userId = users.length ? users[0].id : 0;
                            }
                            drawSteps();
                        });
                    });
                }
            });
            BX24.fitWindow();
        }

        root().querySelector('[data-role="add-step"]').addEventListener('click', function () {
            readSteps();
            if (route.steps.length < MAX_STEPS) {
                route.steps.push(newStep('', 'user'));
                drawSteps();
            }
        });
        root().querySelector('[data-role="close"]').addEventListener('click', function () {
            ctx.showMessage('');
            draw();
        });
        root().querySelector('[data-role="save"]').addEventListener('click', function (e) {
            readSteps();
            readWatchers();
            var form = root();
            var payload = {
                id: route.id,
                isDefault: route.isDefault,
                name: form.querySelector('[name="name"]').value,
                steps: route.steps,
                watchers: route.watchers
            };
            if (!route.isDefault) {
                payload.active = form.querySelector('[name="active"]').checked;
                payload.exclusive = form.querySelector('[name="exclusive"]').checked;
                payload.kinds = Array.prototype.map.call(form.querySelectorAll('[name="kind"]:checked'), function (input) { return input.value; });
                payload.departmentIds = Array.prototype.map.call(form.querySelector('[name="departments"]').selectedOptions, function (option) { return Number(option.value); });
            }
            e.target.disabled = true;
            ctx.api('/api/routes/save', { route: JSON.stringify(payload) }).then(function () {
                ctx.showMessage('Маршрут сохранён');
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
                ctx.api('/api/routes/delete', { id: route.id }).then(function () {
                    ctx.showMessage('Маршрут удалён');
                    show();
                }).catch(ctx.showError);
            });
        }

        drawSteps();
        drawWatchers();
    }

    return {
        init: function (context) { ctx = context; },
        show: show
    };
})();
