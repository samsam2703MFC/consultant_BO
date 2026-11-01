#!/usr/bin/env bash
# Cron du module RH (Dimona) : relecture des déclarations en attente et alerte des certificats.
# Posé par bin/deploy.sh dans /etc/cron.d/cockpit-rapports, chaque minute, sous www-data (le propriétaire
# du dossier privé et de la clé maître). flock : une exécution à la fois, jamais deux relectures croisées.
set -u
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
exec flock -n /tmp/hr_dimona_cron.lock php "$DIR/bin/hr_dimona_cron.php" --duree=55
