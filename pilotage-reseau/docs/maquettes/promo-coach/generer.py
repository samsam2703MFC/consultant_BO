#!/usr/bin/env python3
"""Maquette « Créer un bundle qui monte la marge » (17/11/2026) : les phases avant la fiche de la promotion.
Génère p1-constat.html, p2-couples.html et p3-prix.html depuis halle.json : les pièces vendues par Halle du 19/10 au
17/11/2026 (GET /analyse/produits?mois=1) et ses prix et coûts de recette au panel (/exploitation/promos-magasin/produits).
Lancer : python3 generer.py, puis node capturer.cjs (SEUL=p1-constat…)."""
import json, math, os

ICI = os.path.dirname(os.path.abspath(__file__))
D = json.load(open(os.path.join(ICI, 'halle.json'), encoding='utf-8'))
P = D['produits']
par_id = {r['pid']: r for r in P}
for r in P:
    r['m'] = (r['ht'] - r['cout']) / r['ht'] * 100          # marge brute en %
    r['me'] = r['ht'] - r['cout']                           # marge brute en € HT par pièce

def nf(x, d=0):
    s = f"{x:,.{d}f}".replace(',', ' ').replace('.', ',').replace(' ', ' ')
    return s
def eur(x, d=2): return nf(x, d) + ' €'
def pct(x, d=1): return nf(x, d) + ' %'
def sgn(x, d=2): return ('+ ' if x >= 0 else '− ') + nf(abs(x), d)

HT = sum(r['ht'] * r['q'] for r in P)
MG = sum(r['me'] * r['q'] for r in P)
BASE = MG / HT * 100
NQ = sum(r['q'] for r in P)
SOUS = sum(r['q'] for r in P if r['m'] < BASE) / NQ * 100
HAUT = sum(r['q'] for r in P if r['m'] >= 78) / NQ * 100
PRISE = 0.10   # un client sur dix du produit qui attire prend le bundle

COUPLES = [  # (produit qui attire, produit à grande valeur ajoutée, prix du bundle, le moment)
    (1610004, 3400007, 3.80, 'le matin'),
    (1300003, 1540001, 4.80, 'l’après-midi'),
    (6700170, 3400009, 4.10, 'le matin'),
    (3210004, 3400007, 4.90, 'l’après-midi'),
    (1200003, 1610010, 5.90, 'le week-end'),
]
def couple(a, b, prixB):
    A, B = par_id[a], par_id[b]
    sep = A['ttc'] + B['ttc']
    htB = prixB * (A['ht'] + B['ht']) / sep                 # les TVA des deux produits, au prorata
    mB = htB - A['cout'] - B['cout']
    return {'A': A, 'B': B, 'sep': sep, 'prix': prixB, 'remise': sep - prixB, 'remisePct': (sep - prixB) / sep * 100,
            'htB': htB, 'mB': mB, 'mBpct': mB / htB * 100, 'gain': mB - A['me'], 'mois': PRISE * A['q'] * (mB - A['me']), 'n': PRISE * A['q']}
C = [couple(a, b, p) | {'moment': mo} for a, b, p, mo in COUPLES]
C.sort(key=lambda c: -c['mois'])

def tete(etape, titre, bulle):
    pas = ['Le constat', 'Les couples', 'Le prix', 'La fiche', 'Le lancement']
    st = ''.join(f'<li class="{"fait" if i + 1 < etape else ("on" if i + 1 == etape else "")}"><span>{"✓" if i + 1 < etape else i + 1}</span>{p}</li>' for i, p in enumerate(pas))
    return f'''<header class="pc-h"><img class="pc-av" src="avatars/vente.png" alt="Coach Vente">
    <div><span class="pc-k">Coach Vente · créer un bundle qui monte votre marge</span><h2>{titre}</h2><p>{bulle}</p></div>
    <ol class="bn-pas">{st}</ol></header>'''

def pied(boutons, mu=''):
    return f'<footer class="pc-act">{boutons}<span class="esp"></span><span class="mu">{mu}</span></footer>'

