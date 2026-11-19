#!/usr/bin/env python3
"""Maquette « Le simulateur de couples » (17/11/2026, Sam : « pour les couples, un simulateur, avec un bloc de marge sur
une jauge coefficient ; quand je clique sur un produit, un code couleur entre noir et or avec le produit à apparier pour
augmenter la marge »). Génère s-a-pain-au-chocolat.html et s-b-baguette.html depuis halle.json (vrais prix, coûts et
ventes de Halle). Lancer : python3 generer_simulateur.py, puis SEUL=s-a-pain-au-chocolat node capturer.cjs."""
import json, math, os, re, unicodedata

ICI = os.path.dirname(os.path.abspath(__file__))
D = json.load(open(os.path.join(ICI, 'halle.json'), encoding='utf-8'))['produits']
def famille(c):
    s = ''.join(ch for ch in unicodedata.normalize('NFD', c.lower()) if unicodedata.category(ch) != 'Mn')
    return (re.split(r'[^a-z0-9]+', s.strip()) or [''])[0]
for r in D:
    r['m'] = (r['ht'] - r['cout']) / r['ht'] * 100; r['me'] = r['ht'] - r['cout']; r['f'] = famille(r['cat']); r['coef'] = r['ht'] / r['cout']
    r['nomc'] = re.sub(r'\s*\([^)]*\)\s*$', '', r['nom']); r['nomc'] = r['nomc'][:1].upper() + r['nomc'][1:]
par = {r['nom']: r for r in D}
HT = sum(r['ht'] * r['q'] for r in D); MG = sum(r['me'] * r['q'] for r in D); BASE = MG / HT * 100
P = [r for r in D if r['ttc'] <= 8]
G = sorted(P, key=lambda r: -r['q'])[:26]
G += [r for r in P if r['m'] >= 78 and r['q'] >= 5 and r not in G]
FAM = [('viennoiserie', 'Viennoiserie'), ('pain', 'Pains et boulangerie'), ('petite', 'Pains et boulangerie'), ('boissons', 'Boissons chaudes'),
       ('patisserie', 'Pâtisserie, entremets, biscuits'), ('entremets', 'Pâtisserie, entremets, biscuits'), ('cookies', 'Pâtisserie, entremets, biscuits'),
       ('cougnou', 'Pâtisserie, entremets, biscuits'), ('sandwiches', 'Snacking')]
ORDRE = ['Viennoiserie', 'Pains et boulangerie', 'Boissons chaudes', 'Pâtisserie, entremets, biscuits', 'Snacking']
groupe = lambda r: next((l for k, l in FAM if r['f'] == k), 'Snacking')
SEUILS = (1.67, 2.5)          # Recette & marge : sous 1,67 rouge, sous 2,5 orange

def nf(x, d=0): return f"{x:,.{d}f}".replace(',', ' ').replace('.', ',').replace(' ', ' ')
def eur(x, d=2): return nf(x, d) + ' €'
def pct(x, d=1): return nf(x, d) + ' %'
def sgn(x, d=2): return ('+ ' if x >= 0 else '− ') + nf(abs(x), d)

def couple(X, Y, prix=None):
    sep = X['ttc'] + Y['ttc']
    prix = prix if prix is not None else round(round(sep * 0.9 * 10) / 10, 2)
    htB = prix * (X['ht'] + Y['ht']) / sep; mB = htB - X['cout'] - Y['cout']
    return {'sep': sep, 'prix': prix, 'remise': sep - prix, 'remisePct': (sep - prix) / sep * 100, 'htB': htB, 'mB': mB, 'mBpct': mB / htB * 100,
            'gain': mB - X['me'], 'pts': mB / htB * 100 - X['m'], 'coef': htB / (X['cout'] + Y['cout']), 'n': 0.1 * X['q'], 'mois': 0.1 * X['q'] * (mB - X['me'])}

# Du noir à l'or : la couleur d'un partenaire selon les points de marge qu'il fait gagner au ticket.
NOIR, OR = (26, 23, 20), (214, 174, 69)
RAMPE = [(26, 23, 20), (74, 56, 26), (128, 95, 34), (176, 135, 50), (214, 174, 69)]
def teinte(t):
    t = max(0.0, min(1.0, t)); k = min(len(RAMPE) - 2, int(t * (len(RAMPE) - 1))); u = t * (len(RAMPE) - 1) - k
    c = [round(RAMPE[k][i] + (RAMPE[k + 1][i] - RAMPE[k][i]) * u) for i in range(3)]
    return '#%02x%02x%02x' % tuple(c), (t > 0.62)

