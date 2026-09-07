#!/bin/sh
set -e

db_path="${DB_PATH:-database/janathan.sqlite}"
case "$db_path" in
    /*) db_dir=$(dirname "$db_path") ;;
    *)  db_dir=$(dirname "/var/www/$db_path") ;;
esac

if [ "$(id -u)" = '0' ]; then
    user="${APACHE_RUN_USER:-www-data}"
    group="${APACHE_RUN_GROUP:-www-data}"

    # optional host-user mapping (PUID/PGID both set -> renumber www-data)
    if [ -n "${PUID}" ] && [ -n "${PGID}" ]; then
        usermod -o -u "$PUID" "$user" 2>/dev/null || true
        groupmod -o -g "$PGID" "$group" 2>/dev/null || true
    fi

    mkdir -p "$db_dir"
    chown -R "$user:$group" "$db_dir"
else
    # rootless Docker can't chown; grant group/world write
    mkdir -p "$db_dir"
    chmod -R g+rwX,o+rwX "$db_dir"
fi

exec /usr/local/bin/docker-php-entrypoint "$@"