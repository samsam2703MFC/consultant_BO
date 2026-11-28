"""Assemble avant.png et apres.png côte à côte (avant-apres.png), chacun réduit de moitié, sous un bandeau papier
qui dit lequel est lequel. Police : Gotham du design system (Medium = le trait normal du texte).
    python3 panel/docs/maquettes/equipe-gestion/semaine-avant-apres/composer.py
"""
import os
from PIL import Image, ImageDraw, ImageFont

ICI = os.path.dirname(os.path.abspath(__file__))
DEPOT = os.path.abspath(os.path.join(ICI, '../../../../..'))
POLICE = os.path.join(DEPOT, 'shared/public/assets/ds/fonts/Gotham_Medium.otf')
PAPIER, ENCRE, MUET, FILET, ACCENT = '#faf7f2', '#1c1a17', '#7a736a', '#e6e0d8', '#8D1D2C'

avant = Image.open(os.path.join(ICI, 'avant.png')).convert('RGB')
apres = Image.open(os.path.join(ICI, 'apres.png')).convert('RGB')
w, h = avant.width // 2, avant.height // 2
avant, apres = avant.resize((w, h), Image.LANCZOS), apres.resize((w, h), Image.LANCZOS)

marge, bande, gouttiere = 40, 120, 40
img = Image.new('RGB', (marge * 2 + w * 2 + gouttiere, bande + h + marge), PAPIER)
d = ImageDraw.Draw(img)
titre, note = ImageFont.truetype(POLICE, 34), ImageFont.truetype(POLICE, 22)
for i, (nom, sous) in enumerate([('Avant', 'l’écran en ligne'), ('Après', 'la maquette : le tableau d’abord, le budget de chaque jour en tête')]):
    x = marge + i * (w + gouttiere)
    d.text((x, 34), nom, font=titre, fill=ACCENT if i else ENCRE)
    d.text((x, 80), sous, font=note, fill=MUET)
    img.paste([avant, apres][i], (x, bande))
    d.rectangle([x - 1, bande - 1, x + w, bande + h], outline=FILET, width=1)
img.save(os.path.join(ICI, 'avant-apres.png'), optimize=True)
print('avant-apres.png', img.size)