def jauge(cx0, cb, w=360):
    """La jauge du coefficient (×1 → ×6) : rouge, orange, vert ; l'aiguille grise du produit seul, l'aiguille Ruby du bundle."""
    h = 200; cx, cy, R = w / 2, 168, 132
    a = lambda v: math.pi * (1 - (min(6, max(1, v)) - 1) / 5)
    pt = lambda v, r: (cx + r * math.cos(a(v)), cy - r * math.sin(a(v)))
    def arc(v0, v1, col):
        (x0, y0), (x1, y1) = pt(v0, R), pt(v1, R)
        return f'<path d="M{x0:.1f},{y0:.1f} A{R},{R} 0 0 1 {x1:.1f},{y1:.1f}" fill="none" stroke="{col}" stroke-width="18"/>'
    s = arc(1, SEUILS[0], '#d98b80') + arc(SEUILS[0], SEUILS[1], '#e7c07a') + arc(SEUILS[1], 6, '#9cc4a6')
    for v in [1, 2, 3, 4, 5, 6]:
        (x0, y0), (x1, y1) = pt(v, R - 13), pt(v, R + 13)
        tx, ty = pt(v, R + 26)
        s += f'<line x1="{x0:.1f}" y1="{y0:.1f}" x2="{x1:.1f}" y2="{y1:.1f}" stroke="#fff" stroke-width="2"/><text x="{tx:.1f}" y="{ty + 4:.1f}" font-size="11" text-anchor="middle" fill="#9a948b">×{v}</text>'
    for v, col, wd, r in [(cx0, '#8a847c', 3, R - 30), (cb, '#8D1D2C', 4.5, R - 8)]:
        x, y = pt(v, r)
        s += f'<line x1="{cx}" y1="{cy}" x2="{x:.1f}" y2="{y:.1f}" stroke="{col}" stroke-width="{wd}" stroke-linecap="round"/>'
    s += f'<circle cx="{cx}" cy="{cy}" r="8" fill="#2b2724"/>'
    return f'<svg width="{w}" height="{h}" viewBox="0 0 {w} {h}" style="display:block;margin:0 auto;font-family:inherit" role="img" aria-label="Coefficient">{s}</svg>'

def zone(c): return 'ko' if c < SEUILS[0] else ('att' if c < SEUILS[1] else 'ok')
ZN = {'ko': 'sous ×1,67 : trop juste', 'att': 'entre ×1,67 et ×2,5 : à surveiller', 'ok': '×2,5 et plus : la marge tient'}

