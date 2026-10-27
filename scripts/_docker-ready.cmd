@echo off
rem Общая часть: проверяет, что Docker запущен, и при необходимости запускает Docker Desktop.
rem Вызывается из других скриптов через call. Код возврата 0, если Docker готов.
docker info >nul 2>&1
if %errorlevel%==0 exit /b 0

echo Docker не запущен, ищу Docker Desktop...
rem Если Docker Desktop установлен в нестандартное место, впишите полный путь к "Docker Desktop.exe" сюда:
set "DOCKER_EXE="
if not defined DOCKER_EXE if exist "%LOCALAPPDATA%\Programs\DockerDesktop\Docker Desktop.exe" set "DOCKER_EXE=%LOCALAPPDATA%\Programs\DockerDesktop\Docker Desktop.exe"
if not defined DOCKER_EXE if exist "%ProgramFiles%\Docker\Docker\Docker Desktop.exe" set "DOCKER_EXE=%ProgramFiles%\Docker\Docker\Docker Desktop.exe"
if not defined DOCKER_EXE if exist "%LOCALAPPDATA%\Programs\Docker\Docker\Docker Desktop.exe" set "DOCKER_EXE=%LOCALAPPDATA%\Programs\Docker\Docker\Docker Desktop.exe"
if not defined DOCKER_EXE if exist "%ProgramData%\Microsoft\Windows\Start Menu\Programs\Docker Desktop.lnk" set "DOCKER_EXE=%ProgramData%\Microsoft\Windows\Start Menu\Programs\Docker Desktop.lnk"
if not defined DOCKER_EXE if exist "%APPDATA%\Microsoft\Windows\Start Menu\Programs\Docker Desktop.lnk" set "DOCKER_EXE=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Docker Desktop.lnk"
if not defined DOCKER_EXE (
  echo Не нашёл Docker Desktop. Запустите его вручную, дождитесь Engine running и запустите скрипт ещё раз.
  exit /b 1
)
echo Запускаю: %DOCKER_EXE%
start "" "%DOCKER_EXE%"
set /a WAIT=0
:wait_docker
timeout /t 5 /nobreak >nul
set /a WAIT+=5
docker info >nul 2>&1
if %errorlevel%==0 exit /b 0
if %WAIT% GEQ 180 (
  echo Docker не запустился за 3 минуты. Откройте Docker Desktop вручную и запустите скрипт ещё раз.
  exit /b 1
)
echo   жду Docker... %WAIT% сек
goto wait_docker
