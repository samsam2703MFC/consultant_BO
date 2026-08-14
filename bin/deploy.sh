#!/usr/bin/env bash
#
# Déploiement du Cockpit CEO sur le serveur (Apache + PHP + MySQL du panel).
# Reproduit docs/DEPLOIEMENT.md. À exécuter EN ROOT SUR LE SERVEUR cible,
# depuis un checkout du dépôt (branche main) :
#
#   sudo bin/deploy.sh
#
# Identifiants MySQL : ceux du panel consultant (son config/db.local.php).
#   - fournis en variables d'env  COCKPIT_DB_USER / COCKPIT_DB_PASSWORD  → utilisés ;
#   - sinon lus automatiquement depuis le config du panel sur le serveur ;
#   - sinon config.php est laissé en modèle à compléter (l'API renverra 503).
#
# Aucun secret n'est stocké dans ce script ni committé : config/config.php
# est hors Git (.gitignore).
set -euo pipefail

# --- Paramètres (surchargeables par l'environnement) ---------------------
TARGET_DIR="${TARGET_DIR:-/var/www/consulant_bo}"      # dépôt complet, hors webroot
ALIAS_PATH="${ALIAS_PATH:-/consulant_bo}"              # URL servie par Apache
PUBLIC_BASE="${PUBLIC_BASE:-http://185.180.206.46${ALIAS_PATH}}"
LOCAL_BASE="http://127.0.0.1${ALIAS_PATH}"            # recette locale (loopback)

COCKPIT_DB_HOST="${COCKPIT_DB_HOST:-127.0.0.1}"
COCKPIT_DB_PORT="${COCKPIT_DB_PORT:-3306}"
COCKPIT_DB_NAME="${COCKPIT_DB_NAME:-atelierby_db}"
COCKPIT_DB_USER="${COCKPIT_DB_USER:-}"
COCKPIT_DB_PASSWORD="${COCKPIT_DB_PASSWORD:-}"
COCKPIT_PWA_BASE="${COCKPIT_PWA_BASE:-http://185.180.206.46/pwa_consultant}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_SRC="$(cd "$SCRIPT_DIR/.." && pwd)"

log()  { printf '\033[1;34m[deploy]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[deploy]\033[0m %s\n' "$*"; }

# Fichier d'env optionnel (identifiants passés hors ligne de commande).
if [[ -f "$REPO_SRC/.deployenv" ]]; then
  # shellcheck disable=SC1091
  set -a; . "$REPO_SRC/.deployenv"; set +a
  COCKPIT_DB_USER="${COCKPIT_DB_USER:-}"
  COCKPIT_DB_PASSWORD="${COCKPIT_DB_PASSWORD:-}"
fi

