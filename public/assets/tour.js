/**
 * Интерактивный гид: пошаговые подсказки поверх приложения, подсвечивают нужный элемент и сами открывают вкладки.
 * Два сценария: «Как пользоваться» для всех и «Как настроить» для тех, кто может что-то настраивать.
 * При первом открытии приложения гид предлагается сам; отметка «уже показан» хранится в BX24.userOption.
 */
(function () {
    'use strict';

    var ctx = null;
    var SEEN = 'tour_seen';

    function esc(text) { return ctx.esc(text); }

    function can(tab, level) {
        var access = ctx.me().access || {};
        return level === 'edit' ? access[tab] === 'edit' : (access[tab] || 'none') !== 'none';
    }

    // ---------- движок ----------

    /**
     * Шаг: { tab, target (селектор), title, text (HTML) }. Без target подсказка по центру экрана.
     */
    function run(steps, onClose) {
        steps = steps.filter(Boolean);
        var index = 0;
        var layer = document.createElement('div');
        layer.className = 'kb-tour';
        layer.innerHTML = '<div class="kb-tour-spot"></div><div class="kb-tour-pop" role="dialog" aria-live="polite"></div>';
        document.body.appendChild(layer);
        var spot = layer.querySelector('.kb-tour-spot');
        var box = layer.querySelector('.kb-tour-pop');
        var target = null;
        var token = 0;

        function close() {
            token++;
            layer.remove();
            window.removeEventListener('resize', place);
            window.removeEventListener('scroll', place, true);
            document.removeEventListener('keydown', keys);
            if (onClose) { onClose(); }
        }

        function keys(e) {
            if (e.key === 'Escape') { close(); }
            if (e.key === 'ArrowRight') { go(index + 1); }
            if (e.key === 'ArrowLeft') { go(index - 1); }
        }

        function visible(node) {
            return node && document.body.contains(node) && node.getClientRects().length > 0;
        }

        function place() {
            if (!visible(target)) {
                spot.hidden = true;
                box.classList.add('is-center');
                box.style.left = box.style.top = '';
                return;
            }
            spot.hidden = false;
            box.classList.remove('is-center');
            var r = target.getBoundingClientRect();
            // Высокий блок (таблица, календарь) подсвечиваем только в пределах экрана
            var top = Math.max(r.top, 4);
            var bottom = Math.min(r.bottom, window.innerHeight - 4);
            spot.style.left = (r.left - 6) + 'px';
            spot.style.top = (top - 6) + 'px';
            spot.style.width = (r.width + 12) + 'px';
            spot.style.height = Math.max(bottom - top + 12, 24) + 'px';
            var below = bottom + 14 + box.offsetHeight < window.innerHeight;
            var above = top - box.offsetHeight - 14 > 0;
            box.style.top = (below ? bottom + 14 : above ? top - box.offsetHeight - 14 : Math.max(12, window.innerHeight - box.offsetHeight - 12)) + 'px';
            box.style.left = Math.max(12, Math.min(r.left, window.innerWidth - box.offsetWidth - 12)) + 'px';
        }

        function go(next) {
            if (next >= steps.length) { close(); return; }
            if (next < 0) { return; }
            index = next;
            var step = steps[index];
            var mine = ++token;
            if (step.tab) { ctx.openTab(step.tab); }
            box.innerHTML = '<div class="kb-tour-count">' + (index + 1) + ' из ' + steps.length + '</div>'
                + '<button type="button" class="kb-tour-close" data-role="close" title="Закрыть гид" aria-label="Закрыть">×</button>'
                + '<h4>' + esc(step.title) + '</h4><div class="kb-tour-text">' + step.text + '</div>'
                + '<div class="kb-tour-actions">' + (index ? '<button type="button" data-role="prev">Назад</button>' : '')
                + '<button type="button" class="primary" data-role="next">' + (index === steps.length - 1 ? 'Готово' : 'Далее') + '</button></div>';
            box.querySelector('[data-role="close"]').addEventListener('click', close);
            box.querySelector('[data-role="next"]').addEventListener('click', function () { go(index + 1); });
            var prev = box.querySelector('[data-role="prev"]');
            if (prev) { prev.addEventListener('click', function () { go(index - 1); }); }
            box.querySelector('[data-role="next"]').focus();

            // Вкладки рисуются после загрузки данных: ждём элемент до 3 секунд, иначе подсказка по центру
            target = null;
            place();
            var waited = 0;
            (function wait() {
                if (mine !== token) { return; }
                var found = step.target ? document.querySelector(step.target) : null;
                if (visible(found) || !step.target || waited >= 3000) {
                    target = visible(found) ? found : null;
                    if (target) { target.scrollIntoView({ block: 'nearest' }); }
                    place();
                    return;
                }
                waited += 100;
                setTimeout(wait, 100);
            })();
        }

        window.addEventListener('resize', place);
        window.addEventListener('scroll', place, true);
        document.addEventListener('keydown', keys);
        go(0);
    }

    // ---------- сценарии ----------

    function userSteps() {
        var me = ctx.me();
        var cancelDays = me.settings ? me.settings.max_cancel_working_days : null;
        return [
            {
                title: 'Отпуска и отсутствия',
                text: '<p>Здесь подают заявки на отпуск, отгул и другие отсутствия, согласуют их и видят, кто когда отсутствует. Покажем главное — это займёт минуту.</p>'
                    + '<p>Гид можно закрыть крестиком или клавишей Esc, листать стрелками ← →, а запустить снова — кнопкой «Гид» вверху.</p>'
            },
            can('calendar') && {
                tab: 'calendar',
                target: '#calendar [data-role="grid"]',
                title: 'Календарь',
                text: '<p>Цвет — вид отсутствия, заливка — статус: контур — заявка у руководителя, штриховка — на следующих шагах, сплошная — утверждено. '
                    + 'Отмеченные дни — лимит отдела исчерпан.</p><p>Нажмите на день, чтобы увидеть, кто отсутствует, и подать заявку с этой даты.</p>'
            },
            {
                tab: 'requests',
                target: '#new-request',
                title: 'Новая заявка',
                text: '<p>Выберите вид и даты и нажмите «Подать». Под формой видно, сколько дней ежегодного отпуска доступно; праздники в отпуск не входят.</p>'
                    + '<p>Кто будет согласовывать и за сколько дней подавать, зависит от маршрута для вашего отдела и вида отсутствия.</p>'
                    + (can('onBehalf', 'edit') ? '<p>Вам разрешено подавать заявки за сотрудников: выберите его в поле «Сотрудник», например чтобы внести больничный.</p>' : '')
            },
            {
                tab: 'requests',
                target: '#my-requests',
                title: 'Мои заявки',
                text: '<p>Все ваши заявки и их статусы. Откройте заявку, чтобы увидеть историю согласования, отозвать её, пока она не утверждена, или запросить отмену утверждённой.</p>'
                    + '<p>Отмену согласуют по маршруту отзыва; запросить её можно не позже срока из маршрута'
                    + (cancelDays !== null && cancelDays !== undefined ? ' (по умолчанию — за ' + esc(cancelDays) + ' раб. дн. до начала)' : '') + '.</p>'
            },
            {
                target: '.tabs a[data-tab="approve"]',
                title: 'На согласовании',
                text: '<p>Если вы руководитель или участвуете в маршруте, сюда приходят заявки, которые ждут вашего решения. Уведомление о них приходит и в колокольчик Битрикс24.</p>'
            },
            {
                target: '.tabs a[data-tab="acks"]',
                title: 'На ознакомлении',
                text: '<p>Если маршрут сообщает вам о результате, заявка появится здесь: откройте её и подтвердите ознакомление, иначе каждый рабочий день будет приходить напоминание.</p>'
            },
            {
                target: '#help',
                title: 'Гид всегда под рукой',
                text: '<p>Кнопка «Гид» запускает эти подсказки снова' + (canSetUp() ? ', а также гид по настройке приложения' : '') + '.</p>'
            }
        ];
    }

    function canSetUp() {
        return ['access', 'routes', 'settings', 'balances'].some(function (tab) { return can(tab, 'edit'); });
    }

    function adminSteps() {
        return [
            {
                title: 'Настройка приложения',
                text: '<p>Покажем, где что настраивается. Удобный порядок: роли на вкладке «Доступ», затем маршруты, сроки и лимиты, затем остатки отпусков.</p>'
                    + '<p>Сразу после установки действуют маршрут «По умолчанию» (руководитель → «Отдел кадров» → «Директор») и маршрут отзыва «Отзыв по умолчанию». '
                    + 'Участников ролей «Отдел кадров» и «Директор» назначают на вкладке «Доступ».</p>'
            },
            can('access') && {
                tab: 'access',
                target: '#access',
                title: 'Доступ',
                text: '<p>Роли решают, кто какие вкладки видит и меняет и по каким отделам: только своему, с подотделами или всем. Права нескольких ролей складываются.</p>'
                    + '<p>Роль «Сотрудник» есть у всех. Роли можно ставить согласующими в шаги маршрутов.</p>'
            },
            can('routes') && {
                tab: 'routes',
                target: '#routes .route',
                title: 'Маршруты согласования',
                text: '<p>Маршрут — кто и в каком порядке согласует заявку. «По умолчанию» действует, когда не подошёл другой; свои маршруты задают для видов отсутствия и отделов.</p>'
                    + '<p>В шагах задают, за сколько дней подавать заявку и до какого срока шаг может её отклонить. «Утверждать сразу» подходит для больничного.</p>'
            },
            can('routes') && {
                tab: 'routes',
                target: '#routes [data-add="cancel"]',
                title: 'Маршруты отзыва',
                text: '<p>Кто согласует отмену утверждённого отсутствия и не позже чем за сколько рабочих дней её можно запросить. '
                    + 'Если ни один маршрут отзыва не подошёл, отсутствие отменяется сразу, а узнают об этом те, кто его согласовал.</p>'
            },
            can('settings') && {
                tab: 'settings',
                target: '#settings-options',
                title: 'Сроки и ежегодный отпуск',
                text: '<p>Срок отмены здесь — значение по умолчанию для маршрутов отзыва без своего срока. Здесь же — сколько дней отпуска начисляется за рабочий год '
                    + 'и можно ли подавать отпуск без сохранения зарплаты без учёта срока подачи.</p>'
            },
            can('settings') && {
                tab: 'settings',
                target: '#settings-limits',
                title: 'Лимиты отделов',
                text: '<p>Сколько человек в отделе могут отсутствовать в один день и на сколько дней могут пересекаться два отсутствия. Пустое поле — без ограничения.</p>'
            },
            can('balances') && {
                tab: 'balances',
                target: '#balances',
                title: 'Остатки',
                text: '<p>Даты приёма и остатки отпусков на дату. Дальше приложение само начисляет дни в каждую годовщину и списывает утверждённые отпуска.</p>'
            },
            can('dashboard') && {
                target: '.tabs a[data-tab="dashboard"]',
                title: 'Дашборд',
                text: '<p>Кто давно не отдыхал, нагрузка по отделам, больничные и отгулы, скорость согласования.</p>'
            }
        ];
    }

    // ---------- меню и первый запуск ----------

    var menu = null;

    function closeMenu() {
        if (menu) { menu.remove(); menu = null; }
    }

    function openMenu(button) {
        if (!canSetUp()) {
            run(userSteps());
            return;
        }
        if (menu) { closeMenu(); return; }
        menu = document.createElement('div');
        menu.className = 'kb-tour-menu';
        menu.innerHTML = '<button type="button" data-tour="user">Как пользоваться</button>'
            + '<button type="button" data-tour="admin">Как настроить</button>';
        document.body.appendChild(menu);
        var r = button.getBoundingClientRect();
        menu.style.top = (r.bottom + 6) + 'px';
        menu.style.left = Math.max(12, r.right - menu.offsetWidth) + 'px';
        menu.querySelectorAll('[data-tour]').forEach(function (item) {
            item.addEventListener('click', function () {
                var which = item.dataset.tour;
                closeMenu();
                run(which === 'admin' ? adminSteps() : userSteps());
            });
        });
    }

    document.addEventListener('click', function (e) {
        if (menu && !menu.contains(e.target) && e.target.id !== 'help') { closeMenu(); }
    });

    function markSeen() {
        try {
            if (window.BX24 && BX24.userOption && BX24.userOption.set) {
                BX24.userOption.set(SEEN, '1');
            }
        } catch (e) { /* не страшно: предложим ещё раз */ }
    }

    function seen() {
        try {
            return !!(window.BX24 && BX24.userOption && BX24.userOption.get && BX24.userOption.get(SEEN));
        } catch (e) {
            return true;
        }
    }

    window.Kb24Tour = {
        init: function (context) {
            ctx = context;
            var button = document.getElementById('help');
            button.hidden = false;
            button.addEventListener('click', function () { openMenu(button); });
        },
        /** При первом открытии: сотруднику — «Как пользоваться», тому, кто настраивает, — «Как настроить». */
        offer: function () {
            if (seen()) { return; }
            markSeen();
            run(canSetUp() ? adminSteps() : userSteps());
        },
        run: run,
        userSteps: userSteps,
        adminSteps: adminSteps
    };
})();
