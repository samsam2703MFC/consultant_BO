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
      "seed"    => false,   // base vide par défaut ; pas de données de démo
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

# --- 5b. Opérations base de données (client MySQL) -----------------------
# Deux modes, mutuellement exclusifs, pilotés par le workflow :
#   COCKPIT_WIPE=1  → drop des tables ceo_*, recréation du schéma VIDE, aucun
#                     jeu de démonstration (base prête pour les vraies données).
#   COCKPIT_RESET=1 → drop + schéma + jeu de démonstration (tables ceo_*).
# Dans les deux cas, les tables du panel (of_tag/kpi/position/mac_report_share,
# non préfixées ceo_) ne sont JAMAIS touchées.
if [[ "${COCKPIT_RESET:-0}" == "1" || "${COCKPIT_WIPE:-0}" == "1" ]]; then
  if [[ "${COCKPIT_WIPE:-0}" == "1" ]]; then
    log "COCKPIT_WIPE=1 — remise à zéro : drop des tables ceo_*, base laissée VIDE (aucune démo)."
  else
    log "COCKPIT_RESET=1 — réinstallation des tables ceo_* (schéma + jeu de démonstration)."
  fi
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

    # a bis) Nettoyage : partages de démonstration (tok_demo_*) éventuellement
    #        injectés par erreur dans la table mac_report_share du panel par un
    #        seed antérieur. On ne supprime QUE les tokens de démo.
    del="$(mycli -N -B "$COCKPIT_DB_NAME" \
      -e "DELETE FROM mac_report_share WHERE token LIKE 'tok\\_demo\\_%'; SELECT ROW_COUNT();" 2>/dev/null || true)"
    if [[ -n "$del" && "$del" != "0" ]]; then
      log "mac_report_share : $del partage(s) de démonstration supprimé(s)."
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
    # c) Jeu de démonstration — UNIQUEMENT en mode RESET. En mode WIPE, la base
    #    reste vide (schéma seul), prête pour les vraies données.
    if [[ "${COCKPIT_WIPE:-0}" == "1" ]]; then
      log "WIPE : aucune donnée de démonstration chargée (base ceo_* vide)."
    elif [[ "${COCKPIT_SEED_PANEL:-0}" == "1" ]]; then
      # seed.sql contient 26 INSERT dans des tables PARTAGÉES du panel
      # (of_tag/kpi/position) puis le jeu de démo des tables ceo_*.
      log "Chargement du seed COMPLET (y compris tables partagées du panel, sql_mode relâché)…"
      if mycli --init-command="SET SESSION sql_mode=''" "$COCKPIT_DB_NAME" < "$TARGET_DIR/sql/seed.sql" 2>/tmp/ck_seed_err; then
        log "seed.sql chargé (complet)"
      else
        warn "échec seed.sql — première erreur :"; sed -n '1,3p' /tmp/ck_seed_err
      fi
    else
      log "Chargement du seed cockpit uniquement (tables ceo_*, panel non modifié)…"
      if grep -vE '^INSERT INTO (of_tag|kpi|position|mac_report_share)[ (]' "$TARGET_DIR/sql/seed.sql" \
           | mycli "$COCKPIT_DB_NAME" 2>/tmp/ck_seed_err; then
        log "seed.sql chargé (ceo_* uniquement)"
      else
        warn "échec seed.sql — première erreur :"; sed -n '1,3p' /tmp/ck_seed_err
      fi
    fi
    unset MYSQL_PWD
  fi
fi

