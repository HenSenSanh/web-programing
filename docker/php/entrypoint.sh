#!/usr/bin/env sh
set -eu

PORT_NUM="${PORT:-80}"

case "$PORT_NUM" in
  ''|*[!0-9]*)
    PORT_NUM=80
    ;;
esac

if [ "$PORT_NUM" != "80" ]; then
  sed -ri "s/^Listen 80$/Listen ${PORT_NUM}/" /etc/apache2/ports.conf
  sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${PORT_NUM}>/" /etc/apache2/sites-available/000-default.conf
fi

exec apache2-foreground
