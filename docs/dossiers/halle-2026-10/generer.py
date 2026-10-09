# Le dossier investisseur de Halle : HTML A4 (pages), rendu en PDF par Chromium. Données : fichiers JSON lus sur le cockpit en ligne.
import json, html, datetime
SP = '/tmp/claude-0/-home-user-consultant-BO/64c06f7c-f933-5fd1-a986-9b6771ad42a2/scratchpad'
H = lambda s: html.escape(str(s))
def nf(v, d=0):
    if v is None: return '—'
    s = f'{v:,.{d}f}'.replace(',', ' ').replace('.', ',')
    return s
fE = lambda v, d=0: nf(v, d) + ' €'
fP = lambda v, d=1: nf(v, d) + ' %'

pnl = json.load(open(SP + '/halle/pnl-mois.json'))
perf = json.load(open(SP + '/mat/perf.json')); perf = perf if isinstance(perf, list) else perf.get('perf') or perf.get('data')
bud = json.load(open(SP + '/halle/budgets.json')); bud = bud if isinstance(bud, list) else bud.get('budgets') or bud.get('data')
stats = json.load(open(SP + '/halle/stats-2026-09.json')); sem = json.load(open(SP + '/halle/semaine-type.json'))
sc3 = json.load(open(SP + '/halle/scoring-2026-T3.json')); photos = json.load(open(SP + '/halle/photos-meta.json'))
Hm = [m for m in pnl['magasins'] if m['id'] == '4'][0]; autres = [m for m in pnl['magasins'] if m['id'] != '4']
perfH = {r['mois']: r for r in perf if str(r['storeId']) == '4' and r['annee'] == 2026}
perfA = {}
for r in perf:
    if str(r['storeId']) != '4' and r['annee'] == 2026: perfA.setdefault(r['mois'], []).append(r)
budget = [x for x in bud if str(x.get('storeId')) == '4'][0]
MOIS = {2: 'Février', 3: 'Mars', 4: 'Avril', 5: 'Mai', 6: 'Juin', 7: 'Juillet', 8: 'Août', 9: 'Septembre', 10: 'Octobre'}

# --- Les mois
mois = []
for x in Hm['mois']:
    if x.get('indispo'): continue
    m = int(x['mois'][5:7]); p = perfH.get(m, {}); fc = p.get('foodCostPct'); ca = x['ca']
    mat = ca * fc / 100 if fc is not None else None
    res = ca - mat - x['labour'] - x['overhead'] if mat is not None else None
    mois.append(dict(m=m, nom=MOIS[m], jours=x['joursVentes'], ca=ca, budget=p.get('caBudget'), tickets=p.get('tickets'), panier=p.get('panierMoyen'), food=fc, mat=mat,
                     lab=x['labour'], labPct=x['labourPct'], oh=x['overhead'], ohPct=x['overheadPct'], res=res, resPct=(100 * res / ca if res is not None and ca else None), partiel=(m in (2, 10))))
pleins = [x for x in mois if 3 <= x['m'] <= 9]
cum = dict(ca=sum(x['ca'] for x in pleins), mat=sum(x['mat'] for x in pleins), lab=sum(x['lab'] for x in pleins), oh=sum(x['oh'] for x in pleins), tk=sum(x['tickets'] for x in pleins), bud=sum(x['budget'] for x in pleins), j=sum(x['jours'] for x in pleins))
cum['res'] = cum['ca'] - cum['mat'] - cum['lab'] - cum['oh']
caMois = cum['ca'] / 7
# --- Le réseau (parts seulement)
aCA = sum(x['ca'] for m in autres for x in m['mois'] if not x.get('indispo') and '2026-03' <= x['mois'] <= '2026-09')
aLab = sum(x['labour'] for m in autres for x in m['mois'] if not x.get('indispo') and '2026-03' <= x['mois'] <= '2026-09')
aOh = sum(x['overhead'] for m in autres for x in m['mois'] if not x.get('indispo') and '2026-03' <= x['mois'] <= '2026-09')
aFoodL = [r['foodCostPct'] for mm in range(3, 10) for r in perfA.get(mm, []) if r.get('foodCostPct') and r['foodCostPct'] < 60]
aFood = sum(aFoodL) / len(aFoodL)
aPanL = [r['panierMoyen'] for mm in range(3, 10) for r in perfA.get(mm, []) if r.get('panierMoyen')]
aPan = sum(aPanL) / len(aPanL)
# rang CA du réseau (mars→sept)
rangs = sorted([(m['court'], sum(x['ca'] for x in m['mois'] if not x.get('indispo') and '2026-03' <= x['mois'] <= '2026-09')) for m in pnl['magasins']], key=lambda t: -t[1])
rangH = [i + 1 for i, t in enumerate(rangs) if t[0] == 'Halle'][0]
# --- Qualité
hs = [m for m in sc3['magasins'] if str(m['id']) == '4'][0]; po = hs['postes']
# --- Assortiment
cats = sorted(stats.get('categories') or [], key=lambda c: -(c.get('v') or 0)); tv = sum(c.get('v') or 0 for c in cats)
fam = {}
for c in cats: fam[c.get('groupe') or 'Autres'] = fam.get(c.get('groupe') or 'Autres', 0) + (c.get('v') or 0)
fam = sorted(fam.items(), key=lambda t: -t[1])
# --- Photos retenues (vitrines, pains, salle, ardoise ; aucune personne)
choix = ['2026-10-05-1207', '2026-10-05-1211', '2026-10-05-1209', '2026-09-30-1206', '2026-10-08-1206', '2026-10-05-1213', '2026-10-01-1216', '2026-10-05-1267', '2026-09-21-1211']
meta = {m['fichier'][:-4]: m for m in photos}
LIB = {'CO-01': 'Ouverture du magasin', 'CQ-02': 'Contrôle qualité d’ouverture', 'CO-10': 'Contrôle opérationnel boulangerie'}
def fDj(s): return s[8:10] + '/' + s[5:7] + '/' + s[0:4]