# --- 5c. Inspection lecture seule de atelierby_db (COCKPIT_DBINSPECT=1) ---
# Sert à cartographier les tables partagées du panel que le cockpit lira
# (shops, mac_*, of_tag, kpi…). Aucune écriture. Échantillons limités aux
# tables de référence/figures (pas de PII : ni user_profile ni transaction).
if [[ "${COCKPIT_DBINSPECT:-0}" == "1" ]]; then
  log "COCKPIT_DBINSPECT=1 — inspection lecture seule de $COCKPIT_DB_NAME…"
  DBI_BIN="$(command -v mysql || command -v mariadb || true)"
  if [[ -n "$DBI_BIN" && -n "$COCKPIT_DB_USER" && -n "$COCKPIT_DB_PASSWORD" ]]; then
    export MYSQL_PWD="$COCKPIT_DB_PASSWORD"
    q() { "$DBI_BIN" -h "$COCKPIT_DB_HOST" -P "$COCKPIT_DB_PORT" -u "$COCKPIT_DB_USER" "$COCKPIT_DB_NAME" "$@"; }
    echo "===== TABLES (hors ceo_) : nom + lignes ====="
    q -N -e "SELECT table_name, table_rows FROM information_schema.tables WHERE table_schema='$COCKPIT_DB_NAME' AND table_name NOT LIKE 'ceo\\_%' ORDER BY table_name;" 2>&1
    for t in shops mac_shop_monthly_pnl mac_kpi_threshold mac_consultant_param of_tag kpi position mac_report_share transaction transaction_product user_membership user_profile sig_products sig_product_categories sig_product_prices; do
      echo "===== $t ====="
      if q -N -e "SELECT 1 FROM \`$t\` LIMIT 1;" >/dev/null 2>&1; then
        echo "-- COUNT --"; q -N -e "SELECT COUNT(*) FROM \`$t\`;" 2>&1
        echo "-- COLONNES --"; q -N -e "SHOW COLUMNS FROM \`$t\`;" 2>&1 | awk '{print "   "$1" "$2}'
      else
        echo "  (table absente)"
      fi
    done
    echo "===== ÉCHANTILLONS (référence / figures, non-PII) ====="
    for t in mac_consultant_param mac_kpi_threshold of_tag kpi; do
      echo "-- $t (jusqu'à 25) --"; q -e "SELECT * FROM \`$t\` LIMIT 25;" 2>&1
    done
    echo "-- mac_shop_monthly_pnl (5 lignes) --"; q -e "SELECT * FROM mac_shop_monthly_pnl ORDER BY year DESC, month DESC LIMIT 5;" 2>&1
    echo "-- shops (5 lignes) --"; q -e "SELECT * FROM shops LIMIT 5;" 2>&1
    echo "-- sig_products (3 lignes) --"; q -e "SELECT * FROM sig_products LIMIT 3;" 2>&1
    echo "-- sig_product_categories (10) --"; q -e "SELECT * FROM sig_product_categories LIMIT 10;" 2>&1
    echo "-- consultants : user_membership(app=CONSULTANT) ⨝ user_profile --"
    q -e "SELECT m.id, m.app, m.scope_type, m.scope_id, m.is_active, p.display_name, p.email
          FROM user_membership m LEFT JOIN user_profile p ON p.auth_user_id = m.auth_user_id
          WHERE m.app = 'CONSULTANT' LIMIT 20;" 2>&1
    echo "-- transaction : bornes de dates + agrégat mensuel (1 magasin) --"
    q -N -e "SELECT MIN(insert_timestamp), MAX(insert_timestamp) FROM transaction;" 2>&1
    q -e "SELECT id_shop, YEAR(insert_timestamp) y, MONTH(insert_timestamp) m,
                 COUNT(DISTINCT ticket_key) tickets, ROUND(SUM(total_gross_amount_after_discount),2) ca
          FROM transaction WHERE insert_timestamp >= '2026-06-01'
          GROUP BY id_shop, y, m ORDER BY id_shop, y, m LIMIT 12;" 2>&1
    echo "-- transaction_product : top produits (volume) --"
    q -e "SELECT id_product, MAX(product_name) nom, ROUND(SUM(quantity)) vol,
                 ROUND(AVG(unit_gross_price),2) prix
          FROM transaction_product GROUP BY id_product ORDER BY vol DESC LIMIT 8;" 2>&1
    unset MYSQL_PWD
  else
    warn "inspection impossible (client mysql ou identifiants absents)."
  fi
fi

