#!/usr/bin/env bash
#
# Installe / met à jour l'ERP sur le serveur du cockpit, servi sous /production.
# Lancé EN ROOT par .github/workflows/deploy.yml, depuis la copie du dépôt
# transférée sur le serveur :   bash apps/production/deploy/server.sh
#
# - code : $ERP_TARGET/app ; seul app/public est exposé (Alias Apache)
# - base : $ERP_DB_NAME (« erp »), utilisateur dédié ; mot de passe généré au premier
#   passage et gardé dans $ERP_TARGET/app/.env (hors Git)
# - données : au premier passage seulement (base vide), import du dump déposé
#   dans $ERP_IMPORT_DIR (.zip, .sql ou .sql.gz). La copie est ensuite coupée
#   de l'extérieur (SMTP, KSeF, boutiques des franchisés) et ses comptes
#   reçoivent des mots de passe aléatoires, écrits dans un fichier root-only
#   du serveur — jamais dans les logs (le dépôt est public).
# - transfert : faute d'accès direct au serveur, le dump peut arriver par le
#   dépôt, CHIFFRÉ pour la clé publique de ce serveur (deploy/incoming/). La
#   clé privée reste dans $ERP_IMPORT_DIR et est détruite après l'import.
set -euo pipefail

ERP_TARGET="${ERP_TARGET:-/var/www/production}"
ERP_ALIAS="${ERP_ALIAS:-/production}"
ERP_URL="${ERP_URL:-http://185.180.206.46${ERP_ALIAS}}"
# Une seule base, « erp » (tables d'origine de l'ERP + tables n_* ajoutées) ; l'ancienne base
# erp_production des premiers passages y est déplacée (section 5). Le compte MySQL garde son nom.
ERP_DB_NAME="${ERP_DB_NAME:-erp}"
ERP_OLD_DB_NAME="${ERP_OLD_DB_NAME:-erp_production}"
ERP_DB_USER="${ERP_DB_USER:-erp_production}"
ERP_IMPORT_DIR="${ERP_IMPORT_DIR:-/root/erp_import}"
# Serveur de test : compte ouvert par un bouton « Entrer », sans mot de passe ERP.
# Accepté seulement derrière le mot de passe navigateur (deploy/acces_test.htpasswd), ou en bac à sable
# ouvert déclaré explicitement (ERP_SANDBOX_OUVERT=1 : sans mot de passe navigateur).
ERP_ENTREE_LIBRE="${ERP_ENTREE_LIBRE:-}"
ERP_SANDBOX_OUVERT="${ERP_SANDBOX_OUVERT:-}"
HTPASSWD_SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/acces_test.htpasswd"
HTPASSWD="/etc/apache2/production-erp.htpasswd"
APACHE_CONF="/etc/apache2/conf-available/production-erp.conf"

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$ERP_TARGET/app/.env"

log()  { printf '\033[1;34m[erp]\033[0m %s\n' "$*"; }
warn() { printf '::warning title=ERP::%s\n' "$*"; }
fail() { printf '::error title=ERP::%s\n' "$*"; exit 1; }
aptget() { DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=600 "$@"; }
installed() { dpkg-query -W -f='${Status}' "$1" 2>/dev/null | grep -q 'install ok installed'; }

# --- 1. PHP d'Apache et extensions -------------------------------------
# Le PHP en ligne de commande n'est pas forcément celui d'Apache (constaté ici :
# CLI 8.4, et php-mysql / php-gd signalés absents à chaque livraison du
# cockpit) : on vise la version qu'Apache charge réellement.
APV="$(ls /etc/apache2/mods-enabled 2>/dev/null | sed -n 's/^php\([0-9]\.[0-9]*\)\.load$/\1/p' | head -1)"
FPM=""
if [[ -z "$APV" ]]; then
  APV="$(ls /etc/apache2/conf-enabled 2>/dev/null | sed -n 's/^php\([0-9]\.[0-9]*\)-fpm\.conf$/\1/p' | head -1)"
  [[ -z "$APV" ]] || FPM="php${APV}-fpm"
fi
need=()
if [[ -n "$APV" ]]; then
  log "PHP d'Apache : ${APV}${FPM:+ (FPM)} ; PHP en ligne de commande : $(php -r 'echo PHP_VERSION;' 2>/dev/null || echo absent)"
  [[ "${APV//./}" -ge 81 ]] || fail "Apache utilise PHP ${APV} : l'ERP demande PHP 8.1 ou plus."
  for p in cli mysql mbstring curl gd zip xml; do
    installed "php${APV}-${p}" || need+=("php${APV}-${p}")
  done
else
  warn "Version de PHP d'Apache introuvable : contrôle sur le PHP en ligne de commande."
  php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' \
    || fail "PHP $(php -r 'echo PHP_VERSION;') sur le serveur : l'ERP demande PHP 8.1 ou plus."
  for pair in pdo_mysql:php-mysql mbstring:php-mbstring curl:php-curl gd:php-gd zip:php-zip \
              dom:php-xml simplexml:php-xml xmlreader:php-xml xmlwriter:php-xml; do
    php -m | grep -qix "${pair%%:*}" || need+=("${pair##*:}")
  done