# --- Graphiques SVG
def barres_ca():
    W, Hh, ml, mb = 680, 230, 46, 34; xs = pleins; n = len(xs); maxv = max(max(x['ca'] for x in xs), max(x['budget'] for x in xs)) * 1.08
    bw = (W - ml - 10) / n; out = [f'<svg viewBox="0 0 {W} {Hh}" class="graph">']
    for i in range(5):
        v = maxv * i / 4; y = Hh - mb - (Hh - mb - 14) * v / maxv
        out.append(f'<line x1="{ml}" x2="{W-6}" y1="{y:.1f}" y2="{y:.1f}" stroke="#ece6de"/><text x="{ml-6}" y="{y+3.5:.1f}" text-anchor="end" class="ax">{nf(v/1000,0)} k</text>')
    for i, x in enumerate(xs):
        x0 = ml + i * bw + bw * 0.14; w = bw * 0.72
        hb = (Hh - mb - 14) * x['ca'] / maxv; yb = Hh - mb - hb
        out.append(f'<rect x="{x0:.1f}" y="{yb:.1f}" width="{w:.1f}" height="{hb:.1f}" rx="3" fill="#8D1D2C"/>')
        yB = Hh - mb - (Hh - mb - 14) * x['budget'] / maxv
        out.append(f'<line x1="{x0-2:.1f}" x2="{x0+w+2:.1f}" y1="{yB:.1f}" y2="{yB:.1f}" stroke="#221E1A" stroke-width="2"/>')
        out.append(f'<text x="{x0+w/2:.1f}" y="{yb+16:.1f}" text-anchor="middle" class="val" fill="#fff">{nf(x["ca"]/1000,1)} k</text><text x="{x0+w/2:.1f}" y="{Hh-mb+14}" text-anchor="middle" class="ax">{x["nom"][:4]}.</text><text x="{x0+w/2:.1f}" y="{Hh-mb+26}" text-anchor="middle" class="axs">{nf(100*x["ca"]/x["budget"],0)} % du budget</text>')
    out.append('</svg>'); return ''.join(out)
def lignes_pct():
    W, Hh, ml, mb = 680, 172, 40, 26; xs = pleins; n = len(xs); maxv = 45
    out = [f'<svg viewBox="0 0 {W} {Hh}" class="graph">']
    for v in (0, 10, 20, 30, 40):
        y = Hh - mb - (Hh - mb - 12) * v / maxv
        out.append(f'<line x1="{ml}" x2="{W-6}" y1="{y:.1f}" y2="{y:.1f}" stroke="#ece6de"/><text x="{ml-6}" y="{y+3.5:.1f}" text-anchor="end" class="ax">{v} %</text>')
    def X(i): return ml + (W - ml - 20) * (i + 0.5) / n
    def Y(v): return Hh - mb - (Hh - mb - 12) * v / maxv
    series = [('food', '#8D1D2C', 'Food cost'), ('labPct', '#2a78d6', 'Main-d’œuvre'), ('ohPct', '#9a938a', 'Frais généraux')]
    for k, col, lib in series:
        pts = ' '.join(f'{X(i):.1f},{Y(x[k]):.1f}' for i, x in enumerate(xs))
        out.append(f'<polyline points="{pts}" fill="none" stroke="{col}" stroke-width="2.4"/>')
        for i, x in enumerate(xs): out.append(f'<circle cx="{X(i):.1f}" cy="{Y(x[k]):.1f}" r="3" fill="{col}"/><text x="{X(i):.1f}" y="{Y(x[k])-7:.1f}" text-anchor="middle" class="axs" fill="{col}">{nf(x[k],1)}</text>')
    for i, x in enumerate(xs): out.append(f'<text x="{X(i):.1f}" y="{Hh-mb+14}" text-anchor="middle" class="ax">{x["nom"][:4]}.</text>')
    for v, lib, cote in ((32, 'seuil matière 32 %', 'g'), (33, 'seuil main-d’œuvre 33 %', 'd'), (13.5, 'seuil frais généraux 13,5 %', 'd')):
        out.append(f'<line x1="{ml}" x2="{W-6}" y1="{Y(v):.1f}" y2="{Y(v):.1f}" stroke="#c9c1b5" stroke-dasharray="3 4"/>')
        if cote == 'g': out.append(f'<text x="{ml+4}" y="{Y(v)+10:.1f}" class="axs">{lib}</text>')
        else: out.append(f'<text x="{W-8}" y="{Y(v)-3:.1f}" text-anchor="end" class="axs">{lib}</text>')
    lx = ml + 6
    for k, col, lib in series: out.append(f'<rect x="{lx}" y="4" width="10" height="4" fill="{col}"/><text x="{lx+14}" y="9" class="axs">{lib}</text>'); lx += 110
    out.append('</svg>'); return ''.join(out)
