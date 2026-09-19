#!/usr/bin/env bash
#
# Appelé toutes les 5 minutes par /etc/cron.d/cockpit-rapports (posé par
# bin/deploy.sh) : l'horloge des newsletters. Le jeton (nlJeton) est lu EN BASE
# au moment de l'appel — rien de secret dans le crontab ni dans ce fichier.
# En mode test (pas de dispatch), l'horloge ne fait rien partir.
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ALIAS_PATH="${ALIAS_PATH:-/consulant_bo}"
JETON="$(php -r '
  $cfg = require $argv[1]; $db = $cfg["db"];
  $pdo = new PDO("mysql:host=" . $db["host"] . ";port=" . $db["port"] . ";dbname=" . $db["name"] . ";charset=utf8mb4",
      $db["user"], $db["password"], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $v = $pdo->query("SELECT value FROM ceo_app_setting WHERE `key` = \x27nlJeton\x27")->fetchColumn();
  echo is_string($v) ? (string) json_decode($v, true) : "";
' "$DIR/config/config.php" 2>/dev/null || true)"
[[ -n "$JETON" ]] || exit 0
curl -fsS -m 340 "http://127.0.0.1${ALIAS_PATH}/api/cockpit/newsletter/cron?jeton=${JETON}" >/dev/null
