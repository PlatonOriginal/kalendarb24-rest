@echo off
chcp 65001 >nul
rem Напоминания об ознакомлении с результатом заявок. Добавьте в Планировщик заданий Windows: ежедневно, например в 09:00.
rem Сервер (Docker) должен быть запущен скриптом start-rest.cmd.
cd /d "%~dp0.."
docker compose exec -T app php bin/remind.php