def semaine_svg():
    W, Hh = 320, 150; jours = list(sem.items()); maxv = max(v['moyen'] for k, v in jours) * 1.15; bw = (W - 20) / 7
    out = [f'<svg viewBox="0 0 {W} {Hh}" class="graph">']
    for i, (k, v) in enumerate(jours):
        x0 = 10 + i * bw + bw * 0.15; w = bw * 0.7; hb = (Hh - 36) * v['moyen'] / maxv; yb = Hh - 22 - hb
        out.append(f'<rect x="{x0:.1f}" y="{yb:.1f}" width="{w:.1f}" height="{hb:.1f}" rx="3" fill="{"#8D1D2C" if k in ("samedi", "dimanche") else "#c9a9ad"}"/><text x="{x0+w/2:.1f}" y="{yb-4:.1f}" text-anchor="middle" class="axs">{nf(v["moyen"],0)}</text><text x="{x0+w/2:.1f}" y="{Hh-8}" text-anchor="middle" class="ax">{k[:3]}.</text>')
    out.append('</svg>'); return ''.join(out)

def tuile(k, v, s=''): return f'<div class="tile"><div class="k">{k}</div><div class="v">{v}</div><div class="s">{s}</div></div>'

entete = lambda titre: f'<div class="hd"><span class="marque">Atelier by – Halle</span><span class="dr">Dossier investisseur · {titre}</span></div>'
pied = lambda n: f'<div class="ft"><span>Chiffres lus le 9 octobre 2026 sur le panel et le cockpit du réseau · à lire avec la page « Sources, méthode et limites »</span><span>{n}</span></div>'

pages = []
# 1. Couverture et synthèse
res7 = cum['res']; first = [x for x in mois if x['m'] == 2][0]
pages.append(f'''<section class="page couv">{entete('synthèse')}
<div class="titre"><div class="sur">Dossier investisseur</div><h1>Atelier by – Halle</h1><div class="sous">Le magasin depuis son ouverture, le 20 février 2026, jusqu’au 8 octobre 2026</div></div>
<table class="grille"><tr>
<td>{tuile('Chiffre d’affaires, mars → septembre', fE(cum['ca']), f"7 mois pleins · {fE(caMois)} par mois en moyenne · {fP(100*cum['ca']/cum['bud'],0)} du budget")}</td>
<td>{tuile('Clients servis', nf(cum['tk']), f"tickets sur 7 mois · {nf(cum['tk']/cum['j'],0)} par jour · panier moyen {fE(cum['ca']/cum['tk'],2)}")}</td>
<td>{tuile('Food cost', fP(100*cum['mat']/cum['ca']), f"matière mesurée sur les recettes vendues · modèle du réseau 36 % + 2 % d’emballage")}</td>
<td>{tuile('Résultat opérationnel encodé', fE(res7), f"{fP(100*res7/cum['ca'])} du CA · CA − matière − main-d’œuvre encodée − frais généraux encodés")}</td>
</tr></table>
<div class="deux">
<div><div class="sec">Le magasin en bref</div>
<ul class="pts">
<li><b>Ouvert le 20 février 2026</b>, 7 jours sur 7 depuis mars : {cum['j']} jours de vente sur les 7 mois pleins.</li>
<li><b>Quatre magasins</b> dans le réseau Atelier by en Belgique : Corbais, Gosselies, Sombreffe et Halle. Halle est le dernier ouvert.</li>
<li><b>Étude de marché</b> : {nf(budget['etudeMarche']['potentielMenages'])} ménages dans la zone, potentiel à maturité {fE(budget['etudeMarche']['potentielMaturite'])} par an, montée en régime 70 % en année 1, 80 % en année 2, 90 % en année 3. CA théorique année 1 : {fE(budget['caTheoriqueAn'])}.</li>
<li><b>Budget 2026</b> validé avec le franchisé : 62 000 € par mois de mars à juillet, 61 000 € d’août à décembre.</li>
<li><b>Le week-end fait la semaine</b> : samedi {fP(sem['samedi']['part'],0)} et dimanche {fP(sem['dimanche']['part'],0)} du chiffre, soit {fP(sem['samedi']['part']+sem['dimanche']['part'],0)} sur deux jours.</li>
<li><b>Qualité</b> : note Google {nf(po['google']['note'],1)} / 5 sur {po['google']['avis']} avis, client mystère {po['msp']['obtenu']} / {po['msp']['maximum']}, 2<sup>e</sup> du réseau au scoring du 3<sup>e</sup> trimestre.</li>
</ul></div>
<div><div class="sec">Ce que montrent les sept mois pleins</div>
<ul class="pts">
<li>Le chiffre s’est installé entre <b>46 000 et 60 000 € par mois</b>, avec un pic en mai ({fE([x for x in pleins if x['m']==5][0]['ca'])}) et un creux en juin et juillet.</li>
<li>Le budget n’est atteint aucun mois : <b>{fP(100*cum['ca']/cum['bud'],0)} en cumul</b>, de 75 % en juillet à 97 % en mai.</li>
<li>Le food cost <b>baisse</b> : {fP([x for x in pleins if x['m']==3][0]['food'])} en mars, {fP([x for x in pleins if x['m']==9][0]['food'])} en septembre. Il reste au-dessus du seuil de 32 % du réseau.</li>
<li>La main-d’œuvre encodée au panel pèse {fP(100*cum['lab']/cum['ca'])} du CA, les frais généraux encodés {fP(100*cum['oh']/cum['ca'])} : voir la page des coûts pour ce que ces deux lignes contiennent.</li>
<li>Le panier moyen monte de {fE([x for x in pleins if x['m']==3][0]['panier'],2)} en mars à {fE([x for x in pleins if x['m']==9][0]['panier'],2)} en septembre ; le nombre de clients, lui, stagne autour de 4 200 par mois.</li>
</ul></div></div>
<div class="note">Un mois partiel n’entre pas dans les cumuls : février (9 jours d’ouverture, {fE(first['ca'])}) et octobre (8 jours). Tous les montants sont hors TVA.</div>
{pied(1)}</section>''')

