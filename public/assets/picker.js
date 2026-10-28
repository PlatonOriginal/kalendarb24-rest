(function () {
    'use strict';

    // Выбор сотрудников по структуре компании вместо BX24.selectUser: на части тарифов стандартный диалог
    // отвечает ACCESS_DENIED. Отделы и ФИО запрашивает браузер, на сервер уходят только ID.

    var departments = null;

    function el(id) {
        return document.getElementById(id);
    }

    function esc(text) {
        var div = document.createElement('div');
        div.textContent = text === undefined || text === null ? '' : String(text);
        return div.innerHTML;
    }

    // Все страницы ответа: списочные методы отдают по 50 записей
    function callAll(method, params) {
        return new Promise(function (resolve, reject) {
            var items = [];
            BX24.callMethod(method, params, function handle(result) {
                if (result.error()) {
                    reject({ message: method + ': ' + result.error() });
                    return;
                }
                items = items.concat(result.data());
                if (result.more()) {
                    result.next();
                } else {
                    resolve(items);
                }
            });
        });
    }

    function loadDepartments() {
        if (!departments) {
            departments = callAll('department.get', { sort: 'SORT', order: 'ASC' });
        }
        return departments;
    }

    function userName(user) {
        return [user.LAST_NAME, user.NAME].join(' ').trim() || 'сотрудник ' + user.ID;
    }

    function loadUsers(filter) {
        filter.ACTIVE = true;
        filter.USER_TYPE = 'employee';
        return callAll('user.get', { filter: filter, select: ['ID', 'NAME', 'LAST_NAME', 'WORK_POSITION'] });
    }

    /**
     * Открывает выбор и возвращает Promise со списком выбранных {id, name} или null, если выбор отменён.
     * options: title, multiple, selected (список {id, name}).
     */
    function open(options) {
        var box = el('user-picker');
        var selected = (options.selected || []).slice();

        box.innerHTML = '<h2>' + esc(options.title) + '</h2>'
            + '<div class="picker-search"><input type="search" placeholder="Поиск по фамилии или имени"></div>'
            + '<div class="picker">'
            + '<ul class="picker-tree"><li class="hint">Загружаю структуру…</li></ul>'
            + '<ul class="picker-users"><li class="hint">Выберите отдел или найдите сотрудника</li></ul>'
            + '</div>'
            + '<p class="picker-selected"></p>'
            + '<div class="actions"><button type="button" class="primary" data-picker="save">Сохранить</button>'
            + '<button type="button" data-picker="cancel">Отмена</button></div>';
        box.hidden = false;

        var tree = box.querySelector('.picker-tree');
        var list = box.querySelector('.picker-users');
        var search = box.querySelector('input');

        function renderSelected() {
            box.querySelector('.picker-selected').innerHTML = selected.length
                ? 'Выбрано: ' + selected.map(function (user) {
                    return '<span class="chip">' + esc(user.name) + ' <a href="#" data-remove="' + esc(user.id) + '">×</a></span>';
                }).join(' ')
                : '<span class="hint">Никто не выбран</span>';
            box.querySelectorAll('[data-remove]').forEach(function (link) {
                link.addEventListener('click', function (event) {
                    event.preventDefault();
                    selected = selected.filter(function (user) { return String(user.id) !== link.dataset.remove; });
                    renderSelected();
                });
            });
            BX24.fitWindow();
        }

        function showUsers(promise) {
            list.innerHTML = '<li class="hint">Загружаю…</li>';
            promise.then(function (users) {
                list.innerHTML = users.length ? '' : '<li class="hint">Сотрудников нет</li>';
                users.forEach(function (user) {
                    var li = document.createElement('li');
                    li.innerHTML = esc(userName(user)) + (user.WORK_POSITION ? ' <span class="hint">' + esc(user.WORK_POSITION) + '</span>' : '');
                    li.addEventListener('click', function () {
                        var picked = { id: Number(user.ID), name: userName(user) };
                        if (!options.multiple) {
                            selected = [picked];
                        } else if (!selected.some(function (u) { return u.id === picked.id; })) {
                            selected.push(picked);
                        }
                        renderSelected();
                    });
                    list.appendChild(li);
                });
                BX24.fitWindow();
            }).catch(function (error) {
                list.innerHTML = '<li class="fail">' + esc(error.message) + '</li>';
            });
        }

        loadDepartments().then(function (items) {
            var children = {};
            items.forEach(function (dept) {
                var parent = dept.PARENT || 0;
                (children[parent] = children[parent] || []).push(dept);
            });
            var html = '';
            (function walk(parent, depth) {
                (children[parent] || []).forEach(function (dept) {
                    html += '<li data-dept="' + esc(dept.ID) + '" style="padding-left:' + (8 + depth * 16) + 'px">' + esc(dept.NAME) + '</li>';
                    walk(dept.ID, depth + 1);
                });
            })(0, 0);
            tree.innerHTML = html || '<li class="hint">Отделов нет</li>';
            tree.querySelectorAll('[data-dept]').forEach(function (li) {
                li.addEventListener('click', function () {
                    tree.querySelectorAll('.active').forEach(function (a) { a.classList.remove('active'); });
                    li.classList.add('active');
                    showUsers(loadUsers({ UF_DEPARTMENT: Number(li.dataset.dept) }));
                });
            });
            BX24.fitWindow();
        }).catch(function (error) {
            departments = null;
            tree.innerHTML = '<li class="fail">' + esc(error.message) + '</li>';
        });

        var timer = null;
        search.addEventListener('input', function () {
            clearTimeout(timer);
            var text = search.value.trim();
            if (text.length < 2) {
                return;
            }
            timer = setTimeout(function () {
                showUsers(loadUsers({ NAME_SEARCH: text }));
            }, 400);
        });

        renderSelected();

        return new Promise(function (resolve) {
            box.querySelectorAll('[data-picker]').forEach(function (button) {
                button.addEventListener('click', function () {
                    box.hidden = true;
                    box.innerHTML = '';
                    BX24.fitWindow();
                    resolve(button.dataset.picker === 'save' ? selected : null);
                });
            });
        });
    }

    window.Kb24Picker = { open: open };
})();
