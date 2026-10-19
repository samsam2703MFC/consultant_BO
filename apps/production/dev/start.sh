#!/bin/bash
# Environnement de dev (session cloud Claude ou Linux) :
# MariaDB + dépendances Composer + .env local + serveur PHP sur http://slfood.local
#
#   dev/start.sh                      démarre (la base doit déjà être importée)
#   dev/start.sh chemin/vers/erp.zip  importe d'abord le dump (.sql ou .zip) dans la base "erp"
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
APP="$ROOT_DIR/app"
DUMP="${1:-}"

# 1. MariaDB
if ! command -v mysqld >/dev/null; then
    apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq mariadb-server >/dev/null
fi
mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld
if ! mysqladmin ping >/dev/null 2>&1; then
    (mysqld_safe --user=mysql >/tmp/mysqld.log 2>&1 &)
    for _ in $(seq 1 30); do mysqladmin ping >/dev/null 2>&1 && break; sleep 1; done
fi
mysql -e "CREATE DATABASE IF NOT EXISTS erp CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
          CREATE USER IF NOT EXISTS 'erp'@'localhost' IDENTIFIED BY 'erp_local';
          CREATE USER IF NOT EXISTS 'erp'@'127.0.0.1' IDENTIFIED BY 'erp_local';
          GRANT ALL ON erp.* TO 'erp'@'localhost';
          GRANT ALL ON erp.* TO 'erp'@'127.0.0.1';"

# 2. Import du dump
TABLES=$(mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='erp'")
if [ -n "$DUMP" ] && [ "$TABLES" -gt 0 ]; then
    echo "La base erp contient déjà $TABLES tables : import ignoré (DROP DATABASE erp; pour repartir de zéro)."
elif [ -n "$DUMP" ]; then
    case "$DUMP" in
        *.zip) unzip -p "$DUMP" '*.sql' | mysql erp ;;
        *.gz)  zcat "$DUMP" | mysql erp ;;
        *)     mysql erp < "$DUMP" ;;
    esac
    echo "Base importée : $(mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='erp'") tables"
fi

# 3. Dépendances et .env local (S3, Discord et VIESAPI vides : rien ne part vers l'extérieur)
[ -d "$APP/public/vendor" ] || (cd "$APP/public" && composer install --no-interaction --no-progress)
if [ ! -f "$APP/.env" ]; then
    sed -e 's/^DB_HOST=.*/DB_HOST="127.0.0.1"/' -e 's/^DB_NAME=.*/DB_NAME="erp"/' \
        -e 's/^DB_USER=.*/DB_USER="erp"/' -e 's/^DB_PASS=.*/DB_PASS="erp_local"/' \
        -e 's/^AWS_REGION=.*/AWS_REGION="eu-north-1"/' \
        "$APP/.env.example" > "$APP/.env"
fi

# 4. Serveur (ROOT vaut http://slfood.local quand SERVER_NAME = slfood.local, d'où le port 80)
grep -q slfood.local /etc/hosts || echo "127.0.0.1 slfood.local" >> /etc/hosts
cd "$APP/public"
exec php -d display_errors=On -d error_reporting=E_ALL \
    -S slfood.local:80 "$ROOT_DIR/dev/router.php"
