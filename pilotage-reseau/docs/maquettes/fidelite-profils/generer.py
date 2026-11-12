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

ordre = [4, 3, 2, 1]
def serie(cle, k):
    axes = COMP if cle == 'comp' else FAM
    return ([NIV[k][cle][a] for a in axes], NIV[k]['col'], .16 if k == 4 else .08, 2.2 if k == 4 else 1.4)
r_comp = radar([serie('comp', k) for k in reversed(ordre)], COMPL, 340)
r_fam = radar([serie('fam', k) for k in reversed(ordre)], FAML, 340, maxv=80)
leg = ''.join(f'<span class="lg"><i style="background:{NIV[k]["col"]}"></i>{NIV[k]["nom"]} <span class="muted">· {NIV[k]["n"]}</span></span>' for k in ordre)

lad = []
for k in [1, 2, 3, 4]:
    x = NIV[k]
    vu = 'hier' if x['rec'] == 0 else f"il y a {x['rec']} j"
    lad.append(f'''<div class="step{' is-top' if k == 4 else ''}">
      <div class="step__head"><span class="lvl" style="background:{x['col']}">{k}</span><span class="step__nom">{x['nom']}</span></div>
      <div class="step__val">{x['n']}<span> client{'s' if x['n'] > 1 else ''}</span></div>
      <div class="step__rule">{x['regle']}, sur 90 jours</div>
      <div class="step__bar"><span style="width:{max(3, x['n']/189*100):.0f}%;background:{x['col']}"></span></div>
      <dl>
        <dt>CA</dt><dd>{eur(x['eur'], 0)}</dd>
        <dt>Panier</dt><dd>{eur(x['pan'])}</dd>
        <dt>En commande</dt><dd>{round(100*x['cmd']/x['tk'])} %</dd>
        <dt>Dernier passage</dt><dd>{vu} <span class="muted">méd.</span></dd>
      </dl></div>''')
    if k < 4:
        nxt = {1: 'Déclencheur « 2e visite » : 30 points', 2: 'Carte à tampons dès le 3e passage', 3: 'Bon de 5 € au 6e passage'}[k]
        lad.append(f'<div class="arrow"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#B8B2A8" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg><span>{nxt}</span></div>')
ladder = ''.join(lad)

def timeline(js):
    o = ['<line x1="0" x2="240" y1="9" y2="9" stroke="#e6e0d8"/>']
    for m, lbl in ((17, 'sept.'), (47, 'oct.'), (78, 'nov.')):
        x = m / 89 * 236 + 2
        o.append(f'<line x1="{x:.1f}" x2="{x:.1f}" y1="4" y2="14" stroke="#e6e0d8"/><text x="{x+2:.1f}" y="25" font-size="9.5" fill="#B8B2A8">{lbl}</text>')
    for j in js:
        o.append(f'<circle cx="{j/89*236+2:.1f}" cy="9" r="3.5" fill="#8D1D2C"/>')
    return f'<svg viewBox="0 0 240 28" width="100%" height="28">{"".join(o)}</svg>'

def top_fam(f):
    t = sorted(f.items(), key=lambda kv: -kv[1])
    return ' · '.join([f'{FAML[FAM.index(k)].lower()} {v} %' for k, v in t if v > 0][:3])

actions = {
 'Client A': 'Lui proposer la carte : 16 passages, aucun ne lui a rapporté de point.',
 'Client B': 'Le pain du matin : une carte Pain le fait revenir chaque semaine.',
 'Client C': 'Il achète de tout : le profil idéal pour la jauge de points.',
 'Client D': 'Tartes seulement : l’inviter aux temps forts (cougnou, fêtes).',
 'Client E': 'Le salé du midi : lui parler de la formule lunch.',
 'Client F': 'Anti-gaspi de fin de journée : un bon sur sa prochaine viennoiserie.',
}
cards = []
for c in CLI:
    x = NIV[c['niv']]
    vu = 'vu hier' if c['rec'] == 0 else f"vu il y a {c['rec']} j"
    rc = radar([([c['comp'][a] for a in COMP], x['col'], .18, 1.6)], ['Fréq.', 'Réc.', 'Anc.', 'Panier', 'Div.', 'Compt.'], 180, fs=9.5, pad=40)
    rf = radar([([c['fam'][a] for a in FAM], x['col'], .18, 1.6)], ['Pain', 'Vienn.', 'Anti-g.', 'Tartes', 'Goûter', 'Salé'], 180, fs=9.5, pad=40)
    cards.append(f'''<article class="cli card">
      <header><span class="lvl" style="background:{x['col']}">{c['niv']}</span><b>{c['n']}</b><span class="chip chip--neutral">{x['nom']}</span><span class="muted r">{vu}</span></header>
      {timeline(c['jours'])}
      <div class="cli__nums"><span><em>{len(c['jours'])}</em> jours</span><span><em>{eur(c['eur'], 0)}</em> en 90 j</span><span><em>{eur(c['eur']/c['tk'])}</em> / ticket</span><span><em>{c['cmd']} / {c['tk']}</em> en commande</span></div>
      <div class="cli__radars"><div><p class="mini-t">Récurrence</p>{rc}</div><div><p class="mini-t">Type d’achat</p>{rf}</div></div>
      <p class="cli__fam">{top_fam(c['fam'])}</p>
      <p class="cli__act">{actions[c['n']]}</p>
    </article>''')
cards = ''.join(cards)

fams = [('Tartes & fêtes', 116), ('Pain', 85), ('Viennoiserie', 56), ('Goûter', 46), ('Salé', 18), ('Anti-gaspi', 16)]
famhtml = ''.join(f'<div class="hb"><span>{n}</span><span class="hb__t"><span style="width:{v/116*100:.0f}%"></span></span><span class="num">{v}</span></div>' for n, v in fams)

css = open(BASE + 'fidelite-profils/profils.css').read()
corps = open(BASE + 'fidelite-profils/profils.body.html').read()
for k, v in dict(RAIL=rail, LADDER=ladder, RCOMP=r_comp, RFAM=r_fam, LEG=leg, FAMS=famhtml, CARDS=cards).items():
    corps = corps.replace('{{' + k + '}}', v)
html = ('<!doctype html>\n<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">\n'
        '<title>Maquette — Espace franchisé · Fidélité › Profils clients (Halle)</title>\n<style>' + style + css + '</style></head>\n' + corps)
open(BASE + 'fidelite-profils/index.html', 'w').write(html)
print('écrit', len(html))
