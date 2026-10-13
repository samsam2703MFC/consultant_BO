# Le tableau récap de Halle : une page A4 (ventes et clients face à l'objectif, coûts du magasin, point mort, mois par mois)
# et le classeur Excel du même récap, avec ses formules (le point mort se recalcule quand on change une hypothèse).
#
#   python3 generer.py --relire   relit les chiffres sur le cockpit en ligne et les fige dans donnees.json
#   python3 generer.py            refait le PDF et l'Excel depuis donnees.json
#   --food 45 | --food mesure     food cost retenu pour la matière, le résultat et le point mort (défaut : 45 %, demande du
#                                 13/10/2026 ; « mesure » = le food cost mesuré par le panel, mois par mois)
#   puis : python3 <skill xlsx>/scripts/recalc.py Halle-tableau-recap.xlsx   (les formules n'ont de valeur en cache qu'après recalcul)
#
# Mois pleins : mars → septembre 2026 (février : 9 jours d'ouverture ; octobre : en cours, jusqu'à la veille de la lecture).
import json, html, base64, os, sys, subprocess, tempfile, urllib.request, datetime
from openpyxl import Workbook
from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
from openpyxl.comments import Comment

ICI = os.path.dirname(os.path.abspath(__file__))
RACINE = os.path.abspath(os.path.join(ICI, '..', '..', '..'))
API = os.environ.get('COCKPIT_API', 'http://185.180.206.46/consulant_bo/api/cockpit')
SHOP = '4'
MOIS_PLEINS = [3, 4, 5, 6, 7, 8, 9]
NOMS = {2: 'Février', 3: 'Mars', 4: 'Avril', 5: 'Mai', 6: 'Juin', 7: 'Juillet', 8: 'Août', 9: 'Septembre', 10: 'Octobre'}


# --- 1. La lecture du cockpit, figée dans donnees.json -------------------------------------------------------------------
def lire(chemin):
    with urllib.request.urlopen(API + chemin, timeout=180) as r:
        return json.load(r)


def relire():
    auj = datetime.date.today(); veille = (auj - datetime.timedelta(days=1)).isoformat()
    pnl = lire(f'/analyse/pnl/mois?shop={SHOP}&du=2026-03&au=2026-09')['magasins'][0]['mois']
    perf = lire('/stores/perf'); etp = lire('/stores/etp'); bud = lire('/stores/budgets')
    perf = perf if isinstance(perf, list) else (perf.get('perf') or perf.get('data'))
    bud = bud if isinstance(bud, list) else (bud.get('budgets') or bud.get('data'))
    P = {r['mois']: r for r in perf if str(r['storeId']) == SHOP and r['annee'] == 2026}
    E = {r['mois']: r for r in etp if str(r['storeId']) == SHOP and r['annee'] == 2026}
    mois = []
    for x in pnl:
        m = int(x['mois'][5:7])
        mois.append(dict(m=m, jours=x['joursVentes'], ca=x['ca'], budget=P[m]['caBudget'], tickets=P[m]['tickets'], food=round(P[m]['foodCostPct'] / 100, 4),
                         labour=x['labour'], overhead=x['overhead'], heures=E[m]['heures'], etp=E[m]['etp']))
    # Le food cost recomposé ticket par ticket par le cockpit (lu depuis août seulement)
    def periode(d):
        x = lire(f'/exploitation/periode?vue=mois&date={d}')
        return [s for s in x['magasins'] if str(s['shopId']) == SHOP][0]
    ft = {k: periode(d) for k, d in (('aout', '2026-08-31'), ('sept', '2026-09-30'))}
    o = periode(veille)
    jo = [j for j in o['jours'] if j['date'] <= veille and j.get('ca') is not None]
    # Les frais généraux qu'alloue le panel en octobre, par jour
    pj = lire(f'/analyse/pnl/mois?shop={SHOP}&du=2026-10&au=2026-10&jours=1')['magasins'][0]['mois'][0]['parJour']
    ohJ = [j['overhead'] for j in pj if j['date'] <= veille and j.get('overhead')]
    autres = {}
    for r in perf:
        if str(r['storeId']) in ('2', '3', '5') and r['annee'] == 2026 and 3 <= r['mois'] <= 9 and r.get('panierMoyen'):
            autres.setdefault(str(r['storeId']), []).append(r['panierMoyen'])
    B = [b for b in bud if str(b.get('storeId')) == SHOP][0]
    d = dict(lu=auj.isoformat(), mois=mois,
             fevrier=dict(jours=next(r for r in lire(f'/analyse/pnl/mois?shop={SHOP}&du=2026-02&au=2026-02')['magasins'][0]['mois'])['joursVentes'], ca=P[2]['ca']),
             octobre=dict(jusqua=veille, jours=len(jo), ca=round(sum(j['ca'] for j in jo), 2), tickets=sum(j['tickets'] for j in jo),
                          objectifADate=round(sum(j['objectif'] for j in jo), 2), objectifMois=o['objectif'], overheadJour=ohJ[-1] if ohJ else None, joursMois=31),
             foodTickets={k: dict(ca=P[8 if k == 'aout' else 9]['ca'], matiere=v['coutMatiere'], reseauPct=None) for k, v in ft.items()},
             panierAutres=dict(moyenne=round(sum(sum(v) for v in autres.values()) / sum(len(v) for v in autres.values()), 2),
                               magasins={s: round(sum(v) / len(v), 2) for s, v in autres.items()}),
             budgetCourant=P[10]['caBudget'] or P[9]['caBudget'],
             etude=dict(caTheoriqueAn=B['caTheoriqueAn'], potentielMaturite=B['etudeMarche']['potentielMaturite'], montee=B['etudeMarche']['monteeEnRegime']),
             charges=[dict(categorie=c['categorie'], poste=c['poste'], pctBudget=round(c['pctBudget'] / 100, 4), pctTheorique=round(c['pctTheorique'] / 100, 4), gestion=c['gestion'], description=c['description']) for c in B['charges']])
    json.dump(d, open(os.path.join(ICI, 'donnees.json'), 'w'), ensure_ascii=False, indent=1)
    print('donnees.json relu le', d['lu'])


if '--relire' in sys.argv:
    relire()
D = json.load(open(os.path.join(ICI, 'donnees.json')))


nfP = lambda v: f'{100 * v:.1f}'.replace('.', ',').replace(',0', '') + '\u00a0%'

# --- 2. Les chiffres ------------------------------------------------------------------------------------------------------
a_ = sys.argv[sys.argv.index('--food') + 1] if '--food' in sys.argv else '45'
FOOD_RETENU = None if a_ == 'mesure' else float(a_.replace(',', '.')) / 100
M = D['mois']
for x in M:
    x['foodMes'] = x['food']
    if FOOD_RETENU is not None: x['food'] = FOOD_RETENU
    x['mat'] = x['ca'] * x['food']; x['res'] = x['ca'] - x['mat'] - x['labour'] - x['overhead']
n = len(M)
S = lambda k, L=M: sum(x[k] for x in L)
ca, tk, jr, bud = S('ca'), S('tickets'), S('jours'), S('budget')
caMois, tkMois, caJour, tkJour, panier = ca / n, tk / n, ca / jr, tk / jr, ca / tk
food = S('mat') / ca
labMois, ohMois, resMois, matMois = S('labour') / n, S('overhead') / n, S('res') / n, S('mat') / n
heuresMois, etpMois = S('heures') / n, S('etp') / n
tauxH = [x['labour'] / x['heures'] for x in M]
# La structure récente, juillet → septembre : celle qui sert au point mort
R = [x for x in M if x['m'] >= 7]
caR = S('ca', R) / len(R); foodR = S('mat', R) / S('ca', R); foodMesR = sum(x['ca'] * x['foodMes'] for x in R) / S('ca', R); foodMes = sum(x['ca'] * x['foodMes'] for x in M) / S('ca'); labR = S('labour', R) / len(R); ohR = S('overhead', R) / len(R)
fixes = labR + ohR; tmb = 1 - foodR
pm = fixes / tmb
JM = 365 / 12                                   # jours par mois (ouvert 7 jours sur 7)
pmJour = pm / JM; pmClientsJour = pmJour / panier
securite = 1 - pm / caMois
# L'objectif : le budget courant, mensuel
obj = D['budgetCourant']; objJour = obj / JM
cliObj = obj / panier; cliObjJour = cliObj / JM; panObj = obj / tkMois
resObj = obj * tmb - fixes
mixC = round((tkJour + cliObjJour) / 2)          # un mélange des deux leviers, à mi-chemin en clients
# Octobre en cours
O = D['octobre']; oCaJ = O['ca'] / O['jours']; oTkJ = O['tickets'] / O['jours']; oPan = O['ca'] / O['tickets']; oAtt = O['ca'] / O['objectifADate']
ohOct = O['overheadJour'] * O['joursMois']
# Le food cost ticket par ticket (août + septembre)
FT = D['foodTickets']; foodT = (FT['aout']['matiere'] + FT['sept']['matiere']) / (FT['aout']['ca'] + FT['sept']['ca'])
if FOOD_RETENU is None:
    SCEN = [('Coûts encodés au panel, juillet → septembre', labR, ohR, foodR),
            ('Frais généraux d’octobre, {oh} par mois', labR, ohOct, foodR),
            ('Food cost recomposé ticket par ticket', labR, ohR, foodT),
            ('Les deux à la fois', labR, ohOct, foodT)]
