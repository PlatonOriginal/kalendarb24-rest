(function () {
    'use strict';

    var STATUS = {
        head_review: 'У руководителя',
        hr_review: 'В отделе кадров',
        final_approval: 'У директора',
        approved: 'Утверждена',
        rejected: 'Отклонена',
        withdrawn: 'Отозвана'
    };
    var ACTION = {
        create: 'подал заявку',
        approve: 'согласовал',
        reject: 'отклонил',
        withdraw: 'отозвал заявку'
    };

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

    function showError(error) {
        showMessage(error && error.message ? error.message : 'Не удалось выполнить действие', true);
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

    function status(code) {
        return '<span class="status ' + esc(code) + '">' + esc(STATUS[code] || code) + '</span>';
    }

    function openTab(tab) {
        ['requests', 'approve', 'settings', 'checks', 'card'].forEach(function (name) {
            el('tab-' + name).hidden = name !== tab;
        });
        document.querySelectorAll('.tabs a').forEach(function (link) {
            link.classList.toggle('active', link.dataset.tab === tab);
        });
        showMessage('');
        if (tab === 'requests') {
            loadMine();
        } else if (tab === 'approve') {
            loadToApprove();
        } else if (tab === 'settings') {
            renderRoles();
        } else if (tab === 'checks' && !state.checksDone) {
            state.checksDone = true;
            window.Kb24Checks.run();
        }
        BX24.fitWindow();
    }

    function fillRows(tableId, items, columns, empty) {
        var body = document.querySelector('#' + tableId + ' tbody');
        body.innerHTML = items.length ? '' : '<tr><td colspan="5" class="hint">' + esc(empty) + '</td></tr>';
        items.forEach(function (item) {
            var tr = document.createElement('tr');
            tr.innerHTML = columns(item).map(function (cell) { return '<td>' + cell + '</td>'; }).join('');
            tr.addEventListener('click', function () { openCard(item.id); });
            body.appendChild(tr);
        });
        BX24.fitWindow();
    }

    function loadMine() {
        api('/api/requests/mine').then(function (data) {
            return loadNames(data.items.map(function (r) { return r.approverId; })).then(function () {
                fillRows('my-requests', data.items, function (r) {
                    return [esc(period(r)), esc(state.me.kinds[r.kind] || r.kind), esc(r.days), status(r.status), esc(r.approverId ? name(r.approverId) : '')];
                }, 'Заявок пока нет');
            });
        }).catch(showError);
    }

    function loadToApprove() {
        api('/api/requests/approve').then(function (data) {
            el('approve-count').textContent = data.items.length ? '(' + data.items.length + ')' : '';
            return loadNames(data.items.map(function (r) { return r.userId; })).then(function () {
                fillRows('approve-requests', data.items, function (r) {
                    return [esc(name(r.userId)), esc(period(r)), esc(state.me.kinds[r.kind] || r.kind), esc(r.days), status(r.status)];
                }, 'Нет заявок, которые ждут вашего решения');
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
            return loadNames(ids).then(function () {
                renderCard(data);
                ['requests', 'approve', 'settings', 'checks'].forEach(function (name) { el('tab-' + name).hidden = true; });
                el('tab-card').hidden = false;
                BX24.fitWindow();
            });
        }).catch(showError);
    }

    function renderCard(data) {
        var r = data.request;
        var reasons = state.me.reasons;
        var history = data.history.map(function (h) {
            var reason = h.reason ? ': ' + (reasons[h.reason] || h.reason) : '';
            return '<li>' + esc(name(h.actorId)) + ' ' + esc(ACTION[h.action] || h.action) + esc(reason) + '</li>';
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
            + '<dt>Статус</dt><dd>' + status(r.status) + (r.approverId ? ', решение принимает ' + esc(name(r.approverId)) : '') + '</dd>'
            + '</dl>'
            + '<div class="actions">'
            + (data.can.approve ? '<button type="button" class="primary" data-action="approve">Согласовать</button>' : '')
            + (data.can.reject ? '<select id="reject-reason">' + reasonOptions + '</select><button type="button" class="danger" data-action="reject">Отклонить</button>' : '')
            + (data.can.withdraw ? '<button type="button" data-action="withdraw">Отозвать</button>' : '')
            + (chatWith && chatWith !== state.me.userId ? '<button type="button" data-chat="' + esc(chatWith) + '">Написать в чат: ' + esc(name(chatWith)) + '</button>' : '')
            + '</div>'
            + '<h2>История</h2><ul>' + history + '</ul>'
            + '</div>';

        el('card').querySelectorAll('[data-action]').forEach(function (button) {
            button.addEventListener('click', function () {
                var action = button.dataset.action;
                var payload = action === 'reject' ? { reason: el('reject-reason').value } : {};
                button.disabled = true;
                api('/api/requests/' + r.id + '/' + action, payload).then(function () {
                    openCard(r.id);
                    showMessage({ approve: 'Заявка согласована', reject: 'Заявка отклонена', withdraw: 'Заявка отозвана' }[action]);
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

    function renderRoles() {
        var roles = state.me.roles;
        loadNames([roles.director].concat(roles.hr)).then(function () {
            el('role-director').textContent = roles.director ? name(roles.director) : 'не выбран';
            el('role-hr').textContent = roles.hr.length ? roles.hr.map(name).join(', ') : 'не выбраны';
            BX24.fitWindow();
        });
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

        function pick(title, multiple, ids) {
            var selected = ids.filter(Boolean).map(function (id) { return { id: id, name: name(id) }; });
            return window.Kb24Picker.open({ title: title, multiple: multiple, selected: selected }).then(function (users) {
                (users || []).forEach(function (user) { state.names[user.id] = user.name; });
                return users;
            });
        }
        el('pick-director').addEventListener('click', function () {
            pick('Директор', false, [state.me.roles.director]).then(function (users) {
                if (users) {
                    saveRoles(users.length ? users[0].id : null, state.me.roles.hr);
                }
            });
        });
        el('pick-hr').addEventListener('click', function () {
            pick('Отдел кадров', true, state.me.roles.hr).then(function (users) {
                if (users) {
                    saveRoles(state.me.roles.director, users.map(function (user) { return user.id; }));
                }
            });
        });
        el('card-back').addEventListener('click', function (event) {
            event.preventDefault();
            openTab(location.hash.replace('#', '') || 'requests');
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
            bindForm();
            if (card) {
                showCard(card);
            } else {
                openTab(location.hash.replace('#', '') || 'requests');
            }
            loadToApprove();
        }).catch(function (error) {
            showError(error);
            openTab('checks');
        });
    });
})();
