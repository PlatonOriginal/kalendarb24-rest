<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Установка «Отпуска»</title>
    <script src="https://api.bitrix24.com/api/v1/"></script>
</head>
<body>
<?php if ($error): ?>
    <p><?= htmlspecialchars($error) ?></p>
<?php else: ?>
    <p>Приложение установлено.</p>
    <script>
        BX24.init(function () {
            BX24.installFinish();
        });
    </script>
<?php endif ?>
</body>
</html>
