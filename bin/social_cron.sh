#!/usr/bin/env bash
#
# Appelé toutes les 5 minutes par /etc/cron.d/cockpit-rapports (posé par
# bin/deploy.sh) : l'horloge de publication du portail Marketing — les posts
# validés partent sur Facebook, Instagram et LinkedIn à l'heure prévue. Le jeton
# (socialJeton) est lu EN BASE au moment de l'appel — rien de secret ici.
# Publication réelle désactivée (Paramètres → Publication) : l'horloge ne fait rien.
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ALIAS_PATH="${ALIAS_PATH:-/consulant_bo}"
JETON="$(php -r '
  $cfg = require $argv[1]; $db = $cfg["db"];
  $pdo = new PDO("mysql:host=" . $db["host"] . ";port=" . $db["port"] . ";dbname=" . $db["name"] . ";charset=utf8mb4",
      $db["user"], $db["password"], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $v = $pdo->query("SELECT value FROM ceo_app_setting WHERE `key` = \x27socialJeton\x27")->fetchColumn();
  echo is_string($v) ? (string) json_decode($v, true) : "";
' "$DIR/config/config.php" 2>/dev/null || true)"
[[ -n "$JETON" ]] || exit 0
curl -fsS -m 290 "http://127.0.0.1${ALIAS_PATH}/api/cockpit/marketing/publication/cron?jeton=${JETON}" >/dev/null