# --- Phase 1 : le constat -------------------------------------------------------------------------------------------
def nuage(w=820, h=400):
    g, d, hh, b = 58, 16, 18, 44                            # marges : gauche, droite, haut, bas
    W, H = w - g - d, h - hh - b
    lx = lambda q: g + W * math.log10(max(1, q)) / math.log10(3000)
    ly = lambda m: hh + H * (1 - m / 100)
    s = []
    # Les deux zones : le volume sans la marge (en bas à droite), la valeur ajoutée (en haut).
    s.append(f'<rect x="{lx(300):.1f}" y="{ly(BASE):.1f}" width="{g + W - lx(300):.1f}" height="{ly(15) - ly(BASE):.1f}" fill="#A82E40" fill-opacity=".06" rx="6"/>')
    s.append(f'<rect x="{g}" y="{ly(100):.1f}" width="{W}" height="{ly(78) - ly(100):.1f}" fill="#1B6FA8" fill-opacity=".06" rx="6"/>')
    for m in range(0, 101, 20):
        s.append(f'<line x1="{g}" x2="{g + W}" y1="{ly(m):.1f}" y2="{ly(m):.1f}" stroke="#ece6dc"/><text x="{g - 8}" y="{ly(m) + 4:.1f}" font-size="11" text-anchor="end" fill="#9a948b">{m} %</text>')
    for q in [1, 10, 100, 1000]:
        s.append(f'<line x1="{lx(q):.1f}" x2="{lx(q):.1f}" y1="{hh}" y2="{hh + H}" stroke="#ece6dc"/><text x="{lx(q):.1f}" y="{hh + H + 17}" font-size="11" text-anchor="middle" fill="#9a948b">{nf(q)}</text>')
    s.append(f'<text x="{g + W}" y="{hh + H + 36}" font-size="11" text-anchor="end" fill="#7a736a">pièces vendues en un mois (échelle log) →</text>')
    couplerA = {c['A']['pid'] for c in C}; pousserB = {c['B']['pid'] for c in C}
    for r in sorted(P, key=lambda r: (r['pid'] in couplerA or r['pid'] in pousserB)):
        c = '#A82E40' if r['pid'] in couplerA else ('#1B6FA8' if r['pid'] in pousserB else '#cfc8bd')
        rr = 5 if c != '#cfc8bd' else 3.2
        s.append(f'<circle cx="{lx(r["q"]):.1f}" cy="{ly(r["m"]):.1f}" r="{rr}" fill="{c}" stroke="#fff" stroke-width="{1.5 if rr > 4 else 1}"><title>{r["nom"]} : {nf(r["q"])} pièces, marge {pct(r["m"])}</title></circle>')
    s.append(f'<line x1="{g}" x2="{g + W}" y1="{ly(BASE):.1f}" y2="{ly(BASE):.1f}" stroke="#2b2724" stroke-width="1.5" stroke-dasharray="5 4"/>')
    halo = lambda x, y, t, a='start', c='#2b2724', fw=600: f'<text x="{x:.1f}" y="{y:.1f}" font-size="11.5" font-weight="{fw}" text-anchor="{a}" fill="{c}" stroke="#fff" stroke-width="4" stroke-linejoin="round" paint-order="stroke">{t}</text>'
    s.append(halo(g + 6, ly(BASE) - 7, f'votre marge de base : {pct(BASE)}', fw=600))
    s.append(halo(g + W - 6, ly(17), 'ils font le volume, pas la marge', 'end', '#8D1D2C', 400))
    s.append(halo(g + W - 6, ly(98) + 4, 'grande valeur ajoutée, peu vendus', 'end', '#1B5D8C', 400))
    # Les produits qui attirent font une grappe : leurs noms en colonne à gauche de la grappe, un trait vers chaque point.
    col = lx(560) - 8
    for k, (pid, ym) in enumerate([(6700170, 51), (1200003, 45.5), (1610004, 40)]):
        r = par_id[pid]
        s.append(f'<line x1="{col + 3:.1f}" y1="{ly(ym) - 4:.1f}" x2="{lx(r["q"]):.1f}" y2="{ly(r["m"]):.1f}" stroke="#8D1D2C" stroke-width=".9" stroke-opacity=".55"/>')
        s.append(halo(col, ly(ym), r['nom'], 'end', '#2b2724', 500))
    lab = {1300003: (-10, 4, 'end'), 3210004: (-9, 4, 'end'),
           3400007: (9, 4, 'start'), 1540001: (9, 4, 'start'), 3400009: (-9, 4, 'end'), 1610010: (-9, 4, 'end')}
    for pid, (dx, dy, a) in lab.items():
        r = par_id[pid]
        s.append(halo(lx(r['q']) + dx, ly(r['m']) + dy, r['nom'].replace(' (à emporter)', '').replace('éclair', 'Éclair'), a, '#2b2724', 500))
    return f'<svg width="{w}" height="{h}" viewBox="0 0 {w} {h}" role="img" aria-label="Marge et volume des produits" style="display:block;font-family:inherit">{"".join(s)}</svg>'

def liste(L, cl):
    return ''.join(f'<li><i class="{cl}"></i><span class="n">{(r["nom"][:1].upper() + r["nom"][1:]).replace(" (à emporter)", "")}</span><span>{nf(r["q"])} pcs</span><b>{pct(r["m"])}</b></li>' for r in L)

