@echo off
chcp 65001 >nul
setlocal
rem Запуск REST-приложения «Отпуска» в Docker и туннеля Cloudflare к нему.
rem Коробку (env-docker) этот скрипт не трогает, у неё свои start-box.cmd и stop-box.cmd.
cd /d "%~dp0.."

if not exist ".env" (
  echo Нет файла .env. Скопируйте .env.example в .env и впишите код и ключ приложения, см. README.
  pause
  exit /b 1
)
if not exist "docker\certs\localhost.pem" (
  echo Нет сертификата docker\certs\localhost.pem. Выпустите его командой из README ^(mkcert^).
  pause
  exit /b 1
)
where cloudflared >nul 2>&1
if not %errorlevel%==0 (
  echo Не найден cloudflared. Установите: winget install Cloudflare.cloudflared
  echo и перезапустите окно, затем запустите скрипт ещё раз.
  pause
  exit /b 1
)

echo [1/4] Проверяю Docker...
call "%~dp0_docker-ready.cmd"
if not %errorlevel%==0 (
  pause
  exit /b 1
)

echo [2/4] Запускаю туннель...
rem Старый туннель закрываем: у каждого запуска свой адрес
taskkill /im cloudflared.exe /f >nul 2>&1
set "LOG=%TEMP%\kalendarb24-rest-tunnel.log"
if exist "%LOG%" del "%LOG%"
start "Туннель KalendarB24-REST (не закрывайте)" /min cloudflared tunnel --url http://localhost:8080 --logfile "%LOG%"

set "URL="
set /a WAIT=0
:wait_tunnel
timeout /t 2 /nobreak >nul
set /a WAIT+=2
for /f "usebackq delims=" %%u in (`powershell -NoProfile -Command "if (Test-Path $env:LOG) { (Select-String -Path $env:LOG -Pattern 'https://(?!api\.)[a-z0-9-]+\.trycloudflare\.com' | Select-Object -First 1).Matches.Value }"`) do set "URL=%%u"
if defined URL goto tunnel_ready
if %WAIT% GEQ 60 (
  echo Туннель не выдал адрес за минуту. Лог: %LOG%
  pause
  exit /b 1
)
goto wait_tunnel

:tunnel_ready
echo   адрес туннеля: %URL%

echo [3/4] Записываю адрес в .env и запускаю контейнеры...
rem Без BOM, иначе docker не прочитает первую строку .env
powershell -NoProfile -Command "$p = (Resolve-Path '.env').Path; $c = [IO.File]::ReadAllLines($p) -replace '^APP_URL=.*', ('APP_URL=' + $env:URL); [IO.File]::WriteAllLines($p, $c)"
docker compose up -d --build
if not %errorlevel%==0 (
  echo Ошибка запуска контейнеров. Пришлите вывод команды: docker compose logs
  pause
  exit /b 1
)
if not exist "vendor\autoload.php" docker compose exec -T app composer install

echo [4/4] Проверяю, что приложение отвечает через туннель...
set "CODE="
set /a WAIT=0
:wait_app
for /f %%c in ('curl -s -o nul -w "%%{http_code}" "%URL%/app"') do set "CODE=%%c"
if "%CODE%"=="200" goto app_ready
if %WAIT% GEQ 60 (
  echo Приложение не ответило через туннель ^(код %CODE%^). Проверьте: docker compose logs app
  goto show_urls
)
timeout /t 3 /nobreak >nul
set /a WAIT+=3
goto wait_app

:app_ready
echo   отвечает.

:show_urls
echo.
echo ================================================================
echo  Адрес туннеля новый при каждом запуске. Впишите его в настройках
echo  локального приложения на портале ^(раздел «Разработчикам», список интеграций^):
echo.
echo    Путь для первоначальной установки: %URL%/install
echo    Путь вашего обработчика:           %URL%/app
echo.
echo  Путь обработчика уже скопирован в буфер обмена.
echo  Окно туннеля свёрнуто на панели задач, не закрывайте его.
echo ================================================================
echo %URL%/app| clip
pause
