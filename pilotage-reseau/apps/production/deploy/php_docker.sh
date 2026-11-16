#!/usr/bin/env bash
#
# [297] PHP 8.3 en conteneur pour l'ERP, quand le PHP du serveur est trop ancien (Ubuntu 20.04 : PHP 7.4, sans
# paquet PHP 8). Lancé EN ROOT par deploy/pull_local.sh avant composer (ERP_PHP_DOCKER=1 dans /etc/erp-pull.conf).
#
#  - image erp-production-php:<empreinte> construite depuis deploy/php-docker (reconstruite si le Dockerfile change) ;
#  - conteneur erp-production-php : php-fpm sur 127.0.0.1:9083 (réseau de l'hôte : MariaDB du serveur en 127.0.0.1),
#    mêmes chemins que l'hôte pour le code (/var/www/production) et la copie du dépôt (/opt/erp_pull) ;
#  - commandes /usr/local/bin/php-erp et /usr/local/bin/composer-erp (docker exec dans ce conteneur), utilisées par
#    pull_local.sh, server.sh et les tâches cron.
# Les autres conteneurs du serveur (ancien ERP…) et le PHP de l'hôte ne sont pas touchés.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/php-docker"
NAME="erp-production-php"
ERP_TARGET="${ERP_TARGET:-/var/www/production}"
PULL_DIR="${PULL_DIR:-/opt/erp_pull}"

log()  { printf '%s [php-docker] %s\n' "$(date '+%F %T')" "$*"; }
fail() { printf '%s [php-docker] ERREUR : %s\n' "$(date '+%F %T')" "$*" >&2; exit 1; }

command -v docker >/dev/null 2>&1 || fail "Docker absent : installer Docker ou PHP 8.1+ sur le serveur."
docker info >/dev/null 2>&1 || fail "Docker ne répond pas (service arrêté ?)."

tag="$(cat "$DIR"/Dockerfile "$DIR"/erp.ini "$DIR"/zz-erp-pool.conf | sha256sum | cut -c1-12)"
IMAGE="${NAME}:${tag}"
if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
  log "Construction de l'image PHP 8.3 ${IMAGE} (première fois : quelques minutes)…"
  docker build -q -t "$IMAGE" "$DIR" >/dev/null || fail "Construction de l'image PHP en échec."
fi

mkdir -p "$ERP_TARGET" "$PULL_DIR"
current="$(docker inspect -f '{{.Config.Image}}|{{.State.Running}}' "$NAME" 2>/dev/null || true)"
if [[ "$current" != "${IMAGE}|true" ]]; then
  [[ -z "$current" ]] || docker rm -f "$NAME" >/dev/null
  docker run -d --name "$NAME" --restart unless-stopped --network host \
    -v "${ERP_TARGET}:${ERP_TARGET}" -v "${PULL_DIR}:${PULL_DIR}" -v /etc/localtime:/etc/localtime:ro \
    "$IMAGE" >/dev/null || fail "Démarrage du conteneur ${NAME} en échec."
  log "Conteneur ${NAME} démarré (${IMAGE}, php-fpm 127.0.0.1:9083)."
fi

# docker exec : dossier courant gardé s'il est visible dans le conteneur, sinon « / ». ERP_PHP_USER=www-data pour
# les tâches cron (droits de l'application) ; sinon root, comme les commandes d'installation sur l'hôte.
for tool in php composer; do
  cat > "/usr/local/bin/${tool}-erp.new" <<WRAP
#!/bin/sh
# ${tool} de l'ERP (conteneur ${NAME}, PHP 8.3) — écrit par deploy/php_docker.sh
d="\$(pwd)"
case "\$d" in ${ERP_TARGET}|${ERP_TARGET}/*|${PULL_DIR}|${PULL_DIR}/*) ;; *) d=/ ;; esac
exec docker exec -i -e COMPOSER_ALLOW_SUPERUSER=1 \${ERP_PHP_USER:+-u "\$ERP_PHP_USER"} -w "\$d" ${NAME} ${tool} "\$@"
WRAP
  chmod 755 "/usr/local/bin/${tool}-erp.new" && mv -f "/usr/local/bin/${tool}-erp.new" "/usr/local/bin/${tool}-erp"
done
for i in 1 2 3 4 5 6 7 8 9 10; do
  /usr/local/bin/php-erp -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' 2>/dev/null && break
  sleep 1
  [[ "$i" != 10 ]] || fail "PHP du conteneur ${NAME} injoignable."
done
log "PHP de l'ERP : $(/usr/local/bin/php-erp -r 'echo PHP_VERSION;') (conteneur ${NAME})."