faibles = sorted([r for r in P if r['m'] < BASE and r['q'] >= 300], key=lambda r: -r['q'])[:6]
forts = sorted([r for r in P if r['m'] >= 78 and r['q'] >= 8], key=lambda r: -r['q'])[:6]
p1 = f'''<section class="db-card pc bn" id="pc">
{tete(1, 'Votre marge de base, et ce qui la tire vers le bas',
  f'« Votre marge de base est de {pct(BASE)}. Ce qui fait votre volume, le pain, la baguette et la viennoiserie, est sous cette marge ; ce qui rapporte le plus, le café, l’éclair, la couque à la crème, se vend peu. Couplons-les : le produit qui attire fait vendre celui qui rapporte. »')}
<div class="bn-k"><div class="pc-tuile"><div class="k">Marge de base</div><div class="v">{pct(BASE)}</div><div class="s">prix hors TVA − coût de recette, sur {len(P)} produits chiffrés</div></div>
  <div class="pc-tuile"><div class="k">Sous la marge de base</div><div class="v">{pct(SOUS, 0)}</div><div class="s">des pièces vendues</div></div>
  <div class="pc-tuile"><div class="k">À 78 % de marge et plus</div><div class="v">{pct(HAUT, 1)}</div><div class="s">des pièces vendues : ce qu’il faut faire monter</div></div>
  <div class="pc-tuile"><div class="k">La période</div><div class="v" style="font-size:16px;margin-top:7px">19/10 → 17/11/2026</div><div class="s">{nf(NQ)} pièces vendues à Halle</div></div></div>
<div class="bn-c1"><div class="bn-graphe"><p class="pc-t">Chaque produit : sa marge et ses ventes</p>{nuage()}
  <p class="bn-leg"><span><i style="background:#A82E40"></i>le produit qui attire, à coupler</span><span><i style="background:#1B6FA8"></i>le produit à grande valeur ajoutée</span><span><i style="background:#cfc8bd"></i>les autres produits</span><span><i class="tir"></i>la marge de base</span></p></div>
  <div class="bn-listes"><p class="pc-t">Ils font le volume, pas la marge</p><ul class="bn-l">{liste(faibles, 'r')}</ul>
  <p class="pc-t" style="margin-top:16px">Grande valeur ajoutée, peu vendus</p><ul class="bn-l">{liste(forts, 'b')}</ul></div></div>
{pied('<button class="pc-btn go">Voir les couples qui montent la marge ›</button><a class="pc-lien">En discuter avec le Coach Vente ›</a>', 'Les chiffres de Halle, relus au panel')}
</section>
<p class="pc-maq">Maquette — vrais chiffres de Halle : pièces vendues du 19/10 au 17/11/2026, prix et coûts de recette au panel ; les produits sans coût de recette ne comptent pas.</p>'''

# --- Phase 2 : les couples ------------------------------------------------------------------------------------------
lignes = ''
for i, c in enumerate(C):
    A, B = c['A'], c['B']
    lignes += f'''<tr class="{'on' if i < 2 else ''}"><td><span class="bn-ck">{'✓' if i < 2 else ''}</span></td>
  <td><b>{A['nom']}</b><span class="s">{nf(A['q'])} pcs · marge {pct(A['m'])}</span></td>
  <td class="pl">+</td>
  <td><b>{B['nom'].replace(' (à emporter)', '').replace('éclair', 'Éclair')}</b><span class="s">marge {pct(B['m'])}</span></td>
  <td class="n">{eur(c['sep'])} → <b class="rb">{eur(c['prix'])}</b><span class="s">−{eur(c['remise'])} · −{pct(c['remisePct'], 0)}</span></td>
  <td class="n"><span class="bn-mg"><s>{pct(A['m'])}</s> → <b>{pct(c['mBpct'])}</b></span></td>
  <td class="n"><b class="ok">{sgn(c['gain'])} €</b><span class="s">par ticket</span></td>
  <td class="n"><b>{sgn(c['mois'], 0)} €</b><span class="s">{nf(c['n'])} bundles</span></td>
  <td><span class="bn-mo">{c['moment']}</span></td></tr>'''
