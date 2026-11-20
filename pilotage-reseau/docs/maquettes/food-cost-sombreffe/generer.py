#!/usr/bin/env python3
"""La page du food cost de Sombreffe : gabarit.html + donnees.json + les deux polices Gotham → index.html, un seul fichier,
sans aucun appel réseau (données et polices dedans), à poser tel quel sur le serveur.

    python3 docs/maquettes/food-cost-sombreffe/generer.py
"""
import base64
import json
from pathlib import Path

ICI = Path(__file__).resolve().parent
POLICES = ICI.parent.parent.parent / 'public' / 'assets' / 'ds' / 'fonts'


def police(fichier, graisse):
    b64 = base64.b64encode((POLICES / fichier).read_bytes()).decode('ascii')
    return (f"@font-face {{ font-family: 'Gotham'; src: url(data:font/otf;base64,{b64}) format('opentype'); "
            f"font-weight: {graisse}; font-display: swap; }}")


def main():
    donnees = json.loads((ICI / 'donnees.json').read_text(encoding='utf-8'))
    html = (ICI / 'gabarit.html').read_text(encoding='utf-8')
    html = html.replace('/*__POLICES__*/', police('Gotham_Medium.otf', 400) + '\n' + police('Gotham_Regular.otf', 700))
    html = html.replace('/*__DONNEES__*/null', json.dumps(donnees, ensure_ascii=False, separators=(',', ':')))
    (ICI / 'index.html').write_text(html, encoding='utf-8')
    print('écrit index.html,', round(len(html.encode('utf-8')) / 1024), 'Ko,', len(donnees['produits']), 'produits')


if __name__ == '__main__':
    main()