# 2. Les ventes
lig = ''.join(f'<tr class="{"part" if x["partiel"] else ""}"><td class="l">{x["nom"]}{" <small>20 → 28</small>" if x["m"]==2 else (" <small>1 → 8</small>" if x["m"]==10 else "")}</td><td>{x["jours"]}</td><td><b>{fE(x["ca"])}</b></td><td>{fE(x["budget"]) if x["budget"] else "—"}</td><td>{fP(100*x["ca"]/x["budget"],0) if x["budget"] and not x["partiel"] else "—"}</td><td>{nf(x["tickets"])}</td><td>{fE(x["panier"],2)}</td><td>{fE(x["ca"]/x["jours"]) if x["jours"] else "—"}</td></tr>' for x in mois)
pages.append(f'''<section class="page">{entete('les ventes')}
<h2>Les ventes, mois par mois</h2>
<table class="t"><thead><tr><th class="l">Mois</th><th>Jours de vente</th><th>Chiffre d’affaires</th><th>Budget</th><th>Atteinte</th><th>Clients (tickets)</th><th>Panier moyen</th><th>CA par jour</th></tr></thead>
<tbody>{lig}</tbody><tfoot><tr class="tot"><td class="l">Mars → septembre</td><td>{cum['j']}</td><td>{fE(cum['ca'])}</td><td>{fE(cum['bud'])}</td><td>{fP(100*cum['ca']/cum['bud'],0)}</td><td>{nf(cum['tk'])}</td><td>{fE(cum['ca']/cum['tk'],2)}</td><td>{fE(cum['ca']/cum['j'])}</td></tr></tfoot></table>
<div class="sec">Le chiffre face au budget</div>
{barres_ca()}
<div class="legende"><span class="sw" style="background:#8D1D2C"></span> chiffre d’affaires du mois <span class="sw" style="background:#221E1A;height:2px;margin-left:10px"></span> budget du mois</div>
<div class="deux" style="margin-top:4mm">
<div><div class="sec">La semaine type</div><div class="s">CA moyen par jour de semaine, mars → septembre</div>{semaine_svg()}
<div class="s">Le dimanche vaut {nf(sem['dimanche']['moyen']/sem['lundi']['moyen'],1)} fois un lundi. Meilleure journée depuis l’ouverture : le dimanche 10 mai, {fE(4336.5)}.</div></div>
<div><div class="sec">Ce que ça dit</div><ul class="pts">
<li>Le magasin a trouvé son rythme dès le deuxième mois : <b>+ 14 %</b> d’avril sur mars, puis le pic de mai.</li>
<li>Juin et juillet retombent à 47 000 € : l’été pèse, comme dans les trois autres magasins du réseau (− 10 à − 19 % en juillet chez eux aussi).</li>
<li>La rentrée tient : août et septembre reviennent à 51 à 52 000 €.</li>
<li>Le budget de 62 000 € suppose 4 900 à 5 000 clients par mois au panier actuel : il en manque 700 à 800 chaque mois. C’est la clé du dossier : <b>la fréquentation, pas le panier</b>.</li>
</ul></div></div>
{pied(2)}</section>''')

