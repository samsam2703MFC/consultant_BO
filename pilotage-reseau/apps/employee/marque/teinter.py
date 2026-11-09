#!/usr/bin/env python3
"""Teint le thème Mazer de l'app employés aux couleurs et à la typo de L'Atelier (demande du 06/10/2026).

Lit les CSS compilés de Mazer (public/employee/assets/mazer/compiled/css) et écrit leur version de
marque dans public/employee/assets/atelier/ : le bleu Mazer devient le rouge rubis, le fond bleuté
le beige, Nunito devient Gotham. Rejouable : python3 apps/employee/marque/teinter.py
"""
import os, re

RACINE = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..'))
SRC = os.path.join(RACINE, 'public', 'employee', 'assets', 'mazer', 'compiled', 'css')
DST = os.path.join(RACINE, 'public', 'employee', 'assets', 'atelier')

# Le bleu de Mazer et sa famille → le rouge rubis de la marque et la sienne ; les fonds bleutés → beige.
COULEURS = [
    ('#435ebe', '#8D1D2C'), ('#3c55ab', '#7A1926'), ('#364b98', '#6E1622'), ('#25396f', '#222222'),
    ('#1b264c', '#4A0F17'), ('#0d1326', '#2A090D'), ('#283872', '#5A121C'), ('#8e9ed8', '#D59AA3'),
    ('#a1afdf', '#D9A9AF'), ('#b4bfe5', '#E3B7BD'), ('#d9dff2', '#F3DADD'), ('#7c8db5', '#8A847C'),
    ('#dce7f1', '#E4DCD2'), ('#ebf3ff', '#F4EFE8'), ('#e6eef5', '#EFE8DF'), ('#f2f7ff', '#EAE4DC'),
    ('#607080', '#222222'),
]
TRIPLETS = [
    ('67, 94, 190', '141, 29, 44'), ('67,94,190', '141,29,44'), ('95, 118, 200', '160, 52, 66'),
    ('242, 247, 255', '234, 228, 220'), ('96, 112, 128', '34, 34, 34'),
]

def teinter(css: str) -> str:
    for a, b in COULEURS:
        css = re.sub(re.escape(a), b, css, flags=re.I)
        css = re.sub(re.escape(a.replace('#', '%23')), b.replace('#', '%23'), css, flags=re.I)   # dans les SVG en data:
    for a, b in TRIPLETS:
        css = css.replace(a, b)
    css = re.sub(r'font-family:\s*Nunito', "font-family:'Gotham',system-ui,sans-serif", css)
    css = re.sub(r'--bs-body-font-family:\s*"Nunito"', "--bs-body-font-family: 'Gotham', system-ui, sans-serif", css)
    return '/* Teint aux couleurs de L\'Atelier par apps/employee/marque/teinter.py — ne pas modifier à la main. */\n' + css

os.makedirs(DST, exist_ok=True)
for nom in ('app.css', 'auth.css', 'error.css'):
    with open(os.path.join(SRC, nom), encoding='utf-8') as f:
        css = f.read()
    with open(os.path.join(DST, nom), 'w', encoding='utf-8') as f:
        f.write(teinter(css))
    print(nom, 'teint')