else:
    SCEN = [(f'Food cost retenu {nfP(FOOD_RETENU)}, charges juillet → septembre', labR, ohR, FOOD_RETENU),
            ('Le même, avec les frais généraux d’octobre ({oh} par mois)', labR, ohOct, FOOD_RETENU),
            ('Food cost recomposé ticket par ticket (août-septembre)', labR, ohR, foodT),
            ('Food cost mesuré par le panel (juillet → septembre)', labR, ohR, foodMesR)]
pmPire = max((l + o) / (1 - f) for _, l, o, f in SCEN)
modele = sum(c['pctBudget'] for c in D['charges'])


# --- 3. Le PDF -------------------------------------------------------------------------------------------------------------
H = lambda s: html.escape(str(s))
def nf(v, d=0):
    if v is None: return '—'
    return f'{v:,.{d}f}'.replace(',', ' ').replace('.', ',')
fE = lambda v, d=0: nf(v, d) + ' €'
fP = lambda v, d=1: nf(100 * v, d) + ' %'
arr = lambda v, p=10: round(v / p) * p
LOGO = 'data:image/png;base64,' + base64.b64encode(open(os.path.join(RACINE, 'public/assets/img/logo.png'), 'rb').read()).decode()
FONTS = os.path.join(RACINE, 'public/assets/ds/fonts')
lu = datetime.date.fromisoformat(D['lu']); jusqua = datetime.date.fromisoformat(O['jusqua'])
MOISL = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre']
luTxt = f'{lu.day} {MOISL[lu.month]} {lu.year}'


def tuile(k, v, s):
    return f'<div class="tile"><div class="k">{k}</div><div class="v">{v}</div><div class="s">{s}</div></div>'


tuiles = ''.join([
    tuile('CA moyen par mois', fE(caMois), f'{fP(ca / bud, 0)} du budget · {fE(caJour)} par jour'),
    tuile('Clients par jour', nf(tkJour), f'{nf(tkMois)} tickets par mois'),
    tuile('Panier moyen', fE(panier, 2), f'les 3 autres magasins : {fE(D["panierAutres"]["moyenne"], 2)}'),
    tuile('Point mort', fE(arr(pm, 100)) + '<small> / mois</small>', f'{fE(arr(pmJour))} par jour · {nf(pmClientsJour)} clients par jour'),
])

ventes = f'''
<table class="t">
<tr><th class="l"></th><th>Actuel<br><small>moy. mars → sept.</small></th><th>Octobre<br><small>1 → {jusqua.day}</small></th><th>Objectif<br><small>budget {fE(obj)}</small></th><th>Écart<br><small>actuel → objectif</small></th></tr>
<tr><td class="l">CA par jour</td><td>{fE(caJour)}</td><td>{fE(oCaJ)}</td><td class="o">{fE(objJour)}</td><td>+&nbsp;{fE(objJour - caJour)}</td></tr>
<tr><td class="l">CA par mois</td><td>{fE(caMois)}</td><td class="mu">—</td><td class="o">{fE(obj)}</td><td>+&nbsp;{fE(obj - caMois)}</td></tr>
<tr><td class="l">Clients par jour</td><td>{nf(tkJour)}</td><td>{nf(oTkJ)}</td><td class="o">{nf(cliObjJour)}<sup>1</sup></td><td>+&nbsp;{nf(cliObjJour - tkJour)}</td></tr>
<tr><td class="l">Clients par mois</td><td>{nf(tkMois)}</td><td class="mu">—</td><td class="o">{nf(cliObj)}<sup>1</sup></td><td>+&nbsp;{nf(cliObj - tkMois)}</td></tr>
<tr><td class="l">Panier moyen</td><td>{fE(panier, 2)}</td><td>{fE(oPan, 2)}</td><td class="o">{fE(panObj, 2)}<sup>2</sup></td><td>+&nbsp;{fE(panObj - panier, 2)}</td></tr>
<tr class="tot"><td class="l">Atteinte du budget</td><td>{fP(ca / bud, 0)}</td><td>{fP(oAtt, 0)}<small>de l’objectif à date</small></td><td class="o">100&nbsp;%</td><td></td></tr>
</table>
<p class="s"><sup>1</sup> au panier actuel · <sup>2</sup> avec le nombre de clients actuel. Les deux leviers se combinent : {nf(mixC)} clients par jour (+&nbsp;{nf(mixC - tkJour)}) à {fE(objJour / mixC, 2)} de panier (+&nbsp;{fE(objJour / mixC - panier, 2)}) font aussi {fE(obj)} par mois.</p>
'''

couts = f'''
<table class="t">
<tr><th class="l">Poste</th><th>€ par mois<br><small>moy. mars → sept.</small></th><th>% du CA</th></tr>
<tr><td class="l">Matière (food cost)<small>{'seuil réseau 32&nbsp;% · modèle du franchiseur 36&nbsp;% + 2&nbsp;% d’emballage' if FOOD_RETENU is None else 'hypothèse retenue ' + nfP(FOOD_RETENU) + ' · mesuré ' + fP(foodMes) + ' au panel · seuil réseau 32&nbsp;%'}</small></td><td>{fE(matMois)}</td><td>{fP(food)}</td></tr>
<tr><td class="l">Main-d’œuvre encodée<small>seuil réseau 33&nbsp;% · {nf(heuresMois)} heures par mois, {nf(etpMois, 1)} ETP</small></td><td>{fE(labMois)}</td><td>{fP(labMois / caMois)}</td></tr>
<tr><td class="l">Frais généraux encodés<small>{fE(ohR)} par mois depuis juillet</small></td><td>{fE(ohMois)}</td><td>{fP(ohMois / caMois)}</td></tr>
<tr class="sous"><td class="l">Total des coûts</td><td>{fE(matMois + labMois + ohMois)}</td><td>{fP((matMois + labMois + ohMois) / caMois)}</td></tr>
<tr class="tot"><td class="l">Résultat opérationnel encodé<small>avant rémunération du gérant, charges financières et impôts</small></td><td>{fE(resMois)}</td><td>{fP(resMois / caMois)}</td></tr>
</table>
'''

lignesS = ''
for i, (lib, l, o, f) in enumerate(SCEN):
    p = (l + o) / (1 - f)
    lignesS += f'<tr{" class=base" if i == 0 else ""}><td class="l">{lib.replace("{oh}", fE(arr(o)))}</td><td>{fE(l + o)}</td><td>{fP(f)}</td><td class="pm">{fE(arr(p, 100))}</td><td>{fE(arr(p / JM))}</td><td>{fP(1 - p / caMois, 0)}</td></tr>'
