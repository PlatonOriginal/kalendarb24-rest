<!doctype html>
<html lang="ru">
<head>
    <?php /* ?v= по времени изменения файла: после обновления браузер не берёт старый скрипт из кэша */ ?>
    <meta charset="utf-8">
    <title>Отпуска</title>
    <link rel="stylesheet" href="/assets/app.css?v=<?= @filemtime(dirname(__DIR__) . '/public/assets/app.css') ?>">
    <script src="https://api.bitrix24.com/api/v1/"></script>
</head>
<body>
<h1>Отпуска</h1>
<nav class="tabs">
    <a href="#calendar" data-tab="calendar">Календарь</a>
    <a href="#requests" data-tab="requests">Мои заявки</a>
    <a href="#approve" data-tab="approve">На согласовании <span id="approve-count"></span></a>
    <a href="#balances" data-tab="balances" hidden>Остатки</a>
    <a href="#settings" data-tab="settings" hidden>Настройки</a>
    <a href="#checks" data-tab="checks">Проверки</a>
</nav>
<p id="message" class="message" hidden></p>

<section id="tab-calendar" hidden>
    <div id="calendar"></div>
</section>

<section id="tab-requests" hidden>
    <form id="new-request" class="form">
        <h2>Новая заявка</h2>
        <label>Вид <select name="kind"></select></label>
        <label>С <input type="date" name="dateFrom" required></label>
        <label>по <input type="date" name="dateTo" required></label>
        <button type="submit">Подать</button>
        <p id="balance-hint" class="hint" hidden></p>
    </form>
    <h2>Мои заявки</h2>
    <table id="my-requests" class="list">
        <thead><tr><th>№</th><th>Даты</th><th>Вид</th><th>Дней</th><th>Статус</th><th>Сейчас у</th></tr></thead>
        <tbody></tbody>
    </table>
</section>

<section id="tab-approve" hidden>
    <h2>Ждут вашего решения</h2>
    <table id="approve-requests" class="list">
        <thead><tr><th>№</th><th>Сотрудник</th><th>Даты</th><th>Вид</th><th>Дней</th><th>Шаг</th></tr></thead>
        <tbody></tbody>
    </table>
</section>

<section id="tab-balances" hidden>
    <div id="balances"></div>
</section>

<section id="tab-card" hidden>
    <p><a href="#" id="card-back">← назад</a></p>
    <div id="card"></div>
</section>

<section id="tab-settings" hidden>
    <h2>Роли</h2>
    <p class="hint">Директор утверждает заявки последним. Отдел кадров проверяет заявки после руководителя, заявка достаётся сотруднику ОК с наименьшим числом заявок на проверке.</p>
    <table class="list">
        <tr><th>Директор</th><td id="role-director"></td><td><button type="button" id="pick-director">Выбрать</button></td></tr>
        <tr><th>Отдел кадров</th><td id="role-hr"></td><td><button type="button" id="pick-hr">Выбрать</button></td></tr>
    </table>
    <div id="user-picker" hidden></div>
    <h2>Отмена утверждённого отсутствия</h2>
    <p class="hint">Сотрудник может отменить утверждённое отсутствие не позже чем за указанное число рабочих дней до начала. Отмену согласуют руководитель, затем отдел кадров; после этого событие удаляется из календаря.</p>
    <div class="form">
        <label>Не позже чем за, раб. дней <input type="number" id="cancel-days" min="0" max="365"></label>
    </div>
    <h2>Ежегодный отпуск</h2>
    <p class="hint">Сколько календарных дней начисляется в каждую годовщину приёма на работу (от 28 до 100). Остатки и даты приёма вносит отдел кадров на вкладке «Остатки».</p>
    <div class="form">
        <label>Дней за рабочий год <input type="number" id="annual-days" min="28" max="100"></label>
    </div>
    <h2>Календарь</h2>
    <div class="form">
        <label class="check"><input type="checkbox" id="see-other-departments"> Сотрудники видят календарь других отделов</label>
    </div>
    <p class="hint">Без этой настройки сотрудник видит свой отдел и отделы, которыми руководит. Директор, отдел кадров и администратор видят все отделы.</p>
    <p><button type="button" id="save-options">Сохранить настройки</button></p>
    <h2>Лимиты отделов</h2>
    <p class="hint">Пустое поле — без ограничения. Лимит проверяется при подаче заявки, руководитель отдела в нём не считается, если не отмечено «Учитывать руководителя».
        Заявки относятся к отделу, в котором сотрудник был при подаче.</p>
    <table id="limits">
        <thead><tr><th>Отдел</th><th>Максимум отсутствующих в один день</th><th>Максимум дней пересечения двух отсутствий</th><th>Учитывать подотделы</th><th>Учитывать руководителя</th></tr></thead>
        <tbody></tbody>
    </table>
    <p><button type="button" id="save-limits">Сохранить лимиты</button></p>
</section>

<section id="tab-checks" hidden>
    <h2>Блок 5: где оказалось событие в календаре</h2>
    <div class="form">
        <label>Заявка № <input type="number" id="block5-id" min="1"></label>
        <button type="button" id="block5-run">Проверить</button>
        <button type="button" id="block5-chart">Доступ к графику отсутствий</button>
    </div>
    <table id="block5">
        <thead><tr><th>Проверка</th><th>Результат</th></tr></thead>
        <tbody></tbody>
    </table>
</section>

<script src="/assets/checks.js?v=<?= @filemtime(dirname(__DIR__) . '/public/assets/checks.js') ?>"></script>
<script src="/assets/calendar.js?v=<?= @filemtime(dirname(__DIR__) . '/public/assets/calendar.js') ?>"></script>
<script src="/assets/balance.js?v=<?= @filemtime(dirname(__DIR__) . '/public/assets/balance.js') ?>"></script>
<script src="/assets/picker.js?v=<?= @filemtime(dirname(__DIR__) . '/public/assets/picker.js') ?>"></script>
<script src="/assets/vacation.js?v=<?= @filemtime(dirname(__DIR__) . '/public/assets/vacation.js') ?>"></script>
</body>
</html>
