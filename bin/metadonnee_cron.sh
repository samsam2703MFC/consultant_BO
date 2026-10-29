#!/usr/bin/env bash
#
# Appelé chaque minute par /etc/cron.d/cockpit-rapports (posé par
# bin/deploy.sh) : l'horloge de MétaDonnée — relève des leads Meta (toutes les
# 2 min quand le webhook de l'application va au CRM ; filet horaire sinon),
# envoi à l'ERP, relecture des statuts (toutes les 10 min), SMS aux franchisés
# et e-mails d'escalade. Le jeton (mdJeton) est lu EN BASE au moment de
# l'appel — rien de secret dans le crontab ni ici. Un passage encore en cours
# (relecture longue) : le suivant ne démarre pas par-dessus.
set -euo pipefail
exec 9>"${TMPDIR:-/tmp}/cockpit-metadonnee.lock"
flock -n 9 || exit 0
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ALIAS_PATH="${ALIAS_PATH:-/consulant_bo}"
JETON="$(php -r '
  $cfg = require $argv[1]; $db = $cfg["db"];
  $pdo = new PDO("mysql:host=" . $db["host"] . ";port=" . $db["port"] . ";dbname=" . $db["name"] . ";charset=utf8mb4",
      $db["user"], $db["password"], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $v = $pdo->query("SELECT value FROM ceo_app_setting WHERE `key` = \x27mdJeton\x27")->fetchColumn();
  echo is_string($v) ? (string) json_decode($v, true) : "";
' "$DIR/config/config.php" 2>/dev/null || true)"
# Jeton pas encore semé (il naît à la première ouverture de MétaDonnée).
[[ -n "$JETON" ]] || exit 0
curl -fsS -m 240 "http://127.0.0.1${ALIAS_PATH}/api/cockpit/metadonnee/cron?jeton=${JETON}" >/dev/null