sel = C[:2]
ht2 = HT + sum(c['n'] * (c['htB'] - c['A']['ht']) for c in sel)
mg2 = MG + sum(c['n'] * c['gain'] for c in sel)
p2 = f'''<section class="db-card pc bn" id="pc">
{tete(2, 'Les couples qui montent votre marge',
  '« Chaque couple part d’un produit qui attire mais rapporte peu, et lui ajoute un produit à grande valeur ajoutée. La remise est petite, la marge du ticket monte : le client qui venait pour son pain au chocolat repart avec un café. Choisissez un ou deux couples. »')}
<div class="bn-tab"><table><thead><tr><th></th><th>Le produit qui attire</th><th></th><th>+ le produit qui rapporte</th><th class="n">Séparés → bundle</th><th class="n">Marge du ticket</th><th class="n">En plus / ticket</th><th class="n">Par mois*</th><th>Le moment</th></tr></thead>
<tbody>{lignes}</tbody></table></div>
<div class="bn-bilan"><div><span class="pc-t" style="margin:0">Les 2 couples choisis, si 1 client sur 10 les prend</span>
  <p><b>{sgn(sum(c['mois'] for c in sel), 0)} € de marge par mois</b> · votre marge de base passe de <b>{pct(BASE)}</b> à <b>{pct(mg2 / ht2 * 100, 2)}</b> · {nf(sum(c['n'] for c in sel))} bundles par mois, environ {nf(sum(c['n'] for c in sel) / 30, 0)} par jour</p></div>
  <span class="bn-reg">Prise : <b>1 client sur 10</b> ▾</span></div>
<p class="bn-note">* si 1 client sur 10 qui achète le produit qui attire prend le bundle. « En plus / ticket » : la marge du bundle moins celle du produit qui attire vendu seul, pour le client qui ne prenait que lui. Un client qui prenait déjà les deux paie la remise : le café ne se vend que {nf(par_id[3400007]['q'])} fois par mois, ce cas reste rare.</p>
{pied('<button class="pc-btn go">Régler le prix : Pain au chocolat + Café ›</button><button class="pc-btn">‹ Le constat</button><button class="pc-btn">Un autre couple</button>', '2 couples choisis')}
</section>
<p class="pc-maq">Maquette — prix et coûts de recette de Halle au panel ; la prise (1 client sur 10) est une hypothèse réglable.</p>'''

# --- Phase 3 : le prix ----------------------------------------------------------------------------------------------
c1 = next(c for c in C if c['A']['pid'] == 1610004)
A, B = c1['A'], c1['B']
prixs = [round(x * 0.1, 2) for x in range(33, 43)]
cols = [couple(A['pid'], B['pid'], p) for p in prixs]
def zone(c):
    return 'bon' if 8 <= c['remisePct'] <= 12.5 else ('trop' if c['remisePct'] > 15 else '')
