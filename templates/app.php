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
    <a href="#dashboard" data-tab="dashboard" hidden>Дашборд</a>
    <a href="#requests" data-tab="requests">Мои заявки</a>
    <a href="#approve" data-tab="approve">На согласовании <span id="approve-count"></span></a>
    <a href="#acks" data-tab="acks">На ознакомлении <span id="ack-count"></span></a>
    <a href="#balances" data-tab="balances" hidden>Остатки</a>
    <a href="#routes" data-tab="routes" hidden>Маршруты</a>
    <a href="#settings" data-tab="settings" hidden>Настройки</a>
    <a href="#access" data-tab="access" hidden>Доступ</a>
</nav>
<p id="message" class="message" hidden></p>

<section id="tab-calendar" hidden>
    <div id="calendar"></div>
</section>

<section id="tab-dashboard" hidden>
    <div id="dashboard"></div>
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

<section id="tab-acks" hidden>
    <h2>Ждут вашего ознакомления</h2>
    <p class="hint">Вас указали в маршруте получателем результата: откройте заявку и подтвердите, что ознакомились. Пока не подтвердите, каждый рабочий день до начала отсутствия будет приходить напоминание.</p>
    <table id="ack-requests" class="list">
        <thead><tr><th>№</th><th>Сотрудник</th><th>Даты</th><th>Вид</th><th>Дней</th><th>Результат</th></tr></thead>
        <tbody></tbody>
    </table>
</section>

<section id="tab-balances" hidden>
    <div id="balances"></div>
</section>

<section id="tab-routes" hidden>
    <div id="routes"></div>
</section>

<section id="tab-card" hidden>
    <p><a href="#" id="card-back">← назад</a></p>
    <div id="card"></div>
</section>

<section id="tab-access" hidden>
    <div id="access"></div>
</section>

<section id="tab-settings" hidden>
    <p class="hint" id="settings-readonly" hidden>Вам доступен только просмотр настроек.</p>
    <h2>Срок подачи заявки</h2>
    <p class="hint">За сколько дней до начала подавать заявку, задаётся в шагах маршрутов на вкладке «Маршруты», в том числе в маршруте «По умолчанию».</p>
    <div class="form">
        <label class="check"><input type="checkbox" id="min-submit-exempt-unpaid"> Не проверять срок подачи для отпуска без сохранения заработной платы</label>
    </div>
    <h2>Отмена утверждённого отсутствия</h2>
    <p class="hint">Сотрудник может отменить утверждённое отсутствие не позже чем за указанное число рабочих дней до начала. Отмену согласуют руководитель, затем участник роли «Отдел кадров» с вкладки «Доступ» (пока у отмены нет своих маршрутов); после этого событие удаляется из календаря.</p>
    <div class="form">
        <label>Не позже чем за, раб. дней <input type="number" id="cancel-days" min="0" max="365"></label>
    </div>
    <h2>Ежегодный отпуск</h2>
    <p class="hint">Сколько календарных дней начисляется в каждую годовщину приёма на работу (от 28 до 100). Остатки и даты приёма вносят на вкладке «Остатки».</p>
    <div class="form">
        <label>Дней за рабочий год <input type="number" id="annual-days" min="28" max="100"></label>
    </div>
    <p class="hint">Какие отделы видны в календаре и чьи заявки открываются, задаётся в ролях на вкладке «Доступ».</p>
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


<script src="/assets/calendar.js?v=<?= @filemtime(dirname(__DIR__) . '/public/assets/calendar.js') ?>"></script>
<script src="/assets/balance.js?v=<?= @filemtime(dirname(__DIR__) . '/public/assets/balance.js') ?>"></script>
<script src="/assets/routes.js?v=<?= @filemtime(dirname(__DIR__) . '/public/assets/routes.js') ?>"></script>
<script src="/assets/dashboard.js?v=<?= @filemtime(dirname(__DIR__) . '/public/assets/dashboard.js') ?>"></script>
<script src="/assets/access.js?v=<?= @filemtime(dirname(__DIR__) . '/public/assets/access.js') ?>"></script>
<script src="/assets/picker.js?v=<?= @filemtime(dirname(__DIR__) . '/public/assets/picker.js') ?>"></script>
<script src="/assets/vacation.js?v=<?= @filemtime(dirname(__DIR__) . '/public/assets/vacation.js') ?>"></script>
</body>
</html>