# --- 5d. Inspection de l'API amont du panel (COCKPIT_APIINSPECT=1) -------
# Le panel lit les tâches/checklists (noms, photos, notes) sur son API amont
# `/api/v1`, pas dans la base partagée. Pour que le cockpit affiche la MÊME
# chose, il faut savoir : quelle base d'URL le panel utilise réellement sur ce
# serveur, et si elle répond DEPUIS le serveur (elle est injoignable de
# l'extérieur). Lecture seule, secrets masqués.
if [[ "${COCKPIT_APIINSPECT:-0}" == "1" ]]; then
  log "COCKPIT_APIINSPECT=1 — découverte de l'API amont du panel…"
  # `set -e` est actif : un grep sans résultat renvoie 1 et tuerait le script.
  # Toute commande de ce bloc est donc neutralisée par « || true ».
  PANEL_DIR=""
  for d in /var/www/app/pwa_consultant /var/www/pwa_consultant /var/www/html/pwa_consultant /var/www/html /var/www; do
    if [[ -f "$d/config/app.php" ]]; then PANEL_DIR="$d"; break; fi
    if [[ -f "$d/pwa_consultant/config/app.php" ]]; then PANEL_DIR="$d/pwa_consultant"; break; fi
  done
  echo "===== EMPLACEMENT DU PANEL ====="
  if [[ -n "$PANEL_DIR" ]]; then echo "  $PANEL_DIR"; else
    echo "  introuvable — recherche large :"; find /var/www -maxdepth 5 -name app.php -path '*config*' 2>/dev/null | head -5 || true
  fi
  echo "===== CONSULTANT_API_BASE (env / .htaccess / vhost) ====="
  { grep -rhi "CONSULTANT_API_BASE" \
       "${PANEL_DIR:-/var/www}/public/.htaccess" "${PANEL_DIR:-/var/www}/.htaccess" \
       "${PANEL_DIR:-/var/www}/.env" \
       /etc/apache2/sites-enabled/ /etc/apache2/conf-enabled/ 2>/dev/null \
     | sed 's/[[:space:]]\+/ /g' | head -10; } || true
  echo "  (vide ci-dessus = non défini → défaut : même origine + /api/v1)"
  echo "===== .env du panel : CLÉS uniquement (valeurs masquées) ====="
  { sed -n 's/^\([A-Z_]*\)=.*/  \1=<masqué>/p' "${PANEL_DIR:-/var/www}/.env" 2>/dev/null | head -30; } || true
  echo "===== VHOSTS (ServerName / DocumentRoot / Alias / Proxy) ====="
  { grep -rhi "ServerName\|ServerAlias\|DocumentRoot\|Alias \|ProxyPass" /etc/apache2/sites-enabled/ 2>/dev/null \
     | sed 's/^[[:space:]]*//' | head -40; } || true
  echo "===== L'API RÉPOND-ELLE DEPUIS LE SERVEUR ? ====="
  for base in "http://127.0.0.1/api/v1" "http://localhost/api/v1" "http://127.0.0.1:8080/api/v1" "http://127.0.0.1:3000/api/v1"; do
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 8 -X POST "$base/consultant/auth/login" -H 'Content-Type: application/json' -d '{}' 2>/dev/null || echo "000")
    echo "  POST $base/consultant/auth/login → HTTP $code"
  done
  echo "===== ROUTAGE /api : dossiers candidats ====="
  { find /var/www -maxdepth 4 -type d -name "api*" 2>/dev/null | head -10; } || true
  echo "===== SERVICES A L'ECOUTE (ports) ====="
  { (ss -ltnp 2>/dev/null || netstat -ltnp 2>/dev/null) | head -20; } || true
  echo "===== JOURNAL APACHE (dernières erreurs) ====="
  { tail -n 60 /var/log/apache2/error.log 2>/dev/null | grep -i "503\|proxy\|api\|unavailable" | tail -12; } || true
  echo "===== FIN APIINSPECT ====="
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