def colonnes(w=640, h=300):
    g, d, hh, bas = 50, 10, 26, 46
    W, H = w - g - d, h - hh - bas
    top = max(c['mB'] for c in cols) * 1.12
    y = lambda v: hh + H * (1 - v / top)
    bw = W / len(cols)
    o = []
    for v in [0, 1, 2, 3]:
        if v <= top:
            o.append(f'<line x1="{g}" x2="{g + W}" y1="{y(v):.1f}" y2="{y(v):.1f}" stroke="{'#d9d2c7' if v == 0 else '#ece6dc'}"/><text x="{g - 8}" y="{y(v) + 4:.1f}" font-size="11" text-anchor="end" fill="#9a948b">{v}\u00a0€</text>')
    halo = lambda x, yy, t, a='middle', fw=600, c='#2b2724': f'<text x="{x:.1f}" y="{yy:.1f}" font-size="11.5" font-weight="{fw}" text-anchor="{a}" fill="{c}" stroke="#fff" stroke-width="4" stroke-linejoin="round" paint-order="stroke">{t}</text>'
    for i, c in enumerate(cols):
        z = zone(c); coul = '#A82E40' if z == 'bon' else ('#e9b9a6' if z == 'trop' else '#d9cfc4')
        on = abs(c['prix'] - 3.80) < 1e-6
        x0 = g + i * bw + bw * 0.18; lg = bw * 0.64; yy = y(c['mB'])
        o.append(f'<path d="M{x0:.1f},{y(0):.1f}V{yy + 4:.1f}Q{x0:.1f},{yy:.1f} {x0 + 4:.1f},{yy:.1f}H{x0 + lg - 4:.1f}Q{x0 + lg:.1f},{yy:.1f} {x0 + lg:.1f},{yy + 4:.1f}V{y(0):.1f}Z" fill="{coul}"><title>{eur(c['prix'])} : marge {eur(c['mB'])}</title></path>')
        cx = g + i * bw + bw / 2
        if on:
            o.append(f'<rect x="{cx - 30:.1f}" y="{y(0) + 6:.1f}" width="60" height="21" rx="6" fill="#8D1D2C"/>')
        o.append(f'<text x="{cx:.1f}" y="{y(0) + 21:.1f}" font-size="12" text-anchor="middle" fill="{'#fff' if on else '#2b2724'}">{eur(c['prix'])}</text>')
        o.append(f'<text x="{cx:.1f}" y="{y(0) + 40:.1f}" font-size="11" text-anchor="middle" fill="#7a736a">{'séparés' if c['remise'] < 0.005 else '−' + pct(c['remisePct'], 0)}</text>')
        if on or i in (0, len(cols) - 1):
            o.append(halo(cx, yy - 7, eur(c['mB'])))
    o.append(f'<line x1="{g}" x2="{g + W}" y1="{y(A['me']):.1f}" y2="{y(A['me']):.1f}" stroke="#2b2724" stroke-width="1.5" stroke-dasharray="5 4"/>')
    o.append(halo(g + 4, y(A['me']) - 7, f'le pain au chocolat seul : {eur(A["me"])}', 'start'))
    return f'<svg width="{w}" height="{h}" viewBox="0 0 {w} {h}" role="img" aria-label="Marge du bundle selon son prix" style="display:block;font-family:inherit;max-width:100%">{"".join(o)}</svg>'
seuil = A['me']
p3 = f'''<section class="db-card pc bn" id="pc">
{tete(3, 'Le prix du bundle : Pain au chocolat + Café',
  f'« Séparés, ils font {eur(c1["sep"])}. À {eur(3.80)}, le client gagne {eur(c1["remise"])} : assez pour qu’il le voie, pas assez pour vous coûter. Chaque pain au chocolat vendu en duo laisse {eur(c1["mB"])} de marge au lieu de {eur(A["me"])}. »')}
<div class="bn-c3"><div><p class="pc-t">La marge que laisse un bundle, selon son prix</p>
  {colonnes()}
  <p class="bn-leg"><span><i style="background:#A82E40"></i>la remise conseillée : 8 à 12 %</span><span><i style="background:#d9cfc4"></i>possible</span><span><i style="background:#e9b9a6"></i>plus de 15 % : trop de remise</span><span><i class="tir"></i>la marge du pain au chocolat vendu seul</span></p></div>
  <div class="bn-choix"><p class="pc-t">À {eur(3.80)}</p>
   <div class="pc-tuiles"><div class="pc-tuile"><div class="k">Le client gagne</div><div class="v">{eur(c1['remise'])}</div><div class="s">−{pct(c1['remisePct'])} sur {eur(c1['sep'])}</div></div>
   <div class="pc-tuile"><div class="k">Marge du bundle</div><div class="v">{eur(c1['mB'])} <small style="font-size:13px;color:var(--muted)">HT</small></div><div class="s">{pct(c1['mBpct'])} · le pain au chocolat seul : {pct(A['m'])}</div></div>
   <div class="pc-tuile"><div class="k">Marge en plus</div><div class="v ok">{sgn(c1['gain'])} €</div><div class="s">par client qui prend le duo au lieu du pain au chocolat seul</div></div>
   <div class="pc-tuile"><div class="k">Par mois, 1 client sur 10</div><div class="v">{sgn(c1['mois'], 0)} €</div><div class="s">{nf(c1['n'])} duos · {nf(c1['n'] / 30, 0)} par jour</div></div></div>
   <ul class="pc-pq"><li><b>Garde-fou</b> : la marge du bundle reste au-dessus de votre marge de base ({pct(BASE)}) à tous ces prix.</li>
   <li><b>Le prix rond</b> : {eur(3.80)} se rend sans petite monnaie et se dit en une phrase.</li>
   <li><b>À surveiller</b> : le café se vend {nf(B['q'])} fois par mois ; le bilan dira combien de duos il a fallu pour le faire monter.</li></ul></div></div>
{pied('<button class="pc-btn go">Préparer la fiche du bundle ›</button><button class="pc-btn">‹ Les couples</button>', 'Ensuite : la fiche pré-encodée, puis la validation')}
</section>
<p class="pc-maq">Maquette — prix et coûts de recette de Halle au panel ; la marge en € est hors TVA.</p>'''

for nom, h in [('p1-constat', p1), ('p2-couples', p2), ('p3-prix', p3)]:
    open(os.path.join(ICI, nom + '.html'), 'w', encoding='utf-8').write(h)
print('marge de base', round(BASE, 2), '· sous', round(SOUS, 1), '· haut', round(HAUT, 1))
for c in C: print(c['A']['nom'], '+', c['B']['nom'], round(c['mBpct'], 1), round(c['gain'], 2), round(c['mois']))
print('après', round(mg2 / ht2 * 100, 2))
