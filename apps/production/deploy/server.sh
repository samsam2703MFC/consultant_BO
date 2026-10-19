#!/usr/bin/env bash
#
# Installe / met à jour l'ERP sur le serveur du cockpit, servi sous /production.
# Lancé EN ROOT par .github/workflows/deploy.yml, depuis la copie du dépôt
# transférée sur le serveur :   bash apps/production/deploy/server.sh
#
# - code : $ERP_TARGET/app ; seul app/public est exposé (Alias Apache)
# - base : $ERP_DB_NAME, utilisateur dédié ; mot de passe généré au premier
#   passage et gardé dans $ERP_TARGET/app/.env (hors Git)
# - données : au premier passage seulement (base vide), import du dump déposé
#   dans $ERP_IMPORT_DIR (.zip, .sql ou .sql.gz). La copie est ensuite coupée
#   de l'extérieur (SMTP, KSeF, boutiques des franchisés) et ses comptes
#   reçoivent des mots de passe aléatoires, écrits dans un fichier root-only
#   du serveur — jamais dans les logs (le dépôt est public).
set -euo pipefail

ERP_TARGET="${ERP_TARGET:-/var/www/production}"
ERP_ALIAS="${ERP_ALIAS:-/production}"
ERP_URL="${ERP_URL:-http://185.180.206.46${ERP_ALIAS}}"
ERP_DB_NAME="${ERP_DB_NAME:-erp_production}"
ERP_DB_USER="${ERP_DB_USER:-erp_production}"
ERP_IMPORT_DIR="${ERP_IMPORT_DIR:-/root/erp_import}"
APACHE_CONF="/etc/apache2/conf-available/production-erp.conf"

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$ERP_TARGET/app/.env"

log()  { printf '\033[1;34m[erp]\033[0m %s\n' "$*"; }
warn() { printf '::warning title=ERP::%s\n' "$*"; }
fail() { printf '::error title=ERP::%s\n' "$*"; exit 1; }
aptget() { DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=600 "$@"; }

# --- 1. PHP et extensions ------------------------------------------------
php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' \
  || fail "PHP $(php -r 'echo PHP_VERSION;') sur le serveur : l'ERP demande PHP 8.1 ou plus."
need=()
for pair in pdo_mysql:php-mysql mbstring:php-mbstring curl:php-curl gd:php-gd zip:php-zip \
            dom:php-xml simplexml:php-xml xmlreader:php-xml xmlwriter:php-xml; do
  php -m | grep -qix "${pair%%:*}" || need+=("${pair##*:}")