pointmort = f'''
<div class="deux g12">
<div class="calc">
  <div class="ligne"><span>Main-d’œuvre encodée</span><span>{fE(labR)}</span></div>
  <div class="ligne"><span>Frais généraux encodés</span><span>{fE(ohR)}</span></div>
  <div class="ligne fort"><span>Charges fixes par mois</span><span>{fE(fixes)}</span></div>
  <div class="ligne"><span>÷ marge brute (1 − food cost {fP(foodR)})</span><span>{fP(tmb)}</span></div>
  <div class="ligne pm"><span>= Point mort</span><span>{fE(arr(pm, 100))} / mois</span></div>
  <div class="s">soit {fE(arr(pmJour))} par jour, ou {nf(pmClientsJour)} clients par jour au panier actuel. Charges : moyennes de juillet à septembre, la structure de coûts actuelle{'' if FOOD_RETENU is None else ' ; food cost : ' + nfP(FOOD_RETENU) + ', l’hypothèse retenue'}. Le CA moyen ({fE(caMois)}) peut baisser de {fP(securite, 0)} avant de passer sous le point mort.</div>
</div>
<div>
<table class="t serre">
<tr><th class="l">Hypothèse</th><th>Charges fixes</th><th>Food cost</th><th>Point mort / mois</th><th>/ jour</th><th>Marge de sécurité</th></tr>
{lignesS}
</table>
<p class="s">Chaque 1&nbsp;000&nbsp;€ de charges fixes en plus (la rémunération du gérant, par exemple, absente de la main-d’œuvre encodée) ajoute {fE(arr(1000 / tmb))} au point mort. À l’inverse, chaque 1&nbsp;000&nbsp;€ de CA au-dessus du point mort rapporte {fE(arr(1000 * tmb))} de résultat : au budget de {fE(obj)}, le résultat encodé serait d’environ {fE(arr(resObj, 100))} par mois, contre {fE(arr(resMois, 100))} aujourd’hui.</p>
</div>
</div>
'''

lignesM = ''
for x in M:
    a = x['ca'] / x['budget']
    note = '<sup>*</sup>' if x['m'] in (6, 7) else ''
    lignesM += (f'<tr><td class="l">{NOMS[x["m"]]}{note}</td><td>{fE(x["ca"])}</td><td>{fE(x["budget"])}</td>'
                f'<td class="bar"><span class="barre"><i style="width:{min(100, 100 * a):.1f}%"></i></span><em>{fP(a, 0)}</em></td>'
                f'<td>{nf(x["tickets"])}</td><td>{nf(x["tickets"] / x["jours"])}</td><td>{fE(x["ca"] / x["tickets"], 2)}</td><td>{fP(x["foodMes"])}</td>'
                f'<td>{fE(x["labour"])}</td><td>{fE(x["overhead"])}</td><td>{fE(x["res"])}</td><td>{fP(x["res"] / x["ca"], 0)}</td></tr>')
lignesM += (f'<tr class="tot"><td class="l">Mars → septembre</td><td>{fE(ca)}</td><td>{fE(bud)}</td>'
            f'<td class="bar"><span class="barre"><i style="width:{100 * ca / bud:.1f}%"></i></span><em>{fP(ca / bud, 0)}</em></td>'
            f'<td>{nf(tk)}</td><td>{nf(tkJour)}</td><td>{fE(panier, 2)}</td><td>{fP(foodMes)}</td><td>{fE(S("labour"))}</td><td>{fE(S("overhead"))}</td><td>{fE(S("res"))}</td><td>{fP(S("res") / ca, 0)}</td></tr>')
lignesM += (f'<tr class="part"><td class="l">Octobre 1 → {jusqua.day}<sup>**</sup></td><td>{fE(O["ca"])}</td><td>{fE(O["objectifADate"])}</td>'
            f'<td class="bar"><span class="barre"><i style="width:{min(100, 100 * oAtt):.1f}%"></i></span><em>{fP(oAtt, 0)}</em></td>'
            f'<td>{nf(O["tickets"])}</td><td>{nf(oTkJ)}</td><td>{fE(oPan, 2)}</td><td class="mu" colspan="5">mois en cours</td></tr>')
moisTab = f'''
<table class="t mois">
<tr><th class="l">Mois</th><th>CA</th><th>Budget</th><th>Atteinte</th><th>Clients</th><th>Clients / jour</th><th>Panier</th><th>Food cost{'' if FOOD_RETENU is None else ' mesuré'}</th><th>Main-d’œuvre</th><th>Frais généraux</th><th>Résultat encodé{'' if FOOD_RETENU is None else '<small>food cost ' + nfP(FOOD_RETENU) + '</small>'}</th><th>%</th></tr>
{lignesM}
</table>
'''

notes = f'''
<ul class="notes">
<li>Sources : ventes, clients, main-d’œuvre et frais généraux viennent du P&amp;L du panel (ce que le franchisé y encode) ; le food cost mesuré est la marge du panel sur les recettes vendues ; le budget est celui du cockpit. Hors TVA, lu le {luTxt}. Février (ouverture le 20, {D["fevrier"]["jours"]} jours, {fE(D["fevrier"]["ca"])}) et octobre, partiels, restent hors des moyennes.</li>
<li><sup>*</sup> Juin et juillet : travaux sur le viaduc de Halle, effet estimé à − 20&nbsp;% sur le chiffre. <sup>**</sup> Octobre : jusqu’au {jusqua.day} inclus, face à l’objectif à date (le budget du mois réparti par jour de semaine).</li>
<li>Main-d’œuvre encodée = heures planifiées × taux horaire du panel, {nf(min(tauxH))} à {nf(max(tauxH))}&nbsp;€ de l’heure selon les mois : le franchisé y compte pour 0&nbsp;€/h et les charges sociales n’y sont vraisemblablement pas toutes. Résultat et point mort sont donc avant la rémunération du gérant.</li>
<li>Frais généraux encodés : un montant mensuel saisi au panel, sans détail. Le modèle de charges du franchiseur en donne le contenu attendu — loyer 7&nbsp;%, énergie 5&nbsp;%, amortissements 5&nbsp;%, redevances 5&nbsp;%, entretien et assurances 2&nbsp;%, divers 1,5&nbsp;% : {fP(modele)} du CA, {fE(arr(modele * caMois))} par mois au rythme actuel. En octobre, le panel en alloue {fE(O["overheadJour"], 2)} par jour, {fE(arr(ohOct))} sur le mois : à confirmer avec le franchisé.</li>
<li>{'' if FOOD_RETENU is None else 'Food cost retenu : ' + nfP(FOOD_RETENU) + ' du CA, une hypothèse de travail appliquée à tous les mois (matière, résultat, point mort). '}Deux mesures existent : {fP(foodMesR)} par le panel (juillet → septembre), {fP(foodT)} quand le cockpit recompose chaque ticket au coût de recette du jour (août-septembre). L’écart se joue sur les fiches recettes ; le tableau des hypothèses en montre l’effet sur le point mort.</li>
</ul>
'''