# 3. Les coûts
ligc = ''.join(f'<tr class="{"part" if x["partiel"] else ""}"><td class="l">{x["nom"]}</td><td>{fE(x["ca"])}</td><td>{fP(x["food"]) if x["food"] is not None else "—"}</td><td>{fE(x["mat"]) if x["mat"] is not None else "—"}</td><td>{fE(x["lab"])}</td><td>{fP(x["labPct"])}</td><td>{fE(x["oh"])}</td><td>{fP(x["ohPct"])}</td><td><b>{fE(x["res"]) if x["res"] is not None else "—"}</b></td><td>{fP(x["resPct"]) if x["resPct"] is not None else "—"}</td></tr>' for x in mois if x['m'] != 2)
fev = first
pages.append(f'''<section class="page">{entete('les coûts et le résultat')}
<h2>Les coûts et le résultat opérationnel</h2>
<table class="t"><thead><tr><th class="l">Mois</th><th>CA</th><th>Food cost</th><th>Matière</th><th>Main-d’œuvre encodée</th><th>%</th><th>Frais généraux encodés</th><th>%</th><th>Résultat opérationnel</th><th>%</th></tr></thead>
<tbody>{ligc}</tbody><tfoot><tr class="tot"><td class="l">Mars → septembre</td><td>{fE(cum['ca'])}</td><td>{fP(100*cum['mat']/cum['ca'])}</td><td>{fE(cum['mat'])}</td><td>{fE(cum['lab'])}</td><td>{fP(100*cum['lab']/cum['ca'])}</td><td>{fE(cum['oh'])}</td><td>{fP(100*cum['oh']/cum['ca'])}</td><td>{fE(cum['res'])}</td><td>{fP(100*cum['res']/cum['ca'])}</td></tr></tfoot></table>
<div class="sec">Les trois postes en part du chiffre</div>
{lignes_pct()}
<div class="deux" style="margin-top:3mm">
<div class="cadre"><div class="k">Comment lire ces chiffres</div>
<span class="lg"><b>Food cost</b> : le coût matière des recettes réellement vendues, mesuré ticket par ticket par le cockpit. C’est une mesure, pas une estimation. Il baisse de 39,8 % à 36,2 % entre mars et septembre.</span>
<span class="lg"><b>Main-d’œuvre encodée</b> : les heures de l’équipe planifiées au panel, au taux horaire. À {fP(100*cum['lab']/cum['ca'])} du CA, loin du seuil de 33 % du réseau : la ligne ne porte vraisemblablement ni la rémunération du gérant ni toutes les charges sociales. À vérifier avec le franchisé.</span>
<span class="lg"><b>Frais généraux encodés</b> : un montant mensuel saisi au panel ({fE(13042.63)} par mois depuis juillet, 9 360 € en mars), sans détail ; le modèle de charges de la page suivante dit ce qu’ils devraient contenir.</span>
<span class="lg"><b>Résultat opérationnel encodé</b> : CA − matière − main-d’œuvre encodée − frais généraux encodés. Avant royalties, amortissements, charges financières et impôts, sous la réserve faite sur la main-d’œuvre.</span></div>
<div><div class="sec">Février, le mois d’ouverture</div><ul class="pts"><li>9 jours d’ouverture du 20 au 28 février : {fE(fev['ca'])} de ventes, {nf(fev['tickets'])} clients.</li><li>Les frais encodés ce mois-là, {fE(fev['oh'])}, portent les coûts de démarrage : résultat de {fE(fev['res']) if fev['res'] is not None else fE(fev['ca']-fev['lab']-fev['oh'])}.</li><li>Ce mois est tenu hors des cumuls et des moyennes.</li></ul>
<div class="sec" style="margin-top:4mm">Les invendus</div><ul class="pts"><li>Du 1<sup>er</sup> mars au 8 octobre : 8 169 pièces jetées, un coût matière de 5 874 € (1,5 % du CA) et 14 560 € de chiffre non réalisé. 99 % en fin de journée, le reste en dégustation et casse.</li></ul></div></div>
{pied(3)}</section>''')

# 4. Face au réseau + modèle de charges
ch = ''.join(f'<tr><td class="l">{H(c.get("poste"))}<small>{H(c.get("categorie"))}{(" · " + H(c.get("description"))) if c.get("description") else ""}</small></td><td>{fP(c.get("pctBudget") or 0,1)}</td><td>{fP(c.get("pctTheorique") or 0,1)}</td><td>{fE(caMois*(c.get("pctBudget") or 0)/100)}</td></tr>' for c in budget['charges'])
tb = sum(c.get('pctBudget') or 0 for c in budget['charges'])
pages.append(f'''<section class="page">{entete('le réseau et le modèle')}
<h2>Halle face au réseau</h2>
<table class="t"><thead><tr><th class="l">Mars → septembre 2026</th><th>Halle</th><th>Moyenne des 3 autres magasins</th><th>Seuil du réseau</th></tr></thead><tbody>
<tr><td class="l">Food cost (matière, part du CA)</td><td><b>{fP(100*cum['mat']/cum['ca'])}</b></td><td>{fP(aFood)}</td><td>32 %</td></tr>
<tr><td class="l">Main-d’œuvre encodée (part du CA)</td><td><b>{fP(100*cum['lab']/cum['ca'])}</b></td><td>{fP(100*aLab/aCA)}</td><td>33 %</td></tr>
<tr><td class="l">Frais généraux encodés (part du CA)</td><td><b>{fP(100*cum['oh']/cum['ca'])}</b></td><td>{fP(100*aOh/aCA)}</td><td>13,5 %</td></tr>
<tr><td class="l">Panier moyen</td><td><b>{fE(cum['ca']/cum['tk'],2)}</b></td><td>{fE(aPan,2)}</td><td>—</td></tr>
<tr><td class="l">Rang par chiffre d’affaires sur la période</td><td><b>{rangH}<sup>e</sup> sur 4</b></td><td colspan="2" class="l">le magasin le plus proche en taille fait 4 % de plus, le plus grand trois fois plus</td></tr>
<tr><td class="l">Scoring qualité du 3<sup>e</sup> trimestre (sur 20)</td><td><b>{nf(hs['total'],1)}</b></td><td>{nf(sc3['reseau']['sur20'],1)} (réseau)</td><td>rang {hs['rang']} sur 4</td></tr>
</tbody></table>
<div class="s">Les trois autres magasins : Corbais, Gosselies, Sombreffe. Mêmes sources et mêmes règles que pour Halle ; un mois dont le food cost mesuré dépasse 60 % (anomalie de recette) est écarté de leur moyenne.</div>
<h2 style="margin-top:6mm">Le modèle de charges du franchiseur</h2>
<div class="s">Les postes du budget 2026 de Halle, en part du chiffre, et ce qu’ils représentent au rythme actuel ({fE(caMois)} de CA par mois). Ce sont les hypothèses du modèle, pas des montants constatés : le loyer réel vient du bail, l’énergie des factures.</div>
<table class="t serre"><thead><tr><th class="l">Poste</th><th>Budget</th><th>Théorique</th><th>€ par mois au rythme actuel</th></tr></thead><tbody>{ch}</tbody>
<tfoot><tr class="tot"><td class="l">Total des postes budgétés</td><td>{fP(tb,1)}</td><td></td><td>{fE(caMois*tb/100)}</td></tr></tfoot></table>
<div class="note">Le loyer et les charges locatives sont budgétés à 7 % du CA, « selon emplacement », soit environ {fE(caMois*0.07)} par mois au rythme actuel. Le panel ne porte pas le bail : ce montant est à confirmer avec le franchisé. La matière (36 %) et l’emballage (2 %) ne figurent qu’en théorique dans ce modèle : le food cost mesuré de la page précédente en tient lieu.</div>
{pied(4)}</section>''')

