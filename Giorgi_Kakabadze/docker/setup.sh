#!/usr/bin/env bash
set -euo pipefail
cd /var/www/html

composer install --no-interaction --prefer-dist --no-progress

grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force

mysql -h "${DB_HOST:-mysql}" -u root -proot -e 'CREATE DATABASE IF NOT EXISTS fansapi_test' 2>/dev/null || true
mysql -h "${DB_HOST:-mysql}" -u root -proot -e "GRANT ALL ON fansapi_test.* TO '${DB_USERNAME:-fansapi}'@'%'" 2>/dev/null || true

php artisan migrate --force
php artisan horizon:publish --quiet || true
php artisan config:clear

echo "setup: complete"