css = f'''
@font-face{{font-family:'Gotham';src:url('file://{FONTS}/Gotham_Light.otf');font-weight:400}}
@font-face{{font-family:'Gotham';src:url('file://{FONTS}/Gotham_Medium.otf');font-weight:500}}
@font-face{{font-family:'Vank';src:url('file://{FONTS}/GC_Vank.ttf')}}
@page{{size:A4;margin:0}}
*{{box-sizing:border-box}}
body{{margin:0;font-family:Gotham,Helvetica,Arial,sans-serif;color:#221E1A;font-size:8.2pt;line-height:1.4;background:#fff}}
.page .bloc{{margin-bottom:4.5mm}}
.page{{width:210mm;height:297mm;padding:9mm 11mm 8mm;position:relative;overflow:hidden;background:#fff}}
.hd{{display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #8D1D2C;padding-bottom:1.6mm;margin-bottom:3.2mm;font-size:7pt;letter-spacing:.08em;text-transform:uppercase;color:#7a736a}}
.hd .marque{{color:#8D1D2C;font-weight:500;display:flex;align-items:center;gap:2.5mm}}.hd .logo{{height:4.4mm;width:auto}}
h1{{font-family:Vank,Georgia,serif;font-weight:400;font-size:22pt;margin:0;line-height:1.05}}
.sous{{font-size:8.6pt;color:#7a736a;margin:1mm 0 3.2mm}}
.tiles{{display:grid;grid-template-columns:repeat(4,1fr);gap:2.4mm;margin-bottom:3.6mm}}
.tile{{border:1px solid #e6e0d8;border-radius:7px;background:#fbf9f5;padding:1.8mm 3mm}}
.tile .k{{font-size:6.4pt;letter-spacing:.08em;text-transform:uppercase;color:#7a736a}}
.tile .v{{font-family:Vank,Georgia,serif;font-size:16pt;margin-top:.8mm;white-space:nowrap;color:#8D1D2C}}.tile .v small{{font-family:Gotham,sans-serif;font-size:7pt;color:#7a736a}}
.tile .s{{font-size:6.8pt;color:#7a736a;margin:.6mm 0 0;line-height:1.35}}
.sec{{font-family:Vank,Georgia,serif;font-size:11.5pt;margin:0 0 1.6mm;padding-bottom:.8mm;border-bottom:1.1pt solid #8D1D2C}}
.bloc{{margin-bottom:3.2mm}}
.deux{{display:grid;grid-template-columns:1fr 1fr;gap:5mm}}.deux.g12{{grid-template-columns:.78fr 1.22fr}}
.s{{font-size:6.9pt;color:#7a736a;line-height:1.45;margin:1mm 0 0}}
table.t{{width:100%;border-collapse:collapse}}
.t th{{font-size:6pt;letter-spacing:.05em;text-transform:uppercase;color:#7a736a;font-weight:500;text-align:right;padding:1mm 1.4mm;border-bottom:.9pt solid #221E1A;vertical-align:bottom;line-height:1.25}}
.t th small{{display:block;text-transform:none;letter-spacing:0;font-weight:400;font-size:6pt}}
.t td{{font-size:7.6pt;text-align:right;padding:.95mm 1.4mm;border-bottom:.5pt solid #EAE3D8;white-space:nowrap;font-variant-numeric:tabular-nums}}
.t td small{{display:block;font-size:6pt;color:#9a938a;line-height:1.25;white-space:normal;font-weight:400}}
.t .l{{text-align:left}}.t td.r{{white-space:normal;font-size:6.6pt;color:#7a736a;line-height:1.3}}
.t td.o{{color:#8D1D2C}}.t .mu{{color:#b8b1a6;text-align:center}}
.t tr.tot td{{border-top:.9pt solid #221E1A;border-bottom:none;font-weight:500;background:#FCFAF7}}
.t tr.sous td{{border-top:.7pt solid #221E1A;color:#5a534b}}
.t tr.part td{{color:#7a736a}}
.t tr.base td{{background:#FCFAF7}}.t td.pm{{color:#8D1D2C;font-weight:500}}
.t.serre td{{padding:.9mm 1.3mm}}.t.serre td.l{{white-space:normal;line-height:1.3;width:34%}}
.t.mois td{{padding:.75mm 1.2mm;font-size:7.3pt}}.t.mois td.l{{white-space:nowrap}}.t.mois th{{padding:1mm 1.2mm}}
.t.mois td.l small{{display:inline;margin-left:1mm}}
.t td.bar{{width:24mm}}.t td.bar em{{font-style:normal;display:inline-block;width:8mm;text-align:right}}
.barre{{display:inline-block;position:relative;width:13mm;height:2.2mm;border-radius:2mm;background:#EFE9DF;vertical-align:middle}}.barre i{{position:absolute;left:0;top:0;height:2.2mm;border-radius:2mm;background:#8D1D2C}}
.calc{{border:1px solid #8D1D2C;border-radius:7px;padding:2mm 3mm}}
.calc .ligne{{display:flex;justify-content:space-between;padding:.8mm 0;border-bottom:.5pt solid #EAE3D8;font-variant-numeric:tabular-nums}}
.calc .ligne.fort{{font-weight:500}}.calc .ligne.pm{{border-bottom:none;color:#8D1D2C;font-weight:500;font-size:9.4pt;padding-top:1.4mm}}
sup{{font-size:5.5pt;line-height:0}}
.notes{{margin:0;padding-left:3.5mm;font-size:7.6pt;color:#5a534b;line-height:1.5}}.notes li{{margin-bottom:1.4mm}}
.cadre{{border:1px solid #8D1D2C;border-radius:7px;padding:2mm 3.5mm 1.4mm;margin-bottom:3.6mm}}.cadre .k{{color:#8D1D2C;font-weight:500;font-size:6.6pt;letter-spacing:.08em;text-transform:uppercase;margin-bottom:.8mm}}
.cadre ul{{margin:0;padding-left:3.5mm;font-size:7.7pt;line-height:1.45}}.cadre li{{margin-bottom:.9mm}}
.graph{{width:100%;height:auto;display:block;margin-top:1mm}}.graph .ax{{font-size:9px;fill:#7a736a}}.graph .axl{{font-size:9.5px;fill:#221E1A}}.graph .axv{{font-size:9px;fill:#7a736a}}.graph .lg{{font-size:9px;fill:#5a534b}}
.renvoi{{margin-top:2mm;border-top:.5pt solid #e6e0d8;padding-top:1.4mm}}
.page{{page-break-after:always}}.page:last-child{{page-break-after:auto}}
.ft{{position:absolute;left:11mm;right:11mm;bottom:5mm;display:flex;justify-content:space-between;font-size:6.4pt;color:#9a938a;border-top:.5pt solid #e6e0d8;padding-top:1.2mm}}
'''

# Ce qu'il faut retenir (page 1)
attM = sorted(M, key=lambda x: x['ca'] / x['budget'])
retenir = f"""
<div class="cadre"><div class="k">À retenir</div><ul>
<li>{'' if FOOD_RETENU is None else 'Avec un food cost de ' + nfP(FOOD_RETENU) + ', l'}{'L' if FOOD_RETENU is None else ''}e magasin couvre ses charges encodées : {fE(arr(caMois, 100))} de CA moyen par mois pour un point mort d’environ {fE(arr(pm, 100))}. Le chiffre peut baisser de {fP(securite, 0)} avant d’y passer ; le résultat opérationnel encodé est de {fE(arr(resMois, 100))} par mois ({fP(resMois / caMois, 0)} du CA).</li>
<li>Le budget n’est atteint aucun mois : {fP(ca / bud, 0)} en cumul, de {fP(attM[0]['ca'] / attM[0]['budget'], 0)} ({NOMS[attM[0]['m']].lower()}, travaux du viaduc) à {fP(attM[-1]['ca'] / attM[-1]['budget'], 0)} ({NOMS[attM[-1]['m']].lower()}). Il manque {fE(arr(obj - caMois, 100))} par mois : {nf(cliObjJour - tkJour)} clients de plus par jour au panier actuel, ou {fE(panObj - panier, 2)} de plus par ticket.</li>
<li>Octobre démarre mieux : {fP(oAtt, 0)} de l’objectif au {jusqua.day}, {nf(oTkJ)} clients et {fE(arr(oCaJ))} par jour, un panier de {fE(oPan, 2)}.</li>
<li>{'À vérifier avec le franchisé : la rémunération du gérant (hors de la main-d’œuvre encodée), le contenu des frais généraux (en hausse en octobre) et le food cost — ' + fP(foodR) + ' au panel, ' + fP(foodT) + ' ticket par ticket.' if FOOD_RETENU is None else 'Le food cost est pris à ' + nfP(FOOD_RETENU) + ', au-dessus des deux mesures (' + fP(foodMesR) + ' au panel, ' + fP(foodT) + ' ticket par ticket). Restent à vérifier avec le franchisé la rémunération du gérant (hors de la main-d’œuvre encodée) et les frais généraux, en hausse en octobre.'} Dans le cas le moins favorable du tableau, le point mort monte à {fE(arr(pmPire, 100))}.</li>
</ul></div>
"""

# Le graphique (page 2) : CA par jour de chaque mois, l'objectif du mois par jour, le point mort par jour — une seule échelle, en €/jour
JOURS_MOIS = {3: 31, 4: 30, 5: 31, 6: 30, 7: 31, 8: 31, 9: 30}
COURT = {3: 'Mars', 4: 'Avr.', 5: 'Mai', 6: 'Juin', 7: 'Juil.', 8: 'Août', 9: 'Sept.'}
serie = [dict(lib=COURT[x['m']], v=x['ca'] / x['jours'], obj=x['budget'] / JOURS_MOIS[x['m']], part=False) for x in M]
serie.append(dict(lib=f'Oct. 1→{jusqua.day}', v=oCaJ, obj=O['objectifADate'] / O['jours'], part=True))
W, Hh, gx, gy, gb = 680, 210, 46, 14, 34
vmax = 2400; pas = 500
pw, ph = W - gx - 120, Hh - gy - gb
bw = pw / len(serie)
Y = lambda v: gy + ph * (1 - v / vmax)
svg = [f'<svg class="graph" viewBox="0 0 {W} {Hh}" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="CA par jour de chaque mois face à l’objectif et au point mort">',
       '<defs><pattern id="hach" width="5" height="5" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="5" height="5" fill="#E9D3D6"/><line x1="0" y1="0" x2="0" y2="5" stroke="#8D1D2C" stroke-width="2.4"/></pattern></defs>']
