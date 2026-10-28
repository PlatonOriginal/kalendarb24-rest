<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Отпуска</title>
    <link rel="stylesheet" href="/assets/app.css">
    <script src="https://api.bitrix24.com/api/v1/"></script>
</head>
<body>
<h1>Отпуска</h1>
<nav class="tabs">
    <a href="#requests" data-tab="requests">Мои заявки</a>
    <a href="#approve" data-tab="approve">На согласовании <span id="approve-count"></span></a>
    <a href="#settings" data-tab="settings" hidden>Настройки</a>
    <a href="#checks" data-tab="checks">Проверки</a>
</nav>
<p id="message" class="message" hidden></p>

<section id="tab-requests" hidden>
    <form id="new-request" class="form">
        <h2>Новая заявка</h2>
        <label>Вид <select name="kind"></select></label>
        <label>С <input type="date" name="dateFrom" required></label>
        <label>по <input type="date" name="dateTo" required></label>
        <button type="submit">Подать</button>
    </form>
    <h2>Мои заявки</h2>
    <table id="my-requests" class="list">
        <thead><tr><th>Даты</th><th>Вид</th><th>Дней</th><th>Статус</th><th>Сейчас у</th></tr></thead>
        <tbody></tbody>
    </table>
</section>

<section id="tab-approve" hidden>
    <h2>Ждут вашего решения</h2>
    <table id="approve-requests" class="list">
        <thead><tr><th>Сотрудник</th><th>Даты</th><th>Вид</th><th>Дней</th><th>Шаг</th></tr></thead>
        <tbody></tbody>
    </table>
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
</section>

<section id="tab-checks" hidden>
    <p class="hint">ФИО показывается только в браузере и на сервер не уходит.</p>
    <h2>Блок 1: связка портала и приложения</h2>
    <table id="block1">
        <thead><tr><th>Проверка</th><th>Результат</th></tr></thead>
        <tbody></tbody>
    </table>
    <h2>Блок 2: структура компании</h2>
    <table id="block2">
        <thead><tr><th>Проверка</th><th>Результат</th></tr></thead>
        <tbody></tbody>
    </table>
    <h2>Блок 3: хранилище данных в портале</h2>
    <p class="hint">Таблицу прав и пробную запись проверяйте под администратором, прямой доступ из браузера под обычным сотрудником.</p>
    <table id="block3">
        <thead><tr><th>Проверка</th><th>Результат</th></tr></thead>
        <tbody></tbody>
    </table>
</section>

<script src="/assets/checks.js"></script>
<script src="/assets/picker.js"></script>
<script src="/assets/vacation.js"></script>
</body>
</html>
