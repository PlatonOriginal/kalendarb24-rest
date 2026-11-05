#!/bin/sh
# Автообновление на хостинге: по cron забирает новое из ветки main и пересобирает автозагрузку.
# Ничего не делает, если на GitHub нет новых коммитов. Локальные правки в файлах приложения не допускаются:
# при них git pull откажется, и это будет видно в журнале.
#   */10 * * * * /bin/sh $HOME/aplat.by/vacation/bin/deploy.sh >> $HOME/logs/vacation-deploy.log 2>&1
set -e
cd "$(dirname "$0")/.."
PHP=${PHP:-/usr/local/bin/php}
COMPOSER=${COMPOSER:-$HOME/composer.phar}

git fetch -q origin main
if [ "$(git rev-parse HEAD)" = "$(git rev-parse FETCH_HEAD)" ]; then
    exit 0
fi
echo "$(date '+%Y-%m-%d %H:%M') обновление $(git rev-parse --short HEAD) -> $(git rev-parse --short FETCH_HEAD)"
git merge -q --ff-only FETCH_HEAD
"$PHP" "$COMPOSER" install -q --no-dev --optimize-autoloader --no-interaction
echo "$(date '+%Y-%m-%d %H:%M') готово"
