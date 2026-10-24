#!/usr/bin/env bash
#
# Chiffre un dump de l'ERP pour le serveur, afin de le faire passer par le dépôt
# (public) sans l'exposer :
#
#   apps/production/deploy/encrypt_dump.sh erp.zip cle_publique.pem
#
# La clé publique est celle que deploy/server.sh affiche dans le log du
# déploiement (« CLE PUBLIQUE DE TRANSFERT ERP ») tant que la base est vide.
# Produit deploy/incoming/{erp.zip.enc, erp.key.enc, erp.zip.sha256} :
# AES-256 (clé aléatoire) pour le dump, RSA-OAEP-SHA256 pour la clé AES.
# Au déploiement suivant, le serveur déchiffre, vérifie l'empreinte, importe,
# puis détruit sa clé privée : les fichiers chiffrés deviennent illisibles.
set -euo pipefail

dump="${1:?usage: $0 dump.zip cle_publique.pem}"
pub="${2:?usage: $0 dump.zip cle_publique.pem}"
out="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/incoming"

openssl pkey -pubin -in "$pub" -noout -text | grep -q '4096 bit' \
  || { echo "Clé publique RSA 4096 attendue : $pub" >&2; exit 1; }
mkdir -p "$out"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

openssl rand -hex 32 > "$tmp/k"
openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass "file:$tmp/k" -in "$dump" -out "$out/erp.zip.enc"
openssl pkeyutl -encrypt -pubin -inkey "$pub" -pkeyopt rsa_padding_mode:oaep -pkeyopt rsa_oaep_md:sha256 \
  -in "$tmp/k" -out "$out/erp.key.enc"
sha256sum "$dump" | cut -d' ' -f1 > "$out/erp.zip.sha256"
echo "Chiffré dans $out ($(du -h "$out/erp.zip.enc" | cut -f1))"
