#!/bin/sh
# Обновление на хостинге вручную: забирает новое из ветки main и пересобирает автозагрузку.
#   sh ~/aplat.by/vacation/bin/update.sh
# Если на GitHub нет новых коммитов, ничего не делает. При локальных правках файлов приложения (кроме .env) остановится с ошибкой.
set -e
cd "$(dirname "$0")/.."
PHP=${PHP:-/usr/local/bin/php}
COMPOSER=${COMPOSER:-$HOME/composer.phar}

git fetch -q origin main
if [ "$(git rev-parse HEAD)" = "$(git rev-parse FETCH_HEAD)" ]; then
    echo "Обновлений нет: $(git rev-parse --short HEAD)"
    exit 0
fi
echo "$(date '+%Y-%m-%d %H:%M') обновление $(git rev-parse --short HEAD) -> $(git rev-parse --short FETCH_HEAD)"
git merge -q --ff-only FETCH_HEAD
"$PHP" "$COMPOSER" install -q --no-dev --optimize-autoloader --no-interaction
echo "$(date '+%Y-%m-%d %H:%M') готово"
