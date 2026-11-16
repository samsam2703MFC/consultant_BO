#!/usr/bin/env bash
#
# Serveur local (réseau de l'atelier, non joignable depuis Internet) : il TIRE lui-même l'ERP depuis GitHub.
# Lancé EN ROOT par cron (/etc/cron.d/erp-pull, posé par install_pull_local.sh) toutes les quelques minutes :
#   - git fetch de la branche de déploiement (celle que GitHub Actions livre au serveur en ligne) ;
#   - rien à faire si le dernier commit est déjà livré, ou s'il ne touche pas pilotage-reseau/apps/production ;
#   - sinon : composer install (comme le runner), puis deploy/server.sh — la même installation que le serveur
#     en ligne (copie dans $ERP_TARGET, .env, base, migrations, Apache, recette).
#
# Réglages : /etc/erp-pull.conf (hors Git, root seul), écrit par install_pull_local.sh. Aucune adresse ni
# secret dans ce script. Journal : /var/log/erp-pull.log.
#
#   bash pull_local.sh            # livre seulement s'il y a du nouveau
#   bash pull_local.sh --force    # relivre le dernier commit même s'il est déjà livré
set -euo pipefail

CONF="${ERP_PULL_CONF:-/etc/erp-pull.conf}"
[[ -r "$CONF" ]] || { echo "[erp-pull] $CONF introuvable : lancer d'abord install_pull_local.sh" >&2; exit 1; }
# shellcheck disable=SC1090
. "$CONF"

: "${REPO_URL:?REPO_URL manquant dans $CONF}"
: "${ERP_URL:?ERP_URL manquant dans $CONF}"
BRANCH="${BRANCH:?BRANCH manquant dans $CONF}"
PULL_DIR="${PULL_DIR:-/opt/erp_pull}"
APP_PATH="pilotage-reseau/apps/production"
CHECKOUT="$PULL_DIR/src"
STATE="$PULL_DIR/state"
SERVER_SH="${SERVER_SH:-$CHECKOUT/$APP_PATH/deploy/server.sh}"
RETRY_MIN="${RETRY_MIN:-60}"     # un commit en échec n'est retenté qu'après ce délai (minutes)

FORCE=0
[[ "${1:-}" == "--force" ]] && FORCE=1

log() { printf '%s [erp-pull] %s\n' "$(date '+%F %T')" "$*"; }

mkdir -p "$PULL_DIR" "$STATE"
chmod 700 "$PULL_DIR"

# Une seule livraison à la fois (cron toutes les 5 min, livraison parfois plus longue).
exec 9>"$PULL_DIR/.lock"
flock -n 9 || { log "Livraison déjà en cours : rien à faire."; exit 0; }

# --- 1. Copie du dépôt : seulement le dossier de l'ERP (clone partiel + sparse) ----
if [[ ! -d "$CHECKOUT/.git" ]]; then
  log "Premier passage : clone de la branche ${BRANCH} (dossier ${APP_PATH} seulement)…"
  rm -rf "$CHECKOUT"
  git clone --quiet --filter=blob:none --no-checkout --branch "$BRANCH" --single-branch "$REPO_URL" "$CHECKOUT"
  git -C "$CHECKOUT" sparse-checkout set "$APP_PATH"
  git -C "$CHECKOUT" checkout --quiet "$BRANCH"
fi

git -C "$CHECKOUT" fetch --quiet origin "$BRANCH"
new="$(git -C "$CHECKOUT" rev-parse "origin/$BRANCH")"
old="$(cat "$STATE/deployed" 2>/dev/null || true)"

if [[ "$FORCE" != "1" && "$new" == "$old" ]]; then
  exit 0   # rien de nouveau : silencieux (cron toutes les 5 min)
fi

# Commit sans changement de l'ERP (autre portail, cockpit…) : noté comme livré, sans réinstaller.
if [[ "$FORCE" != "1" && -n "$old" ]] && git -C "$CHECKOUT" cat-file -e "$old^{commit}" 2>/dev/null \
   && git -C "$CHECKOUT" diff --quiet "$old" "$new" -- "$APP_PATH"; then
  log "Commit ${new:0:8} : rien de changé dans ${APP_PATH}."
  echo "$new" > "$STATE/deployed"
  exit 0
fi

# Commit qui a échoué récemment : on attend RETRY_MIN minutes avant de réessayer (pas toutes les 5 min).
if [[ "$FORCE" != "1" && -f "$STATE/failed" ]]; then
  read -r failed_sha failed_at < "$STATE/failed" || true
  if [[ "${failed_sha:-}" == "$new" && $(( $(date +%s) - ${failed_at:-0} )) -lt $(( RETRY_MIN * 60 )) ]]; then
    exit 0
  fi
fi

log "Livraison du commit ${new:0:8} (précédent : ${old:0:8})…"
git -C "$CHECKOUT" reset --quiet --hard "$new"
git -C "$CHECKOUT" clean -fdq          # garde vendor/ (ignoré par Git) : composer install plus rapide

# --- 2. Dépendances PHP (le runner GitHub le fait pour le serveur en ligne) ---------
COMPOSER="$(command -v composer || true)"
[[ -n "$COMPOSER" ]] || { log "composer introuvable : relancer install_pull_local.sh."; exit 1; }
COMPOSER_ALLOW_SUPERUSER=1 "$COMPOSER" install --working-dir="$CHECKOUT/$APP_PATH/app/public" \
  --no-dev --no-interaction --no-progress --optimize-autoloader --quiet

# --- 3. Installation : exactement celle du serveur en ligne ------------------------
if ERP_URL="$ERP_URL" ERP_TARGET="${ERP_TARGET:-/var/www/production}" ERP_ALIAS="${ERP_ALIAS:-/production}" \
   ERP_ENTREE_LIBRE="${ERP_ENTREE_LIBRE:-}" ERP_SANDBOX_OUVERT="${ERP_SANDBOX_OUVERT:-}" ERP_TFB_DEMO="${ERP_TFB_DEMO:-}" \
   ERP_COHABITATION="${ERP_COHABITATION:-}" ERP_DB_NAME="${ERP_DB_NAME:-erp}" ERP_MAIL_MODE="${ERP_MAIL_MODE:-}" \
   ERP_COHAB_DB_USER="${ERP_COHAB_DB_USER:-}" \
   bash "$SERVER_SH"; then
  echo "$new" > "$STATE/deployed"
  rm -f "$STATE/failed"
  log "OK : commit ${new:0:8} livré sur ${ERP_URL}/"
  # Ce script lui-même : la version du dépôt remplace la copie installée (nouveau fichier, la livraison en
  # cours garde l'ancien — bash le lit encore).
  SELF_INSTALLED="${SELF_INSTALLED:-/usr/local/sbin/erp-pull}"
  if [[ -f "$SELF_INSTALLED" ]] && ! cmp -s "$CHECKOUT/$APP_PATH/deploy/pull_local.sh" "$SELF_INSTALLED"; then
    install -m 700 "$CHECKOUT/$APP_PATH/deploy/pull_local.sh" "$SELF_INSTALLED.new" && mv -f "$SELF_INSTALLED.new" "$SELF_INSTALLED"
    log "Script de livraison mis à jour ($SELF_INSTALLED)."
  fi
else
  echo "$new $(date +%s)" > "$STATE/failed"
  log "ÉCHEC de la livraison du commit ${new:0:8} (détail ci-dessus) ; nouvel essai dans ${RETRY_MIN} min (ou --force)."
  exit 1
fi
