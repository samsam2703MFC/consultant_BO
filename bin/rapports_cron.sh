#!/usr/bin/env bash
#
# Appelé chaque heure par /etc/cron.d/cockpit-rapports (posé par bin/deploy.sh).
# Le jeton est lu EN BASE au moment de l'appel : rien de secret dans le crontab
# ni dans ce fichier, et une rotation du jeton ne casse pas l'envoi automatique.
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ALIAS_PATH="${ALIAS_PATH:-/consulant_bo}"
JETON="$(php -r '
  $cfg = require $argv[1]; $db = $cfg["db"];
  $pdo = new PDO("mysql:host=" . $db["host"] . ";port=" . $db["port"] . ";dbname=" . $db["name"] . ";charset=utf8mb4",
      $db["user"], $db["password"], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $v = $pdo->query("SELECT value FROM ceo_app_setting WHERE `key` = \x27rapportsJeton\x27")->fetchColumn();
  echo is_string($v) ? (string) json_decode($v, true) : "";
' "$DIR/config/config.php" 2>/dev/null || true)"
# Jeton pas encore semé (il naît au premier chargement de l'écran Reporting).
[[ -n "$JETON" ]] || exit 0
curl -fsS -m 600 "http://127.0.0.1${ALIAS_PATH}/api/cockpit/rapports/cron?jeton=${JETON}" >/dev/null
# E-mail « commande fournisseur » (centrale d'achat) : même jeton, même heure.
curl -fsS -m 300 "http://127.0.0.1${ALIAS_PATH}/api/cockpit/centrale/commandes/mail/cron?jeton=${JETON}" >/dev/null || true