for v in range(0, vmax + 1, pas):
    svg.append(f'<line x1="{gx}" x2="{gx + pw}" y1="{Y(v):.1f}" y2="{Y(v):.1f}" stroke="#EAE3D8" stroke-width="{1 if v else 1.2}"/>'
               f'<text class="ax" x="{gx - 6}" y="{Y(v) + 3:.1f}" text-anchor="end">{nf(v)}&#8239;€</text>')
for i, d in enumerate(serie):
    x0 = gx + i * bw + bw * 0.22; w = bw * 0.56; y = Y(d['v']); h = Y(0) - y; r = 3
    fill = 'url(#hach)' if d['part'] else '#8D1D2C'
    svg.append(f'<path d="M{x0:.1f},{Y(0):.1f} V{y + r:.1f} Q{x0:.1f},{y:.1f} {x0 + r:.1f},{y:.1f} H{x0 + w - r:.1f} Q{x0 + w:.1f},{y:.1f} {x0 + w:.1f},{y + r:.1f} V{Y(0):.1f} Z" fill="{fill}"/>')
    yo = Y(d['obj'])
    svg.append(f'<line x1="{x0 - 5:.1f}" x2="{x0 + w + 5:.1f}" y1="{yo:.1f}" y2="{yo:.1f}" stroke="#221E1A" stroke-width="2" stroke-linecap="round"/>')
    cx = x0 + w / 2
    svg.append(f'<text class="axl" x="{cx:.1f}" y="{Y(0) + 13:.1f}" text-anchor="middle">{d["lib"]}</text>'
               f'<text class="axv" x="{cx:.1f}" y="{Y(0) + 25:.1f}" text-anchor="middle">{nf(d["v"])}&#8239;€</text>')
ypm = Y(pmJour)
svg.append(f'<line x1="{gx}" x2="{gx + pw}" y1="{ypm:.1f}" y2="{ypm:.1f}" stroke="#7a736a" stroke-width="1.5" stroke-dasharray="5 4"/>')
lx = gx + pw + 10
yo_last = Y(serie[-2]['obj'])
svg.append(f'<line x1="{lx}" x2="{lx + 14}" y1="{yo_last - 18:.1f}" y2="{yo_last - 18:.1f}" stroke="#221E1A" stroke-width="2" stroke-linecap="round"/>'
           f'<text class="lg" x="{lx + 19}" y="{yo_last - 15:.1f}">objectif (budget</text><text class="lg" x="{lx + 19}" y="{yo_last - 4:.1f}">du mois par jour)</text>')
svg.append(f'<line x1="{lx}" x2="{lx + 14}" y1="{ypm:.1f}" y2="{ypm:.1f}" stroke="#7a736a" stroke-width="1.5" stroke-dasharray="5 4"/>'
           f'<text class="lg" x="{lx + 19}" y="{ypm + 3:.1f}">point mort</text><text class="lg" x="{lx + 19}" y="{ypm + 14:.1f}">{nf(arr(pmJour))}&#8239;€ par jour</text>')
svg.append(f'<rect x="{lx}" y="{Y(500) - 5:.1f}" width="14" height="9" rx="2" fill="url(#hach)"/><text class="lg" x="{lx + 19}" y="{Y(500) + 3:.1f}">octobre, en cours</text>')
svg.append('</svg>')
graphique = ''.join(svg)

hd = f'<div class="hd"><span class="marque"><img class="logo" src="{LOGO}">Atelier by – Halle</span><span>Tableau récap · chiffres lus le {luTxt}</span></div>'
ft = lambda n: f'<div class="ft"><span>Atelier by – Halle · tableau récap · mars → septembre 2026 · panel et cockpit du réseau, {luTxt}</span><span>{n} / 2</span></div>'
page = f"""<div class="page">
{hd}
<h1>Halle — tableau récap</h1>
<div class="sous">Les sept mois pleins depuis l’ouverture du 20 février : mars → septembre 2026, et octobre en cours. Montants hors TVA.{'' if FOOD_RETENU is None else ' Food cost retenu : ' + nfP(FOOD_RETENU) + ' du CA.'}</div>
<div class="tiles">{tuiles}</div>
{retenir}
<div class="deux bloc">
  <div><div class="sec">Ventes et clients : actuel → objectif</div>{ventes}</div>
  <div><div class="sec">Les coûts du magasin</div>{couts}</div>
</div>
<div class="bloc"><div class="sec">Le point mort : le CA qui couvre toutes les charges</div>{pointmort}</div>
<p class="s renvoi">Main-d’œuvre et frais généraux tels que le franchisé les encode au panel : le résultat et le point mort s’entendent avant la rémunération du gérant. Sources, réserves et détail mois par mois en page 2.</p>
{ft(1)}
</div>
<div class="page">
{hd}
<div class="bloc"><div class="sec">Mois par mois</div>{moisTab}</div>
<div class="bloc"><div class="sec">Le CA par jour, face à l’objectif et au point mort</div>
<p class="s">Chaque barre : le CA moyen par jour de vente du mois. Le trait noir : le budget du mois ramené au jour. Tous les mois pleins passent au-dessus du point mort ; aucun n’atteint l’objectif.</p>
{graphique}</div>
<div><div class="sec">À savoir</div>{notes}</div>
{ft(2)}
</div>"""
doc = f'<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Atelier by – Halle · tableau récap</title><style>{css}</style></head><body>{page}</body></html>'

with tempfile.TemporaryDirectory() as tmp:
    f = os.path.join(tmp, 'recap.html'); open(f, 'w', encoding='utf-8').write(doc)
    if os.environ.get('RECAP_HTML'): open(os.environ['RECAP_HTML'], 'w', encoding='utf-8').write(doc)
    subprocess.run([os.environ.get('CHROMIUM', '/opt/pw-browsers/chromium'), '--headless', '--no-sandbox', '--disable-gpu', '--no-pdf-header-footer',
                    '--print-to-pdf=' + os.path.join(ICI, 'Halle-tableau-recap.pdf'), 'file://' + f], check=True, capture_output=True)
print('PDF · CA moyen', round(caMois), '· clients/jour', round(tkJour), '· panier', round(panier, 2), '· point mort', round(pm), '· octobre', round(100 * oAtt), '%')


# --- 4. L'Excel, avec ses formules -----------------------------------------------------------------------------------------
wb = Workbook(); wb.remove(wb.active)
BLEU, NOIR, VERT, BORD = '0000FF', '000000', '008000', '8D1D2C'
F = lambda **k: Font(name='Arial', size=k.pop('size', 9), **k)
F_T = F(size=13, bold=True, color=BORD); F_S = F(size=8, italic=True, color='7A736A'); F_H = F(bold=True); F_SEC = F(size=10, bold=True, color=BORD)
F_IN = F(color=BLEU); F_LK = F(color=VERT); F_FX = F(color=NOIR); F_TOT = F(bold=True)
JAUNE = PatternFill('solid', fgColor='FFFF00'); GRIS = PatternFill('solid', fgColor='F3EFE8'); CREME = PatternFill('solid', fgColor='FBF9F5')
fin = Side(style='thin', color='BFB8AD'); B_H = Border(bottom=Side(style='medium', color='221E1A')); B_T = Border(top=Side(style='thin', color='221E1A'))
EUR = '#,##0 "€";(#,##0 "€");"-"'; EUR2 = '#,##0.00 "€";(#,##0.00 "€");"-"'; PCT = '0.0%;(0.0%);"-"'; PCT0 = '0%;(0%);"-"'; NB = '#,##0;(#,##0);"-"'; NB1 = '#,##0.0;(#,##0.0);"-"'
WRAP = Alignment(wrap_text=True, vertical='top')


def cell(ws, ref, v, font=F_FX, fmt=None, fill=None, al=None, border=None, note=None):
    c = ws[ref]; c.value = v; c.font = font
    if fmt: c.number_format = fmt
    if fill: c.fill = fill
    if al: c.alignment = al
    if border: c.border = border
    if note: c.comment = Comment(note, 'Cockpit')
    return c


# Feuille « Mois par mois » : les données lues (bleu) et les calculs (noir)
mm = wb.create_sheet('Mois par mois')
cell(mm, 'A1', 'Atelier by – Halle · les mois pleins, mars → septembre 2026', F_T)
cell(mm, 'A2', f'Lu le {luTxt} sur le panel (P&L quotidien agrégé par mois : CA, main-d’œuvre, frais généraux) et le cockpit (tickets, food cost mesuré par le panel, budget, heures planifiées). '
     'En bleu : les chiffres lus ; en noir : les calculs. Hors TVA. Juin et juillet : travaux du viaduc de Halle (effet estimé − 20 %).', F_S)