# 5. L'assortiment et les canaux
top = cats[:14]; maxv = top[0]['v']
lc = ''.join(f'<tr><td class="l">{H(c["nom"])}<small>{H(c.get("groupe") or "")}</small></td><td>{fE(c["v"])}</td><td>{fP(100*c["v"]/tv)}</td><td class="l" style="width:34%"><span class="barre"><i style="width:{100*c["v"]/maxv:.0f}%;background:#8D1D2C"></i></span></td><td>{nf(c.get("q"))}</td><td>{c.get("refs")}</td></tr>' for c in top)
lf = ''.join(f'<span class="lg"><b>{H(k)}</b> {fP(100*v/tv)}</span>' for k, v in fam[:8])
pages.append(f'''<section class="page">{entete('l’assortiment et les canaux')}
<h2>L’assortiment : ce qui se vend en septembre</h2>
<div class="deux g31">
<div><table class="t"><thead><tr><th class="l">Catégorie</th><th>CA</th><th>Part</th><th class="l"></th><th>Pièces</th><th>Réf.</th></tr></thead><tbody>{lc}</tbody></table>
<div class="s">Septembre 2026, {nf(stats['totaux']['ca'])} € de ventes sur {stats['nJoursOuverts']} jours, 27 catégories, {sum(c.get('refs') or 0 for c in cats)} références vendues. Les 14 premières catégories font {fP(100*sum(c['v'] for c in top)/tv,0)} du chiffre.</div></div>
<div><div class="cadre"><div class="k">Par famille</div>{lf}</div>
<div class="sec" style="margin-top:4mm">Les canaux</div><ul class="pts">
<li><b>Le comptoir fait tout</b> : en septembre, 99 % du chiffre passe en caisse ; le click &amp; collect et la livraison sont marginaux.</li>
<li><b>Clients professionnels</b> : 755 € en septembre, 1,5 % du CA, 5 sociétés. Le réseau tolère jusqu’à 40 % de CA pro ; ce levier est presque vierge à Halle.</li>
<li><b>Heure la plus forte</b> : 11 h, avec {fE(stats['meilleure']['res'])} de ventes cumulées sur le mois ; après 19 h, presque rien.</li>
</ul></div></div>
{pied(5)}</section>''')

# 6. La qualité et les photos
grid = ''
for c in choix:
    m = meta.get(c)
    if not m: continue
    code = (m['checklist'] or '')[:5].strip(' -—'); lib = LIB.get(code, m['checklist'] or '')
    note = m.get('avis', {}) or {}
    grid += f'<figure><img src="photos/{m["fichier"]}"><figcaption>{H(lib)} · {fDj(m["date"])}{(" · noté " + str(note.get("note")) + " / 5 par le consultant") if note.get("note") else ""}</figcaption></figure>'
