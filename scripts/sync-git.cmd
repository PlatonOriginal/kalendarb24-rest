@echo off
chcp 65001 >nul
setlocal enabledelayedexpansion
rem Забирает свежие изменения из GitHub для обоих репозиториев: коробочного модуля и REST-приложения.
rem Пути к папкам поправьте здесь, если они другие.
set "REPOS=E:\VibeCoding\KalendarB24;E:\VibeCoding\KalendarB24-REST"

for %%r in ("%REPOS:;=" "%") do call :sync "%%~r"
echo.
echo Чтобы переключиться на другую ветку: git -C ^<папка^> switch ^<ветка^>
pause
exit /b 0

:sync
set "DIR=%~1"
echo.
echo ===== %DIR%
if not exist "%DIR%\.git" (
  echo   не найден git-репозиторий, пропускаю
  exit /b 0
)
git -C "%DIR%" fetch --all --prune --quiet
for /f "delims=" %%b in ('git -C "%DIR%" branch --show-current') do set "BRANCH=%%b"
echo   текущая ветка: !BRANCH!

set "DIRTY="
for /f "delims=" %%s in ('git -C "%DIR%" status --porcelain') do set "DIRTY=1"
if defined DIRTY (
  echo   есть несохранённые локальные изменения, обновление пропущено:
  git -C "%DIR%" status --short
  exit /b 0
)

git -C "%DIR%" pull --ff-only
if not %errorlevel%==0 echo   не удалось обновить автоматически, пришлите этот вывод в тред

echo   ветки от Claude на GitHub:
git -C "%DIR%" branch -r --list "origin/claude/*"
exit /b 0