fi
command -v unzip >/dev/null || need+=(unzip)
command -v pdftotext >/dev/null || need+=(poppler-utils)   # texte des factures PDF (Zaopatrzenie › Faktury)
if [[ ${#need[@]} -gt 0 ]]; then
  mapfile -t need < <(printf '%s\n' "${need[@]}" | sort -u)
  log "Paquets manquants : ${need[*]}"
  aptget update -qq || true
  aptget install -y -qq "${need[@]}" >/dev/null
  RESTART_APACHE=1
  NEW_PHP_EXT=1   # une extension n'est chargée qu'au redémarrage complet
fi
PHPBIN="php"
if [[ -n "$APV" ]] && command -v "php${APV}" >/dev/null; then PHPBIN="php${APV}"; fi

# --- 2. Refuser de livrer du code qui ne se charge pas -------------------
log "Contrôle de syntaxe avec le PHP d'Apache ($("$PHPBIN" -r 'echo PHP_VERSION;'))…"
bad="$(find "$SRC/app" -name '*.php' -not -path '*/vendor/*' -print0 \
        | xargs -0 -n 1 -P 4 "$PHPBIN" -l 2>&1 | grep -v '^No syntax errors' || true)"
[[ -z "$bad" ]] || { echo "$bad" | head -20; fail "Syntaxe PHP invalide : rien n'a été livré."; }
[[ -f "$SRC/app/public/vendor/autoload.php" ]] || fail "vendor/ absent de la livraison (composer install sur le runner)."

# --- 3. Fichiers ---------------------------------------------------------
log "Copie vers $ERP_TARGET/app…"
mkdir -p "$ERP_TARGET/app"
rsync -a --delete --exclude '/.env' --exclude '*.log' --exclude 'cron_log.txt' \
  "$SRC/app/" "$ERP_TARGET/app/"
# Szablony Twig z datą teraz : rsync zachowuje datę checkoutu na runnerze, starszą niż szablon
# skompilowany przez zapytanie w trakcie wdrożenia — auto_reload uznałby wtedy stary cache za aktualny.
find "$ERP_TARGET/app/App/Views" -type f -name '*.twig' -exec touch {} +
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
# Lu par phpdotenv, comme le fait l'ERP (valeurs échappées comprises).
envget() {
  "$PHPBIN" -r 'require $argv[1]; echo Dotenv\Dotenv::parse(file_get_contents($argv[2]))[$argv[3]] ?? "";' \
    "$ERP_TARGET/app/public/vendor/autoload.php" "$ENV_FILE" "$1"
}
dbpass="$(envget DB_PASS)"
[[ -n "$dbpass" ]] || fail "DB_PASS introuvable dans $ENV_FILE."

# --- 5. Base de données ---------------------------------------------------
MYSQL="$(command -v mysql || command -v mariadb || true)"
[[ -n "$MYSQL" ]] || fail "Client mysql introuvable sur le serveur."
# Compte d'administration : root par socket, sinon le compte de maintenance
# d'Ubuntu, sinon le compte MySQL du cockpit (config.php écrit par bin/deploy.sh).
COCKPIT_CFG="${COCKPIT_CFG:-/var/www/consulant_bo/config/config.php}"
C_USER=""; C_PASS=""
if "$MYSQL" -e 'SELECT 1' >/dev/null 2>&1; then
  ADMIN=("$MYSQL"); ADMIN_KIND="root (socket)"
elif [[ -r /etc/mysql/debian.cnf ]] && "$MYSQL" --defaults-file=/etc/mysql/debian.cnf -e 'SELECT 1' >/dev/null 2>&1; then
  ADMIN=("$MYSQL" --defaults-file=/etc/mysql/debian.cnf); ADMIN_KIND="debian-sys-maint"
elif [[ -r "$COCKPIT_CFG" ]]; then
  cdb() { "$PHPBIN" -r '$c = require $argv[1]; echo $c["db"][$argv[2]] ?? "";' "$COCKPIT_CFG" "$1"; }
  C_USER="$(cdb user)"; C_PASS="$(cdb password)"
  ADMIN=(env MYSQL_PWD="$C_PASS" "$MYSQL" -h "$(cdb host)" -P "$(cdb port)" -u "$C_USER")
  ADMIN_KIND="compte du cockpit (${C_USER})"
  "${ADMIN[@]}" -e 'SELECT 1' >/dev/null 2>&1 || fail "Le compte MySQL du cockpit (${C_USER}) ne se connecte pas."
else
  fail "Aucun accès administrateur à MySQL (root par socket, debian.cnf, config du cockpit) : créez la base ${ERP_DB_NAME} à la main."
fi
log "Accès MySQL : ${ADMIN_KIND}"

# Remplace (ou ajoute) une ligne CLE="valeur" du .env, valeur échappée pour phpdotenv.
set_env() {
  "$PHPBIN" -r '
    [$f, $k, $v] = [$argv[1], $argv[2], $argv[3]];
    $line = $k . "=\"" . addcslashes($v, "\"\\") . "\"";
    $s = file_get_contents($f);
    $s = preg_replace_callback("/^" . preg_quote($k, "/") . "=.*$/m", fn () => $line, $s, -1, $n);
    if (!$n) { $s = rtrim($s, "\n") . "\n" . $line . "\n"; }
    file_put_contents($f, $s);' "$ENV_FILE" "$1" "$2"
}

if [[ -n "$ERP_ENTREE_LIBRE" && ! -f "$HTPASSWD_SRC" && "$ERP_SANDBOX_OUVERT" != "1" ]]; then
  warn "ERP_ENTREE_LIBRE ignoré : pas de mot de passe navigateur (deploy/acces_test.htpasswd), l'ERP garde ses mots de passe."
  ERP_ENTREE_LIBRE=""
fi
[[ "$(envget ENTREE_LIBRE)" == "$ERP_ENTREE_LIBRE" ]] || set_env ENTREE_LIBRE "$ERP_ENTREE_LIBRE"
if [[ -n "$ERP_ENTREE_LIBRE" && ! -f "$HTPASSWD_SRC" ]]; then
  warn "Bac à sable ouvert : pas de mot de passe navigateur, le bouton « Entrer » ouvre l'ERP (compte ${ERP_ENTREE_LIBRE}) à quiconque a l'adresse."
elif [[ -n "$ERP_ENTREE_LIBRE" ]]; then
  log "Entrée : mot de passe navigateur, puis bouton « Entrer » (compte ${ERP_ENTREE_LIBRE})."
else
  log "Entrée : login et mot de passe de l'ERP."
fi

# Base unique « erp » : la base des passages précédents (erp_production) est déplacée telle quelle
# (RENAME TABLE : instantané, données, index et clés gardés), puis le .env pointe sur erp.
count_tables() { "${ADMIN[@]}" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$1'"; }
if [[ "$ERP_OLD_DB_NAME" != "$ERP_DB_NAME" ]]; then
  old_n="$(count_tables "$ERP_OLD_DB_NAME")"
  new_n="$(count_tables "$ERP_DB_NAME")"
  if [[ "$old_n" -gt 0 && "$new_n" -eq 0 ]]; then
    others="$("${ADMIN[@]}" -N -e "SELECT (SELECT COUNT(*) FROM information_schema.views WHERE table_schema='${ERP_OLD_DB_NAME}')
      + (SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema='${ERP_OLD_DB_NAME}')
      + (SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema='${ERP_OLD_DB_NAME}')")"
    [[ "$others" -eq 0 ]] || fail "Base ${ERP_OLD_DB_NAME} : vues, déclencheurs ou procédures présents, déplacement vers ${ERP_DB_NAME} à faire à la main."
    "${ADMIN[@]}" -e "CREATE DATABASE IF NOT EXISTS \`${ERP_DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;" \
      || fail "Le compte MySQL ${ADMIN_KIND} ne peut pas créer la base ${ERP_DB_NAME}."
    rename_sql="$("${ADMIN[@]}" -N -e "SET SESSION group_concat_max_len = 1000000;
      SELECT CONCAT('RENAME TABLE ', GROUP_CONCAT(CONCAT('\`${ERP_OLD_DB_NAME}\`.\`', table_name, '\` TO \`${ERP_DB_NAME}\`.\`', table_name, '\`') SEPARATOR ', '))
      FROM information_schema.tables WHERE table_schema='${ERP_OLD_DB_NAME}'")"
    "${ADMIN[@]}" -e "$rename_sql" || fail "Déplacement des tables de ${ERP_OLD_DB_NAME} vers ${ERP_DB_NAME} en échec (rien n'est déplacé : RENAME TABLE est atomique)."
    set_env DB_NAME "$ERP_DB_NAME"
    if [[ "$(count_tables "$ERP_OLD_DB_NAME")" -eq 0 ]]; then
      "${ADMIN[@]}" -e "DROP DATABASE \`${ERP_OLD_DB_NAME}\`;"
    fi
    log "Base ${ERP_OLD_DB_NAME} déplacée dans ${ERP_DB_NAME} (${old_n} tables, données gardées)."
  elif [[ "$old_n" -gt 0 ]]; then
    warn "Deux bases : ${ERP_OLD_DB_NAME} (${old_n} tables) et ${ERP_DB_NAME} (${new_n} tables) ; l'ERP utilise $(envget DB_NAME)."
  fi
fi
if [[ "$(envget DB_NAME)" != "$ERP_DB_NAME" && "$(count_tables "$(envget DB_NAME)")" -eq 0 ]]; then
  set_env DB_NAME "$ERP_DB_NAME"
fi

"${ADMIN[@]}" -e "CREATE DATABASE IF NOT EXISTS \`${ERP_DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;" \
  || fail "Le compte MySQL ${ADMIN_KIND} ne peut pas créer la base ${ERP_DB_NAME} : créez-la à la main puis relancez."
grant_err="$(mktemp)"
if [[ "$(envget DB_USER)" != "$ERP_DB_USER" ]]; then
  log "L'ERP utilise le compte MySQL $(envget DB_USER) (choisi à un passage précédent)."
elif "${ADMIN[@]}" 2>"$grant_err" <<SQL
CREATE USER IF NOT EXISTS '${ERP_DB_USER}'@'localhost' IDENTIFIED BY '${dbpass}';
CREATE USER IF NOT EXISTS '${ERP_DB_USER}'@'127.0.0.1' IDENTIFIED BY '${dbpass}';
ALTER USER '${ERP_DB_USER}'@'localhost' IDENTIFIED BY '${dbpass}';
ALTER USER '${ERP_DB_USER}'@'127.0.0.1' IDENTIFIED BY '${dbpass}';
GRANT ALL PRIVILEGES ON \`${ERP_DB_NAME}\`.* TO '${ERP_DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${ERP_DB_NAME}\`.* TO '${ERP_DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
then
  log "Utilisateur MySQL dédié : ${ERP_DB_USER}"
elif [[ -n "$C_USER" ]]; then
  warn "Le compte MySQL du cockpit ne peut pas créer d'utilisateur : l'ERP s'en sert directement (base ${ERP_DB_NAME} séparée)."
  set_env DB_USER "$C_USER"
  set_env DB_PASS "$C_PASS"
else
  cat "$grant_err"; rm -f "$grant_err"
  fail "Création de l'utilisateur MySQL ${ERP_DB_USER} refusée."
fi
rm -f "$grant_err"

# Transfert chiffré : clé privée générée ici, clé publique publiée dans le log.
mkdir -p "$ERP_IMPORT_DIR"
chmod 700 "$ERP_IMPORT_DIR"
TKEY="$ERP_IMPORT_DIR/cle_transfert.pem"
INCOMING="$SRC/deploy/incoming"

# Un import n'est réputé fait qu'une fois ce repère écrit : une base remplie à
# moitié (import interrompu) est vidée puis réimportée.
IMPORT_OK="$ERP_IMPORT_DIR/IMPORT_OK"
dumps=()
tables="$("${ADMIN[@]}" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${ERP_DB_NAME}'")"
dbver="$("${ADMIN[@]}" -N -e 'SELECT VERSION()')"
if [[ "$dbver" == *MariaDB* ]]; then IS_MYSQL=0; else IS_MYSQL=1; fi

# Sur MySQL, l'ERP garde le mode SQL de MariaDB 10.3, sous lequel il a été écrit
# (sans ONLY_FULL_GROUP_BY : /test-products casse sinon).
if [[ "$IS_MYSQL" == "1" && -z "$(envget DB_SQL_MODE)" ]]; then
  set_env DB_SQL_MODE "STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION"
  log "MySQL ${dbver} : mode SQL de MariaDB 10.3 pour l'ERP (DB_SQL_MODE)."
fi

if [[ "$tables" -gt 0 && -f "$IMPORT_OK" ]]; then
  log "Base ${ERP_DB_NAME} déjà remplie (${tables} tables) : aucun import."
else
  if [[ -f "$INCOMING/erp.zip.enc" && ! -f "$TKEY" ]]; then
    warn "Dump chiffré présent dans le dépôt, mais pour une clé que ce serveur n'a plus (déjà importé ?) : ignoré."
  elif [[ -f "$INCOMING/erp.zip.enc" && -f "$INCOMING/erp.key.enc" && -f "$INCOMING/erp.zip.sha256" ]]; then
    log "Déchiffrement du dump reçu par le dépôt…"
    ( umask 077
      openssl pkeyutl -decrypt -inkey "$TKEY" -pkeyopt rsa_padding_mode:oaep -pkeyopt rsa_oaep_md:sha256 \
        -in "$INCOMING/erp.key.enc" -out "$ERP_IMPORT_DIR/.k"
      openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass "file:$ERP_IMPORT_DIR/.k" \
        -in "$INCOMING/erp.zip.enc" -out "$ERP_IMPORT_DIR/erp_transfert.zip"
      shred -u "$ERP_IMPORT_DIR/.k" )
    echo "$(tr -d '[:space:]' < "$INCOMING/erp.zip.sha256")  $ERP_IMPORT_DIR/erp_transfert.zip" | sha256sum -c --quiet - \
      || { rm -f "$ERP_IMPORT_DIR/erp_transfert.zip"; fail "Dump déchiffré corrompu (empreinte SHA-256 différente)."; }
  fi
  shopt -s nullglob
  dumps=("$ERP_IMPORT_DIR"/*.zip "$ERP_IMPORT_DIR"/*.sql "$ERP_IMPORT_DIR"/*.sql.gz)
  shopt -u nullglob
  if [[ ${#dumps[@]} -eq 0 ]]; then
    if [[ ! -f "$TKEY" ]]; then
      ( umask 077; openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:4096 -out "$TKEY" 2>/dev/null )
    fi
    if [[ "$tables" -gt 0 ]]; then
      warn "Base ${ERP_DB_NAME} incomplète (import interrompu) et aucun dump disponible : renvoyez-le."
    else
      warn "Base ${ERP_DB_NAME} vide : déposez le dump dans ${ERP_IMPORT_DIR} sur le serveur, ou envoyez-le chiffré pour la clé publique ci-dessous."
    fi
    echo "=== CLE PUBLIQUE DE TRANSFERT ERP ==="
    openssl pkey -in "$TKEY" -pubout
    echo "=== FIN CLE PUBLIQUE ==="
  else
    dump="$(ls -t "${dumps[@]}" | head -1)"
    if [[ "$tables" -gt 0 ]]; then
      log "Import précédent incomplet (${tables} tables) : base ${ERP_DB_NAME} vidée avant réimport."
      "${ADMIN[@]}" -e "DROP DATABASE \`${ERP_DB_NAME}\`; CREATE DATABASE \`${ERP_DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
    fi
    # Dump MariaDB vers MySQL : deux syntaxes de valeurs par défaut à convertir.
    if [[ "$IS_MYSQL" == "1" ]]; then convert=(perl -p "$SRC/deploy/mariadb_vers_mysql.pl"); else convert=(cat); fi
    log "Import de $(basename "$dump") dans ${ERP_DB_NAME} (${dbver})…"
    case "$dump" in
      *.zip) unzip -p "$dump" '*.sql' | "${convert[@]}" | "${ADMIN[@]}" "$ERP_DB_NAME" ;;
      *.gz)  zcat "$dump" | "${convert[@]}" | "${ADMIN[@]}" "$ERP_DB_NAME" ;;
      *)     "${convert[@]}" < "$dump" | "${ADMIN[@]}" "$ERP_DB_NAME" ;;
    esac
    log "Import terminé : $("${ADMIN[@]}" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${ERP_DB_NAME}'") tables."

    # La copie ne doit rien envoyer à l'extérieur : ni e-mails aux clients, ni
    # factures à KSeF, ni accusés de réception aux boutiques des franchisés
    # (qui retireraient ces commandes à l'ERP en service).
    log "Coupure des intégrations externes de la copie…"
    "${ADMIN[@]}" "$ERP_DB_NAME" <<'SQL'
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
      "${ADMIN[@]}" "$ERP_DB_NAME" -e "UPDATE user SET password = '${upass}' WHERE id = ${uid};" < /dev/null
      printf '%s\t%s\n' "$ulogin" "$upass" >> "$pwfile"
    done < <("${ADMIN[@]}" -N "$ERP_DB_NAME" -e "SELECT id, login FROM user")
    log "Comptes de la copie : nouveaux mots de passe dans ${pwfile} (sur le serveur)."

    date -u +%FT%TZ > "$IMPORT_OK"
    mkdir -p "$ERP_IMPORT_DIR/importe"
    chmod 600 "$dump"
    mv "$dump" "$ERP_IMPORT_DIR/importe/"
    # Sans clé privée, le fichier chiffré resté dans l'historique Git est illisible.
    if [[ -f "$TKEY" ]]; then
      shred -u "$TKEY"
      log "Clé de transfert détruite."
    fi
  fi
fi

# --- 5b. Module « Gestion de production atelier » (tables n_*) ------------
# Migrations versionnées (jamais de schéma modifié à la main), secret des jetons JWT généré
# une fois sur le serveur, données de démonstration seulement si ERP_TFB_DEMO=1.
if [[ -f "$IMPORT_OK" ]]; then
  [[ -n "$(envget PRODUCTION_JWT_SECRET)" ]] || set_env PRODUCTION_JWT_SECRET "$(openssl rand -hex 32)"
  [[ -n "$(envget API_BASE_URL)" ]] || set_env API_BASE_URL "$ERP_URL"
  # Sauvegarde complète de la base avant d'appliquer des migrations en attente (certaines suppriment des copies de
  # sauvegarde de l'ERP d'origine ou des lignes orphelines : 074, 076). Lisible par root seulement, 5 dernières gardées.
  pending="$( cd "$ERP_TARGET/app" && "$PHPBIN" Bin/tfb_migrate.php plan | grep -c '^pending' || true )"
  if [[ "${pending:-0}" -gt 0 ]]; then
    DUMPBIN="$(command -v mysqldump || command -v mariadb-dump || true)"
    [[ -n "$DUMPBIN" ]] || fail "mysqldump introuvable : sauvegarde impossible, migrations non appliquées."
    DUMPER=()
    for a in "${ADMIN[@]}"; do [[ "$a" == "$MYSQL" ]] && DUMPER+=("$DUMPBIN") || DUMPER+=("$a"); done
    BACKUP_DIR="$ERP_IMPORT_DIR/sauvegardes"
    mkdir -p "$BACKUP_DIR" && chmod 700 "$BACKUP_DIR"
    backup="$BACKUP_DIR/${ERP_DB_NAME}_avant_migrations_$(date -u +%Y%m%d_%H%M%S).sql.gz"
    "${DUMPER[@]}" --single-transaction --no-tablespaces "$ERP_DB_NAME" | gzip > "$backup.part" \
      || { rm -f "$backup.part"; fail "Sauvegarde de ${ERP_DB_NAME} avant migrations en échec : migrations non appliquées."; }
    mv "$backup.part" "$backup" && chmod 600 "$backup"
    log "Sauvegarde avant ${pending} migration(s) : ${backup} ($(du -h "$backup" | cut -f1))."
    ls -1t "$BACKUP_DIR"/*_avant_migrations_*.sql.gz 2>/dev/null | tail -n +6 | xargs -r rm -f
  fi
  ( cd "$ERP_TARGET/app" && "$PHPBIN" Bin/tfb_migrate.php apply ) \
    || fail "Migrations du module atelier (migrations/tfb) en échec : voir le message ci-dessus."
  # Mots de passe des comptes : bcrypt (ceux encore en clair, idempotent ; même mot de passe pour se connecter).
  ( cd "$ERP_TARGET/app" && "$PHPBIN" Bin/hash_passwords.php ) \
    || fail "Chiffrement des mots de passe des comptes en échec : voir le message ci-dessus."
  # Prix magasin (marges, Belgique) : compte API du panel = le même que dans le cockpit consultant, recopié chiffré
  # dans la base de l'ERP (le mot de passe n'apparaît jamais dans les logs ; un compte saisi dans l'ERP est gardé).
  ( cd "$ERP_TARGET/app" && COCKPIT_CFG="$COCKPIT_CFG" "$PHPBIN" Bin/store_price_cockpit_account.php ) \
    || warn "Compte API du panel (prix magasin) : copie depuis le cockpit en échec — à saisir dans l'ERP (Ustawienia → Konto API panelu)."
  # E-mails : la copie contient de vraies adresses de clients, rien n'en part (log_only),
  # sauf choix explicite ERP_MAIL_MODE=redirect avec ERP_MAIL_REDIRECT_TO (recette).
  mail_mode="${ERP_MAIL_MODE:-log_only}"
  [[ "$mail_mode" == "redirect" || "$mail_mode" == "log_only" ]] || mail_mode="log_only"
  [[ "$(envget MAIL_DELIVERY_MODE)" == "$mail_mode" ]] || set_env MAIL_DELIVERY_MODE "$mail_mode"
  [[ "$mail_mode" != "redirect" ]] || set_env MAIL_REDIRECT_TO "${ERP_MAIL_REDIRECT_TO:-}"
  # Toutes les 5 minutes : événements → messages, puis file d'envoi (selon le mode ci-dessus) ;
  # boîtes mail des utilisateurs (Poczta) : nouveaux messages.
  cat > /etc/cron.d/production-erp-mail <<CRON
# ERP /production — e-mails automatiques (écrit par apps/production/deploy/server.sh)
*/5 * * * * www-data cd ${ERP_TARGET}/app && ${PHPBIN} Bin/mail_dispatch.php >> /var/log/production-erp-mail.log 2>&1
*/5 * * * * www-data cd ${ERP_TARGET}/app && ${PHPBIN} Bin/user_mail_sync.php >> /var/log/production-erp-mail.log 2>&1
CRON
  chmod 644 /etc/cron.d/production-erp-mail
  touch /var/log/production-erp-mail.log && chown www-data:www-data /var/log/production-erp-mail.log
  if [[ "${ERP_TFB_DEMO:-}" == "1" ]]; then
    ( cd "$ERP_TARGET/app" && "$PHPBIN" Bin/tfb_seed_demo.php ${ERP_ENTREE_LIBRE:+--grant-admin="$ERP_ENTREE_LIBRE"} ) \
      || fail "Données de démonstration du module atelier en échec."
  fi
fi

# --- 6. Apache : Alias /production --------------------------------------
if grep -rlsE "^\s*Alias\s+${ERP_ALIAS}(\s|/)" /etc/apache2/sites-enabled /etc/apache2/conf-enabled \
     | grep -v "production-erp.conf" | grep -q .; then
  fail "Un autre site Apache utilise déjà ${ERP_ALIAS} : rien n'a été modifié."
fi
# Mot de passe navigateur (Basic Auth) : seule son empreinte bcrypt est dans le dépôt.
# Le serveur lui-même (127.0.0.1) passe sans, pour la recette ci-dessous.
if [[ -f "$HTPASSWD_SRC" ]]; then
  install -m 640 -o root -g www-data "$HTPASSWD_SRC" "$HTPASSWD"
  a2enmod auth_basic authn_file authz_user >/dev/null 2>&1 || true
  # REDIRECT_ERP_AUTHED : renvoi interne vers index.php / api.php posé par nos RewriteRule (E=ERP_AUTHED),
  # qui ne s'exécutent qu'APRÈS le contrôle du mot de passe de ce répertoire. Sans lui, Apache revérifiait
  # le mot de passe (bcrypt) sur le renvoi : deux contrôles par page et par appel (mesuré : +0,4 s chacun).
  # Le client ne peut pas poser cette variable (en-têtes → HTTP_*) ; sans mot de passe, tout reste 401.
  ACCESS="    AuthType Basic
    AuthName \"ERP test\"
    AuthUserFile ${HTPASSWD}
    <RequireAny>
        Require ip 127.0.0.1 ::1
        Require env REDIRECT_ERP_AUTHED
        Require valid-user
    </RequireAny>"
else
  rm -f "$HTPASSWD"
  ACCESS="    Require all granted"
fi
newconf="$(mktemp)"
# Fichiers statiques (CSS, JS, polices, images, libellés JSON) gardés par le navigateur : une page en
# charge ~33, et chacun redemandé à chaque page repasse par le contrôle du mot de passe (bcrypt) —
# mesuré : 12 à 16 s par page, appels coupés (« Failed to fetch »). 5 min sans requête, puis la copie
# locale sert tout de suite pendant que le navigateur vérifie en arrière-plan.
a2enmod headers >/dev/null 2>&1 || true
CACHE="    <IfModule mod_headers.c>
        <FilesMatch \"\\.(css|js|mjs|json|woff2?|ttf|otf|eot|svg|png|jpe?g|gif|webp|ico)\$\">
            Header set Cache-Control \"private, max-age=300, stale-while-revalidate=86400\"
        </FilesMatch>
    </IfModule>"
cat > "$newconf" <<APACHE
# ERP (apps/production de consultant_BO) — écrit par apps/production/deploy/server.sh
Alias ${ERP_ALIAS} ${ERP_TARGET}/app/public
<Directory ${ERP_TARGET}/app/public>
    Options -Indexes +FollowSymLinks
    AllowOverride None
${ACCESS}
${CACHE}
    RewriteEngine On
    RewriteBase ${ERP_ALIAS}/
    RewriteRule ^api/(.*)$ api.php [QSA,L,E=ERP_AUTHED:1]
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.*)$ index.php?url=\$1 [L,QSA,E=ERP_AUTHED:1]
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
if [[ ! -f "$IMPORT_OK" ]]; then
  log "Recette sautée : la base n'est pas encore importée."
elif [[ "$code" == "200" ]] && grep -qE 'name="password"|>Entrer<' "$body"; then
  code2="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1${ERP_ALIAS}/client-orders" || true)"
  [[ "$code2" == "302" ]] || fail "${ERP_ALIAS}/client-orders sans session répond HTTP ${code2} (302 attendu)."
  if [[ -n "$ERP_ENTREE_LIBRE" ]]; then
    jar="$(mktemp)"
    code3="$(curl -s -c "$jar" -b "$jar" -o /dev/null -w '%{http_code}' -X POST "http://127.0.0.1${ERP_ALIAS}/" || true)"
    code4="$(curl -s -b "$jar" -o /dev/null -w '%{http_code}' "http://127.0.0.1${ERP_ALIAS}/client-orders" || true)"
    rm -f "$jar"
    [[ "$code3" == "302" && "$code4" == "200" ]] \
      || fail "Bouton « Entrer » : POST ${code3} puis /client-orders ${code4} (302 puis 200 attendus)."
    log "Recette OK : le bouton « Entrer » ouvre l'ERP."
  fi
  # API du module atelier : description publique, refus sans jeton, connexion puis lecture.
  api="http://127.0.0.1${ERP_ALIAS}/api/v1/production"
  c1="$(curl -s -o /dev/null -w '%{http_code}' "$api/openapi.json" || true)"
  c2="$(curl -s -o /dev/null -w '%{http_code}' "$api/referentiels/groups" || true)"
  [[ "$c1" == "200" && "$c2" == "401" ]] || fail "API atelier : openapi.json ${c1} (200 attendu), groupes sans jeton ${c2} (401 attendu)."
  pwfile="$ERP_IMPORT_DIR/MOTS_DE_PASSE_ERP.txt"
  if [[ -n "$ERP_ENTREE_LIBRE" && "${ERP_TFB_DEMO:-}" == "1" && -f "$pwfile" ]]; then
    upass="$(awk -F '\t' -v l="$ERP_ENTREE_LIBRE" '$1 == l {print $2; exit}' "$pwfile")"
    login_json="$("$PHPBIN" -r 'echo json_encode(["login" => $argv[1], "password" => $argv[2]]);' "$ERP_ENTREE_LIBRE" "$upass")"
    token="$(curl -s -X POST -H 'Content-Type: application/json' --data-binary @- "$api/auth/login" <<<"$login_json" \
      | "$PHPBIN" -r 'echo json_decode(stream_get_contents(STDIN), true)["data"]["access_token"] ?? "";')"
    [[ -n "$token" ]] || fail "API atelier : connexion du compte ${ERP_ENTREE_LIBRE} refusée."
    groups="$(curl -s -H "X-Access-Token: $token" "$api/referentiels/groups" \
      | "$PHPBIN" -r 'echo count(json_decode(stream_get_contents(STDIN), true)["data"] ?? []);')"
    [[ "$groups" -ge 6 ]] || fail "API atelier : ${groups} groupe(s) lu(s), 6 attendus."
    log "Recette OK : API atelier (connexion JWT, ${groups} groupes de production)."
  fi
  log "Recette OK : page de connexion servie, pages protégées. ERP disponible sur ${ERP_URL}/"
else
  head -c 600 "$body"; echo
  fail "${ERP_ALIAS}/ répond HTTP ${code} sans page de connexion."
fi
rm -f "$body"

# --- 8. Diagnostic de performance (lecture seule, n'arrête jamais la livraison) ----
# CPU, mémoire, OPcache du PHP d'Apache, mémoire MySQL et temps de génération de quelques pages lourdes mesurés sur
# le serveur lui-même (sans le réseau), pour suivre l'effet des index et des réécritures de requêtes.
perf_diag() {
  log "Diagnostic performance : $(nproc 2>/dev/null || echo '?') CPU, charge $(cut -d' ' -f1-3 /proc/loadavg 2>/dev/null || echo '?'), mémoire $(free -m 2>/dev/null | awk '/^Mem:/ {print $3 " / " $2 " Mo utilisés, " $7 " Mo disponibles"}')."
  local ini="/etc/php/${APV:-x}/apache2"
  [[ -d "$ini" ]] || ini="/etc/php/${APV:-x}/fpm"
  local oc ocmod="absent"
  oc="$(grep -hsE '^\s*opcache\.(enable|memory_consumption|validate_timestamps|revalidate_freq|max_accelerated_files)\s*=' "$ini/php.ini" "$ini"/conf.d/*.ini 2>/dev/null | tr -s ' ' | paste -sd ';' - || true)"
  ls "$ini"/conf.d/ 2>/dev/null | grep -q opcache && ocmod="chargé"
  log "PHP ${APV:-?} (Apache) : module OPcache ${ocmod} ; réglages : ${oc:-valeurs par défaut}."
  log "Swap : $(free -m 2>/dev/null | awk '/^Swap:/ {print $3 " / " $2 " Mo utilisés"}')."
  local my
  my="$("${ADMIN[@]}" -N -e "SELECT CONCAT('MySQL ', @@version, ' : buffer pool ', ROUND(@@innodb_buffer_pool_size/1048576), ' Mo, tmp_table ', ROUND(@@tmp_table_size/1048576), ' Mo, flush_log_at_trx_commit ', @@innodb_flush_log_at_trx_commit, ', base ${ERP_DB_NAME} ', (SELECT ROUND(SUM(data_length + index_length)/1048576) FROM information_schema.tables WHERE table_schema = '${ERP_DB_NAME}'), ' Mo, toutes les bases ', (SELECT ROUND(SUM(data_length + index_length)/1048576) FROM information_schema.tables), ' Mo')" 2>/dev/null || true)"
  [[ -n "$my" ]] && log "$my"
  # Temps SQL pur (sans PHP) de trois agrégats lourds : ventes WDT 18 mois par mois et produit, stock par état,
  # registre des mouvements — pour séparer le coût de la base de celui du calcul PHP.
  local q t0 label out=""
  for label in ventes stock registre; do
    case "$label" in
      ventes) q="SELECT LEFT(w.established_date, 7) AS ym, p.id_product, SUM(p.quantity), MAX(CAST(p.name AS CHAR(255))) FROM document_wdt w JOIN document_wdt_product p ON p.id_document_wdt = w.id WHERE w.established_date >= CURDATE() - INTERVAL 18 MONTH GROUP BY ym, p.id_product" ;;
      stock) q="SELECT id_product, COUNT(*) FROM carton_stock WHERE status IN (1, 2) GROUP BY id_product" ;;
      registre) q="SELECT id_product, SUM(date_add < CURDATE() - INTERVAL 30 DAY), SUM(status = 3 AND date_pallet >= CURDATE() - INTERVAL 30 DAY) FROM carton_stock GROUP BY id_product" ;;
    esac
    t0="$(date +%s%N)"
    "${ADMIN[@]}" -N "${ERP_DB_NAME}" -e "$q" >/dev/null 2>&1 || true
    out+=" ${label} $(( ($(date +%s%N) - t0) / 1000000 )) ms ;"
  done
  log "Temps SQL pur :${out%;}"
  if [[ -n "$ERP_ENTREE_LIBRE" ]]; then
    local jar p t out=""
    jar="$(mktemp)"
    curl -s -c "$jar" -b "$jar" -o /dev/null -X POST "http://127.0.0.1${ERP_ALIAS}/" || true
    for p in /today /dashboard /dashboard/monthly /finances/sales-analytics /workshop/stock-movements /purchasing /finances/clients /client-orders; do
      t="$(curl -s -b "$jar" -o /dev/null -w '%{time_starttransfer}' "http://127.0.0.1${ERP_ALIAS}${p}" || echo '?')"
      out+=" ${p} ${t} s ;"
    done
    rm -f "$jar"
    log "Temps serveur des pages (sans réseau) :${out%;}"
  fi
}
perf_diag || true