done
command -v unzip >/dev/null || need+=(unzip)
if [[ ${#need[@]} -gt 0 ]]; then
  mapfile -t need < <(printf '%s\n' "${need[@]}" | sort -u)
  log "Paquets manquants : ${need[*]}"
  aptget update -qq || true
  aptget install -y -qq "${need[@]}" >/dev/null
  RESTART_APACHE=1
  NEW_PHP_EXT=1   # mod_php ne charge une extension qu'au redémarrage complet
fi

# --- 2. Refuser de livrer du code qui ne se charge pas -------------------
log "Contrôle de syntaxe avec le PHP du serveur ($(php -r 'echo PHP_VERSION;'))…"
bad="$(find "$SRC/app" -name '*.php' -not -path '*/vendor/*' -print0 \
        | xargs -0 -n 1 -P 4 php -l 2>&1 | grep -v '^No syntax errors' || true)"
[[ -z "$bad" ]] || { echo "$bad" | head -20; fail "Syntaxe PHP invalide : rien n'a été livré."; }
[[ -f "$SRC/app/public/vendor/autoload.php" ]] || fail "vendor/ absent de la livraison (composer install sur le runner)."

# --- 3. Fichiers ---------------------------------------------------------
log "Copie vers $ERP_TARGET/app…"
mkdir -p "$ERP_TARGET/app"
rsync -a --delete --exclude '/.env' --exclude '*.log' --exclude 'cron_log.txt' \
  "$SRC/app/" "$ERP_TARGET/app/"
chown -R root:root "$ERP_TARGET/app"
mkdir -p "$ERP_TARGET/app/App/Log"
chown www-data:www-data "$ERP_TARGET/app/App/Log"

# --- 4. .env (créé une seule fois, jamais écrasé) ------------------------
if [[ ! -f "$ENV_FILE" ]]; then
  log "Création de $ENV_FILE…"
  # Majuscule, chiffre et tiret : passe la politique de mots de passe MySQL 8.
  dbpass="$(openssl rand -hex 16)Aa-9"
  cat > "$ENV_FILE" <<ENV
# Copie de l'ERP sur le serveur du cockpit — écrit par apps/production/deploy/server.sh
APP_ENV=copie
APP_URL="${ERP_URL}"

DB_HOST="127.0.0.1"
DB_NAME="${ERP_DB_NAME}"
DB_USER="${ERP_DB_USER}"
DB_PASS="${dbpass}"

# Copie isolée : ni S3, ni Discord, ni VIESAPI, ni API des franchisés
AWS_REGION="eu-north-1"
AWS_ACCESS_KEY_ID=""
AWS_SECRET_ACCESS_KEY=""
AWS_S3_BUCKET=""
DISCORD_CRON_WEBHOOK_URL=
VIESAPI_KEY_ID=
VIESAPI_KEY=
APIPREFIXE="http://127.0.0.1:9/"
ATELIER_RESOURCES_PREFIXE=
ENV
fi
chown root:www-data "$ENV_FILE"
chmod 640 "$ENV_FILE"
dbpass="$(sed -n 's/^DB_PASS="\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' "$ENV_FILE" | head -1)"
[[ -n "$dbpass" ]] || fail "DB_PASS introuvable dans $ENV_FILE."

# --- 5. Base de données ---------------------------------------------------
MYSQL="$(command -v mysql || command -v mariadb || true)"
[[ -n "$MYSQL" ]] || fail "Client mysql introuvable sur le serveur."
"$MYSQL" -e 'SELECT 1' >/dev/null 2>&1 \
  || fail "Connexion MySQL en root par socket impossible : créez la base ${ERP_DB_NAME} et l'utilisateur ${ERP_DB_USER} à la main."
"$MYSQL" <<SQL
CREATE DATABASE IF NOT EXISTS \`${ERP_DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS '${ERP_DB_USER}'@'localhost' IDENTIFIED BY '${dbpass}';
CREATE USER IF NOT EXISTS '${ERP_DB_USER}'@'127.0.0.1' IDENTIFIED BY '${dbpass}';
ALTER USER '${ERP_DB_USER}'@'localhost' IDENTIFIED BY '${dbpass}';
ALTER USER '${ERP_DB_USER}'@'127.0.0.1' IDENTIFIED BY '${dbpass}';
GRANT ALL PRIVILEGES ON \`${ERP_DB_NAME}\`.* TO '${ERP_DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${ERP_DB_NAME}\`.* TO '${ERP_DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

dumps=()
tables="$("$MYSQL" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${ERP_DB_NAME}'")"
if [[ "$tables" -eq 0 ]]; then
  mkdir -p "$ERP_IMPORT_DIR"
  chmod 700 "$ERP_IMPORT_DIR"
  shopt -s nullglob
  dumps=("$ERP_IMPORT_DIR"/*.zip "$ERP_IMPORT_DIR"/*.sql "$ERP_IMPORT_DIR"/*.sql.gz)
  shopt -u nullglob
  if [[ ${#dumps[@]} -eq 0 ]]; then
    warn "Base ${ERP_DB_NAME} vide et aucun dump dans ${ERP_IMPORT_DIR} sur le serveur : déposez-y le .zip de la base puis relancez le déploiement."
  else
    dump="$(ls -t "${dumps[@]}" | head -1)"
    log "Import de $(basename "$dump") dans ${ERP_DB_NAME}…"
    case "$dump" in
      *.zip) unzip -p "$dump" '*.sql' | "$MYSQL" "$ERP_DB_NAME" ;;
      *.gz)  zcat "$dump" | "$MYSQL" "$ERP_DB_NAME" ;;
      *)     "$MYSQL" "$ERP_DB_NAME" < "$dump" ;;
    esac
    log "Import terminé : $("$MYSQL" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${ERP_DB_NAME}'") tables."

    # La copie ne doit rien envoyer à l'extérieur : ni e-mails aux clients, ni
    # factures à KSeF, ni accusés de réception aux boutiques des franchisés
    # (qui retireraient ces commandes à l'ERP en service).
    log "Coupure des intégrations externes de la copie…"
    "$MYSQL" "$ERP_DB_NAME" <<'SQL'
UPDATE communication_email_account
   SET is_active = 0, smtp_secret = NULL, imap_secret = NULL,
       smtp_secret_encrypted = NULL, imap_secret_encrypted = NULL;
UPDATE ksef_integration_settings SET environment = 'test', token_encrypted = NULL;
UPDATE customer SET credentials = NULL, franchisee_connection_password = NULL;
SQL

    # Mots de passe des comptes de la copie : aléatoires, lisibles par root seulement.
    pwfile="$ERP_IMPORT_DIR/MOTS_DE_PASSE_ERP.txt"
    : > "$pwfile"
    chmod 600 "$pwfile"
    while IFS=$'\t' read -r uid ulogin; do
      upass="$(openssl rand -hex 8)"
      "$MYSQL" "$ERP_DB_NAME" -e "UPDATE user SET password = '${upass}' WHERE id = ${uid};" < /dev/null
      printf '%s\t%s\n' "$ulogin" "$upass" >> "$pwfile"
    done < <("$MYSQL" -N "$ERP_DB_NAME" -e "SELECT id, login FROM user")
    log "Comptes de la copie : nouveaux mots de passe dans ${pwfile} (sur le serveur)."

    mkdir -p "$ERP_IMPORT_DIR/importe"
    mv "$dump" "$ERP_IMPORT_DIR/importe/"
  fi
else
  log "Base ${ERP_DB_NAME} déjà remplie (${tables} tables) : aucun import."
fi

# --- 6. Apache : Alias /production --------------------------------------
if grep -rlsE "^\s*Alias\s+${ERP_ALIAS}(\s|/)" /etc/apache2/sites-enabled /etc/apache2/conf-enabled \
     | grep -v "production-erp.conf" | grep -q .; then
  fail "Un autre site Apache utilise déjà ${ERP_ALIAS} : rien n'a été modifié."
fi
newconf="$(mktemp)"
cat > "$newconf" <<APACHE
# ERP (apps/production de consultant_BO) — écrit par apps/production/deploy/server.sh
Alias ${ERP_ALIAS} ${ERP_TARGET}/app/public
<Directory ${ERP_TARGET}/app/public>
    Options -Indexes +FollowSymLinks
    AllowOverride None
    Require all granted
    RewriteEngine On
    RewriteBase ${ERP_ALIAS}/
    RewriteRule ^api/(.*)$ api.php [QSA,L]
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.*)$ index.php?url=\$1 [L,QSA]
</Directory>
APACHE
if ! cmp -s "$newconf" "$APACHE_CONF"; then
  cp "$newconf" "$APACHE_CONF"
  RESTART_APACHE=1
fi
rm -f "$newconf"
a2enmod rewrite >/dev/null 2>&1 || true
if [[ ! -e /etc/apache2/conf-enabled/production-erp.conf ]]; then
  a2enconf production-erp >/dev/null
  RESTART_APACHE=1
fi
if [[ "${RESTART_APACHE:-0}" == "1" ]]; then
  if ! apache2ctl configtest >/dev/null 2>&1; then
    a2disconf production-erp >/dev/null 2>&1 || true
    fail "Configuration Apache refusée (apache2ctl configtest) : ${ERP_ALIAS} désactivé, le reste du serveur est intact."
  fi
  if [[ "${NEW_PHP_EXT:-0}" == "1" ]]; then systemctl restart apache2; else systemctl reload apache2; fi
fi

# --- 7. Recette locale ---------------------------------------------------
body="$(mktemp)"
code="$(curl -s -o "$body" -w '%{http_code}' "http://127.0.0.1${ERP_ALIAS}/" || true)"
if [[ "$tables" -eq 0 && ${#dumps[@]} -eq 0 ]]; then
  log "Recette sautée : la base est encore vide."
elif [[ "$code" == "200" ]] && grep -q 'name="password"' "$body"; then
  code2="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1${ERP_ALIAS}/client-orders" || true)"
  [[ "$code2" == "302" ]] || fail "${ERP_ALIAS}/client-orders sans session répond HTTP ${code2} (302 attendu)."
  log "Recette OK : page de connexion servie, pages protégées. ERP disponible sur ${ERP_URL}/"
else
  head -c 600 "$body"; echo
  fail "${ERP_ALIAS}/ répond HTTP ${code} sans page de connexion."
fi
rm -f "$body"
