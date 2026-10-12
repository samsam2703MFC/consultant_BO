#!/usr/bin/env bash
#
# Appelé toutes les 5 minutes par /etc/cron.d/cockpit-rapports (posé par
# bin/deploy.sh) : le relevé du stock matière de chaque magasin. L'API du panel
# ne rend que le stock courant ; relu toutes les cinq minutes, il garde chaque
# inventaire et chaque livraison au moment où ils passent (src/inventaires.php).
# Rien de secret : la route ne fait que lire le panel, et refuse de tourner
# plus d'une fois toutes les quatre minutes.
set -euo pipefail
ALIAS_PATH="${ALIAS_PATH:-/consulant_bo}"
curl -fsS -m 170 "http://127.0.0.1${ALIAS_PATH}/api/cockpit/stock/inventaires/releve" >/dev/null
