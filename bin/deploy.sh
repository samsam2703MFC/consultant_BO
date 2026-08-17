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
# mod_headers porte l'en-tete de revalidation des fichiers JS/CSS : sans lui le
# navigateur garde l'ancienne version apres une livraison.
a2enmod headers >/dev/null 2>&1 || true

# --- 1 bis. Syntaxe : refuser de livrer du code qui ne se charge pas -----
# Le navigateur charge assets/js/*.js comme des MODULES ES. `node --check` sur
# un fichier .js le lit comme un script, mode dans lequel il a déjà laissé
# passer une erreur qui rendait l'application muette en ligne (un accent grave
# dans un commentaire, DANS un littéral de gabarit : il refermait le littéral).
# On vérifie donc en mode module — la copie en .mjs est ce qui l'impose — et on
# arrête le déploiement plutôt que de servir un écran de chargement infini.
if command -v node >/dev/null 2>&1; then
  log "Vérification de la syntaxe des modules JS…"
  jstmp="$(mktemp -d)"
  jsko=0
  for f in "$REPO_SRC"/public/assets/js/*.js; do
    cp "$f" "$jstmp/$(basename "${f%.js}").mjs"
    if ! node --check "$jstmp/$(basename "${f%.js}").mjs"; then
      warn "SYNTAXE : $(basename "$f") ne se charge pas comme module ES."
      jsko=1
    fi
  done
  rm -rf "$jstmp"
  if [[ $jsko -ne 0 ]]; then
    warn "Déploiement interrompu : corrigez la syntaxe avant de livrer."
    exit 1
  fi
  log "Modules JS : syntaxe correcte."
else
  warn "node absent : syntaxe JS non vérifiée avant livraison."
fi
for f in "$REPO_SRC"/src/*.php "$REPO_SRC"/public/api/*.php; do
  php -l "$f" >/dev/null || { warn "SYNTAXE PHP : $f"; exit 1; }
done

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
    echo "===== RECHERCHE : perte / casse / stock / attributs produit ====="
    q -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$COCKPIT_DB_NAME'
             AND (table_name REGEXP 'wast|loss|perte|casse|discard|unsold|invendu|shrink|stock|inventor|product') ORDER BY table_name;" 2>&1
    echo "-- colonnes evoquant une perte / un cout / une presence --"
    q -N -e "SELECT CONCAT(table_name,'.',column_name) FROM information_schema.columns WHERE table_schema='$COCKPIT_DB_NAME'
             AND (column_name REGEXP 'wast|loss|perte|casse|discard|unsold|shrink|cost|cout|labour|labor|counter|comptoir|display|image');" 2>&1 | head -60
    echo "===== TABLES (hors ceo_) : nom + lignes ====="
    q -N -e "SELECT table_name, table_rows FROM information_schema.tables WHERE table_schema='$COCKPIT_DB_NAME' AND table_name NOT LIKE 'ceo\\_%' ORDER BY table_name;" 2>&1
    for t in product_category_group_connection product_recipe recipe_cost product_recipe_material_connection product_movement material_movement_reason shop_product product_portion product_positioning product_storage; do
      echo "===== $t ====="
      if q -N -e "SELECT 1 FROM \`$t\` LIMIT 1;" >/dev/null 2>&1; then
        echo "-- COUNT --"; q -N -e "SELECT COUNT(*) FROM \`$t\`;" 2>&1
        echo "-- COLONNES --"; q -N -e "SHOW COLUMNS FROM \`$t\`;" 2>&1 | awk '{print "   "$1" "$2}'
      else
        echo "  (table absente)"
      fi
    done
    echo "===== CATALOGUE PRODUIT : échantillons ====="
    for t in product_category_group_connection product_recipe recipe_cost product_recipe_material_connection material_movement_reason; do
      echo "-- $t (8 lignes) --"; q -e "SELECT * FROM \`$t\` LIMIT 8;" 2>&1
    done
    echo "-- product_movement (8 lignes récentes) --"; q -e "SELECT * FROM product_movement ORDER BY id DESC LIMIT 8;" 2>&1
    echo "-- shop_product (8 lignes) --"; q -e "SELECT * FROM shop_product LIMIT 8;" 2>&1
    # Le coût matière décide de la « marge nette » du scoring : sans lui le
    # critère vaut zéro pour tout le monde. Combien de produits en portent un ?
    echo "-- couverture du coût matière (product ⨝ product_recipe ⨝ recipe_cost) --"
    q -e "SELECT COUNT(*) produits, COUNT(p.id_recipe) avec_recette FROM product p WHERE p.is_active = 1;" 2>&1
    echo "-- recipe_cost : bornes --"; q -e "SELECT COUNT(*) n FROM recipe_cost;" 2>&1
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
  # Quels endpoints le panel appelle-t-il RÉELLEMENT ? Plutôt que de deviner
  # les chemins un par un, on les lit dans son code. C'est la liste qui fait
  # foi : elle dit ce qui existe, et sous quelle écriture exacte.
  echo "===== ENDPOINTS APPELÉS PAR LE PANEL (extraits de son code) ====="
  { grep -rhoE "'/(v1/)?[a-z0-9][a-z0-9._/{}$-]{2,80}'" "${PANEL_DIR:-/var/www}/src" "${PANEL_DIR:-/var/www}/app" \
       "${PANEL_DIR:-/var/www}/public" "${PANEL_DIR:-/var/www}/lib" 2>/dev/null \
     | tr -d "'" | grep -vE '\.(php|css|js|png|jpg|svg|ico|woff|map)$' \
     | sort -u | head -120; } || true
  echo "===== APPELS HTTP DU PANEL (méthode + chemin) ====="
  { grep -rhoE "(get|post|put|patch|delete|request)\(\s*'[^']+'" \
       "${PANEL_DIR:-/var/www}/src" "${PANEL_DIR:-/var/www}/app" "${PANEL_DIR:-/var/www}/lib" 2>/dev/null \
     | sed "s/[[:space:]]\+/ /g" | sort -u | head -80; } || true
  echo "===== FICHIERS DU PANEL QUI PARLENT À L'API ====="
  { grep -rlE "api/v1|CONSULTANT_API_BASE|apiGet|apiPost" \
       "${PANEL_DIR:-/var/www}/src" "${PANEL_DIR:-/var/www}/app" "${PANEL_DIR:-/var/www}/lib" 2>/dev/null | head -25; } || true
  # Forme réelle des endpoints qui portent le P&L. On les interroge avec le
  # compte consultant configuré, DEPUIS le serveur (l'API n'est pas joignable
  # de l'extérieur). Seules les CLÉS et un échantillon tronqué sont affichés :
  # ces réponses peuvent contenir des données de boutique, pas de la doc.
  echo "===== FORME DES ENDPOINTS DU P&L ====="
  php -r '
    // `setting()` vit dans endpoints.php : sans lui, PanelApi::config() lève
    // « fonction indefinie » et la sonde meurt avant le premier appel.
    require "'"$TARGET_DIR"'/src/Db.php"; require "'"$TARGET_DIR"'/src/endpoints.php";
    require "'"$TARGET_DIR"'/src/installer.php"; require "'"$TARGET_DIR"'/src/writes.php";
    require "'"$TARGET_DIR"'/src/panel_api.php";
    if (!PanelApi::configured()) { echo "  compte consultant non configuré — sonde impossible\n"; exit; }
    $shop = 2; $jour = date("Y-m-d", strtotime("2026-07-14"));
    $cibles = [
      "/statistics/sales/kpis?shop_id=$shop&date=$jour",
      "/statistics/sales/kpis?id_shop=$shop&date=$jour",
      "/pnl?shop_id=$shop&date=$jour",
      "/labour/daily?shop_id=$shop&date=$jour",
      "/consultant/dashboard?date=$jour",
      "/consultant/shops",
      "/consultant/network/shops/summary",
      "/trends/data?shop_id=$shop",
      "/targets",
    ];
    foreach ($cibles as $p) {
      $r = PanelApi::brut($p);
      if ($r === null) { printf("  %-52s → %s\n", $p, PanelApi::$lastError ?: "vide"); continue; }
      if (!is_array($r)) { printf("  %-52s → scalaire\n", $p); continue; }
      $liste = array_is_list($r);
      $ech = $liste ? ($r[0] ?? []) : $r;
      $cles = is_array($ech) ? array_slice(array_keys($ech), 0, 18) : [];
      printf("  %-52s → %s, %d clé(s)\n", $p, $liste ? "liste(".count($r).")" : "objet", count($cles));
      if ($cles) { echo "      ", implode(", ", $cles), "\n"; }
      $j = json_encode($ech, JSON_UNESCAPED_UNICODE);
      if ($j !== false) { echo "      ", substr($j, 0, 240), "\n"; }
    }
  ' 2>&1 | head -70 || true
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

# Le catalogue et le coût matière viennent d'être branchés sur les vraies
# tables. Un mauvais rapprochement ne lève aucune erreur : il rend un chiffre
# faux qui a l'air juste. On mesure donc la couverture et la vraisemblance.
# L'écran Exploitation ne sert à rien s'il ne rend aucun magasin, et il ment
# s'il prend le mois courant pour un mois clos. On vérifie les deux.
curl -fsS "${LOCAL_BASE}/api/cockpit/exploitation" 2>/dev/null | php -r '
$r = json_decode(file_get_contents("php://stdin"), true);
$m = $r["magasins"] ?? [];
printf("  exploitation: %d magasin(s), jour %s, mois %s%s\n", count($m),
  $r["jour"] ?? "?", $r["mois"] ?? "?", !empty($r["erreur"]) ? " — ".$r["erreur"] : "");
if (!$m) { echo "    ATTENTION : aucun magasin rendu\n"; exit; }
$sb = $r["magasinsSansBudget"] ?? 0;
if ($sb) { printf("    %d magasin(s) sans budget encodé : la colonne objectif restera vide\n", $sb); }
foreach ($m as $s) {
  printf("    %-34s jour %8.2f  semaine %9.2f  mois %10.2f\n",
    mb_substr($s["magasin"], 0, 34), $s["jour"]["ca"], $s["semaine"]["ca"], $s["mois"]["ca"]);
}
'
echo "== catalogue produit =="
CAT_JSON="$(curl -fsS "${LOCAL_BASE}/api/cockpit/production/catalogue" 2>/dev/null || echo '[]')"
php -r '
$j = json_decode(file_get_contents("php://stdin"), true);
if (!is_array($j) || !$j) { echo "  AUCUN produit rendu par /production/catalogue\n"; exit; }
$n = count($j);
$mat = $prix = $grp = $per = $ko = 0;
foreach ($j as $p) {
  if (isset($p["mat"]) && $p["mat"] !== null) { $mat++; }
  if (isset($p["prix"]) && $p["prix"] !== null) { $prix++; }
  if (!empty($p["groupe"])) { $grp++; }
  if (!empty($p["periods"])) { $per++; }
  // Un coût matière au-dessus du prix de vente signale un rapprochement
  // douteux (ou une recette à revoir) : mieux vaut le dire que le tolérer.
  if (isset($p["matFiable"]) && $p["matFiable"] === false) { $ko++; }
}
printf("  produits            : %d\n", $n);
printf("  avec coût matière   : %d (%.0f %%)\n", $mat, 100 * $mat / $n);
printf("  avec prix de vente  : %d (%.0f %%)\n", $prix, 100 * $prix / $n);
printf("  avec groupe         : %d (%.0f %%)\n", $grp, 100 * $grp / $n);
printf("  avec gamme saison   : %d\n", $per);
printf("  coût >= prix        : %d%s\n", $ko, $ko > 0 ? "  <-- recettes à rechiffrer (marge non publiée)" : "");
$mg = 0;
foreach ($j as $p) { if (isset($p["margePct"]) && $p["margePct"] !== null) { $mg++; } }
printf("  marge exploitable   : %d (%.0f %%)\n", $mg, 100 * $mg / $n);
' <<< "$CAT_JSON"
# L'arbre produit : groupes, puis catégories. Une catégorie orpheline n'est
# pas une erreur, mais un arbre entièrement orphelin trahit une jointure morte.
echo "== arbre produit =="
for r in groupes categories; do
  curl -fsS "${LOCAL_BASE}/api/cockpit/production/${r}" 2>/dev/null | php -r '
  $r = json_decode(file_get_contents("php://stdin"), true);
  $k = isset($r["groupes"]) ? "groupes" : "categories";
  if (!is_array($r) || empty($r[$k])) { printf("  %-11s : VIDE%s\n", $k, isset($r["erreur"]) ? " — ".$r["erreur"] : ""); exit; }
  $n = count($r[$k]);
  $sans = 0;
  foreach ($r[$k] as $e) { if ($k === "categories" && empty($e["groupe"])) { $sans++; } }
  printf("  %-11s : %d (source %s%s)%s\n", $k, $n, $r["source"] ?? "?",
         !empty($r["chemin"]) ? ", chemin ".$r["chemin"] : "",
         $k === "categories" && $sans ? " — dont $sans SANS GROUPE" : "");
  '
done
# Ouvrir une branche réelle : un référentiel qui se compte bien mais dont
# aucune catégorie ne rend de produit reste un arbre mort.
CID="$(curl -fsS "${LOCAL_BASE}/api/cockpit/production/categories" 2>/dev/null \
  | php -r '$r=json_decode(file_get_contents("php://stdin"),true);
            foreach (($r["categories"] ?? []) as $c) { if (!empty($c["id"])) { echo $c["id"]; break; } }')"
if [ -n "$CID" ]; then
  curl -fsS "${LOCAL_BASE}/api/cockpit/production/categorie/produits?id=${CID}" 2>/dev/null | php -r '
  $r = json_decode(file_get_contents("php://stdin"), true);
  $p = $r["produits"] ?? [];
  printf("  branche %s (%s) : %d référence(s), source %s%s\n",
    $r["categorieId"] ?? "?", $r["categorie"] ?? "?", count($p), $r["source"] ?? "aucune",
    !empty($r["erreur"]) ? " — ".$r["erreur"] : "");
  if (!$p) { echo "    ATTENTION : catégorie sans référence\n"; }
  '
fi
# Analyse dans le temps. Le sélecteur et les chiffres viennent de deux routes
# differentes : si leurs vocabulaires divergent, aucun nom ne se rencontre et
# l ecran rend une serie vide SANS lever la moindre erreur. On ne verifie donc
# pas que la route repond, mais qu une option reellement proposee produit au
# moins un point chiffre. C est le seul controle qui attrape ce decalage.
# Une livraison invisible cote navigateur vaut une livraison ratee : on verifie
# que l en-tete de revalidation est bien servi, pas seulement present au fichier.
echo "== cache navigateur =="
for f in "" "assets/js/app.js" "assets/js/templates.js" "assets/css/app.css"; do
  cc="$(curl -sI --max-time 15 "${LOCAL_BASE}/${f}" 2>/dev/null | tr -d '\r' | sed -n 's/^[Cc]ache-[Cc]ontrol: //p')"
  printf "  %-24s %s\n" "/${f:-index.html}" "${cc:-ABSENT — le navigateur gardera l ancienne version}"
done

echo "== analyse dans le temps =="
rm -f /tmp/ceo_an_cat /tmp/ceo_an_prod /tmp/ceo_an_sous
curl -fsS --max-time 300 "${LOCAL_BASE}/api/cockpit/produits/analyse/options" 2>/dev/null | php -r '
$r = json_decode(file_get_contents("php://stdin"), true);
$c = $r["categories"] ?? []; $p = $r["produits"] ?? []; $s = $r["souscategories"] ?? [];
printf("  options     : %d groupe(s), %d categorie(s), %d reference(s), releve %s%s\n",
  count($c), count($s), count($p), $r["periode"] ?? "?", !empty($r["erreur"]) ? " — ".$r["erreur"] : "");
if (!$c) { echo "    ATTENTION : aucun groupe analysable\n"; }
if (!$s) { echo "    ATTENTION : aucune sous-categorie analysable\n"; }
if ($c) { file_put_contents("/tmp/ceo_an_cat", (string) $c[0]["cle"]); printf("    ex. groupe    : %s\n", $c[0]["nom"]); }
if ($s) { file_put_contents("/tmp/ceo_an_sous", (string) $s[0]["cle"]); printf("    ex. categorie : %s\n", $s[0]["nom"]); }
if ($p) { file_put_contents("/tmp/ceo_an_prod", (string) $p[0]["cle"]); printf("    ex. reference : %s\n", $p[0]["nom"]); }
'
# Les periodes closes sont mises en cache a la premiere lecture. On les lit ici
# pour que le premier visiteur trouve un ecran deja chaud, et parce qu un cache
# vide est justement le cas le plus lent : c est celui-la qu il faut mesurer.
for kind in categorie souscategorie produit; do
 for gran in mois trimestre annee; do
  f="/tmp/ceo_an_cat"
  [ "$kind" = "produit" ] && f="/tmp/ceo_an_prod"
  [ "$kind" = "souscategorie" ] && f="/tmp/ceo_an_sous"
  KEY="$(cat "$f" 2>/dev/null || true)"
  [ -z "$KEY" ] && continue
  T0=$(date +%s)
  curl -fsS --max-time 300 -G "${LOCAL_BASE}/api/cockpit/produits/analyse" \
       --data-urlencode "type=${kind}" --data-urlencode "cle=${KEY}" \
       --data-urlencode "granularite=${gran}" 2>/dev/null | php -r '
  $r = json_decode(file_get_contents("php://stdin"), true);
  $pts = $r["points"] ?? [];
  $ok = 0; foreach ($pts as $x) { if ($x["valeur"] !== null) { $ok++; } }
  printf("  serie %-9s : %s -> %d point(s), %d chiffre(s)%s\n", $r["type"] ?? "?",
    mb_substr((string) ($r["libelle"] ?? $r["cle"] ?? "?"), 0, 26), count($pts), $ok,
    !empty($r["motif"]) ? " — ".$r["motif"] : "");
  if (!$ok) { echo "    ATTENTION : option proposee mais serie vide — vocabulaires desynchronises\n"; }
  // Le N-1 et le detail par magasin se cablent en silence : sans controle, une
  // colonne vide passerait pour une absence de donnees plutot que pour un
  // branchement casse. On compte donc ce qui est reellement chiffre.
  $n1 = 0; foreach ($pts as $x) { if (($x["n1"] ?? null) !== null) { $n1++; } }
  $mg = 0; foreach ($pts as $x) { $mg = max($mg, count((array) ($x["parMagasin"] ?? []))); }
  printf("    N-1 %d/%d point(s) · par magasin : %s\n", $n1, count($pts),
    ($r["parMagasin"] ?? "?") === "ok" ? $mg." magasin(s), ".count($r["magasins"] ?? [])." nomme(s)" : "en attente d API");
  if (($r["parMagasin"] ?? "") === "ok" && !$mg) { echo "    ATTENTION : detail par magasin annonce mais aucun chiffre\n"; }
  '
  echo "    ${gran} : $(( $(date +%s) - T0 )) s (cache froid)"
 done
done
rm -f /tmp/ceo_an_cat /tmp/ceo_an_prod
# Gammes saisonnières : les traductions ne vivent que dans l'API, la table
# d'alias de la base étant vide. Si elles manquent, l'écran restera monolingue.
curl -fsS "${LOCAL_BASE}/api/cockpit/production/periodes" 2>/dev/null | php -r '
$r = json_decode(file_get_contents("php://stdin"), true);
$p = $r["periodes"] ?? [];
$tr = 0; $vides = 0;
foreach ($p as $x) { if (!empty($x["alias"])) { $tr++; } if (empty($x["references"])) { $vides++; } }
printf("  gammes      : %d (source %s) — %d traduite(s), %d sans référence%s%s\n",
  count($p), $r["source"] ?? "?", $tr, $vides,
  !empty($r["aliasErreur"]) ? "\n    ALIAS KO : ".$r["aliasErreur"] : "",
  !empty($r["aliasInfo"])   ? "\n    alias    : ".$r["aliasInfo"] : "");
// Ouvrir la gamme la mieux fournie : une gamme qui compte des references sans
// en rendre aucune signale un rattachement casse, pas une offre vide.
$best = null;
foreach ($p as $x) { if (!empty($x["references"]) && (!$best || $x["references"] > $best["references"])) { $best = $x; } }
if ($best) { file_put_contents("/tmp/ceo_periode_id", (string) $best["id"]); }
'
PID="$(cat /tmp/ceo_periode_id 2>/dev/null || true)"; rm -f /tmp/ceo_periode_id
if [ -n "$PID" ]; then
  curl -fsS "${LOCAL_BASE}/api/cockpit/production/periode/produits?id=${PID}" 2>/dev/null | php -r '
  $r = json_decode(file_get_contents("php://stdin"), true);
  $p = $r["produits"] ?? [];
  printf("  gamme %s (%s) : %d référence(s), source %s%s\n", $r["periodeId"] ?? "?",
    $r["gamme"] ?? "?", count($p), $r["source"] ?? "aucune",
    !empty($r["erreur"]) ? " — ".$r["erreur"] : "");
  if (!$p) { echo "    ATTENTION : gamme annoncée non vide mais sans référence rendue\n"; }
  '
fi

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
