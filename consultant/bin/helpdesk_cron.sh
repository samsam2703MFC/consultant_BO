#!/usr/bin/env bash
#
# Appelé toutes les 10 minutes par /etc/cron.d/cockpit-rapports (posé par shared/bin/deploy.sh) : l'horloge des
# relances SMS des demandes au bureau (consultant/src/helpdesk.php, 20/11/2026). Les relances ne partent qu'aux heures
# réglées (08:30 par défaut, jusqu'à 20:00, jours ouvrés) : hors de ces heures, l'appel ne lit rien. Le jeton (celui
# des visites, visitesJeton) est lu EN BASE au moment de l'appel — rien de secret dans le crontab ni ici.
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ALIAS_PATH="${ALIAS_PATH:-/consulant_bo}"
JETON="$(php -r '
  $cfg = require $argv[1]; $db = $cfg["db"];
  $pdo = new PDO("mysql:host=" . $db["host"] . ";port=" . $db["port"] . ";dbname=" . $db["name"] . ";charset=utf8mb4",
      $db["user"], $db["password"], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $v = $pdo->query("SELECT value FROM ceo_app_setting WHERE `key` = \x27visitesJeton\x27")->fetchColumn();
  echo is_string($v) ? (string) json_decode($v, true) : "";
' "$DIR/../shared/config/config.php" 2>/dev/null || true)"
[[ -n "$JETON" ]] || exit 0
curl -fsS -m 120 "http://127.0.0.1${ALIAS_PATH}/api/cockpit/helpdesk/relances/cron?jeton=${JETON}" >/dev/null
