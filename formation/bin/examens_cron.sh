#!/usr/bin/env bash
#
# Appelé chaque jour à 06:20 par /etc/cron.d/cockpit-rapports (posé par shared/bin/deploy.sh) : la relecture mensuelle
# des examens théoriques (formation/src/examens.php, exReleveAuto). La route ne relit que si aucune relecture n'a eu
# lieu ce mois-ci. Le jeton de service (erp_jeton_service, table erp_cles) est lu EN BASE au moment de l'appel — rien
# de secret dans le crontab ni ici.
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ALIAS_PATH="${ALIAS_PATH:-/consulant_bo}"
JETON="$(php -r '
  $cfg = require $argv[1]; $db = $cfg["db"];
  $pdo = new PDO("mysql:host=" . $db["host"] . ";port=" . $db["port"] . ";dbname=" . $db["name"] . ";charset=utf8mb4",
      $db["user"], $db["password"], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $v = $pdo->query("SELECT valeur FROM erp_cles WHERE cle = \x27erp_jeton_service\x27")->fetchColumn();
  echo is_string($v) ? trim($v) : "";
' "$DIR/../shared/config/config.php" 2>/dev/null || true)"
[[ -n "$JETON" ]] || exit 0
curl -fsS -m 600 -H "X-Service-Token: ${JETON}" "http://127.0.0.1${ALIAS_PATH}/api/cockpit/formation/examens/releve-auto" >/dev/null
