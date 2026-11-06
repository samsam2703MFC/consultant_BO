#!/usr/bin/env bash
#
# Le disque du serveur : relevé, ménage sûr, alerte (24/10/2026).
#
# Un disque plein casse tout : apt, MySQL (qui n'écrit plus, ses connexions s'empilent jusqu'à
# « Too many connections »), les sessions PHP — l'API du cockpit répond 503. Ce script ne touche à
# AUCUNE donnée métier : il retire ce qui se recrée tout seul.
#
#   bash bin/menage_disque.sh                  relevé, puis ménage si / dépasse 90 %
#   bash bin/menage_disque.sh --seuil 85       le seuil du ménage, en %
#   bash bin/menage_disque.sh --force          ménage quel que soit le remplissage
#   bash bin/menage_disque.sh --releve         relevé seul, rien n'est supprimé
#   bash bin/menage_disque.sh --alerte         après le ménage, notifie l'admin du cockpit si / dépasse encore 90 %
#   bash bin/menage_disque.sh --mysql          relance MySQL s'il refuse les connexions (« Too many connections »)
#                                              et que le disque a de nouveau de la place
#
# Ce que le ménage retire : le cache apt ; les journaux systemd au-delà de 200 Mo ; les journaux
# tournés de /var/log de plus de 3 jours ; les journaux du cockpit au-delà de 50 Mo (on garde les
# 5 000 dernières lignes) ; les caches de pip, composer, npm, yarn et node-gyp de root ; les images
# et le cache de construction Docker inutilisés depuis 7 jours (jamais un conteneur ni un volume) ;
# les anciennes révisions des snaps ; les binlogs MySQL de plus de 3 jours (seulement sans réplica) ;
# les fichiers de /tmp de plus de 2 jours. Hors relevé, il règle aussi la rétention des binlogs à 3 jours.
# MySQL est piloté par le compte de maintenance d'Ubuntu (/etc/mysql/debian.cnf) : root a un mot de passe.
#
# Posé par bin/deploy.sh : au début de chaque livraison, et toutes les 6 heures par
# /etc/cron.d/cockpit-disque (journal : /var/log/cockpit-disque.log). La dernière ligne dit
# « LIBRE_MO=<n> » : l'espace libre sur / après le ménage, en Mo.
set -uo pipefail

SEUIL=90; FORCE=0; RELEVE=0; ALERTE=0; RELANCE_MYSQL=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --seuil) SEUIL="${2:-90}"; shift 2 ;;
    --force) FORCE=1; shift ;;
    --releve) RELEVE=1; shift ;;
    --alerte) ALERTE=1; shift ;;
    --mysql) RELANCE_MYSQL=1; shift ;;
    *) echo "option inconnue : $1" >&2; exit 2 ;;
  esac
done

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
log()  { printf '\033[1;34m[disque]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[disque]\033[0m %s\n' "$*"; }
pct()  { df -P / | awk 'NR==2 { gsub("%", "", $5); print $5 }'; }
libre_mo() { df -Pm / | awk 'NR==2 { print $4 }'; }
etat() { df -hP / | awk 'NR==2 { print $3 " utilisés sur " $2 " (" $5 "), " $4 " libres" }'; }
# MySQL en administrateur : root y a un mot de passe ; le compte de maintenance d'Ubuntu (debian.cnf) a les droits.
mysqladm() { if [[ -r /etc/mysql/debian.cnf ]]; then mysql --defaults-file=/etc/mysql/debian.cnf "$@"; else mysql "$@"; fi; }

log "$(date '+%Y-%m-%d %H:%M') · disque / : $(etat)"
avant="$(pct)"

# --- Relevé : où va la place --------------------------------------------------
if [[ "$avant" -ge "$SEUIL" || "$FORCE" -eq 1 || "$RELEVE" -eq 1 ]]; then
  for d in / /var /var/lib /var/www /srv /root; do
    [[ -d "$d" ]] || continue
    log "Les plus gros dans ${d} :"
    du -xh --max-depth=1 "$d" 2>/dev/null | sort -rh | sed -n '2,7p' | sed 's/^/    /'
  done
  log "Fichiers de plus de 200 Mo :"
  find / -xdev -type f -size +200M -printf '%s\t%p\n' 2>/dev/null | sort -rn | head -15 | awk -F'\t' '{ printf "    %d Mo\t%s\n", $1 / 1048576, $2 }' || true
  datadir="$(mysqladm -N -e 'SELECT @@datadir' 2>/dev/null || true)"
  if [[ -n "$datadir" ]]; then
    bl="$(mysqladm -N -e 'SHOW BINARY LOGS' 2>/dev/null | awk '{ s += $2 } END { if (NR) printf "%d binlog(s), %.0f Mo", NR, s / 1048576 }')"
    log "MySQL : datadir ${datadir}${bl:+ · $bl}"
    mysqladm -N -e "SELECT table_schema, ROUND(SUM(data_length + index_length) / 1048576) FROM information_schema.tables GROUP BY table_schema ORDER BY 2 DESC LIMIT 8" 2>/dev/null \
      | awk '{ printf "    base %s : %s Mo\n", $1, $2 }' || true
  elif command -v mysql >/dev/null 2>&1; then
    warn "MySQL : pas d'accès administrateur (root, debian.cnf) — binlogs non relevés ni purgés."
  fi
  if command -v docker >/dev/null 2>&1; then
    log "Docker :"; docker system df 2>/dev/null | sed 's/^/    /' || true
  fi
  [[ -d /root/.cache ]] && { log "Cache de root :"; du -xhd1 /root/.cache 2>/dev/null | sort -rh | head -8 | sed 's/^/    /'; }