tache = po['taches']
pages.append(f'''<section class="page">{entete('la qualité')}
<h2>La qualité au quotidien</h2>
<table class="grille"><tr>
<td>{tuile('Note Google', nf(po['google']['note'],1) + ' / 5', f"{po['google']['avis']} avis au {fDj(po['google']['le'][:10])}")}</td>
<td>{tuile('Client mystère', f"{po['msp']['obtenu']} / {po['msp']['maximum']}", '3e trimestre 2026')}</td>
<td>{tuile('Contrôles rendus', fP(tache['part'],0), f"{nf(tache['faites'])} tâches rendues sur {nf(tache['attendues'])} attendues au 3e trimestre")}</td>
<td>{tuile('Scoring du trimestre', f"{nf(hs['total'],1)} / 20", f"{hs['rang']}e magasin sur 4 · réseau {nf(sc3['reseau']['sur20'],1)} / 20")}</td>
</tr></table>
<div class="s">Chaque matin, l’équipe photographie les vitrines, les pains et la salle à l’ouverture (« contrôle qualité d’ouverture ») et le consultant du réseau note ce qu’il voit. Les photos ci-dessous sont celles des contrôles des trois dernières semaines, telles qu’elles ont été rendues.</div>
<div class="photos">{grid}</div>
<div class="note">Le poste « contrôles rendus » compte les tâches rendues sur toutes celles attendues par le panel, photos comprises ; une tâche obligatoire manquée met la journée à zéro. Halle rend ses contrôles d’ouverture avec régularité mais pas l’ensemble des tâches attendues : c’est le point d’amélioration du scoring.</div>
{pied(6)}</section>''')

# 7. Sources
pages.append(f'''<section class="page">{entete('sources, méthode et limites')}
<h2>Sources, méthode et limites</h2>
<div class="cadre"><div class="k">D’où vient chaque chiffre</div>
<span class="lg"><b>Chiffre d’affaires, clients, panier</b> : la caisse du magasin, lue par l’API du panel (statistiques de ventes par mois et P&amp;L quotidien). Hors TVA.</span>
<span class="lg"><b>Food cost</b> : le cockpit du réseau mesure, ticket par ticket, le coût de recette de chaque référence vendue (recettes et prix des matières du panel). Les anomalies de recette sont filtrées. Le modèle du réseau prévoit 36 % de matière et 2 % d’emballage.</span>
<span class="lg"><b>Main-d’œuvre et frais généraux</b> : les lignes « labour » et « overhead » du P&amp;L quotidien du panel, additionnées par mois. Elles valent ce que le franchisé y a encodé : les heures planifiées de l’équipe et un montant mensuel de frais fixes.</span>
<span class="lg"><b>Budget et modèle de charges</b> : le budget 2026 validé avec le franchisé et l’étude de marché, tels qu’encodés au cockpit.</span>
<span class="lg"><b>Qualité</b> : la fiche Google du magasin (note et nombre d’avis), le client mystère du trimestre, les tâches du panel et les photos des contrôles d’ouverture.</span>
<span class="lg"><b>Réseau</b> : les mêmes sources pour les trois autres magasins, rendues en parts du chiffre seulement.</span></div>
<div class="sec" style="margin-top:5mm">Ce que le dossier ne dit pas</div>
<ul class="pts">
<li><b>Le loyer réel</b>, les contrats d’énergie, les amortissements et les charges financières ne sont pas dans le panel. Le modèle de charges donne les hypothèses du réseau ; le bail et la comptabilité du franchisé donnent le réel.</li>
<li><b>La rémunération du gérant</b> et le détail des charges sociales ne sont pas visibles dans la ligne main-d’œuvre encodée. Le résultat opérationnel présenté est donc un résultat avant ces éléments.</li>
<li><b>Les résultats comptables</b> (bilan, compte de résultat) ne sont pas dans ce dossier. Il décrit l’exploitation telle que les outils du réseau la mesurent, pas les comptes de la société.</li>
<li>Octobre est en cours (8 jours) et février est un mois d’ouverture : ni l’un ni l’autre n’entre dans les moyennes.</li>
</ul>
<div class="sec" style="margin-top:5mm">Pour aller plus loin</div>
<ul class="pts"><li>Le classeur Excel joint reprend chaque tableau du dossier, mois par mois, avec les parts et les montants, pour refaire les calculs.</li><li>Le cockpit du réseau permet de suivre le magasin jour par jour : ventes à l’heure, recettes et marges par produit, contrôles en photo.</li></ul>
{pied(7)}</section>''')