hd = ['Mois', 'Jours de vente', 'CA (€)', 'Budget CA (€)', 'Atteinte', 'Clients (tickets)', 'Panier moyen (€)', 'CA par jour (€)', 'Clients par jour', 'Food cost (% CA)' if FOOD_RETENU is None else 'Food cost retenu (% CA)',
      'Matière (€)', 'Main-d’œuvre encodée (€)', 'Main-d’œuvre (% CA)', 'Heures planifiées', 'Coût horaire encodé (€/h)', 'Frais généraux encodés (€)', 'Frais généraux (% CA)',
      'Résultat opérationnel encodé (€)', 'Résultat (% CA)', 'Food cost mesuré par le panel (% CA)']
FOOD_CELL = "'Récap'!$B$31"   # l'hypothèse de food cost, une seule case (feuille Récap)
for i, h in enumerate(hd):
    cell(mm, f'{chr(65 + i)}4', h, F_H, al=Alignment(wrap_text=True, vertical='bottom', horizontal='left' if i == 0 else 'right'), border=B_H)
r0 = 5
for i, x in enumerate(M):
    r = r0 + i
    cell(mm, f'A{r}', NOMS[x['m']] + (' (travaux viaduc)' if x['m'] in (6, 7) else ''))
    cell(mm, f'B{r}', x['jours'], F_IN, NB); cell(mm, f'C{r}', x['ca'], F_IN, EUR); cell(mm, f'D{r}', x['budget'], F_IN, EUR)
    cell(mm, f'E{r}', f'=IF(D{r}>0,C{r}/D{r},0)', fmt=PCT0); cell(mm, f'F{r}', x['tickets'], F_IN, NB)
    cell(mm, f'G{r}', f'=IF(F{r}>0,C{r}/F{r},0)', fmt=EUR2); cell(mm, f'H{r}', f'=IF(B{r}>0,C{r}/B{r},0)', fmt=EUR); cell(mm, f'I{r}', f'=IF(B{r}>0,F{r}/B{r},0)', fmt=NB)
    if FOOD_RETENU is None: cell(mm, f'J{r}', x['food'], F_IN, PCT)
    else: cell(mm, f'J{r}', '=' + FOOD_CELL, F_LK, PCT)
    cell(mm, f'K{r}', f'=C{r}*J{r}', fmt=EUR); cell(mm, f'T{r}', x['foodMes'], F_IN, PCT)
    cell(mm, f'L{r}', x['labour'], F_IN, EUR); cell(mm, f'M{r}', f'=IF(C{r}>0,L{r}/C{r},0)', fmt=PCT)
    cell(mm, f'N{r}', x['heures'], F_IN, NB1); cell(mm, f'O{r}', f'=IF(N{r}>0,L{r}/N{r},0)', fmt=EUR2)
    cell(mm, f'P{r}', x['overhead'], F_IN, EUR); cell(mm, f'Q{r}', f'=IF(C{r}>0,P{r}/C{r},0)', fmt=PCT)
    cell(mm, f'R{r}', f'=C{r}-K{r}-L{r}-P{r}', fmt=EUR); cell(mm, f'S{r}', f'=IF(C{r}>0,R{r}/C{r},0)', fmt=PCT)
rN = r0 + n - 1; rT = rN + 1; rM = rN + 2; rR = rN + 3; rRa = r0 + MOIS_PLEINS.index(7)


def ligneTot(r, lib, plage, moy):
    cell(mm, f'A{r}', lib, F_TOT, fill=GRIS, border=B_T)
    agg = (lambda c: f'=AVERAGE({c}{plage[0]}:{c}{plage[1]})') if moy else (lambda c: f'=SUM({c}{plage[0]}:{c}{plage[1]})')
    for c, fmt in (('B', NB1 if moy else NB), ('C', EUR), ('D', EUR), ('F', NB), ('K', EUR), ('L', EUR), ('N', NB1), ('P', EUR), ('R', EUR)):
        cell(mm, f'{c}{r}', agg(c), F_TOT, fmt, GRIS, border=B_T)
    for c, f_, fmt in (('E', f'=IF(D{r}>0,C{r}/D{r},0)', PCT0), ('G', f'=IF(F{r}>0,C{r}/F{r},0)', EUR2), ('H', f'=IF(B{r}>0,C{r}/B{r},0)', EUR), ('I', f'=IF(B{r}>0,F{r}/B{r},0)', NB),
                       ('J', f'=IF(C{r}>0,K{r}/C{r},0)', PCT), ('M', f'=IF(C{r}>0,L{r}/C{r},0)', PCT), ('O', f'=IF(N{r}>0,L{r}/N{r},0)', EUR2), ('Q', f'=IF(C{r}>0,P{r}/C{r},0)', PCT), ('S', f'=IF(C{r}>0,R{r}/C{r},0)', PCT)):
        cell(mm, f'{c}{r}', f_, F_TOT, fmt, GRIS, border=B_T)
    pl = f'{plage[0]}:C{plage[1]}'
    cell(mm, f'T{r}', f'=IF(SUM(C{pl})>0,SUMPRODUCT(C{pl},T{plage[0]}:T{plage[1]})/SUM(C{pl}),0)', F_TOT, PCT, GRIS, border=B_T)


ligneTot(rT, 'Total mars → septembre', (r0, rN), False)
ligneTot(rM, 'Moyenne par mois, mars → septembre', (r0, rN), True)
ligneTot(rR, 'Moyenne par mois, juillet → septembre (structure actuelle)', (rRa, rN), True)
rO = rR + 2
cell(mm, f'A{rO}', f'Octobre, 1 → {jusqua.day} (en cours)', F(italic=True))
cell(mm, f'B{rO}', O['jours'], F_IN, NB); cell(mm, f'C{rO}', O['ca'], F_IN, EUR)
cell(mm, f'D{rO}', O['objectifADate'], F_IN, EUR, note='Objectif à date : le budget d’octobre (61 000 €) réparti sur les jours par la pondération réseau des jours de semaine.')
cell(mm, f'E{rO}', f'=IF(D{rO}>0,C{rO}/D{rO},0)', fmt=PCT0); cell(mm, f'F{rO}', O['tickets'], F_IN, NB)
cell(mm, f'G{rO}', f'=IF(F{rO}>0,C{rO}/F{rO},0)', fmt=EUR2); cell(mm, f'H{rO}', f'=IF(B{rO}>0,C{rO}/B{rO},0)', fmt=EUR); cell(mm, f'I{rO}', f'=IF(B{rO}>0,F{rO}/B{rO},0)', fmt=NB)
cell(mm, f'P{rO}', O['overheadJour'], F_IN, EUR2, note=f'Frais généraux alloués par le panel en octobre, PAR JOUR (× 31 jours ≈ {fE(arr(ohOct))} sur le mois, contre {fE(ohR)} de juillet à septembre).')
cell(mm, f'Q{rO}', '← € par jour', F_S)
mm.column_dimensions['A'].width = 44
for i in range(1, len(hd)): mm.column_dimensions[chr(65 + i)].width = 13
mm.row_dimensions[4].height = 40; mm.freeze_panes = 'B5'

# Feuille « Récap » : les chiffres clés, l'objectif et le point mort — liens verts vers « Mois par mois », hypothèses en jaune
rc = wb.create_sheet('Récap', 0)
MP = "'Mois par mois'!"
cell(rc, 'A1', 'Atelier by – Halle · tableau récap', F_T)
cell(rc, 'A2', f'Mars → septembre 2026 (7 mois pleins), lu le {luTxt}. Hors TVA.{"" if FOOD_RETENU is None else " Food cost retenu : " + nfP(FOOD_RETENU) + " (case B31, appliquée à tous les mois)."} En vert : liens vers « Mois par mois » ; en bleu : chiffres lus ; fond jaune : hypothèses à modifier ; '
     'en noir : calculs.', F_S)
for col, w in zip('ABCDEF', (54, 16, 16, 16, 16, 62)): rc.column_dimensions[col].width = w


def sec(r, t, cols=()):
    cell(rc, f'A{r}', t, F_SEC, border=B_H)
    for i, h in enumerate(cols):
        cell(rc, f'{chr(66 + i)}{r}', h, F_H, al=Alignment(horizontal='left' if i == 4 else 'right', wrap_text=True, vertical='bottom'), border=B_H)
    rc.row_dimensions[r].height = 28