fi

# --- Ménage --------------------------------------------------------------------
if [[ "$RELEVE" -eq 0 && ( "$avant" -ge "$SEUIL" || "$FORCE" -eq 1 ) ]]; then
  log "Ménage (seuil ${SEUIL} %, disque à ${avant} %)…"
  apt-get clean >/dev/null 2>&1 || true
  journalctl --vacuum-size=200M >/dev/null 2>&1 || true
  find /var/log -xdev -type f \( -name '*.gz' -o -name '*.[0-9]' -o -name '*.old' -o -name '*.xz' \) -mtime +3 -delete 2>/dev/null || true
  for f in /var/log/cockpit-*.log; do
    [[ -f "$f" ]] || continue
    if [[ "$(stat -c %s "$f" 2>/dev/null || echo 0)" -gt 52428800 ]]; then
      tail -n 5000 "$f" > "$f.court" 2>/dev/null && cat "$f.court" > "$f"; rm -f "$f.court"
      log "  $(basename "$f") ramené à ses 5 000 dernières lignes."
    fi
  done
  rm -rf /root/.cache/pip /root/.cache/composer /root/.cache/yarn /root/.cache/node-gyp /root/.npm/_cacache 2>/dev/null || true
  command -v npm >/dev/null 2>&1 && npm cache clean --force >/dev/null 2>&1 || true
  command -v composer >/dev/null 2>&1 && COMPOSER_ALLOW_SUPERUSER=1 composer clear-cache >/dev/null 2>&1 || true
  if command -v docker >/dev/null 2>&1; then
    docker builder prune -af --filter 'until=168h' >/dev/null 2>&1 || true
    docker image prune -af --filter 'until=168h' >/dev/null 2>&1 || true
  fi
  if command -v snap >/dev/null 2>&1; then
    snap list --all 2>/dev/null | awk '/disabled/ { print $1, $3 }' | while read -r nom rev; do snap remove "$nom" --revision="$rev" >/dev/null 2>&1 || true; done
  fi
  # Les binlogs ne servent qu'à un réplica ou à une restauration point par point : aucun réplica ici.
  if mysqladm -N -e 'SELECT 1' >/dev/null 2>&1; then
    if [[ -z "$(mysqladm -N -e 'SHOW REPLICAS' 2>/dev/null || mysqladm -N -e 'SHOW SLAVE HOSTS' 2>/dev/null || true)" ]]; then
      mysqladm -e 'PURGE BINARY LOGS BEFORE NOW() - INTERVAL 3 DAY' >/dev/null 2>&1 || true
    fi
  fi
  find /tmp -xdev -maxdepth 1 -type f -mtime +2 -delete 2>/dev/null || true
  log "Après ménage : $(etat) (avant : ${avant} %)."
fi

# --- Les binlogs ne s'accumulent plus : 3 jours au lieu des 30 par défaut de MySQL 8 ----
# (le 24/10/2026, 4 Go de binlogs sur un disque de 25 Go). Réglage persistant, posé une fois.
if [[ "$RELEVE" -eq 0 ]]; then
  exp="$(mysqladm -N -e 'SELECT @@binlog_expire_logs_seconds' 2>/dev/null || true)"
  if [[ "$exp" =~ ^[0-9]+$ && "$exp" -gt 259200 ]]; then
    mysqladm -e 'SET PERSIST binlog_expire_logs_seconds = 259200' >/dev/null 2>&1 \
      && log "MySQL : binlogs gardés 3 jours (au lieu de $((exp / 86400)) j)." || warn "MySQL : la rétention des binlogs n'a pas pu être réglée."
  fi
fi

# --- MySQL bloqué par le disque plein : il refuse toute connexion -----------------
if [[ "$RELANCE_MYSQL" -eq 1 && "$(libre_mo)" -ge 400 ]]; then
  if mysqladm -N -e 'SELECT 1' 2>&1 | grep -q '1040\|Too many connections'; then
    svc="$(systemctl list-units --type=service --no-legend 2>/dev/null | awk '{ print $1 }' | grep -m1 -E '^(mysql|mariadb|mysqld)\.service$' || true)"
    if [[ -n "$svc" ]]; then
      warn "MySQL refuse les connexions (Too many connections) : relance de ${svc}."
      systemctl restart "$svc" && log "${svc} relancé." || warn "La relance de ${svc} a échoué."
    fi
  fi
fi

# --- Alerte : encore plein après le ménage ------------------------------------------
apres="$(pct)"
if [[ "$ALERTE" -eq 1 && "$apres" -ge 90 ]]; then
  warn "Le disque reste à ${apres} % après le ménage : alerte à l'admin du cockpit."
  php "$DIR/bin/disque_alerte.php" "$apres" "$(libre_mo)" 2>&1 | sed 's/^/    /' || true
fi
echo "LIBRE_MO=$(libre_mo)"
