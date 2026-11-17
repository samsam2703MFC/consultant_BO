#!/usr/bin/env python3
"""Maquette « Le simulateur de couples », version légère (17/11/2026, Sam : « plus lean, moins lourd, refaire l'UX »).
Des pastilles au lieu des tuiles, pas de bulle ni de liste en plus ; à droite, un seul panneau : le prix, une petite jauge
du coefficient avec la marge dessus, trois chiffres, un bouton. Génère sl-a-avant.html (rien de cliqué) et
sl-b-couple.html (Pain au chocolat, puis Cappuccino) depuis halle.json."""
import json, math, os, re, unicodedata

ICI = os.path.dirname(os.path.abspath(__file__))
D = json.load(open(os.path.join(ICI, 'halle.json'), encoding='utf-8'))['produits']
def famille(c):
    s = ''.join(ch for ch in unicodedata.normalize('NFD', c.lower()) if unicodedata.category(ch) != 'Mn')
    return (re.split(r'[^a-z0-9]+', s.strip()) or [''])[0]
for r in D:
    r['m'] = (r['ht'] - r['cout']) / r['ht'] * 100; r['me'] = r['ht'] - r['cout']; r['f'] = famille(r['cat']); r['coef'] = r['ht'] / r['cout']
    n = re.sub(r'\s*\([^)]*\)\s*$', '', r['nom']); r['nomc'] = n[:1].upper() + n[1:]
par = {r['nom']: r for r in D}
P = [r for r in D if r['ttc'] <= 8]
G = sorted(P, key=lambda r: -r['q'])[:26]
G += [r for r in P if r['m'] >= 78 and r['q'] >= 5 and r not in G]
FAM = {'viennoiserie': 'Viennoiserie', 'pain': 'Pains', 'petite': 'Pains', 'boissons': 'Boissons', 'patisserie': 'Pâtisserie', 'entremets': 'Pâtisserie',
       'cookies': 'Pâtisserie', 'cougnou': 'Pâtisserie', 'sandwiches': 'Snacking'}
ORDRE = ['Viennoiserie', 'Pains', 'Boissons', 'Pâtisserie', 'Snacking']
SEUILS = (1.67, 2.5)
def nf(x, d=0): return f"{x:,.{d}f}".replace(',', ' ').replace('.', ',').replace(' ', ' ')
def eur(x, d=2): return nf(x, d) + ' €'
def pct(x, d=1): return nf(x, d) + ' %'
def couple(X, Y, prix=None):
    sep = X['ttc'] + Y['ttc']; prix = prix if prix is not None else round(round(sep * 0.9 * 10) / 10, 2)
    htB = prix * (X['ht'] + Y['ht']) / sep; mB = htB - X['cout'] - Y['cout']
    return {'sep': sep, 'prix': prix, 'remisePct': (sep - prix) / sep * 100, 'mB': mB, 'mBpct': mB / htB * 100, 'gain': mB - X['me'],
            'pts': mB / htB * 100 - X['m'], 'coef': htB / (X['cout'] + Y['cout']), 'mois': 0.1 * X['q'] * (mB - X['me'])}
RAMPE = [(26, 23, 20), (74, 56, 26), (128, 95, 34), (176, 135, 50), (214, 174, 69)]
def teinte(t):
    t = max(0.0, min(1.0, t)); k = min(len(RAMPE) - 2, int(t * (len(RAMPE) - 1))); u = t * (len(RAMPE) - 1) - k
    return '#%02x%02x%02x' % tuple(round(RAMPE[k][i] + (RAMPE[k + 1][i] - RAMPE[k][i]) * u) for i in range(3)), t > 0.62

def jauge(cx0, cb, marge):
    """Une petite jauge du coefficient (×1 → ×5) ; au centre, le coefficient et la marge du ticket."""
    w, h, cx, cy, R = 300, 178, 150, 140, 118
    a = lambda v: math.pi * (1 - (min(5, max(1, v)) - 1) / 4)
    pt = lambda v, r: (cx + r * math.cos(a(v)), cy - r * math.sin(a(v)))
    def arc(v0, v1, col):
        (x0, y0), (x1, y1) = pt(v0, R), pt(v1, R)
        return f'<path d="M{x0:.1f},{y0:.1f} A{R},{R} 0 0 1 {x1:.1f},{y1:.1f}" fill="none" stroke="{col}" stroke-width="10" stroke-linecap="butt"/>'
    s = arc(1, SEUILS[0], '#e3a59c') + arc(SEUILS[0], SEUILS[1], '#ecd09a') + arc(SEUILS[1], 5, '#a9cdb3')
    for v, lab in [(SEUILS[0], '1,67'), (SEUILS[1], '2,5')]:
        x, y = pt(v, R + 17); s += f'<text x="{x:.1f}" y="{y + 4:.1f}" font-size="10.5" text-anchor="middle" fill="#9a948b">×{lab}</text>'
    x, y = pt(cx0, R - 12); x2, y2 = pt(cx0, R + 7)
    s += f'<line x1="{x:.1f}" y1="{y:.1f}" x2="{x2:.1f}" y2="{y2:.1f}" stroke="#8a847c" stroke-width="3" stroke-linecap="round"/>'
    x, y = pt(cb, R - 4); x2, y2 = pt(cb, R - 40)
    s += f'<line x1="{x2:.1f}" y1="{y2:.1f}" x2="{x:.1f}" y2="{y:.1f}" stroke="#8D1D2C" stroke-width="4" stroke-linecap="round"/><circle cx="{x:.1f}" cy="{y:.1f}" r="5" fill="#8D1D2C" stroke="#fff" stroke-width="2"/>'
    s += f'<text x="{cx}" y="{cy - 34}" font-size="30" text-anchor="middle" fill="#1c1a17">×{nf(cb, 2)}</text>'
    s += f'<text x="{cx}" y="{cy - 12}" font-size="12" text-anchor="middle" fill="#5c5852">marge du ticket {pct(marge)}</text>'
    s += f'<text x="{cx - R}" y="{cy + 18}" font-size="10.5" text-anchor="middle" fill="#9a948b">×1</text><text x="{cx + R}" y="{cy + 18}" font-size="10.5" text-anchor="middle" fill="#9a948b">×5</text>'
    return f'<svg width="{w}" height="{h}" viewBox="0 0 {w} {h}" style="display:block;margin:0 auto;font-family:inherit" role="img" aria-label="Coefficient du ticket">{s}</svg>'

