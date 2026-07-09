#!/bin/bash
set -e

sed -i "s/80/${PORT:-80}/g" /etc/apache2/ports.conf
sed -i "s/:80/:${PORT:-80}/g" /etc/apache2/sites-available/000-default.conf

php artisan storage:link 2>/dev/null || true
php artisan l5-swagger:generate || true

exec apache2-foreground
