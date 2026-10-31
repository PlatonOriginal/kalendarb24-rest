(function () {
    'use strict';

    var STATUS = {
        head_review: 'У руководителя',
        hr_review: 'В отделе кадров',
        final_approval: 'У директора',
        route_review: 'На согласовании',
        approved: 'Утверждена',
        rejected: 'Отклонена',
        withdrawn: 'Отозвана',
        cancelling: 'Отменяется',
        cancelled: 'Отменена'
    };
    var CANCEL_STEP = {
        head_review: 'отмену согласует руководитель',
        hr_review: 'отмену согласует отдел кадров'
    };
    var ACTION = {
        create: 'подал заявку',
        approve: 'согласовал',
        auto_approve: 'согласовал автоматически (уже согласовал эту заявку раньше)',
        reject: 'отклонил',
        withdraw: 'отозвал заявку',
        cancel_start: 'попросил отменить',
        cancel_approve: 'согласовал отмену',
        cancel_auto_approve: 'согласовал отмену автоматически (уже согласовал её раньше)',
        cancel_refuse: 'отказал в отмене'
    };
    var ACK_EVENT = { approved: 'Отсутствие утверждено', cancelled: 'Утверждённое отсутствие отменено' };

    // Шаги маршрута в карточке: пройден, текущий, впереди, пропущен, на нём отказали
    var STEP_MARK = { done: '✓', current: '●', next: '○', skipped: '–', rejected: '✕' };
    var STEP_STATE = { done: 'согласовано', current: 'сейчас здесь', next: 'впереди', skipped: 'пропущен', rejected: 'отклонено' };
    var STEP_KIND = { head: 'руководитель по структуре', hr: 'отдел кадров', director: 'директор' };

    var state = { me: null, names: {}, checksDone: false };

    function el(id) {
        return document.getElementById(id);
    }

    function esc(text) {
        var div = document.createElement('div');
        div.textContent = text === undefined || text === null ? '' : String(text);
        return div.innerHTML;
    }

    // Запрос к нашему серверу с токеном текущего пользователя
    function api(path, data) {
        var auth = BX24.getAuth();
        var options = {
            method: data ? 'POST' : 'GET',
            headers: { 'X-B24-Auth': auth.access_token, 'X-B24-Member': auth.member_id }
        };
        if (data) {
            options.body = new URLSearchParams(data);
        }
        return fetch(path, options).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok) {
                    throw body;
                }
                return body;
            });
        });
    }

    function showMessage(text, isError) {
        var box = el('message');
        box.hidden = !text;
        box.textContent = text || '';
        box.className = 'message' + (isError ? ' error' : '');
        BX24.fitWindow();
    }

    // Сервер не знает ФИО: в тексте ошибки сотрудник указан как {user:ID}, имя подставляем здесь
    function showError(error) {
        var text = error && error.message ? error.message : 'Не удалось выполнить действие';
        var ids = (text.match(/\{user:\d+\}/g) || []).map(function (m) { return Number(m.replace(/\D/g, '')); });
        loadNames(ids).then(function () {
            showMessage(text.replace(/\{user:(\d+)\}/g, function (m, id) { return name(Number(id)); }), true);
        });
    }

    // ФИО по ID запрашивает браузер, сервер их не видит
    function loadNames(ids) {
        var missing = ids.filter(function (id) { return id && !state.names[id]; });
        if (!missing.length) {
            return Promise.resolve();
        }
        return new Promise(function (resolve) {
            BX24.callMethod('user.get', { filter: { ID: missing }, select: ['ID', 'NAME', 'LAST_NAME'] }, function (result) {
                (result.error() ? [] : result.data()).forEach(function (user) {
                    state.names[user.ID] = [user.LAST_NAME, user.NAME].join(' ').trim();
                });
                resolve();
            });
        });
    }

    function name(id) {
        return id ? (state.names[id] || 'сотрудник ' + id) : '—';
    }

    function formatDate(value) {
        var parts = String(value).split('-');
        return parts.length === 3 ? parts[2] + '.' + parts[1] + '.' + parts[0] : value;
    }

    function period(request) {
        return formatDate(request.dateFrom) + ' — ' + formatDate(request.dateTo);
    }

    // По маршруту вместо «На согласовании» показываем шаг: «На шаге «Юрист»»
    function status(code, request) {
        var text = code === 'route_review' && request && request.stepName ? 'На шаге «' + request.stepName + '»' : (STATUS[code] || code);
        return '<span class="status ' + esc(code) + '">' + esc(text) + '</span>';
    }

    var TABS = ['calendar', 'requests', 'approve', 'balances', 'routes', 'settings', 'checks'];

    function openTab(tab) {
        if (TABS.indexOf(tab) < 0) {
            tab = 'calendar';
        }
        TABS.concat(['card']).forEach(function (name) {
            el('tab-' + name).hidden = name !== tab;
        });
        document.querySelectorAll('.tabs a').forEach(function (link) {
            link.classList.toggle('active', link.dataset.tab === tab);
        });
        showMessage('');
        if (tab === 'calendar') {
            window.Kb24Calendar.show();
        } else if (tab === 'balances') {
            window.Kb24Balance.show();
        } else if (tab === 'routes') {
            window.Kb24Routes.show();
        } else if (tab === 'requests') {
            loadMine();
        } else if (tab === 'approve') {
            loadToApprove();
        } else if (tab === 'settings') {
            renderRoles();
            renderOptions();
            renderLimits();
        } else if (tab === 'checks' && !state.checksDone) {
            state.checksDone = true;
            window.Kb24Checks.run();
        }
        BX24.fitWindow();
    }

    function fillRows(tableId, items, columns, empty) {
        var body = document.querySelector('#' + tableId + ' tbody');
        body.innerHTML = items.length ? '' : '<tr><td colspan="6" class="hint">' + esc(empty) + '</td></tr>';
        items.forEach(function (item) {
            var tr = document.createElement('tr');
            tr.innerHTML = columns(item).map(function (cell) { return '<td>' + cell + '</td>'; }).join('');
            tr.addEventListener('click', function () { openCard(item.id); });
            body.appendChild(tr);
        });
        BX24.fitWindow();
    }

    // Подсказка в форме: сколько ежегодного отпуска доступно (если ОК внёс остаток)
    function renderBalanceHint() {
        var form = el('new-request');
        var hint = el('balance-hint');
        var b = state.balance;
        hint.hidden = form.kind.value !== 'vacation';
        hint.innerHTML = !b ? 'Праздничные дни в ежегодный отпуск не входят.'
            : 'Доступно <b>' + esc(window.Kb24Balance.formatDays(b.available)) + ' дн.</b> ежегодного отпуска (остаток на '
              + esc(formatDate(b.asOf)) + ' за вычетом поданных заявок). Праздничные дни в отпуск не входят.';
    }

    function loadMine() {
        api('/api/requests/mine').then(function (data) {
            state.balance = data.balance;
            renderBalanceHint();
            return loadNames(data.items.map(function (r) { return r.approverId; })).then(function () {
                fillRows('my-requests', data.items, function (r) {
                    return [esc(r.id), esc(period(r)), esc(state.me.kinds[r.kind] || r.kind), esc(r.days), status(r.status, r), esc(r.approverId ? name(r.approverId) : '')];
                }, 'Заявок пока нет');
            });
        }).catch(showError);
    }

    function loadToApprove() {
        api('/api/requests/approve').then(function (data) {
            var acks = data.acks || [];
            var count = data.items.length + acks.length;
            el('approve-count').textContent = count ? '(' + count + ')' : '';
            return loadNames(data.items.concat(acks).map(function (r) { return r.userId; })).then(function () {
                fillRows('approve-requests', data.items, function (r) {
                    return [esc(r.id), esc(name(r.userId)), esc(period(r)), esc(state.me.kinds[r.kind] || r.kind), esc(r.days), status(r.status, r)];
                }, 'Нет заявок, которые ждут вашего решения');
                el('acks-block').hidden = !acks.length;
                fillRows('ack-requests', acks, function (r) {
                    return [esc(r.id), esc(name(r.userId)), esc(period(r)), esc(state.me.kinds[r.kind] || r.kind), esc(r.days), esc(ACK_EVENT[r.ack.event] || r.ack.event)];
                }, '');
            });
        }).catch(showError);
    }

    function openCard(id) {
        showCard(api('/api/requests/' + id));
    }

    function showCard(request) {
        request.then(function (data) {
            var r = data.request;
            var ids = [r.userId, r.approverId].concat(data.history.map(function (h) { return h.actorId; }));
            (data.route ? data.route.steps : []).forEach(function (step) { ids.push(step.userId, step.actorId); });
            (data.acks || []).forEach(function (a) { ids.push(a.userId); });
            return loadNames(ids).then(function () {
                renderCard(data);
                TABS.forEach(function (name) { el('tab-' + name).hidden = true; });
                el('tab-card').hidden = false;
                BX24.fitWindow();
            });
        }).catch(showError);
    }

    // Запись в графике отсутствий и событие в календаре создаёт сервер после утверждения, результат хранится в заявке
    function calendarState(r) {
        var parts = [];
        if (r.absenceId) {
            parts.push('запись в графике отсутствий добавлена');
        }
        if (r.calEventId) {
            parts.push('событие добавлено в календарь сотрудника');
        }
        if (r.calError) {
            parts.push('<span class="fail">не добавлено: ' + esc(r.calError) + '</span>');
        }
        return parts.length ? parts.join('<br>') : 'добавляется, обновите карточку через несколько секунд';
    }

    function renderCard(data) {
        var r = data.request;
        var reasons = state.me.reasons;
        var history = data.history.map(function (h) {
            var reason = h.reason ? ': ' + (reasons[h.reason] || h.reason) : '';
            if (h.action === 'reroute') {
                return '<li>Шаг «' + esc(h.stepName) + '»: согласующий не найден, заявка передана в отдел кадров — ' + esc(name(h.actorId)) + '</li>';
            }
            return '<li>' + esc(name(h.actorId)) + ' ' + esc(ACTION[h.action] || h.action)
                + (h.stepName ? ' на шаге «' + esc(h.stepName) + '»' : '') + esc(reason) + '</li>';
        }).join('');
        var reasonOptions = Object.keys(reasons).map(function (code) {
            return '<option value="' + esc(code) + '">' + esc(reasons[code]) + '</option>';
        }).join('');
        // В чат пишем тому, с кем нужно обсудить: согласующий сотруднику, сотрудник текущему согласующему
        var chatWith = state.me.userId === r.userId ? r.approverId : r.userId;

        el('card').innerHTML = '<div class="card">'
            + '<h2>Заявка № ' + esc(r.id) + '</h2>'
            + '<dl>'
            + '<dt>Сотрудник</dt><dd>' + esc(name(r.userId)) + '</dd>'
            + '<dt>Вид</dt><dd>' + esc(state.me.kinds[r.kind] || r.kind) + '</dd>'
            + '<dt>Даты</dt><dd>' + esc(period(r)) + '</dd>'
            + '<dt>Дней</dt><dd>' + esc(r.days) + '</dd>'
            + '<dt>Статус</dt><dd>' + status(r.status, r)
            + (r.status === 'cancelling' ? ', ' + esc(CANCEL_STEP[r.cancelStep] || '') : '')
            + (r.approverId ? ', решение принимает ' + esc(name(r.approverId)) : '') + '</dd>'
            + (r.cancelReason && (r.status === 'cancelling' || r.status === 'cancelled') ? '<dt>Причина отмены</dt><dd>' + esc(r.cancelReason) + '</dd>' : '')
            + (r.status === 'approved' || r.status === 'cancelling' || r.calError ? '<dt>График и календарь</dt><dd>' + calendarState(r) + '</dd>' : '')
            + '</dl>'
            + '<div class="actions">'
            + (data.can.approve ? '<button type="button" class="primary" data-action="approve">Согласовать</button>' : '')
            + (data.can.reject ? '<select id="reject-reason">' + reasonOptions + '</select><button type="button" class="danger" data-action="reject">Отклонить</button>' : '')
            + (data.can.withdraw ? '<button type="button" data-action="withdraw">Отозвать</button>' : '')
            + (data.can.retryCalendar ? '<button type="button" data-action="calendar-retry">Добавить в график и календарь</button>' : '')
            + (data.can.cancel ? '<input type="text" id="cancel-reason" placeholder="Причина отмены" size="30"><button type="button" class="danger" data-action="cancel">Отменить отсутствие</button>' : '')
            + (data.can.decideCancel ? '<button type="button" class="primary" data-action="cancel-approve">Согласовать отмену</button>'
                + '<input type="text" id="refuse-reason" placeholder="Причина отказа" size="30"><button type="button" class="danger" data-action="cancel-refuse">Отказать в отмене</button>' : '')
            + (chatWith && chatWith !== state.me.userId ? '<button type="button" data-chat="' + esc(chatWith) + '">Написать в чат: ' + esc(name(chatWith)) + '</button>' : '')
            + '</div>'
            + (data.cancelHint ? '<p class="hint">' + esc(data.cancelHint) + '</p>' : '')
            + (data.rejectBlocked ? '<p class="hint">' + esc(data.rejectBlocked) + '</p>' : '')
            + ackBlock(data)
            + routeSteps(data.route)
            + '<h2>История</h2><ul>' + history + '</ul>'
            + '</div>';

        el('card').querySelectorAll('[data-action]').forEach(function (button) {
            button.addEventListener('click', function () {
                var action = button.dataset.action;
                var payload = {
                    reject: function () { return { reason: el('reject-reason').value }; },
                    cancel: function () { return { reason: el('cancel-reason').value }; },
                    'cancel-refuse': function () { return { reason: el('refuse-reason').value }; },
                    acknowledge: function () {
                        var task = el('ack-task');
                        return { task: task && task.checked ? '1' : '0', deadline: el('ack-deadline') ? el('ack-deadline').value : '' };
                    }
                }[action];
                payload = payload ? payload() : {};
                button.disabled = true;
                api('/api/requests/' + r.id + '/' + action, payload).then(function () {
                    openCard(r.id);
                    showMessage({
                        approve: 'Заявка согласована',
                        reject: 'Заявка отклонена',
                        withdraw: 'Заявка отозвана',
                        cancel: 'Отмена отправлена на согласование',
                        'cancel-approve': 'Отмена согласована',
                        'cancel-refuse': 'В отмене отказано',
                        'calendar-retry': 'Событие добавляется, обновите карточку через несколько секунд',
                        acknowledge: 'Ознакомление подтверждено'
                    }[action]);
                }).catch(function (error) {
                    button.disabled = false;
                    showError(error);
                });
            });
        });
        el('card').querySelectorAll('[data-chat]').forEach(function (button) {
            button.addEventListener('click', function () {
                BX24.im.openMessenger(String(button.dataset.chat));
            });
        });
    }

    // Ознакомление с результатом: кнопка для текущего сотрудника и кто уже ознакомился
    function ackBlock(data) {
        var acks = data.acks || [];
        if (!acks.length) {
            return '';
        }
        var my = data.myAck;
        var form = '';
        if (my) {
            form = '<div class="actions ack-form"><b>' + esc(ACK_EVENT[my.event] || my.event) + '.</b>'
                + (my.taskMode === 'ask' ? '<label class="check"><input type="checkbox" id="ack-task" checked> поставить мне задачу со сроком</label>'
                    + '<input type="date" id="ack-deadline" value="' + esc(my.deadline) + '">' : '')
                + '<button type="button" class="primary" data-action="acknowledge">Ознакомился</button></div>';
        }
        var domain = BX24.getDomain();
        return form + '<h2>Ознакомление с результатом</h2><ul>' + acks.map(function (a) {
            var task = a.taskId
                ? ', <a href="https://' + esc(domain) + '/company/personal/user/' + esc(a.userId) + '/tasks/task/view/' + esc(a.taskId) + '/" target="_blank">задача № ' + esc(a.taskId) + '</a>'
                : (a.taskError ? ', <span class="fail">задача не поставлена: ' + esc(a.taskError) + '</span>' : '');
            return '<li>' + esc(name(a.userId)) + ' — ' + esc((ACK_EVENT[a.event] || a.event).toLowerCase()) + ': '
                + (a.ackAt ? 'ознакомился ' + esc(a.ackAt) : '<span class="hint">ещё не ознакомился</span>') + task + '</li>';
        }).join('') + '</ul>';
    }

    function routeSteps(route) {
        if (!route) {
            return '';
        }
        return '<h2>Маршрут «' + esc(route.name) + '»</h2><ol class="card-steps">' + route.steps.map(function (step) {
            var who = step.actorId ? name(step.actorId) : (step.kind === 'user' ? name(step.userId) : STEP_KIND[step.kind] || '');
            return '<li class="step-' + esc(step.state) + '"><span class="step-mark">' + STEP_MARK[step.state] + '</span> <b>' + esc(step.name) + '</b> — '
                + esc(who) + ', ' + esc(STEP_STATE[step.state])
                + (step.rejectUntil ? ', отклонить можно до ' + esc(formatDate(step.rejectUntil)) : '') + '</li>';
        }).join('') + '</ol>';
    }

    function renderRoles() {
        var roles = state.me.roles;
        loadNames([roles.director].concat(roles.hr)).then(function () {
            el('role-director').textContent = roles.director ? name(roles.director) : 'не выбран';
            el('role-hr').textContent = roles.hr.length ? roles.hr.map(name).join(', ') : 'не выбраны';
            BX24.fitWindow();
        });
    }

    function renderOptions() {
        el('cancel-days').value = state.me.settings ? state.me.settings.max_cancel_working_days : '';
        el('see-other-departments').checked = !!(state.me.settings && state.me.settings.employees_see_other_departments);
        el('annual-days').value = state.me.settings ? state.me.settings.annual_days : '';
        el('min-submit-exempt-unpaid').checked = !!(state.me.settings && state.me.settings.min_submit_exempt_unpaid);
    }

    function saveOptions() {
        api('/api/settings/options', {
            max_cancel_working_days: el('cancel-days').value,
            employees_see_other_departments: el('see-other-departments').checked ? '1' : '0',
            annual_days: el('annual-days').value,
            min_submit_exempt_unpaid: el('min-submit-exempt-unpaid').checked ? '1' : '0'
        }).then(function (settings) {
            state.me.settings = settings;
            renderOptions();
            showMessage('Настройки сохранены');
        }).catch(showError);
    }

    // Лимиты отделов: пустое поле — без ограничения. Администратор видит все отделы
    function renderLimits() {
        var limits = state.me.limits || {};
        var body = document.querySelector('#limits tbody');
        body.innerHTML = state.me.calendar.departments.map(function (d) {
            var limit = limits['d' + d.id] || { maxConcurrent: null, maxOverlap: null, withSub: false, withHead: false };
            return '<tr data-department="' + d.id + '">'
                + '<td>' + new Array(d.depth + 1).join('\u00a0\u00a0\u00a0') + esc(d.name) + '</td>'
                + '<td><input type="number" min="1" data-field="maxConcurrent" value="' + (limit.maxConcurrent === null ? '' : esc(limit.maxConcurrent)) + '"></td>'
                + '<td><input type="number" min="0" data-field="maxOverlap" value="' + (limit.maxOverlap === null ? '' : esc(limit.maxOverlap)) + '"></td>'
                + '<td><input type="checkbox" data-field="withSub"' + (limit.withSub ? ' checked' : '') + '></td>'
                + '<td><input type="checkbox" data-field="withHead"' + (limit.withHead ? ' checked' : '') + '></td>'
                + '</tr>';
        }).join('');
    }

    function limitRow(tr) {
        var field = function (name) { return tr.querySelector('[data-field="' + name + '"]'); };
        return {
            department: Number(tr.dataset.department),
            maxConcurrent: field('maxConcurrent').value.trim(),
            maxOverlap: field('maxOverlap').value.trim(),
            withSub: field('withSub').checked,
            withHead: field('withHead').checked
        };
    }

    function saveLimits() {
        var limits = state.me.limits || {};
        // Отправляем только изменённые строки: каждая строка — отдельная запись в портале
        var changed = Array.prototype.map.call(document.querySelectorAll('#limits tbody tr'), limitRow).filter(function (row) {
            var old = limits['d' + row.department] || { maxConcurrent: null, maxOverlap: null, withSub: false, withHead: false };
            return row.maxConcurrent !== (old.maxConcurrent === null ? '' : String(old.maxConcurrent))
                || row.maxOverlap !== (old.maxOverlap === null ? '' : String(old.maxOverlap))
                || row.withSub !== old.withSub || row.withHead !== old.withHead;
        });
        if (!changed.length) {
            showMessage('Лимиты не изменились');
            return;
        }
        var button = el('save-limits');
        button.disabled = true;
        api('/api/settings/limits', { limits: JSON.stringify(changed) }).then(function (saved) {
            state.me.limits = saved;
            renderLimits();
            showMessage('Лимиты сохранены');
        }).catch(showError).then(function () { button.disabled = false; });
    }

    function saveRoles(director, hr) {
        api('/api/settings/roles', { director: director || '', hr: hr.join(',') }).then(function (roles) {
            state.me.roles = roles;
            renderRoles();
            showMessage('Роли сохранены');
        }).catch(showError);
    }

    function bindForm() {
        var form = el('new-request');
        form.kind.innerHTML = Object.keys(state.me.kinds).map(function (code) {
            return '<option value="' + esc(code) + '">' + esc(state.me.kinds[code]) + '</option>';
        }).join('');
        form.kind.addEventListener('change', renderBalanceHint);
        form.dateFrom.addEventListener('change', function () {
            if (!form.dateTo.value || form.dateTo.value < form.dateFrom.value) {
                form.dateTo.value = form.dateFrom.value;
            }
        });
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var button = form.querySelector('button');
            button.disabled = true;
            api('/api/requests', { kind: form.kind.value, dateFrom: form.dateFrom.value, dateTo: form.dateTo.value })
                .then(function (request) {
                    form.reset();
                    loadMine();
                    return loadNames([request.approverId]).then(function () {
                        showMessage('Заявка подана, дней: ' + request.days + '. Решение принимает ' + name(request.approverId) + '.');
                    });
                })
                .catch(showError)
                .then(function () { button.disabled = false; });
        });

        function pick(title, multiple, ids, anchor) {
            var selected = ids.filter(Boolean).map(function (id) { return { id: id, name: name(id) }; });
            return window.Kb24Picker.open({ title: title, multiple: multiple, selected: selected, anchor: anchor }).then(function (users) {
                (users || []).forEach(function (user) { state.names[user.id] = user.name; });
                return users;
            });
        }
        el('pick-director').addEventListener('click', function () {
            pick('Директор', false, [state.me.roles.director], el('pick-director').closest('tr')).then(function (users) {
                if (users) {
                    saveRoles(users.length ? users[0].id : null, state.me.roles.hr);
                }
            });
        });
        el('pick-hr').addEventListener('click', function () {
            pick('Отдел кадров', true, state.me.roles.hr, el('pick-hr').closest('tr')).then(function (users) {
                if (users) {
                    saveRoles(state.me.roles.director, users.map(function (user) { return user.id; }));
                }
            });
        });
        el('save-options').addEventListener('click', saveOptions);
        el('save-limits').addEventListener('click', saveLimits);
        el('card-back').addEventListener('click', function (event) {
            event.preventDefault();
            openTab(location.hash.replace('#', '') || 'calendar');
        });
    }

    BX24.init(function () {
        document.querySelectorAll('.tabs a').forEach(function (link) {
            link.addEventListener('click', function () { openTab(link.dataset.tab); });
        });
        // Открыто по ссылке из уведомления (/marketplace/view/<код>/?params[req]=ID): карточку запрашиваем сразу,
        // параллельно с данными о сотруднике, а список заявок не грузим, пока к нему не вернутся
        var placement = BX24.placement.info();
        var linked = placement.placement === 'REST_APP_URI' && placement.options && Number(placement.options.req);
        var card = linked ? api('/api/requests/' + linked) : null;
        api('/api/vacation').then(function (me) {
            state.me = me;
            document.querySelector('[data-tab="settings"]').hidden = !me.isAdmin;
            document.querySelector('[data-tab="balances"]').hidden = !me.balances;
            document.querySelector('[data-tab="routes"]').hidden = !me.canEditRoutes;
            bindForm();
            window.Kb24Balance.init({
                api: api,
                esc: esc,
                me: function () { return state.me; },
                loadNames: loadNames,
                name: name,
                rememberName: function (id, text) { state.names[id] = text; },
                showError: showError
            });
            window.Kb24Routes.init({
                api: api,
                esc: esc,
                me: function () { return state.me; },
                loadNames: loadNames,
                name: name,
                rememberName: function (id, text) { state.names[id] = text; },
                showError: showError,
                showMessage: showMessage
            });
            window.Kb24Calendar.init({
                api: api,
                esc: esc,
                me: function () { return state.me; },
                loadNames: loadNames,
                name: name,
                rememberName: function (id, text) { state.names[id] = text; },
                status: status,
                statusName: function (code) { return STATUS[code] || code; },
                openCard: function (id) { openCard(id); },
                createFrom: function (date) {
                    location.hash = 'requests';
                    openTab('requests');
                    var form = el('new-request');
                    form.dateFrom.value = date;
                    form.dateTo.value = date;
                    form.dateTo.focus();
                }
            });
            if (card) {
                showCard(card);
            } else {
                openTab(location.hash.replace('#', '') || 'calendar');
            }
            loadToApprove();
        }).catch(function (error) {
            showError(error);
            openTab('checks');
        });
    });
})();