# Ventes et clients : lignes 5 à 10 ; l'objectif et ses repères : lignes 12 à 17
r = 4; sec(r, 'Ventes et clients', ('Actuel (moy. mars → sept.)', f'Octobre 1 → {jusqua.day}', 'Objectif', 'Écart actuel → objectif', 'Note'))
rCA, rCAj, rCl, rClj, rPan, rAtt = 5, 6, 7, 8, 9, 10
rObj, rJM = 12, 13
lig = [(rCA, 'CA par mois', f'={MP}C{rM}', None, f'=B{rObj}', EUR, ''),
       (rCAj, 'CA par jour', f'={MP}H{rM}', f'={MP}H{rO}', f'=B{rObj}/B{rJM}', EUR, ''),
       (rCl, 'Clients par mois', f'={MP}F{rM}', None, f'=IF(B{rPan}>0,B{rObj}/B{rPan},0)', NB, 'objectif : au panier actuel'),
       (rClj, 'Clients par jour', f'={MP}I{rM}', f'={MP}I{rO}', f'=D{rCl}/B{rJM}', NB, 'objectif : au panier actuel'),
       (rPan, 'Panier moyen', f'={MP}G{rM}', f'={MP}G{rO}', f'=IF(B{rCl}>0,B{rObj}/B{rCl},0)', EUR2, 'objectif : avec le nombre de clients actuel'),
       (rAtt, 'Atteinte du budget', f'={MP}E{rT}', f'={MP}E{rO}', None, PCT0, 'octobre : face à l’objectif à date (budget réparti par jour de semaine)')]
for rr, lib, a, b, c, fmt, nt in lig:
    cell(rc, f'A{rr}', lib); cell(rc, f'B{rr}', a, F_LK, fmt)
    if b: cell(rc, f'C{rr}', b, F_LK, fmt)
    if c:
        cell(rc, f'D{rr}', c, F_FX, fmt); cell(rc, f'E{rr}', f'=D{rr}-B{rr}', F_FX, fmt)
    cell(rc, f'F{rr}', nt, F_S)
cell(rc, f'A{rObj}', 'Objectif de CA mensuel (budget validé, août → décembre)'); cell(rc, f'B{rObj}', obj, F_IN, EUR, JAUNE, note='Budget 2026 de Halle au cockpit : 62 000 € par mois de mars à juillet, 61 000 € d’août à décembre.')
cell(rc, f'F{rObj}', 'hypothèse : remplacer pour tester un autre objectif', F_S)
cell(rc, f'A{rJM}', 'Jours par mois (ouvert 7 jours sur 7)'); cell(rc, f'B{rJM}', '=365/12', fmt=NB1)
cell(rc, f'A{rJM + 1}', 'Panier moyen des 3 autres magasins (repère)'); cell(rc, f'B{rJM + 1}', D['panierAutres']['moyenne'], F_IN, EUR2,
     note='Moyenne des paniers mensuels de Corbais, Gosselies et Sombreffe, mars → septembre 2026 (stores/perf du cockpit).')
cell(rc, f'A{rJM + 2}', 'CA sur 12 mois au rythme actuel'); cell(rc, f'B{rJM + 2}', f'=B{rCA}*12', fmt=EUR)
cell(rc, f'A{rJM + 3}', 'CA sur 12 mois à l’objectif'); cell(rc, f'B{rJM + 3}', f'=B{rObj}*12', fmt=EUR)
cell(rc, f'A{rJM + 4}', 'Potentiel année 1 de l’étude de marché (CA annuel)'); cell(rc, f'B{rJM + 4}', D['etude']['caTheoriqueAn'], F_IN, EUR,
     note=f'Étude de marché : potentiel à maturité {fE(D["etude"]["potentielMaturite"])} par an, montée en régime 70 % en année 1, 80 % en année 2, 90 % en année 3.')

# Les coûts : lignes 21 à 25
r = 20; sec(r, 'Les coûts du magasin (par mois)', ('Moy. mars → sept.', '% du CA', 'Moy. juil. → sept.', '% du CA', 'Repère'))
for i, (lib, col, nt) in enumerate([('Matière (food cost)', 'K', 'seuil réseau 32 % ; modèle du franchiseur 36 % + 2 % d’emballage' if FOOD_RETENU is None else f'food cost retenu {nfP(FOOD_RETENU)} (case B31) ; mesuré {fP(foodMes)} au panel ; seuil réseau 32 %'),
                                    ('Main-d’œuvre encodée', 'L', 'seuil réseau 33 % ; le franchisé est à 0 €/h au panel'),
                                    ('Frais généraux encodés', 'P', 'montant mensuel saisi au panel, sans détail (voir « Modèle de charges »)')]):
    rr = r + 1 + i
    cell(rc, f'A{rr}', lib); cell(rc, f'B{rr}', f'={MP}{col}{rM}', F_LK, EUR); cell(rc, f'C{rr}', f'=IF({MP}C{rM}>0,B{rr}/{MP}C{rM},0)', fmt=PCT)
    cell(rc, f'D{rr}', f'={MP}{col}{rR}', F_LK, EUR); cell(rc, f'E{rr}', f'=IF({MP}C{rR}>0,D{rr}/{MP}C{rR},0)', fmt=PCT); cell(rc, f'F{rr}', nt, F_S)
rt = r + 4
cell(rc, f'A{rt}', 'Total des coûts', F_TOT, border=B_T)
for c_, f_, fmt in (('B', f'=SUM(B{r + 1}:B{r + 3})', EUR), ('C', f'=IF({MP}C{rM}>0,B{rt}/{MP}C{rM},0)', PCT), ('D', f'=SUM(D{r + 1}:D{r + 3})', EUR), ('E', f'=IF({MP}C{rR}>0,D{rt}/{MP}C{rR},0)', PCT)):
    cell(rc, f'{c_}{rt}', f_, F_TOT, fmt, border=B_T)
cell(rc, f'A{rt + 1}', 'Résultat opérationnel encodé', F_TOT)
for c_, f_, fmt in (('B', f'={MP}C{rM}-B{rt}', EUR), ('C', f'=IF({MP}C{rM}>0,B{rt + 1}/{MP}C{rM},0)', PCT), ('D', f'={MP}C{rR}-D{rt}', EUR), ('E', f'=IF({MP}C{rR}>0,D{rt + 1}/{MP}C{rR},0)', PCT)):
    cell(rc, f'{c_}{rt + 1}', f_, F_TOT, fmt)
cell(rc, f'F{rt + 1}', 'avant rémunération du gérant, charges financières et impôts', F_S)

# Le point mort : lignes 28 à 41
r = rt + 3; sec(r, 'Le point mort (structure de coûts juillet → septembre)', ('Valeur', '', '', '', 'Comment'))
p0 = r + 1
assert FOOD_CELL == f"'Récap'!$B${p0 + 3}", 'la case du food cost a bougé'
pm_lignes = [
    ('Main-d’œuvre par mois', f'={MP}L{rR}', F_LK, EUR, JAUNE, 'hypothèse : remplacer pour tester'),
    ('Frais généraux par mois', f'={MP}P{rR}', F_LK, EUR, JAUNE, f'octobre : {fE(O["overheadJour"], 2)} par jour au panel, soit ≈ {fE(arr(ohOct))} par mois'),
    ('Autres charges fixes à ajouter (rémunération du gérant…)', 0, F_IN, EUR, JAUNE, 'absentes du panel : à renseigner avec le franchisé'),
    ('Food cost', f'={MP}J{rR}', F_LK, PCT, JAUNE, f'mesure du panel ; recomposé ticket par ticket par le cockpit : {fP(foodT)} (août-septembre)') if FOOD_RETENU is None else
    ('Food cost (hypothèse, s’applique à tous les mois)', FOOD_RETENU, F_IN, PCT, JAUNE, f'hypothèse demandée ; mesuré {fP(foodMesR)} par le panel (juillet → septembre), {fP(foodT)} ticket par ticket (août-septembre)'),
    ('Charges fixes par mois', f'=SUM(B{p0}:B{p0 + 2})', F_TOT, EUR, None, ''),
    ('Taux de marge brute (1 − food cost)', f'=1-B{p0 + 3}', F_FX, PCT, None, ''),
    ('Point mort, CA par mois', f'=IF(B{p0 + 5}>0,B{p0 + 4}/B{p0 + 5},0)', F(bold=True, color=BORD), EUR, CREME, 'charges fixes ÷ taux de marge brute'),
    ('Point mort, CA par jour', f'=B{p0 + 6}/B{rJM}', F_FX, EUR, None, ''),
    ('Point mort, clients par jour au panier actuel', f'=IF(B{rPan}>0,B{p0 + 7}/B{rPan},0)', F_FX, NB, None, ''),
    ('Marge de sécurité (baisse de CA possible avant le point mort)', f'=IF(B{rCA}>0,1-B{p0 + 6}/B{rCA},0)', F_FX, PCT0, None, 'face au CA moyen mars → septembre'),
    ('Résultat encodé au CA actuel (par mois)', f'=B{rCA}*B{p0 + 5}-B{p0 + 4}', F_FX, EUR, None, 'avec ces hypothèses'),
    ('Résultat encodé à l’objectif (par mois)', f'=B{rObj}*B{p0 + 5}-B{p0 + 4}', F_FX, EUR, None, ''),
    ('Point mort en plus pour 1 000 € de charges fixes', f'=IF(B{p0 + 5}>0,1000/B{p0 + 5},0)', F_FX, EUR, None, ''),
    ('Résultat en plus pour 1 000 € de CA', f'=1000*B{p0 + 5}', F_FX, EUR, None, '')]
