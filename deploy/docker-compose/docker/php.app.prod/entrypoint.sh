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

chown -R www-data:www-data /var/www/html/storage

exec gosu www-data "$@"

