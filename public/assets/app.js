(function () {
    'use strict';

    var tbody = document.querySelector('#checks tbody');

    function row(title, ok, details) {
        var tr = document.createElement('tr');
        var status = ok ? '<span class="ok">✓</span> ' : '<span class="fail">✗</span> ';
        var text = typeof details === 'string' ? details : JSON.stringify(details, null, 2);
        tr.innerHTML = '<td>' + title + '</td><td>' + status + '<pre></pre></td>';
        tr.querySelector('pre').textContent = text;
        tbody.appendChild(tr);
        BX24.fitWindow();
    }

    // Запрос к нашему серверу с токеном текущего пользователя
    function api(path) {
        var auth = BX24.getAuth();
        return fetch(path, {
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

    BX24.init(function () {
        var auth = BX24.getAuth();
        row('BX24.init', !!auth, { domain: auth.domain, member_id: auth.member_id });

        BX24.callMethod('user.current', {}, function (result) {
            if (result.error()) {
                row('user.current в браузере', false, result.error().toString());
                return;
            }
            var user = result.data();
            row('user.current в браузере', true, user.ID + ': ' + [user.LAST_NAME, user.NAME].join(' ').trim());
        });

        api('/api/me')
            .then(function (data) { row('/api/me: сервер проверил токен пользователя', true, data); })
            .catch(function (error) { row('/api/me: сервер проверил токен пользователя', false, error); });

        api('/api/check')
            .then(function (data) { row('/api/check: app.info с сервера по токену приложения', true, data); })
            .catch(function (error) { row('/api/check: app.info с сервера по токену приложения', false, error); });
    });
})();