def ecran(xnom=None, ynom=None, prix=None):
    X = par.get(xnom); Y = par.get(ynom)
    cand = {r['nom']: couple(X, r) for r in G if X and r is not X and r['f'] != X['f']}
    best = max([c['pts'] for c in cand.values()] or [1])
    groupes = ''
    for g in ORDRE:
        L = sorted([r for r in G if FAM.get(r['f'], 'Snacking') == g], key=lambda r: -r['q'])
        if not L: continue
        pills = ''
        for r in L:
            if X is None:
                pills += f'<button class="sl-p">{r["nomc"]}</button>'
            elif r is X:
                pills += f'<button class="sl-p x">{r["nomc"]}</button>'
            elif r['nom'] not in cand:
                pills += f'<button class="sl-p fam">{r["nomc"]}</button>'
            else:
                c = cand[r['nom']]; col, clair = teinte(c['pts'] / best) if c['pts'] > 0 else ('#141210', False)
                pills += f'<button class="sl-p c{" clair" if clair else ""}{" sel" if r is Y else ""}" style="background:{col}">{r["nomc"]}<small>{("+" + nf(c["pts"], 0)) if c["pts"] > 0 else "−"}</small></button>'
        groupes += f'<div class="sl-g"><span>{g}</span><div>{pills}</div></div>'
    if X is None:
        droite = '<aside class="sl-d vide"><p>Cliquez le produit qui attire&nbsp;:<br>les autres se colorent, du noir à l’or.</p></aside>'
        aide = 'Cliquez le produit qui attire'
    else:
        c = couple(X, Y, prix)
        zb = 'ok' if c['coef'] >= SEUILS[1] else ('att' if c['coef'] >= SEUILS[0] else 'ko')
        droite = f'''<aside class="sl-d">
        <div class="sl-duo"><b>{X["nomc"]}</b><span>+</span><b class="or">{Y["nomc"]}</b></div>
        <div class="sl-prix"><button>−</button><div><b>{eur(c["prix"])}</b><span>au lieu de {eur(c["sep"])} · −{pct(c["remisePct"], 0)}</span></div><button>+</button></div>
        {jauge(X["coef"], c["coef"], c["mBpct"])}
        <p class="sl-seul"><i></i>{X["nomc"].lower()} seul : ×{nf(X["coef"], 2)} · {pct(X["m"])}</p>
        <div class="sl-3"><div><b class="ok">+{nf(c["pts"], 1)} pts</b><span>de marge</span></div><div><b class="ok">+{eur(c["gain"])}</b><span>par ticket</span></div><div><b>+{nf(c["mois"])}&nbsp;€</b><span>par mois*</span></div></div>
        <button class="bd-btn go sl-go">Préparer la fiche ›</button>
        <p class="sl-note">* si 1 client sur 10 prend le bundle</p></aside>'''
        aide = f'{X["nomc"]} · {pct(X["m"])} de marge'
    leg = ''.join(f'<i style="background:{teinte(k / 6)[0]}"></i>' for k in range(7))
    return f'''<section class="db-card bd sl" id="pc">
<header class="sl-h"><img src="avatars/vente.png" alt=""><div><b>Simulateur de couples</b><span>{aide}</span></div>
  <span class="sl-leg">baisse {leg} monte la marge</span>
  <span class="sl-mode"><button>Proposés</button><button class="on">Simulateur</button></span></header>
<div class="sl-c"><div class="sl-gs">{groupes}</div>{droite}</div>
</section>
<p class="pc-maq">Maquette — vrais prix, coûts et ventes de Halle ; la couleur : les points de marge du ticket au prix conseillé (−10 %). En pointillé : la même famille (ils se remplacent).</p>'''

open(os.path.join(ICI, 'sl-a-avant.html'), 'w', encoding='utf-8').write(ecran())
open(os.path.join(ICI, 'sl-b-couple.html'), 'w', encoding='utf-8').write(ecran('Pain au Chocolat', 'Cappuccino (à emporter)', 4.20))
