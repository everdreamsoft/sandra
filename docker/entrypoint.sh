#!/usr/bin/env sh
set -e

# Wait for MySQL when running in compose mode (SANDRA_DB_HOST is set).
# Uses PHP PDO so we don't need mysql-client in the runtime image.
if [ -n "${SANDRA_DB_HOST:-}" ] && [ -n "${SANDRA_DB:-}" ] && [ -n "${SANDRA_DB_USER:-}" ]; then
    echo "Waiting for MySQL at ${SANDRA_DB_HOST}:3306..."
    tries=0
    until php -r '
        try {
            new PDO(
                "mysql:host=" . getenv("SANDRA_DB_HOST") . ";dbname=" . getenv("SANDRA_DB"),
                getenv("SANDRA_DB_USER"),
                getenv("SANDRA_DB_PASS")
            );
            exit(0);
        } catch (Throwable $e) {
            exit(1);
        }
    ' 2>/dev/null; do
        tries=$((tries + 1))
        if [ "$tries" -ge 60 ]; then
            echo "MySQL did not become reachable after 60s — aborting." >&2
            exit 1
        fi
        sleep 1
    done
    echo "MySQL is ready."
fi

exec "$@"
