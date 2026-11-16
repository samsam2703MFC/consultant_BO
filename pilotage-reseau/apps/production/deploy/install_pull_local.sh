#!/usr/bin/env bash
#
# Installation (une seule fois, EN ROOT) de la livraison automatique de l'ERP sur un serveur LOCAL
# (Debian / Ubuntu, PHP + MariaDB), qui va lui-même chercher les mises à jour sur GitHub — aucun accès
# entrant n'est nécessaire, seulement une sortie HTTPS vers github.com.
#
#   sudo bash install_pull_local.sh \
#     --repo  https://github.com/<compte>/<dépôt>.git \
#     --url   http://<adresse-du-serveur-local>/production \
#     [--branch <branche de déploiement>] [--every 5] [--demo]
#
# Pose : paquets manquants (git, apache2, composer, rsync, unzip, curl), /etc/erp-pull.conf (root seul),
# /etc/cron.d/erp-pull (toutes les N minutes), puis lance une première livraison (deploy/pull_local.sh).
# Aucune adresse ni secret n'est écrit dans le dépôt : tout va dans /etc/erp-pull.conf.
set -euo pipefail

REPO_URL=""; ERP_URL=""; BRANCH="claude/deploiement-application-serveur-lmz1bm"; EVERY=5; DEMO=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --repo)   REPO_URL="$2"; shift 2 ;;
    --url)    ERP_URL="${2%/}"; shift 2 ;;
    --branch) BRANCH="$2"; shift 2 ;;
    --every)  EVERY="$2"; shift 2 ;;
    --demo)   DEMO="1"; shift ;;
    *) echo "Option inconnue : $1" >&2; exit 2 ;;
  esac
done

log()  { printf '\033[1;34m[erp-pull]\033[0m %s\n' "$*"; }
fail() { printf '\033[1;31m[erp-pull]\033[0m %s\n' "$*" >&2; exit 1; }

[[ "$(id -u)" == "0" ]] || fail "À lancer en root (sudo)."
[[ -n "$REPO_URL" && -n "$ERP_URL" ]] || fail "Usage : install_pull_local.sh --repo <url git> --url <adresse de l'ERP, ex. http://192.168.1.10/production>"
[[ "$EVERY" =~ ^[0-9]+$ && "$EVERY" -ge 1 && "$EVERY" -le 59 ]] || fail "--every : de 1 à 59 minutes."
[[ "$ERP_URL" =~ ^https?://[^/]+(/.*)?$ ]] || fail "--url : adresse complète attendue (http://… ou https://…)."
ERP_ALIAS="/${ERP_URL#*://*/}"
[[ "$ERP_URL" =~ ^https?://[^/]+$ ]] && ERP_ALIAS="/production" && ERP_URL="${ERP_URL}/production"
command -v apt-get >/dev/null 2>&1 || fail "Serveur sans apt-get : ce script vise Debian / Ubuntu (deploy/server.sh aussi)."

# --- 1. Paquets -------------------------------------------------------------
need=()
for c in git rsync unzip curl flock; do command -v "$c" >/dev/null 2>&1 || need+=("$c"); done
command -v apache2ctl >/dev/null 2>&1 || need+=(apache2 libapache2-mod-php)
command -v composer   >/dev/null 2>&1 || need+=(composer)
command -v php        >/dev/null 2>&1 || need+=(php-cli)
command -v mysql >/dev/null 2>&1 || command -v mariadb >/dev/null 2>&1 || need+=(mariadb-client)
need=("${need[@]/flock/util-linux}")
if [[ ${#need[@]} -gt 0 ]]; then
  log "Installation : ${need[*]}"
  DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=600 update -qq
  DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=600 install -y -qq "${need[@]}"
fi

# --- 2. Accès à GitHub (sortie HTTPS) ----------------------------------------
git ls-remote --exit-code --heads "$REPO_URL" "$BRANCH" >/dev/null \
  || fail "Branche ${BRANCH} introuvable sur ${REPO_URL} (adresse, branche, ou accès sortant vers github.com ?)."
log "GitHub joignable, branche ${BRANCH} trouvée."

# --- 3. Réglages (hors Git, root seul) -----------------------------------------
umask 077
cat > /etc/erp-pull.conf <<CONF
# Livraison automatique de l'ERP sur ce serveur local — écrit par install_pull_local.sh le $(date '+%F %T').
REPO_URL="${REPO_URL}"
BRANCH="${BRANCH}"
ERP_URL="${ERP_URL}"
ERP_ALIAS="${ERP_ALIAS}"
ERP_TARGET="/var/www/production"
PULL_DIR="/opt/erp_pull"
# Données de démonstration du module atelier (1 = oui). Laisser vide sur un serveur réel.
ERP_TFB_DEMO="${DEMO}"
# Bouton « Entrer » sans mot de passe : laisser vide (chacun se connecte avec son login ERP).
ERP_ENTREE_LIBRE=""
ERP_SANDBOX_OUVERT=""
# Un commit en échec n'est retenté qu'après ce délai (minutes).
RETRY_MIN=60
CONF
chmod 600 /etc/erp-pull.conf
log "Réglages : /etc/erp-pull.conf"

# --- 4. Script de livraison hors du dépôt cloné (le clone est réécrit à chaque livraison) ---
SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
install -m 700 "$SELF_DIR/pull_local.sh" /usr/local/sbin/erp-pull
log "Commande : /usr/local/sbin/erp-pull  (--force pour relivrer)"

# --- 5. Cron + rotation du journal --------------------------------------------
umask 022
cat > /etc/cron.d/erp-pull <<CRON
# Livraison automatique de l'ERP depuis GitHub (install_pull_local.sh)
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
*/${EVERY} * * * * root /usr/local/sbin/erp-pull >> /var/log/erp-pull.log 2>&1
CRON
cat > /etc/logrotate.d/erp-pull <<'ROT'
/var/log/erp-pull.log {
  weekly
  rotate 8
  compress
  missingok
  notifempty
}
ROT
log "Cron : toutes les ${EVERY} min (/etc/cron.d/erp-pull), journal /var/log/erp-pull.log"

# --- 6. Première livraison -------------------------------------------------------
log "Première livraison (quelques minutes)…"
/usr/local/sbin/erp-pull --force 2>&1 | tee -a /var/log/erp-pull.log