for i, (lib, v, fo, fmt, fi, nt) in enumerate(pm_lignes):
    rr = p0 + i
    cell(rc, f'A{rr}', lib, F_TOT if fo is F_TOT else (F(bold=True, color=BORD) if 'Point mort, CA par mois' in lib else F_FX),
         border=B_T if lib.startswith('Charges fixes') else None)
    cell(rc, f'B{rr}', v, fo, fmt, fi, border=B_T if lib.startswith('Charges fixes') else None); cell(rc, f'F{rr}', nt, F_S)

# Les hypothèses du point mort
r = p0 + len(pm_lignes) + 1; sec(r, 'Le point mort selon les hypothèses', ('Charges fixes', 'Food cost', 'Point mort / mois', 'Point mort / jour', 'Marge de sécurité'))
cell(rc, f'A{r + 1}', 'Coûts encodés au panel (juillet → septembre)' if FOOD_RETENU is None else 'Food cost retenu, charges juillet → septembre'); cell(rc, f'B{r + 1}', f'={MP}L{rR}+{MP}P{rR}', fmt=EUR); cell(rc, f'C{r + 1}', f'={MP}J{rR}', F_LK, PCT)
cell(rc, f'A{r + 2}', 'Frais généraux encodés en octobre (par jour × 31)' if FOOD_RETENU is None else 'Le même, avec les frais généraux d’octobre (par jour × 31)'); cell(rc, f'B{r + 2}', f'={MP}L{rR}+{MP}P{rO}*31', fmt=EUR); cell(rc, f'C{r + 2}', f'={MP}J{rR}', F_LK, PCT)
cell(rc, f'A{r + 3}', 'Food cost recomposé ticket par ticket (août-septembre)'); cell(rc, f'B{r + 3}', f'=B{r + 1}', fmt=EUR); cell(rc, f'C{r + 3}', round(foodT, 4), F_IN, PCT,
     note=f'P&L du cockpit, tickets relus au coût de recette du jour : matière {fE(FT["aout"]["matiere"])} en août et {fE(FT["sept"]["matiere"])} en septembre, pour {fE(FT["aout"]["ca"] + FT["sept"]["ca"])} de CA.')
if FOOD_RETENU is None:
    cell(rc, f'A{r + 4}', 'Les deux à la fois'); cell(rc, f'B{r + 4}', f'=B{r + 2}', fmt=EUR); cell(rc, f'C{r + 4}', f'=C{r + 3}', fmt=PCT)
else:
    cell(rc, f'A{r + 4}', 'Food cost mesuré par le panel (juillet → septembre)'); cell(rc, f'B{r + 4}', f'=B{r + 1}', fmt=EUR); cell(rc, f'C{r + 4}', f'={MP}T{rR}', F_LK, PCT)
for rr in range(r + 1, r + 5):
    cell(rc, f'D{rr}', f'=IF(C{rr}<1,B{rr}/(1-C{rr}),0)', F(bold=True, color=BORD), EUR)
    cell(rc, f'E{rr}', f'=D{rr}/$B${rJM}', fmt=EUR)
    cell(rc, f'F{rr}', f'=IF($B${rCA}>0,1-D{rr}/$B${rCA},0)', fmt=PCT0, al=Alignment(horizontal='left'))

r = r + 6
cell(rc, f'A{r}', 'À savoir', F_SEC, border=B_H)
for i, t in enumerate([
        'Main-d’œuvre encodée = heures planifiées × taux horaire du panel (8 à 16 €/h selon les mois) : le franchisé y compte pour 0 €/h et les charges sociales n’y sont vraisemblablement pas toutes. Résultat et point mort sont donc avant la rémunération du gérant : l’ajouter en « Autres charges fixes ».',
        'Les frais généraux encodés sont un montant mensuel saisi au panel, sans détail ; la feuille « Modèle de charges » donne le contenu attendu par le franchiseur (loyer, énergie, redevances, amortissements…).',
        'Février (ouverture le 20) et octobre, partiels, restent hors des moyennes. Juin et juillet : travaux sur le viaduc de Halle, effet estimé − 20 %.']):
    cell(rc, f'A{r + 1 + i}', t, F(size=8, color='5A534B'), al=WRAP); rc.merge_cells(f'A{r + 1 + i}:F{r + 1 + i}'); rc.row_dimensions[r + 1 + i].height = 24
rc.freeze_panes = 'A4'

# Feuille « Modèle de charges » : les postes du budget franchisé, en € au rythme actuel
mc = wb.create_sheet('Modèle de charges')
cell(mc, 'A1', 'Le modèle de charges du budget franchisé (Halle, exercice 2026)', F_T)
cell(mc, 'A2', 'Les hypothèses du franchiseur, en part du CA — pas des montants constatés : le loyer réel vient du bail, l’énergie des factures. Le € par mois se calcule sur le CA moyen mars → septembre.', F_S)
cell(mc, 'A3', 'CA moyen par mois (mars → septembre)'); cell(mc, 'C3', f'={MP}C{rM}', F_LK, EUR)
for i, h in enumerate(['Catégorie', 'Poste', 'Budget (% du CA)', 'Théorique (% du CA)', '€ par mois au rythme actuel (budget)', 'Gestion']):
    cell(mc, f'{chr(65 + i)}5', h, F_H, al=Alignment(wrap_text=True, vertical='bottom'), border=B_H)
for i, c in enumerate(D['charges']):
    rr = 6 + i
    cell(mc, f'A{rr}', c['categorie'].replace('Matière,première', 'Matière première')); cell(mc, f'B{rr}', c['poste'])
    cell(mc, f'C{rr}', c['pctBudget'], F_IN, PCT); cell(mc, f'D{rr}', c['pctTheorique'], F_IN, PCT); cell(mc, f'E{rr}', f'=C{rr}*$C$3', fmt=EUR); cell(mc, f'F{rr}', c['gestion'])
rl = 6 + len(D['charges'])
cell(mc, f'B{rl}', 'Total des postes budgétés', F_TOT, border=B_T); cell(mc, f'C{rl}', f'=SUM(C6:C{rl - 1})', F_TOT, PCT, border=B_T); cell(mc, f'E{rl}', f'=SUM(E6:E{rl - 1})', F_TOT, EUR, border=B_T)
cell(mc, f'A{rl + 2}', 'Frais généraux encodés au panel, par mois (juillet → septembre)'); cell(mc, f'E{rl + 2}', f'={MP}P{rR}', F_LK, EUR)
cell(mc, f'A{rl + 3}', 'La matière (36 %) et l’emballage (2 %) ne sont qu’en théorique dans ce modèle : le food cost mesuré en tient lieu.', F_S)
for col, w in zip('ABCDEF', (30, 36, 14, 14, 18, 34)): mc.column_dimensions[col].width = w
mc.row_dimensions[5].height = 30

for ws in wb.worksheets:
    ws.sheet_view.showGridLines = False
wb.save(os.path.join(ICI, 'Halle-tableau-recap.xlsx'))
print('Excel écrit')