def ecran(xnom, ynom, prix_choisi):
    X, Y = par[xnom], par[ynom]
    cand = {r['nom']: couple(X, r) for r in G if r is not X and r['f'] != X['f']}
    best = max(c['pts'] for c in cand.values())
    # Les tuiles, famille par famille.
    tuiles = ''
    for g in ORDRE:
        L = [r for r in G if groupe(r) == g]
        if not L: continue
        t = ''
        for r in sorted(L, key=lambda r: -r['q']):
            if r is X:
                t += f'<button class="sm-t x"><span class="sm-tag">le produit qui attire</span><b>{r["nomc"]}</b><span class="sm-i">{eur(r["ttc"])} · marge {pct(r["m"])}</span><span class="sm-v">{nf(r["q"])} pcs / mois</span></button>'
                continue
            if r['nom'] not in cand:
                t += f'<button class="sm-t fam" title="Même famille : ils se remplacent, ils ne se complètent pas"><b>{r["nomc"]}</b><span class="sm-i">{eur(r["ttc"])} · marge {pct(r["m"])}</span><span class="sm-v">même famille</span></button>'
                continue
            c = cand[r['nom']]
            col, clair = teinte(c['pts'] / best) if c['pts'] > 0 else ('#141210', False)
            sel = r is Y
            t += f'''<button class="sm-t c{' clair' if clair else ''}{' sel' if sel else ''}" style="background:{col}" title="{r['nomc']} : ticket {pct(X['m'])} → {pct(c['mBpct'])}, {sgn(c['gain'])} € par ticket">
              {'<span class="sm-ck">✓</span>' if sel else ''}<b>{r["nomc"]}</b><span class="sm-i">{eur(r["ttc"])} · marge {pct(r["m"])}</span>
              <span class="sm-v">{('<b>' + sgn(c['pts'], 1) + ' pts</b> · ' + sgn(c['gain']) + ' €') if c['pts'] > 0 else '<b>baisse la marge</b> · ' + pct(c['mBpct'], 0)}</span></button>'''
        tuiles += f'<div class="sm-g"><p class="sm-gt">{g}</p><div class="sm-grille">{t}</div></div>'
    # Le couple simulé.
    c = couple(X, Y, prix_choisi)
    prixs = [round(x / 10, 2) for x in range(int(round(c['sep'] * 0.78 * 10)), int(round(c['sep'] * 10)) + 1)]
    rail = ''.join(f'<span class="{"on" if abs(p - prix_choisi) < 1e-6 else ""}{" bon" if 7.95 <= (c["sep"] - p) / c["sep"] * 100 <= 12.55 else ""}">{nf(p, 2)}</span>' for p in prixs)
    top3 = sorted(cand.items(), key=lambda kv: -kv[1]['pts'])[:3]
    ht2 = HT + c['n'] * (c['htB'] - X['ht']); mg2 = MG + c['n'] * c['gain']
    zx, zb = zone(X['coef']), zone(c['coef'])
    panneau = f'''<aside class="sm-p">
      <p class="bd-t">Le couple simulé</p>
      <div class="sm-duo"><span class="sm-px">{X['nomc']}</span><span class="sm-plus">+</span><span class="sm-py">{Y['nomc']}</span></div>
      <div class="sm-prix"><div class="sm-prix-h"><span>Le prix du bundle</span><b>{eur(prix_choisi)}</b><s>{eur(c['sep'])}</s><em>−{pct(c['remisePct'], 0)}</em></div>
        <div class="sm-rail">{rail}</div><p class="sm-mu">en rouge : la remise conseillée, 8 à 12 %</p></div>
      <div class="sm-jauge"><p class="bd-t" style="margin:0 0 2px">Le coefficient du ticket</p>{jauge(X['coef'], c['coef'])}
        <div class="sm-aig"><span><i style="background:#8a847c"></i>{X['nomc']} seul <b>×{nf(X['coef'], 2)}</b> <em class="{zx}">{ZN[zx]}</em></span>
          <span><i style="background:#8D1D2C"></i>le bundle <b>×{nf(c['coef'], 2)}</b> <em class="{zb}">{ZN[zb]}</em></span></div>
        <div class="sm-bloc"><div><span class="k">Marge du ticket</span><b>{pct(X['m'])} → {pct(c['mBpct'])}</b><span class="s">{sgn(c['pts'], 1)} pts</span></div>
          <div><span class="k">En € HT par ticket</span><b>{eur(X['me'])} → {eur(c['mB'])}</b><span class="s ok">{sgn(c['gain'])} € de marge</span></div>
          <div><span class="k">Par mois, 1 client sur 10</span><b>{sgn(c['mois'], 0)} €</b><span class="s">{nf(c['n'])} bundles</span></div>
          <div><span class="k">Votre marge de base</span><b>{pct(BASE)} → {pct(mg2 / ht2 * 100, 2)}</b><span class="s">sur tout le magasin</span></div></div></div>
      <p class="bd-t" style="margin-top:14px">Les plus dorés pour {X['nomc'].lower()}</p>
      <ol class="sm-top">{''.join(f'<li><i style="background:{teinte(v["pts"] / best)[0]}"></i>{par[k]["nomc"]}<span>{sgn(v["pts"], 1)} pts · {eur(v["prix"])}</span></li>' for k, v in top3)}</ol>
      <button class="bd-btn go" style="width:100%;justify-content:center;margin-top:12px">Préparer la fiche du bundle ›</button></aside>'''
    leg = ''.join(f'<i style="background:{teinte(k / 8)[0]}"></i>' for k in range(9))
    return f'''<section class="db-card bd" id="pc">
<header class="bd-h"><img class="bd-av" src="avatars/vente.png" alt="Coach Vente"><div><span class="bd-k">Coach Vente · le simulateur de couples</span>
  <h2>Avec quoi coupler {X['nomc'].lower()} ?</h2><p>« Vous avez cliqué {X['nomc'].lower()} : marge {pct(X['m'])}, {nf(X['q'])} pièces par mois. Plus un produit est doré, plus il monte la marge du ticket avec lui ; noir, il la baisse. Cliquez un produit doré pour le simuler, ou un autre produit qui attire pour recommencer. »</p></div>
  <ol class="bd-pas"><li class="fait"><span>✓</span>Le constat</li><li class="on"><span>2</span>Les couples</li><li><span>3</span>Le prix</li><li><span>4</span>La fiche</li><li><span>5</span>Le lancement</li></ol></header>
<div class="sm-barre"><span class="sm-mode"><button>Les couples proposés</button><button class="on">Le simulateur</button></span>
  <span class="sm-leg"><span>baisse la marge du ticket</span><span class="sm-rampe">{leg}</span><span>la monte le plus</span></span>
  <span class="sm-aide">1<sup>er</sup> clic : le produit qui attire · 2<sup>e</sup> clic : son partenaire</span></div>
<div class="sm-c"><div class="sm-tuiles">{tuiles}</div>{panneau}</div>
</section>
<p class="pc-maq">Maquette — vrais prix, coûts de recette et ventes de Halle (30 jours) ; chaque partenaire au prix conseillé (−10 %) pour la couleur. Le coefficient : prix payé HT ÷ coût de recette (seuils de Recette &amp; marge).</p>'''

for nom, (x, y, p) in {'s-a-pain-au-chocolat': ('Pain au Chocolat', 'Cappuccino (à emporter)', 4.20), 's-b-baguette': ('Baguette Tradition', 'Cappuccino (à emporter)', 4.50)}.items():
    open(os.path.join(ICI, nom + '.html'), 'w', encoding='utf-8').write(ecran(x, y, p))
print('base', round(BASE, 2))