css = '''
@font-face{font-family:'Gotham';src:url('file:///home/user/consultant_BO/public/assets/ds/fonts/Gotham_Regular_New.otf');font-weight:400}
@font-face{font-family:'Gotham';src:url('file:///home/user/consultant_BO/public/assets/ds/fonts/Gotham_Medium.otf');font-weight:500}
@font-face{font-family:'Gotham';src:url('file:///home/user/consultant_BO/public/assets/ds/fonts/Gotham_Black.otf');font-weight:800}
@font-face{font-family:'Vank';src:url('file:///home/user/consultant_BO/public/assets/ds/fonts/GC_Vank.ttf')}
@page{size:A4;margin:0}
*{box-sizing:border-box}
body{margin:0;font-family:Gotham,Helvetica,Arial,sans-serif;color:#221E1A;font-size:9pt;line-height:1.45;background:#fff}
.page{width:210mm;height:297mm;padding:12mm 14mm 10mm;position:relative;page-break-after:always;overflow:hidden;background:#fff}
.page:last-child{page-break-after:auto}
.hd{display:flex;justify-content:space-between;border-bottom:2px solid #8D1D2C;padding-bottom:2mm;margin-bottom:5mm;font-size:7.5pt;letter-spacing:.08em;text-transform:uppercase;color:#7a736a}
.hd .marque{color:#8D1D2C;font-weight:500}
.ft{position:absolute;left:14mm;right:14mm;bottom:7mm;display:flex;justify-content:space-between;font-size:7pt;color:#9a938a;border-top:.5pt solid #e6e0d8;padding-top:1.5mm}
h1{font-family:Vank,Georgia,serif;font-weight:400;font-size:34pt;margin:0;letter-spacing:-.01em;line-height:1.05}
h2{font-family:Vank,Georgia,serif;font-weight:400;font-size:17pt;margin:0 0 3.5mm;color:#221E1A}
.titre{margin:14mm 0 9mm}
.sur{font-size:8pt;letter-spacing:.12em;text-transform:uppercase;color:#8D1D2C;font-weight:500;margin-bottom:2mm}
.sous{font-size:10.5pt;color:#7a736a;margin-top:2.5mm}
.sec{font-family:Vank,Georgia,serif;font-size:12.5pt;margin:0 0 2.5mm;padding-bottom:1.2mm;border-bottom:1.2pt solid #8D1D2C}
.s{font-size:7.8pt;color:#7a736a;line-height:1.5;margin:1mm 0 2mm}
table.grille{width:100%;border-collapse:separate;border-spacing:2mm 0;margin:0 -2mm 6mm}
.tile{border:1px solid #e6e0d8;border-radius:8px;background:#fbf9f5;padding:3mm 3.5mm;height:100%}
.tile .k{font-size:6.8pt;letter-spacing:.08em;text-transform:uppercase;color:#7a736a}
.tile .v{font-family:Vank,Georgia,serif;font-size:19pt;margin-top:1.5mm;white-space:nowrap;color:#8D1D2C}
.tile .s{font-size:7.2pt;color:#7a736a;margin-top:1mm;line-height:1.4}
.deux{display:grid;grid-template-columns:1fr 1fr;gap:7mm}
.deux.g31{grid-template-columns:1.35fr 1fr}
.pts{margin:0;padding-left:4mm;font-size:8.6pt}
.pts li{margin-bottom:1.6mm;line-height:1.45}
table.t{width:100%;border-collapse:collapse;margin-bottom:4mm}
.t th{font-size:6.6pt;letter-spacing:.06em;text-transform:uppercase;color:#7a736a;font-weight:500;text-align:right;padding:1.4mm 1.8mm;border-bottom:1pt solid #221E1A;vertical-align:bottom;line-height:1.25}
.t td{font-size:8.4pt;text-align:right;padding:1.3mm 1.8mm;border-bottom:.5pt solid #EAE3D8;white-space:nowrap;font-variant-numeric:tabular-nums}
.t .l{text-align:left;white-space:normal}
.t td small{display:block;font-size:6.8pt;color:#9a938a;line-height:1.3}
.t tr.tot td{border-top:1pt solid #221E1A;border-bottom:none;font-weight:500;background:#FCFAF7}
.t tr.part td{color:#9a938a}
.t.serre td{padding:0.9mm 1.8mm}.t.serre td small{font-size:6.4pt}
.graph{width:100%;height:auto;display:block}
.graph .ax{font-size:9px;fill:#7a736a}.graph .axs{font-size:8.5px;fill:#7a736a}.graph .val{font-size:9.5px;font-weight:500}
.legende{font-size:7.5pt;color:#7a736a;margin:1mm 0 2mm}.legende .sw{display:inline-block;width:10px;height:6px;vertical-align:middle;margin-right:4px}
.cadre{border:1px solid #8D1D2C;border-radius:8px;padding:2.5mm 3.5mm;margin-bottom:3mm;font-size:8.2pt}
.cadre .k{color:#8D1D2C;font-weight:500;font-size:7pt;letter-spacing:.08em;text-transform:uppercase;margin-bottom:1mm}
.cadre .lg{display:block;padding:1.2mm 0;border-bottom:.5pt solid #EAE3D8;line-height:1.45}.cadre .lg:last-child{border-bottom:none}
.note{border-left:3px solid #8D1D2C;background:#fbf9f5;padding:2mm 3mm;font-size:8pt;color:#5a534b;margin-top:3mm;line-height:1.5}
.barre{display:inline-block;position:relative;width:100%;height:2.6mm;border-radius:2mm;background:#EFE9DF;vertical-align:middle}.barre i{position:absolute;left:0;top:0;height:2.6mm;border-radius:2mm}
.photos{display:grid;grid-template-columns:repeat(3,1fr);gap:3mm;margin-top:3mm}
.photos figure{margin:0}.photos img{width:100%;height:46mm;object-fit:cover;border-radius:6px;display:block}
.photos figcaption{font-size:7pt;color:#7a736a;margin-top:1mm;line-height:1.3}
.couv .deux{margin-top:2mm}
'''
doc = f'<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Atelier by – Halle · dossier investisseur</title><style>{css}</style></head><body>{"".join(pages)}</body></html>'
open(SP + '/halle/dossier.html', 'w', encoding='utf-8').write(doc)
print('dossier.html', len(doc), 'octets ·', len(pages), 'pages · CA 7 mois', round(cum['ca']), '· résultat', round(cum['res']))
