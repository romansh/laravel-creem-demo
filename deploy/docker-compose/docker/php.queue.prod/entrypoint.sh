#!/bin/sh

# Only for APP_KEY and DB_PASSWORD
for VAR_NAME in APP_KEY DB_PASSWORD; do
  # Get the value of the variable from the environment
  VAR_VALUE=$(printenv "$VAR_NAME")

  # If it's set and points to a file, read the file and override the variable
  if [ -n "$VAR_VALUE" ] && [ -f "$VAR_VALUE" ]; then
    export "$VAR_NAME"="$(cat "$VAR_VALUE")"
  fi
done

mkdir -p \
  /var/www/html/storage/app/private/data \
  /var/www/html/storage/framework/cache \
  /var/www/html/storage/framework/sessions \
  /var/www/html/storage/framework/testing \
  /var/www/html/storage/framework/views \
  /var/www/html/bootstrap/cache

chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R ug+rwX /var/www/html/storage /var/www/html/bootstrap/cache || true

exec gosu www-data "$@"

