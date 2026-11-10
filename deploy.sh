#!/bin/bash
# Выкладка сайта «Почерк» на хостинг Рег.ру.
#
#   ./deploy.sh
#
# Хостинг: Хост-А, логин u3719581, сервер server34.hosting.reg.ru.
# Вход по ключу ~/.ssh/pocherk_deploy (в ~/.ssh/config это Host pocherk). Без ключа ssh спросит пароль хостинга.
# Журнал заявок (api/data) на сервере не трогаем и не перезаписываем.
set -euo pipefail

TARGET="${REGRU_TARGET:-pocherk}"   # алиас из ~/.ssh/config или u3719581@server34.hosting.reg.ru
REMOTE_DIR="${REGRU_DIR:-www/pocherkagency.ru}"

SRC="$(cd "$(dirname "$0")" && pwd)"
[ -f "$SRC/api/config.php" ] || { echo "Нет api/config.php — без него не будет входа в /zayavki/ и писем"; exit 1; }

echo "→ Откуда: $SRC"
echo "→ Куда:   $TARGET:$REMOTE_DIR"
echo

rsync -avz --delete \
  --exclude 'api/data/' \
  --exclude '.git/' \
  --exclude '.gitignore' \
  --exclude '.claude/' \
  --exclude 'CNAME' \
  --exclude '.nojekyll' \
  --exclude 'deploy.sh' \
  --exclude 'api/config.sample.php' \
  --exclude '.DS_Store' \
  "$SRC/" "$TARGET:$REMOTE_DIR/"

echo
echo "✓ Готово. Проверьте: https://pocherkagency.ru/ и https://pocherkagency.ru/zayavki/"