# --- 1. Prérequis (sans perturber le panel déjà en place) ----------------
log "Vérification des prérequis…"
export DEBIAN_FRONTEND=noninteractive
# apt-get qui ATTEND le verrou (unattended-upgrades / apt daily le tiennent
# souvent quelques minutes sur un serveur Ubuntu) au lieu d'échouer aussitôt.
aptget() { apt-get -o DPkg::Lock::Timeout=600 "$@"; }
need=()
command -v apache2ctl >/dev/null 2>&1 || command -v apachectl >/dev/null 2>&1 || need+=(apache2)
command -v php       >/dev/null 2>&1 || need+=(php-cli)
php -m 2>/dev/null | grep -qi '^pdo_mysql$' || need+=(php-mysql)
command -v rsync     >/dev/null 2>&1 || need+=(rsync)
command -v curl      >/dev/null 2>&1 || need+=(curl)
if [[ ${#need[@]} -gt 0 ]]; then
  log "Paquets manquants : ${need[*]} (attente du verrou apt si nécessaire, max 10 min)…"
  if ! aptget update -qq; then
    warn "apt-get update a échoué (verrou ?) — tentative d'installation sur le cache existant."
  fi
  aptget install -y -qq "${need[@]}" >/dev/null
else
  log "Tous les prérequis sont déjà présents (aucune installation)."
fi
# mod_rewrite requis par le .htaccess (idempotent ; ne touche pas au SAPI PHP).
a2enmod rewrite >/dev/null 2>&1 || true

# --- 2. Fichiers ---------------------------------------------------------
log "Copie du dépôt vers $TARGET_DIR (hors webroot ; public/ sera aliasé)…"
mkdir -p "$TARGET_DIR"
rsync -a --delete \
  --exclude '.git' --exclude 'config/config.php' \
  --exclude 'node_modules' --exclude '.deployenv' \
  "$REPO_SRC/" "$TARGET_DIR/"

# --- 3. Identifiants MySQL du panel -------------------------------------
if [[ -z "$COCKPIT_DB_USER" || -z "$COCKPIT_DB_PASSWORD" ]]; then
  log "Identifiants MySQL non fournis — lecture du config du panel…"
  PANEL_CFG="$(find /var/www -maxdepth 6 -name 'db.local.php' -path '*pwa_consultant*' 2>/dev/null | head -1 || true)"
  if [[ -n "$PANEL_CFG" ]]; then
    log "Config panel : $PANEL_CFG"
    JSON="$(php "$REPO_SRC/bin/extract_panel_db.php" "$PANEL_CFG" 2>/dev/null || echo '{}')"
    getf() { php -r '$j=json_decode(stream_get_contents(STDIN),true)?:[];echo $j[$argv[1]]??"";' "$1" <<<"$JSON"; }
    [[ -z "$COCKPIT_DB_USER"     ]] && COCKPIT_DB_USER="$(getf user)"
    [[ -z "$COCKPIT_DB_PASSWORD" ]] && COCKPIT_DB_PASSWORD="$(getf password)"
    h="$(getf host)"; [[ -n "$h" ]] && COCKPIT_DB_HOST="$h"
    p="$(getf port)"; [[ -n "$p" ]] && COCKPIT_DB_PORT="$p"
    n="$(getf name)"; [[ -n "$n" ]] && COCKPIT_DB_NAME="$n"
    if [[ -n "$COCKPIT_DB_USER" ]]; then
      log "Identifiants extraits : user=$COCKPIT_DB_USER host=$COCKPIT_DB_HOST db=$COCKPIT_DB_NAME (mot de passe masqué)"
    else
      warn "Extraction infructueuse — renseignez COCKPIT_DB_USER/PASSWORD."
    fi
  else
    warn "Config panel introuvable sous /var/www — renseignez COCKPIT_DB_USER/PASSWORD."
  fi
fi

# --- 4. config/config.php (génération sûre via var_export) ---------------
CFG="$TARGET_DIR/config/config.php"
if [[ -n "$COCKPIT_DB_USER" && -n "$COCKPIT_DB_PASSWORD" ]]; then
  log "Écriture de config/config.php…"
  umask 027
  COCKPIT_DB_HOST="$COCKPIT_DB_HOST" COCKPIT_DB_PORT="$COCKPIT_DB_PORT" \
  COCKPIT_DB_NAME="$COCKPIT_DB_NAME" COCKPIT_DB_USER="$COCKPIT_DB_USER" \
  COCKPIT_DB_PASSWORD="$COCKPIT_DB_PASSWORD" COCKPIT_PWA_BASE="$COCKPIT_PWA_BASE" \
  php -r '
    $cfg = [
      "db" => [
        "host"     => getenv("COCKPIT_DB_HOST"),
        "port"     => getenv("COCKPIT_DB_PORT"),
        "name"     => getenv("COCKPIT_DB_NAME"),
        "user"     => getenv("COCKPIT_DB_USER"),
        "password" => getenv("COCKPIT_DB_PASSWORD"),
        "charset"  => "utf8mb4",
      ],
      "pwaBase" => getenv("COCKPIT_PWA_BASE") ?: null,
      "auth"    => false,
    ];
    echo "<?php\nreturn " . var_export($cfg, true) . ";\n";
  ' > "$CFG"
else
  warn "Pas d'identifiants : copie du modèle (à compléter à la main)."
  [[ -f "$CFG" ]] || cp "$TARGET_DIR/config/config.example.php" "$CFG"
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

# --- 5b. Remise à zéro optionnelle des tables ceo_* (schéma obsolète) -----
# atelierby_db est partagée avec le panel : d'anciennes tables ceo_* (schéma
# périmé, ex. description NOT NULL) empêchent l'auto-installation (le seed
# échoue en 1364). COCKPIT_RESET=1 les supprime pour repartir du schéma courant.
if [[ "${COCKPIT_RESET:-0}" == "1" ]]; then
  log "COCKPIT_RESET=1 — suppression des tables ceo_* obsolètes (client MySQL)…"
  MYSQL_BIN="$(command -v mysql || command -v mariadb || true)"
  if [[ -z "$MYSQL_BIN" ]]; then
    warn "client mysql introuvable — reset ignoré."
  elif [[ -z "$COCKPIT_DB_USER" || -z "$COCKPIT_DB_PASSWORD" ]]; then
    warn "identifiants MySQL absents — reset ignoré."
  else
    export MYSQL_PWD="$COCKPIT_DB_PASSWORD"
    mycli() { "$MYSQL_BIN" -h "$COCKPIT_DB_HOST" -P "$COCKPIT_DB_PORT" -u "$COCKPIT_DB_USER" "$@"; }

    # a) DROP des tables ceo_* existantes (schéma potentiellement obsolète).
    ceo_tables="$(mycli -N -B "$COCKPIT_DB_NAME" \
      -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$COCKPIT_DB_NAME' AND table_name LIKE 'ceo\\_%';" 2>/dev/null || true)"
    ceo_n="$(printf '%s\n' "$ceo_tables" | grep -c . || true)"
    if [[ -n "$ceo_tables" ]]; then
      log "Suppression de $ceo_n table(s) ceo_* : $(printf '%s ' $ceo_tables)"
      {
        echo "SET FOREIGN_KEY_CHECKS=0;"
        while IFS= read -r t; do [[ -n "$t" ]] && echo "DROP TABLE IF EXISTS \`$t\`;"; done <<< "$ceo_tables"
        echo "SET FOREIGN_KEY_CHECKS=1;"
      } | mycli "$COCKPIT_DB_NAME" && log "DROP OK ($ceo_n)" || warn "DROP : erreur"
    else
      log "Aucune table ceo_* à supprimer."
    fi

    # b) (Re)chargement DÉTERMINISTE via le client mysql (parseur correct,
    #    mono-thread) — évite les courses de l'auto-installation applicative
    #    quand plusieurs requêtes arrivent en même temps. Équivaut à
    #    l'alternative manuelle de docs/DEPLOIEMENT.md (mysql < schema puis seed).
    log "Chargement de sql/schema.sql via mysql…"
    if mycli "$COCKPIT_DB_NAME" < "$TARGET_DIR/sql/schema.sql" 2>/tmp/ck_schema_err; then
      log "schema.sql chargé"
    else
      warn "échec schema.sql :"; sed -n '1,3p' /tmp/ck_schema_err
    fi
    log "Chargement de sql/seed.sql via mysql…"
    if mycli "$COCKPIT_DB_NAME" < "$TARGET_DIR/sql/seed.sql" 2>/tmp/ck_seed_err; then
      log "seed.sql chargé"
    else
      warn "échec seed.sql — première erreur :"; sed -n '1,3p' /tmp/ck_seed_err
    fi
    unset MYSQL_PWD
  fi
fi

# --- 6. Premier appel API (auto-installation) + recette locale -----------
log "Recette (loopback) — premier appel déclenche tables + seed…"
rc=0
for ep in meta stores pwa/reports; do
  echo "== GET ${LOCAL_BASE}/api/cockpit/${ep} =="
  if ! curl -fsS "${LOCAL_BASE}/api/cockpit/${ep}" | head -c 500; then
    rc=1; warn "Échec sur /api/cockpit/${ep}"
  fi
  echo
done

if [[ $rc -eq 0 ]]; then
  log "Recette OK. Cockpit disponible sur ${PUBLIC_BASE}/"
else
  warn "Recette en échec — vérifiez config/config.php (identifiants MySQL) et les logs Apache."
  # --- Diagnostic DB (lecture seule) sur échec ---------------------------
  DIAG_BIN="$(command -v mysql || command -v mariadb || true)"
  if [[ -n "$DIAG_BIN" && -n "$COCKPIT_DB_USER" && -n "$COCKPIT_DB_PASSWORD" ]]; then
    log "Diagnostic MySQL (version / sql_mode / SHOW CREATE ceo_project_task)…"
    MYSQL_PWD="$COCKPIT_DB_PASSWORD" "$DIAG_BIN" -h "$COCKPIT_DB_HOST" -P "$COCKPIT_DB_PORT" \
      -u "$COCKPIT_DB_USER" "$COCKPIT_DB_NAME" \
      -e "SELECT VERSION() AS version\G SELECT @@sql_mode AS sql_mode\G SHOW CREATE TABLE ceo_project_task\G" 2>&1 \
      | sed -n '1,60p' || warn "diag SQL indisponible"
  fi
fi
exit $rc
