import re, math, sys
BASE = __import__('os').path.dirname(__import__('os').path.abspath(__file__)) + '/../'
src = open(BASE + 'fidelite-franchise/index.html').read()
style = re.search(r'<style>(.*?)</style>', src, re.S).group(1)
rail = re.search(r'(<nav class="sidebar".*?</nav>)', src, re.S).group(1)

COMP = ['frequence', 'recence', 'anciennete', 'panier', 'diversite', 'comptoir']
COMPL = ['Fréquence', 'Récence', 'Ancienneté', 'Panier', 'Diversité', 'Comptoir']
FAM = ['pain', 'vienno', 'antigaspi', 'tartes', 'gouter', 'sale']
FAML = ['Pain', 'Viennoiserie', 'Anti-gaspi', 'Tartes & fêtes', 'Goûter', 'Salé']
NIV = {
 4: dict(nom='Habitué', col='#8D1D2C', regle='6 jours de passage ou plus', n=2, tk=23, eur=306.00, pan=13.30, cmd=18, rec=0,
         comp=dict(frequence=79, recence=100, anciennete=79, panier=27, diversite=50, comptoir=32), fam=dict(pain=75, vienno=19, antigaspi=0, tartes=5, gouter=1, sale=0)),
 3: dict(nom='Régulier', col='#B0792A', regle='3 à 5 jours de passage', n=4, tk=12, eur=408.54, pan=34.04, cmd=6, rec=8,
         comp=dict(frequence=25, recence=93, anciennete=58, panier=57, diversite=54, comptoir=50), fam=dict(pain=24, vienno=4, antigaspi=13, tartes=36, gouter=1, sale=23)),
 2: dict(nom='De retour', col='#3f7a52', regle='2 jours de passage', n=14, tk=28, eur=1100.70, pan=39.31, cmd=10, rec=35,
         comp=dict(frequence=17, recence=66, anciennete=32, panier=65, diversite=34, comptoir=64), fam=dict(pain=31, vienno=14, antigaspi=7, tartes=39, gouter=7, sale=2)),
 1: dict(nom='Découverte', col='#9a948b', regle='1 jour de passage', n=189, tk=190, eur=9081.56, pan=47.80, cmd=70, rec=32,
         comp=dict(frequence=8, recence=56, anciennete=0, panier=64, diversite=26, comptoir=63), fam=dict(pain=25, vienno=19, antigaspi=5, tartes=38, gouter=8, sale=5)),
}
CLI = [
 dict(n='Client A', niv=4, jours=[38,43,45,48,56,63,64,68,71,74,77,78,81,84,88,89], tk=16, eur=145.00, cmd=15, rec=0,
      comp=dict(frequence=100, recence=100, anciennete=57, panier=15, diversite=67, comptoir=6), fam=dict(pain=78, vienno=10, antigaspi=0, tartes=10, gouter=2, sale=0)),
 dict(n='Client B', niv=4, jours=[0,12,24,39,51,81,89], tk=7, eur=161.00, cmd=3, rec=0,
      comp=dict(frequence=58, recence=100, anciennete=100, panier=38, diversite=33, comptoir=57), fam=dict(pain=72, vienno=28, antigaspi=0, tartes=0, gouter=0, sale=0)),
 dict(n='Client C', niv=3, jours=[5,70,86], tk=3, eur=131.10, cmd=1, rec=3,
      comp=dict(frequence=25, recence=97, anciennete=91, panier=73, diversite=100, comptoir=67), fam=dict(pain=56, vienno=7, antigaspi=20, tartes=5, gouter=2, sale=10)),
 dict(n='Client D', niv=3, jours=[30,72,81], tk=3, eur=114.20, cmd=1, rec=8,
      comp=dict(frequence=25, recence=91, anciennete=57, panier=63, diversite=17, comptoir=67), fam=dict(pain=0, vienno=0, antigaspi=0, tartes=100, gouter=0, sale=0)),
 dict(n='Client E', niv=3, jours=[10,20,75], tk=3, eur=107.50, cmd=1, rec=14,
      comp=dict(frequence=25, recence=84, anciennete=73, panier=60, diversite=33, comptoir=67), fam=dict(pain=0, vienno=0, antigaspi=0, tartes=20, gouter=0, sale=80)),
 dict(n='Client F', niv=3, jours=[77,81,87], tk=3, eur=55.74, cmd=3, rec=2,
      comp=dict(frequence=25, recence=98, anciennete=11, panier=31, diversite=67, comptoir=0), fam=dict(pain=41, vienno=7, antigaspi=33, tartes=19, gouter=0, sale=0)),
]

def eur(v, d=2):
    return f"{v:,.{d}f}".replace(',', ' ').replace('.', ',') + ' €'

def radar(series, labels, size=300, maxv=100, lab=True, fs=11, pad=58):
    cx = cy = size / 2; R = size / 2 - pad; n = len(labels); o = []
    ang = lambda i: -math.pi / 2 + 2 * math.pi * i / n
    for f in (0.25, 0.5, 0.75, 1):
        pts = ' '.join(f"{cx+R*f*math.cos(ang(i)):.1f},{cy+R*f*math.sin(ang(i)):.1f}" for i in range(n))
        o.append(f'<polygon points="{pts}" fill="none" stroke="#e6e0d8" stroke-width="1"/>')
    for i in range(n):
        x = cx + R * math.cos(ang(i)); y = cy + R * math.sin(ang(i))
        o.append(f'<line x1="{cx}" y1="{cy}" x2="{x:.1f}" y2="{y:.1f}" stroke="#e6e0d8"/>')
        if lab:
            lx = cx + (R + 12) * math.cos(ang(i)); ly = cy + (R + 12) * math.sin(ang(i))
            anc = 'middle' if abs(math.cos(ang(i))) < 0.3 else ('start' if math.cos(ang(i)) > 0 else 'end')
            dy = -4 if math.sin(ang(i)) < -0.5 else (12 if math.sin(ang(i)) > 0.5 else 4)
            o.append(f'<text x="{lx:.1f}" y="{ly+dy:.1f}" font-size="{fs}" text-anchor="{anc}" fill="#5c5852">{labels[i]}</text>')
    for vals, col, op, w in series:
        pts = ' '.join(f"{cx+R*min(maxv,max(2,v))/maxv*math.cos(ang(i)):.1f},{cy+R*min(maxv,max(2,v))/maxv*math.sin(ang(i)):.1f}" for i, v in enumerate(vals))
        o.append(f'<polygon points="{pts}" fill="{col}" fill-opacity="{op}" stroke="{col}" stroke-width="{w}" stroke-linejoin="round"/>')
    return f'<svg viewBox="0 0 {size} {size}" width="100%" style="display:block;max-width:{size}px;margin:0 auto">{"".join(o)}</svg>'

exec(open(BASE + 'fidelite-profils/vignettes.py').read())

css = open(BASE + 'fidelite-profils/profils.css').read()
corps = open(BASE + 'fidelite-profils/profils.body.html').read()
for k, v in dict(RAIL=rail, LADDER=ladder, RCOMP=r_comp, RFAM=r_fam, LEG=leg, FAMS=famhtml, CARDS=cards).items():
    corps = corps.replace('{{' + k + '}}', v)
html = ('<!doctype html>\n<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">\n'
        '<title>Maquette — Espace franchisé · Fidélité › Profils clients (Halle)</title>\n<style>' + style + css + '</style></head>\n' + corps)
open(BASE + 'fidelite-profils/index.html', 'w').write(html)
print('écrit', len(html))
