#!/bin/sh
set -eu
stockroom_port="${PORT:-10000}"
case "$stockroom_port" in ''|*[!0-9]*) echo 'Invalid PORT' >&2; exit 1;; esac
if [ "$stockroom_port" -lt 1024 ] || [ "$stockroom_port" -gt 65535 ]; then echo 'PORT must be between 1024 and 65535' >&2; exit 1; fi
sed -i "s/Listen 80/Listen $stockroom_port/" /etc/apache2/ports.conf
sed -i "s/\*:10000/*:$stockroom_port/" /etc/apache2/sites-available/000-default.conf
stockroom_data_dir="$(dirname "$DB_PATH")"
mkdir -p "$stockroom_data_dir" "$SESSION_PATH"
php bin/init.php
chown www-data:www-data "$stockroom_data_dir" "$SESSION_PATH"
for stockroom_file in "$DB_PATH" "$DB_PATH-wal" "$DB_PATH-shm"; do
    if [ -f "$stockroom_file" ]; then chown www-data:www-data "$stockroom_file"; chmod 640 "$stockroom_file"; fi
done
exec apache2-foreground
