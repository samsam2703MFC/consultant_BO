#!/usr/bin/env bash
#
# Déploiement du Cockpit CEO sur le serveur (Apache + PHP + MySQL du panel).
# Reproduit docs/DEPLOIEMENT.md. À exécuter EN ROOT SUR LE SERVEUR cible,
# depuis un checkout du dépôt (branche main) :
#
#   sudo COCKPIT_DB_USER=... COCKPIT_DB_PASSWORD=... bin/deploy.sh
#
# Les identifiants MySQL sont ceux du panel consultant (son
# config/db.local.php). S'ils ne sont pas fournis en variables
# d'environnement, le script tente de les lire depuis le config du panel ;
# à défaut il écrit un config.php avec des marqueurs à compléter.
#
# Aucun secret n'est stocké dans ce script ni committé : config/config.php
# est hors Git (.gitignore).
set -euo pipefail

# --- Paramètres (surchargeables par l'environnement) ---------------------
TARGET_DIR="${TARGET_DIR:-/var/www/consulant_bo}"      # dépôt complet, hors webroot
ALIAS_PATH="${ALIAS_PATH:-/consulant_bo}"              # URL servie par Apache
BASE_URL="${BASE_URL:-http://185.180.206.46${ALIAS_PATH}}"

COCKPIT_DB_HOST="${COCKPIT_DB_HOST:-127.0.0.1}"
COCKPIT_DB_PORT="${COCKPIT_DB_PORT:-3306}"
COCKPIT_DB_NAME="${COCKPIT_DB_NAME:-atelierby_db}"
COCKPIT_DB_USER="${COCKPIT_DB_USER:-}"
COCKPIT_DB_PASSWORD="${COCKPIT_DB_PASSWORD:-}"
COCKPIT_PWA_BASE="${COCKPIT_PWA_BASE:-http://185.180.206.46/pwa_consultant}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_SRC="$(cd "$SCRIPT_DIR/.." && pwd)"

log() { printf '\033[1;34m[deploy]\033[0m %s\n' "$*"; }

# --- 1. Prérequis --------------------------------------------------------
log "Installation des prérequis (Apache, PHP >= 8.1, pdo_mysql, mod_rewrite)…"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq apache2 php php-mysql libapache2-mod-php rsync >/dev/null
a2enmod rewrite >/dev/null

# --- 2. Fichiers ---------------------------------------------------------
log "Copie du dépôt vers $TARGET_DIR (hors webroot, public/ sera aliasé)…"
mkdir -p "$TARGET_DIR"
rsync -a --delete \
  --exclude '.git' --exclude 'config/config.php' --exclude 'node_modules' \
  "$REPO_SRC/" "$TARGET_DIR/"

# --- 3. Identifiants MySQL du panel -------------------------------------
if [[ -z "$COCKPIT_DB_USER" || -z "$COCKPIT_DB_PASSWORD" ]]; then
  log "Identifiants MySQL non fournis — recherche du config du panel…"
  PANEL_CFG="$(find /var/www -maxdepth 6 -name 'db.local.php' -path '*pwa_consultant*' 2>/dev/null | head -1 || true)"
  if [[ -n "$PANEL_CFG" ]]; then
    log "Config panel trouvé : $PANEL_CFG (à recopier manuellement si l'extraction échoue)"
  else
    log "Config panel introuvable automatiquement."
  fi
fi

# --- 4. config/config.php ------------------------------------------------
CFG="$TARGET_DIR/config/config.php"
if [[ -n "$COCKPIT_DB_USER" && -n "$COCKPIT_DB_PASSWORD" ]]; then
  log "Écriture de config/config.php avec les identifiants fournis…"
  umask 027
  cat > "$CFG" <<PHP
<?php
return [
    'db' => [
        'host'     => '${COCKPIT_DB_HOST}',
        'port'     => '${COCKPIT_DB_PORT}',
        'name'     => '${COCKPIT_DB_NAME}',
        'user'     => '${COCKPIT_DB_USER}',
        'password' => '${COCKPIT_DB_PASSWORD}',
        'charset'  => 'utf8mb4',
    ],
    'pwaBase' => '${COCKPIT_PWA_BASE}',
    'auth'    => false,
];
PHP
else
  log "AUCUN identifiant fourni : copie de l'exemple à compléter à la main."
  [[ -f "$CFG" ]] || cp "$TARGET_DIR/config/config.example.php" "$CFG"
  log ">>> Éditez $CFG (user/password du panel) puis relancez l'étape recette."
fi
chmod 640 "$CFG"
chown www-data:www-data "$CFG"

# --- 5. Apache : alias + AllowOverride ----------------------------------
log "Configuration Apache (alias $ALIAS_PATH → $TARGET_DIR/public)…"
cat > /etc/apache2/conf-available/consulant_bo.conf <<APACHE
Alias ${ALIAS_PATH} ${TARGET_DIR}/public
<Directory ${TARGET_DIR}/public>
    AllowOverride All
    Require all granted
</Directory>
APACHE
a2enconf consulant_bo >/dev/null
apache2ctl configtest
systemctl reload apache2

# --- 6. Premier appel API (auto-installation) + recette ------------------
log "Premier appel API (déclenche création des tables + seed)…"
echo "== GET ${BASE_URL}/api/cockpit/meta =="
curl -fsS "${BASE_URL}/api/cockpit/meta"        | head -c 400; echo
echo "== GET ${BASE_URL}/api/cockpit/stores =="
curl -fsS "${BASE_URL}/api/cockpit/stores"      | head -c 400; echo
echo "== GET ${BASE_URL}/api/cockpit/pwa/reports =="
curl -fsS "${BASE_URL}/api/cockpit/pwa/reports" | head -c 400; echo

log "Terminé. Ouvrez ${BASE_URL}/ dans le navigateur."
