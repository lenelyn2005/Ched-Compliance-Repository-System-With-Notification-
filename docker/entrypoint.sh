#!/bin/sh
set -e

# --- Database CA certificate (Aiven requires TLS) ---------------------------
# Option A: Render "Secret File" named ca.pem  -> /etc/secrets/ca.pem
# Option B: env var DB_SSL_CA_CERT containing the PEM text
# Either way, copy to a path readable by www-data and point DB_SSL_CA at it.
CA_TARGET=/etc/ssl/certs/db-ca.pem
if [ -n "$DB_SSL_CA_CERT" ]; then
    printf '%s\n' "$DB_SSL_CA_CERT" > "$CA_TARGET"
elif [ -n "$DB_SSL_CA" ] && [ -f "$DB_SSL_CA" ]; then
    cp "$DB_SSL_CA" "$CA_TARGET"
elif [ -f /etc/secrets/ca.pem ]; then
    cp /etc/secrets/ca.pem "$CA_TARGET"
elif [ -f /var/www/html/certs/ca.pem ]; then
    # Aiven project CA committed in the repo (public cert, not a secret).
    cp /var/www/html/certs/ca.pem "$CA_TARGET"
fi
if [ -f "$CA_TARGET" ]; then
    chmod 0644 "$CA_TARGET"
    export DB_SSL_CA="$CA_TARGET"
    unset DB_SSL_CA_CERT
    echo "[entrypoint] Database CA certificate: $CA_TARGET" >&2
else
    # Don't point PHP at a file that doesn't exist (it would refuse to connect).
    unset DB_SSL_CA
    echo "[entrypoint] WARNING: no database CA certificate found (set a Render Secret File 'ca.pem' or commit certs/ca.pem)." >&2
fi

# Runtime dirs (Render disks, if mounted, start empty and root-owned).
mkdir -p /var/www/html/logs /var/www/html/system/storage/cache /var/www/html/uploads/avatars \
         /var/www/storage/documents /tmp/ched_temp
chown -R www-data:www-data /var/www/html/logs /var/www/html/system /var/www/html/uploads \
         /var/www/html/websocket /var/www/storage /tmp/ched_temp

export PORT="${PORT:-10000}"
exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf
