#!/usr/bin/env python3
"""Le dossier pour un agent de code : la fiche produits du ticket de caisse.

    python3 construire.py   →  fiche-ticket.html     la vraie page (marketing/public/ticket/), autonome : CSS, JS, polices,
                                                     logo, vignettes et données d'exemple intégrés ; s'ouvre sans serveur
                               fiche-ticket-agent.zip  fiche-ticket.html + PROMPT_AGENT.md + donnees-exemple.json

La page est assemblée à partir des fichiers servis (ticket.css, ticket.js) : elle ne diverge jamais de la page en ligne.
Les données sont donnees-exemple.json (une réponse de GET /marketing/ticket/client), passées par window.TICKET_DONNEES.
Les vignettes du webshop sont lues en ligne une fois et réduites (PIL), pour que le fichier reste lisible hors ligne.
"""
from __future__ import annotations

import base64
import io
import json
import os
import re
import urllib.request
import zipfile

ICI = os.path.dirname(os.path.abspath(__file__))
RACINE = os.path.abspath(os.path.join(ICI, '../../..'))
PAGE = os.path.join(RACINE, 'marketing/public/ticket')
POLICES = os.path.join(RACINE, 'shared/public/assets/ds/fonts')
LOGO = os.path.join(RACINE, 'shared/public/assets/img/logo.png')


def data_uri(octets: bytes, mime: str) -> str:
    return f'data:{mime};base64,' + base64.b64encode(octets).decode('ascii')


def vignette(src: str) -> str:
    """Une image de produit → data URI JPEG de 240 px (lue en ligne ou dans le dépôt)."""
    if not src or src.startswith('data:'):
        return src
    try:
        if src.startswith('http'):
            req = urllib.request.Request(src, headers={'User-Agent': 'Mozilla/5.0 (fiche-ticket, construire.py)'})
            with urllib.request.urlopen(req, timeout=30) as r:
                brut = r.read()
        else:
            with open(os.path.normpath(os.path.join(ICI, src)), 'rb') as f:
                brut = f.read()
        from PIL import Image  # réduction : le fichier reste léger
        im = Image.open(io.BytesIO(brut)).convert('RGB')
        im.thumbnail((240, 240))
        out = io.BytesIO()
        im.save(out, 'JPEG', quality=82)
        return data_uri(out.getvalue(), 'image/jpeg')
    except Exception as e:  # une image illisible : la page montre une vignette vide
        print('vignette illisible :', src, e)
        return ''


def construire() -> None:
    with open(os.path.join(PAGE, 'ticket.css'), encoding='utf-8') as f:
        css = f.read()
    with open(os.path.join(PAGE, 'ticket.js'), encoding='utf-8') as f:
        js = f.read()
    with open(os.path.join(ICI, 'donnees-exemple.json'), encoding='utf-8') as f:
        donnees = json.load(f)

    # Les polices, dans la feuille.
    def police(m: re.Match) -> str:
        nom = m.group(1)
        with open(os.path.join(POLICES, nom), 'rb') as f:
            return "url('" + data_uri(f.read(), 'font/ttf' if nom.endswith('.ttf') else 'font/otf') + "')"
    css = re.sub(r"url\('\.\./assets/ds/fonts/([^']+)'\)", police, css)
    # Le logo, dans le script.
    with open(LOGO, 'rb') as f:
        js = js.replace('../assets/img/logo.png', data_uri(f.read(), 'image/png'))
    # Les vignettes, dans les données.
    for l in donnees['lignes']:
        l['image'] = vignette(l.get('image') or '')
    donnees.pop('_exemple', None)

    html = f"""<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#FCFBF8">
<title>L’Atelier By — fiche produits du ticket (référence)</title>
<!-- Référence pour un agent de code (voir PROMPT_AGENT.md) : la page en ligne (marketing/public/ticket/ de consultant_BO),
     assemblée par construire.py avec un ticket d'exemple. Ouvrir au format téléphone (390 px) puis ordinateur ;
     ?lang=nl pour le néerlandais. -->
<style>
{css}
</style>
</head>
<body>
<div id="page"></div>
<script>window.TICKET_DONNEES = {json.dumps(donnees, ensure_ascii=False)};</script>
<script>
{js}
</script>
</body>
</html>
"""
    sortie = os.path.join(ICI, 'fiche-ticket.html')
    with open(sortie, 'w', encoding='utf-8') as f:
        f.write(html)
    print('fiche-ticket.html', len(html.encode('utf-8')) // 1024, 'Ko')

    with zipfile.ZipFile(os.path.join(ICI, 'fiche-ticket-agent.zip'), 'w', zipfile.ZIP_DEFLATED) as z:
        for nom in ('PROMPT_AGENT.md', 'fiche-ticket.html', 'donnees-exemple.json'):
            z.write(os.path.join(ICI, nom), 'fiche-ticket-agent/' + nom)
    print('fiche-ticket-agent.zip', os.path.getsize(os.path.join(ICI, 'fiche-ticket-agent.zip')) // 1024, 'Ko')


if __name__ == '__main__':
    construire()
