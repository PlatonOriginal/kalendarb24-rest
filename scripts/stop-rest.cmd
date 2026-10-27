@echo off
chcp 65001 >nul
rem Остановка REST-приложения «Отпуска» и туннеля. База приложения сохраняется.
cd /d "%~dp0.."

taskkill /im cloudflared.exe /f >nul 2>&1
if %errorlevel%==0 (echo Туннель остановлен.) else (echo Туннель не был запущен.)

docker info >nul 2>&1
if not %errorlevel%==0 (
  echo Docker не запущен, значит контейнеры уже остановлены.
  pause
  exit /b 0
)
rem Без флага -v, иначе удалится том с базой приложения
docker compose down
echo Готово.
pause
