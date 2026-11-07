#!/usr/bin/env bash
#
# Appelé toutes les 5 minutes par /etc/cron.d/cockpit-rapports (posé par
# bin/deploy.sh) : l'horloge des visites terrain — escalade des P0 dépassés,
# rappels J-1 et jour J, synthèse du matin. Le jeton (visitesJeton) est lu EN
# BASE au moment de l'appel — rien de secret dans le crontab ni ici.
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ALIAS_PATH="${ALIAS_PATH:-/consulant_bo}"
JETON="$(php -r '
  $cfg = require $argv[1]; $db = $cfg["db"];
  $pdo = new PDO("mysql:host=" . $db["host"] . ";port=" . $db["port"] . ";dbname=" . $db["name"] . ";charset=utf8mb4",
      $db["user"], $db["password"], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $v = $pdo->query("SELECT value FROM ceo_app_setting WHERE `key` = \x27visitesJeton\x27")->fetchColumn();
  echo is_string($v) ? (string) json_decode($v, true) : "";
' "$DIR/config/config.php" 2>/dev/null || true)"
[[ -n "$JETON" ]] || exit 0
curl -fsS -m 120 "http://127.0.0.1${ALIAS_PATH}/api/cockpit/visites/cron?jeton=${JETON}" >/dev/null