# --- 6 bis. Câblage des bases : QUI est branché sur QUOI ------------------
# Le cockpit vit dans la base du panel : il crée ses tables `ceo_*` et LIT des
# tables qui ne lui appartiennent pas. Quand l'une d'elles manque, le code
# l'attrape et continue en silence — c'est exactement ce qui a caché le budget
# jamais relu. Ce rapport s'imprime à CHAQUE déploiement, pas seulement sur
# échec : une dépendance absente doit se voir avant de produire des écrans
# vides plutôt qu'une erreur.
WIRE_BIN="$(command -v mysql || command -v mariadb || true)"
if [[ -n "$WIRE_BIN" && -n "$COCKPIT_DB_USER" && -n "$COCKPIT_DB_PASSWORD" ]]; then
  log "Câblage des bases — tables partagées et tables du cockpit :"
  MYSQL_PWD="$COCKPIT_DB_PASSWORD" "$WIRE_BIN" -h "$COCKPIT_DB_HOST" -P "$COCKPIT_DB_PORT" \
    -u "$COCKPIT_DB_USER" "$COCKPIT_DB_NAME" -N -B -e "
    SELECT CONCAT(
             RPAD(t.nom, 24, ' '), ' ',
             RPAD(t.role, 10, ' '), ' ',
             IF(i.TABLE_NAME IS NULL, 'ABSENTE', 'présente')
           ) AS ligne
      FROM (
        SELECT 'mac_shop_monthly_pnl' nom, 'partagée' role UNION ALL
        SELECT 'transaction',           'partagée' UNION ALL
        SELECT 'transaction_product',   'partagée' UNION ALL
        SELECT 'sig_products',          'partagée' UNION ALL
        SELECT 'sig_product_categories','partagée' UNION ALL
        SELECT 'user_membership',       'partagée' UNION ALL
        SELECT 'user_profile',          'partagée' UNION ALL
        SELECT 'of_tag',                'partagée' UNION ALL
        SELECT 'kpi',                   'partagée' UNION ALL
        SELECT 'position',              'partagée' UNION ALL
        SELECT 'mac_report_share',      'partagée' UNION ALL
        SELECT 'ceo_shop',              'cockpit'  UNION ALL
        SELECT 'ceo_shop_month_perf',   'cockpit'  UNION ALL
        SELECT 'ceo_project_task',      'cockpit'  UNION ALL
        SELECT 'ceo_task_issue',        'cockpit'  UNION ALL
        SELECT 'ceo_app_setting',       'cockpit'
      ) t
      LEFT JOIN information_schema.TABLES i
        ON i.TABLE_SCHEMA = DATABASE() AND i.TABLE_NAME = t.nom
     ORDER BY t.role DESC, t.nom;" 2>&1 | sed 's/^/    /' || warn "câblage : lecture impossible"

  # Ce que le cockpit a réellement sous la main, en volumes. Une table présente
  # mais vide ne produit pas d'erreur non plus — juste un écran à zéro.
  log "Volumes :"
  MYSQL_PWD="$COCKPIT_DB_PASSWORD" "$WIRE_BIN" -h "$COCKPIT_DB_HOST" -P "$COCKPIT_DB_PORT" \
    -u "$COCKPIT_DB_USER" "$COCKPIT_DB_NAME" -N -B -e "
    SELECT CONCAT('magasins            : ', COUNT(*)) FROM ceo_shop UNION ALL
    SELECT CONCAT('tâches              : ', COUNT(*)) FROM ceo_project_task UNION ALL
    SELECT CONCAT('  dont validées     : ', COUNT(*)) FROM ceo_project_task WHERE note IS NOT NULL UNION ALL
    SELECT CONCAT('signalements ouverts: ', COUNT(*)) FROM ceo_task_issue WHERE closed_at IS NULL UNION ALL
    SELECT CONCAT('mois budgétés       : ', COUNT(*)) FROM ceo_shop_month_perf WHERE revenue_budget IS NOT NULL;" 2>&1 \
    | sed 's/^/    /' || warn "volumes : lecture impossible"
fi

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
