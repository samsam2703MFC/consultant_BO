# Le tableau récap de Halle : une page A4 (ventes et clients face à l'objectif, coûts du magasin, point mort, mois par mois)
# et le classeur Excel du même récap, avec ses formules (le point mort se recalcule quand on change une hypothèse).
#
#   python3 generer.py --relire   relit les chiffres sur le cockpit en ligne et les fige dans donnees.json
#   python3 generer.py            refait le PDF et l'Excel depuis donnees.json
#   --food 45 | --food mesure     food cost retenu pour la matière, le résultat et le point mort (défaut : 45 %, demande du
#                                 13/10/2026 ; « mesure » = le food cost mesuré par le panel, mois par mois)
#   --mo 2650 | --mo encodee      main-d'œuvre retenue, en € PAR SEMAINE (défaut : 2 650 €, demande du 13/10/2026), comptée
#                                 au prorata des jours du mois ; « encodee » = la main-d'œuvre encodée au panel
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
b_ = sys.argv[sys.argv.index('--mo') + 1] if '--mo' in sys.argv else '2650'
MO_SEM = None if b_ == 'encodee' else float(b_.replace(',', '.'))
JOURS_CAL = {2: 28, 3: 31, 4: 30, 5: 31, 6: 30, 7: 31, 8: 31, 9: 30, 10: 31}
M = D['mois']
for x in M:
    x['foodMes'] = x['food']
    if FOOD_RETENU is not None: x['food'] = FOOD_RETENU
    x['labMes'] = x['labour']
    if MO_SEM is not None: x['labour'] = MO_SEM * JOURS_CAL[x['m']] / 7
    x['mat'] = x['ca'] * x['food']; x['res'] = x['ca'] - x['mat'] - x['labour'] - x['overhead']
n = len(M)
S = lambda k, L=M: sum(x[k] for x in L)
ca, tk, jr, bud = S('ca'), S('tickets'), S('jours'), S('budget')
caMois, tkMois, caJour, tkJour, panier = ca / n, tk / n, ca / jr, tk / jr, ca / tk
food = S('mat') / ca
labMois, ohMois, resMois, matMois = S('labour') / n, S('overhead') / n, S('res') / n, S('mat') / n
heuresMois, etpMois = S('heures') / n, S('etp') / n
tauxH = [x['labMes'] / x['heures'] for x in M]; labMesMois = S('labMes') / n
# La structure récente, juillet → septembre : celle qui sert au point mort
R = [x for x in M if x['m'] >= 7]
caR = S('ca', R) / len(R); foodR = S('mat', R) / S('ca', R); foodMesR = sum(x['ca'] * x['foodMes'] for x in R) / S('ca', R); foodMes = sum(x['ca'] * x['foodMes'] for x in M) / S('ca'); labR = S('labour', R) / len(R) if MO_SEM is None else MO_SEM * 365 / 12 / 7; ohR = S('overhead', R) / len(R)
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
# Le compte d'exploitation comptable (compta.json, analyse transmise le 13/10/2026), rapproché de la caisse
C = json.load(open(os.path.join(ICI, 'compta.json'))) if os.path.exists(os.path.join(ICI, 'compta.json')) else None
if C:
    # L'analyse divisait par 6 (ouverture supposée en avril) ; le magasin a ouvert en mars : les mêmes totaux se divisent par 7.
    # Les lignes déjà ramenées à leur coût mensuel par l'analyse (loyer, honoraires) ne changent pas.
    N6 = C['periodeMois']; NR = C.get('periodeReelleMois', N6); kR = N6 / NR; MENS = set(C.get('dejaMensuels', []))
    recM = lambda x: x['montant'] if x['poste'] in MENS else x['montant'] * kR
    cC6 = C['ca']; cV6 = sum(x['montant'] for x in C['variables']); cF6 = sum(x['montant'] for x in C['fixes']); cE6 = cC6 - cV6 - cF6
    cC = cC6 * kR; VARR = [recM(x) for x in C['variables']]; FIXR = [recM(x) for x in C['fixes']]
    cV = sum(VARR); cF = sum(FIXR); cAch = VARR[0]; cPerso = FIXR[0]
    cMB = cC - cV; cE = cMB - cF; cN = C['nathanSemaine'] * 52 / 12; cVar = cV / cC
    cPM = (cF + cN) / (1 - cVar); cPMb = (cF + cN + C['remboursementsMois']) / (1 - cVar)
    cPersoSem = (cPerso + cN) * 12 / 52
    MPER = M[-NR:] if NR <= len(M) else M                 # les mois de la période comptable (mars → septembre)
    caPer = sum(x['ca'] for x in MPER); caPerM = caPer / len(MPER)
    caAS = sum(x['ca'] for x in M if x['m'] >= 4); caDepuis = D['fevrier']['ca'] + ca
    PER_TXT = f"{NOMS[MPER[0]['m']].lower()} → {NOMS[MPER[-1]['m']].lower()}"
    fournM = C['achatsFournitures'] / NR
NPAGES = 2 + (2 if C else 0) + (1 if os.path.exists(os.path.join(ICI, 'plan.json')) else 0)


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
<tr><td class="l">{'Main-d’œuvre encodée<small>seuil réseau 33&nbsp;% · ' + nf(heuresMois) + ' heures par mois, ' + nf(etpMois, 1) + ' ETP</small>' if MO_SEM is None else 'Main-d’œuvre<small>' + fE(MO_SEM) + ' par semaine · encodée au panel : ' + fE(labMesMois) + ' par mois · seuil réseau 33&nbsp;%</small>'}</td><td>{fE(labMois)}</td><td>{fP(labMois / caMois)}</td></tr>
<tr><td class="l">Frais généraux encodés<small>{fE(ohR)} par mois depuis juillet</small></td><td>{fE(ohMois)}</td><td>{fP(ohMois / caMois)}</td></tr>
<tr class="sous"><td class="l">Total des coûts</td><td>{fE(matMois + labMois + ohMois)}</td><td>{fP((matMois + labMois + ohMois) / caMois)}</td></tr>
<tr class="tot"><td class="l">Résultat opérationnel{' encodé' if MO_SEM is None else ''}<small>{'avant rémunération du gérant, charges financières et impôts' if MO_SEM is None else 'avant charges financières et impôts'}</small></td><td>{fE(resMois)}</td><td>{fP(resMois / caMois)}</td></tr>
</table>
'''

lignesS = ''
for i, (lib, l, o, f) in enumerate(SCEN):
    p = (l + o) / (1 - f)
    lignesS += f'<tr{" class=base" if i == 0 else ""}><td class="l">{lib.replace("{oh}", fE(arr(o)))}</td><td>{fE(l + o)}</td><td>{fP(f)}</td><td class="pm">{fE(arr(p, 100))}</td><td>{fE(arr(p / JM))}</td><td>{fP(1 - p / caMois, 0)}</td></tr>'
pointmort = f'''
<div class="deux g12">
<div class="calc">
  <div class="ligne"><span>{'Main-d’œuvre encodée' if MO_SEM is None else 'Main-d’œuvre (' + fE(MO_SEM) + ' par semaine)'}</span><span>{fE(labR)}</span></div>
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
<p class="s">Chaque 1&nbsp;000&nbsp;€ de charges fixes en plus{' (la rémunération du gérant, par exemple, absente de la main-d’œuvre encodée)' if MO_SEM is None else ''} ajoute {fE(arr(1000 / tmb))} au point mort. À l’inverse, chaque 1&nbsp;000&nbsp;€ de CA au-dessus du point mort rapporte {fE(arr(1000 * tmb))} de résultat : au budget de {fE(obj)}, le résultat{' encodé' if MO_SEM is None else ''} serait d’environ {fE(arr(resObj, 100))} par mois, contre {fE(arr(resMois, 100))} aujourd’hui.</p>
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
<tr><th class="l">Mois</th><th>CA</th><th>Budget</th><th>Atteinte</th><th>Clients</th><th>Clients / jour</th><th>Panier</th><th>Food cost{'' if FOOD_RETENU is None else ' mesuré'}</th><th>Main-d’œuvre{'' if MO_SEM is None else '<small>' + fE(MO_SEM) + '/sem.</small>'}</th><th>Frais généraux</th><th>Résultat{' encodé' if MO_SEM is None else ''}{'' if FOOD_RETENU is None else '<small>food cost ' + nfP(FOOD_RETENU) + '</small>'}</th><th>%</th></tr>
{lignesM}
</table>
'''

notes = f'''
<ul class="notes">
<li>Sources : {'ventes, clients, main-d’œuvre et frais généraux' if MO_SEM is None else 'ventes, clients et frais généraux'} viennent du P&amp;L du panel (ce que le franchisé y encode) ; le food cost mesuré est la marge du panel sur les recettes vendues ; le budget est celui du cockpit. Hors TVA, lu le {luTxt}. Ouverture en mars ; les ventes de février ({fE(D["fevrier"]["ca"])} du 20 au 28) et octobre, partiel, restent hors des moyennes.</li>
<li><sup>*</sup> Juin et juillet : travaux sur le viaduc de Halle, effet estimé à − 20&nbsp;% sur le chiffre. <sup>**</sup> Octobre : jusqu’au {jusqua.day} inclus, face à l’objectif à date (le budget du mois réparti par jour de semaine).</li>
<li>{'' if MO_SEM is None else 'Main-d’œuvre retenue : ' + fE(MO_SEM) + ' par semaine (montant communiqué le ' + luTxt + '), comptée au prorata des jours de chaque mois, soit ' + fE(labR) + ' pour un mois moyen. Elle remplace la main-d’œuvre encodée au panel, '}{'Main-d’œuvre encodée' if MO_SEM is None else 'qui'}{' = ' if MO_SEM is None else ' vaut '}{'' if MO_SEM is None else fE(labMesMois) + ' par mois en moyenne : '}heures planifiées × taux horaire du panel, {nf(min(tauxH))} à {nf(max(tauxH))}&nbsp;€ de l’heure selon les mois, le franchisé y comptant pour 0&nbsp;€/h. {'Résultat et point mort sont donc avant la rémunération du gérant.' if MO_SEM is None else ('Si la rémunération du gérant n’est pas dans les ' + fE(MO_SEM) + ', chaque 1&nbsp;000&nbsp;€ par mois à ajouter repousse le point mort de ' + fE(arr(1000 / tmb)) + '.' if not C else 'La fiduciaire compte ' + fE(cPerso) + ' de personnel par mois sans Nathan, sur sept mois : avec ses ' + fE(C['nathanSemaine']) + ' par semaine, ≈ ' + fE(arr(cPersoSem, 10)) + ' par semaine, sous les ' + fE(MO_SEM) + ' retenus (page 3).')}</li>
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
.deux.g11{{grid-template-columns:1fr 1fr;gap:6mm}}.deux.bas,.bas{{margin-top:3mm}}.pts.deuxcol{{columns:2;column-gap:6mm}}.pts.deuxcol li{{break-inside:avoid}}.sec.petit{{font-size:10pt}}
.t.compta td{{padding:.55mm 1.6mm;font-size:7.2pt}}.t.compta td.l{{white-space:nowrap;width:40%}}.t.compta td.p{{color:#9a938a;font-size:6.6pt}}.t.compta td.fx{{color:#9a938a}}.t.compta td.cb{{color:#8D1D2C}}.t.compta tr.tot td.cb,.t.compta tr.sous td.cb{{font-weight:500}}.t.compta th.g{{text-align:center;border-bottom:.5pt solid #b8b1a6;color:#8D1D2C}}.t.compta th.g small{{color:#7a736a}}.t.compta tr.plus td{{border-top:.9pt solid #221E1A;font-weight:500}}.t.compta tr.plus td small{{display:block;font-weight:400;color:#7a736a;font-size:6.2pt}}.t td.a6,.t th.a6{{color:#9a938a}}.t.perte td.l{{width:62%}}
.t.plan td{{padding:.8mm 1.2mm;font-size:7pt;vertical-align:top}}.t.plan td.l{{white-space:normal;line-height:1.3}}.t.plan td.l small{{display:block;font-size:6.3pt;color:#7a736a;margin-top:.3mm}}
.t.plan td.n{{color:#8D1D2C;font-weight:500;text-align:left;width:4mm}}.t.plan td.r{{font-size:6.5pt;width:30%}}.t.plan tr.tot td{{vertical-align:middle}}
.deux.g21{{grid-template-columns:1.55fr 1fr;gap:6mm}}.pts.plan{{font-size:7pt;line-height:1.42;padding-left:0;list-style:none}}.pts.plan li{{margin-bottom:1.8mm}}
.pts.plan .ti{{color:#8D1D2C;font-weight:500}}.pts.plan .ef{{display:block;color:#221E1A;font-weight:500;margin-top:.4mm}}
.t.effets td.l2{{text-align:left;white-space:normal;font-size:7pt}}
.t.comp td{{padding:1.1mm 1.4mm;font-size:7.6pt}}.t.comp td.p{{color:#7a736a;font-size:7pt}}.t.comp td.r{{font-size:6.5pt;width:34%;white-space:normal}}
.graph .val{{font-size:10px;font-weight:500;fill:#221E1A}}
.t.sens th{{text-align:right}}.t.sens th.g{{text-align:center;border-bottom:.5pt solid #b8b1a6;color:#8D1D2C}}.t.sens td{{padding:1.2mm 1.3mm;font-size:7.6pt}}.t.sens td.g{{color:#5a534b}}.t.sens td.res{{font-weight:500}}.t.sens tr.base td{{background:#FCFAF7;color:#5a534b}}.neg{{color:#C0182B}}.cadre .ind{{font-size:7.4pt;line-height:1.45}}
.pts{{margin:0;padding-left:4mm;font-size:7.3pt;line-height:1.45;color:#221E1A}}.pts li{{margin-bottom:1.1mm}}
.ft{{position:absolute;left:11mm;right:11mm;bottom:5mm;display:flex;justify-content:space-between;font-size:6.4pt;color:#9a938a;border-top:.5pt solid #e6e0d8;padding-top:1.2mm}}
'''

# Ce qu'il faut retenir (page 1)
attM = sorted(M, key=lambda x: x['ca'] / x['budget'])
retenir = f"""
<div class="cadre"><div class="k">À retenir</div><ul>
<li>{'' if FOOD_RETENU is None else 'Avec un food cost de ' + nfP(FOOD_RETENU) + ('' if MO_SEM is None else ' et ' + fE(MO_SEM) + ' de main-d’œuvre par semaine') + ', l'}{'L' if FOOD_RETENU is None else ''}e magasin couvre ses charges{' encodées' if MO_SEM is None else ''} : {fE(arr(caMois, 100))} de CA moyen par mois pour un point mort d’environ {fE(arr(pm, 100))}. Le chiffre peut baisser de {fP(securite, 0)} avant d’y passer ; le résultat opérationnel{' encodé' if MO_SEM is None else ''} est de {fE(arr(resMois, 100))} par mois ({fP(resMois / caMois, 0)} du CA).</li>
<li>Le budget n’est atteint aucun mois : {fP(ca / bud, 0)} en cumul, de {fP(attM[0]['ca'] / attM[0]['budget'], 0)} ({NOMS[attM[0]['m']].lower()}, travaux du viaduc) à {fP(attM[-1]['ca'] / attM[-1]['budget'], 0)} ({NOMS[attM[-1]['m']].lower()}). Il manque {fE(arr(obj - caMois, 100))} par mois : {nf(cliObjJour - tkJour)} clients de plus par jour au panier actuel, ou {fE(panObj - panier, 2)} de plus par ticket.</li>
<li>Octobre démarre mieux : {fP(oAtt, 0)} de l’objectif au {jusqua.day}, {nf(oTkJ)} clients et {fE(arr(oCaJ))} par jour, un panier de {fE(oPan, 2)}.</li>
<li>{'À vérifier avec le franchisé : la rémunération du gérant (hors de la main-d’œuvre encodée), le contenu des frais généraux (en hausse en octobre) et le food cost — ' + fP(foodR) + ' au panel, ' + fP(foodT) + ' ticket par ticket.' if FOOD_RETENU is None else 'Le food cost est pris à ' + nfP(FOOD_RETENU) + ', au-dessus des deux mesures (' + fP(foodMesR) + ' au panel, ' + fP(foodT) + ' ticket par ticket). ' + ('Restent à vérifier avec le franchisé la rémunération du gérant (hors de la main-d’œuvre encodée) et les frais généraux, en hausse en octobre.' if MO_SEM is None else 'Restent à vérifier avec le franchisé les frais généraux, en hausse en octobre, et si les ' + fE(MO_SEM) + ' de main-d’œuvre par semaine comprennent la rémunération du gérant.' if not C else 'Restent à vérifier avec le franchisé les frais généraux, en hausse en octobre ; les ' + fE(MO_SEM) + ' de main-d’œuvre par semaine couvrent le personnel des comptes et les 500&nbsp;€ de Nathan (≈ ' + fE(arr(cPersoSem, 10)) + ' par semaine, page 3).')} Dans le cas le moins favorable du tableau, le point mort monte à {fE(arr(pmPire, 100))}.</li>
{'' if not C else '<li>Les comptes de la fiduciaire (page 3, à valider) sont plus durs : matière et emballages à ' + fP(cVar) + ' du CA, ' + fE(arr(cF - cPerso, 100)) + ' de frais fixes par mois hors personnel, et un point mort d’environ ' + fE(arr(cPM, 100)) + ' par mois, Nathan payé.</li>'}
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
ft = lambda n: f'<div class="ft"><span>Atelier by – Halle · tableau récap · mars → septembre 2026 · panel et cockpit du réseau, {luTxt}</span><span>{n} / {NPAGES}</span></div>'
renvoi = '' if C else f"""<p class="s renvoi">{'Main-d’œuvre et frais généraux tels que le franchisé les encode au panel : le résultat et le point mort s’entendent avant la rémunération du gérant.' if MO_SEM is None else 'Main-d’œuvre : ' + fE(MO_SEM) + ' par semaine ; frais généraux : ce que le franchisé encode au panel ; food cost : ' + (nfP(FOOD_RETENU) if FOOD_RETENU is not None else 'mesuré au panel') + '.'} Sources, réserves et détail mois par mois en page 2.</p>"""
page = f"""<div class="page">
{hd}
<h1>Halle — tableau récap</h1>
<div class="sous">Les sept mois depuis l’ouverture en mars 2026 (mars → septembre), et octobre en cours. Montants hors TVA.{'' if FOOD_RETENU is None else ' Food cost retenu : ' + nfP(FOOD_RETENU) + ' du CA.'}{'' if MO_SEM is None else ' Main-d’œuvre : ' + fE(MO_SEM) + ' par semaine.'}</div>
<div class="tiles">{tuiles}</div>
{retenir}
<div class="deux bloc">
  <div><div class="sec">Ventes et clients : actuel → objectif</div>{ventes}</div>
  <div><div class="sec">Les coûts du magasin</div>{couts}</div>
</div>
<div class="bloc"><div class="sec">Le point mort : le CA qui couvre toutes les charges</div>{pointmort}</div>
{renvoi}
{ft(1)}
</div>
<div class="page">
{hd}
<div class="bloc"><div class="sec">Mois par mois</div>{moisTab}</div>
<div class="bloc"><div class="sec">Le CA par jour, face à l’objectif et au point mort</div>
<p class="s">Chaque barre : le CA moyen par jour de vente du mois. Le trait noir : le budget du mois ramené au jour. {'Tous les mois pleins passent au-dessus du point mort' if min(d['v'] for d in serie[:-1]) > pmJour else 'Certains mois passent sous le point mort'}{' ; aucun n’atteint l’objectif.' if all(d['v'] < d['obj'] for d in serie[:-1]) else '.'}</p>
{graphique}</div>
<div><div class="sec">À savoir</div>{notes}</div>
{ft(2)}
</div>"""
# --- La page 3 : le compte d'exploitation comptable (ramené au mois sur les mois réels d'exploitation), et le CA qu'il faut
#     pour finir le mois à 0, 5 000 ou 10 000 € après Nathan et la banque — achats et emballages suivent le CA, le reste ne bouge pas
p3 = ''
CIBLES = [0, 5000, 10000]
if C:
    remb = C['remboursementsMois']
    caCible = lambda t: (cF + cN + remb + t) / (1 - cVar)
    COLS = [dict(ca=cC, ach=cAch, emb=VARR[1], base=True)] + [dict(ca=caCible(t), ach=caCible(t) * cAch / cC, emb=caCible(t) * VARR[1] / cC, base=False) for t in CIBLES]
    for c_ in COLS:
        c_['mb'] = c_['ca'] - c_['ach'] - c_['emb']; c_['eb'] = c_['mb'] - cF; c_['ebN'] = c_['eb'] - cN; c_['ap'] = c_['ebN'] - remb
    def l3(lib, key=None, vals=None, cls='', pct=True, signe=False, fixe=False):
        vs = vals if vals is not None else [c_[key] for c_ in COLS]
        f = (lambda v: ('− ' + fE(abs(v))) if signe else fE(v))
        cel = [f'<td>{f(vs[0])}</td><td class="p">{fP(vs[0] / cC) if pct else ""}</td>']
        cel += [f'<td class="{"fx" if fixe else "cb"}">{f(v)}</td>' for v in vs[1:]]
        return f'<tr{" class=" + cls if cls else ""}><td class="l">{lib}</td>{"".join(cel)}</tr>'
    lig = [l3('Chiffre d’affaires', 'ca', cls='tot'), l3(H(C['variables'][0]['poste']), 'ach'), l3(H(C['variables'][1]['poste']), 'emb'), l3('Marge brute', 'mb', cls='sous')]
    lig += [l3(H(x['poste']), vals=[FIXR[i]] * 4, fixe=True) for i, x in enumerate(C['fixes'])]
    lig += [l3('EBITDA d’exploitation', 'eb', cls='tot'),
            l3(f'Rémunération de Nathan ({fE(C["nathanSemaine"])} par semaine)', vals=[cN] * 4, pct=False, signe=True, fixe=True),
            l3('EBITDA, Nathan payé', 'ebN', cls='sous'),
            l3('Remboursements crédits et leasing', vals=[remb] * 4, pct=False, signe=True, fixe=True),
            l3('Après Nathan et la banque', 'ap', cls='tot')]
    plus = [c_['ca'] - cC for c_ in COLS[1:]]
    lig += [f'<tr class="plus"><td class="l">CA en plus par mois · par jour</td><td></td><td></td>'
            + ''.join(f'<td class="cb">+&nbsp;{fE(v)}<small>+&nbsp;{fE(v / JM)} par jour</small></td>' for v in plus) + '</tr>']
    tabCompta = ('<table class="t serre compta"><tr><th class="l" rowspan="2">Poste (€ par mois)</th><th rowspan="2">Analyse</th><th rowspan="2">% CA</th>'
                 '<th class="g" colspan="3">Le CA qu’il faut pour finir le mois à…<small>après Nathan et la banque ; achats et emballages suivent le CA, le reste ne bouge pas</small></th></tr>'
                 '<tr>' + ''.join(f'<th>{fE(t)}</th>' for t in CIBLES) + f'</tr>{"".join(lig)}</table>')
    # La perte, sur sept mois d'exploitation
    pR = []
    for x in C['perte']:
        o = x['origine']; v = x['montant']
        if o.startswith('Charges courues'):
            v = -C['chargesCouruesMois'] * C['chargesCouruesMoisSansCA']; o = f'Charges courues sans CA, d’octobre à février (≈ {nf(C["chargesCouruesMois"] / 1000, 1)} k€ par mois)'
        elif o.startswith('Exploitation'):
            v = cE * NR; o = f'Exploitation réelle de {PER_TXT}'
        pR.append((o, v))
    perteL = ''.join(f'<tr><td class="l">{H(o)}</td><td>{nf(v / 1000, 1)}&nbsp;k€</td></tr>' for o, v in pR)
    perteL += (f'<tr class="sous"><td class="l">Somme des lignes</td><td>{nf(sum(v for _, v in pR) / 1000, 1)}&nbsp;k€</td></tr>'
               f'<tr class="tot"><td class="l">Perte de la situation au 30/09</td><td>{nf(C["perteSituation"] / 1000, 1)}&nbsp;k€</td></tr>')
    margeStd = (cVar - 0.41) * cC, (cVar - 0.40) * cC
    p3 = f"""<div class="page">
{hd}
<div class="sec">Le compte d’exploitation comptable, {PER_TXT} 2026</div>
<p class="s">Situation au 30/09/2026 de la fiduciaire (Pardoen), ramenée au mois sur les {NR} mois d’exploitation (ouverture en {C.get('ouverture', 'mars 2026')}) ; loyer, charges locatives, assurance et honoraires à leur coût mensuel. Le CA comptable de la période ({fE(cC * NR)}) colle à la caisse de {PER_TXT} ({fE(caPer)} HT, {fP(cC * NR / caPer - 1, 0)}). À valider avec la fiduciaire.</p>
{tabCompta}
<div class="deux g11 bas">
<div><div class="sec petit">Ce que ça dit</div><ol class="pts">
<li>La matière, c’est là que l’argent part : {fP(cAch / cC)} d’achats et {fP(VARR[1] / cC)} d’emballages, contre 38&nbsp;% + 2 à 3&nbsp;% dans la structure standard : {nf(margeStd[0] / 1000, 1)} à {nf(margeStd[1] / 1000, 1)}&nbsp;k€ de marge par mois, plus que l’EBITDA. Le cockpit chiffre {fP(foodT)} au coût de recette des tickets : 4 à 6 points d’écart entre ce qui est acheté et ce qui est vendu. Avant d’accuser la casse, vérifier les sorties vers Couq / Saint-Géry ou GBXATL sans refacturation, et les {nf(C['achatsFournitures'] / 1000, 1)}&nbsp;k€ d’« achats fournitures ».</li>
<li>Le personnel à {fP(cPerso / cC, 0)} est flatteur : Nathan n’est pas payé dans ces comptes. Avec ses {fE(C['nathanSemaine'])} par semaine, il passe à {fP((cPerso + cN) / cC)} (≈ {fE(arr(cPersoSem, 10))} par semaine en tout, sous les {fE(MO_SEM) if MO_SEM else '2 650 €'} retenus aux pages 1 et 2).</li>
<li>Avant la banque, la boutique ne perd pas d’argent ; après, si : les crédits (≈&nbsp;{nf(C['credits'] / 1000)}&nbsp;k€ sur {C['creditsAns']} ans) et le leasing (≈&nbsp;{nf(C['leasing'] / 1000)}&nbsp;k€ sur {C['leasingAns']} ans) font ≈ {nf(remb / 1000, 1)}&nbsp;k€ par mois, et le mois finit à {fE(arr(COLS[0]['ap'], 100))}.</li>
<li>Pour finir le mois à 0 après Nathan et la banque, il faut {fE(arr(COLS[1]['ca'], 100))} de CA par mois, + {fE(plus[0] / JM)} par jour ; à 5 000 €, {fE(arr(COLS[2]['ca'], 100))} (+ {fE(plus[1] / JM)} par jour) ; à 10 000 €, {fE(arr(COLS[3]['ca'], 100))} (+ {fE(plus[2] / JM)} par jour).</li>
</ol></div>
<div><div class="sec petit">D’où vient la perte de {nf(-C['perteSituation'] / 1000, 1)}&nbsp;k€</div>
<table class="t serre perte">{perteL}</table>
<p class="s">Le trou vient du démarrage trop long et de la dette, pas de la boutique qui tourne. Les lignes bouclent à {nf(abs(sum(v for _, v in pR) - C['perteSituation']) / 1000, 1)}&nbsp;k€ près.</p></div>
</div>
{ft(3)}
</div>"""

# --- La page 4 : ce que rapportent quelques euros de plus par jour (A), et ramener les achats à 40 % du CA (B)
p4 = ''
PL = json.load(open(os.path.join(ICI, 'plan.json'))) if os.path.exists(os.path.join(ICI, 'plan.json')) else None
PALIERS = [50, 100, 150, 200, 250]
if PL:
    Bm = PL['matiere']
    # A — à personnel et frais généraux égaux, un euro de plus ne coûte que sa matière
    neg = lambda v, d=0: f'<span class="neg">{fE(v, d)}</span>' if v < 0 else fE(v, d)
    tmbB = (1 - cVar) if C else None
    def ligneA(e):
        caM = e * JM
        cel = [f'<td class="l">{"Aujourd’hui" if e == 0 else "+&nbsp;" + fE(e)}</td>', f'<td>{"—" if e == 0 else "+&nbsp;" + fE(caM)}</td>', f'<td>{"—" if e == 0 else "+&nbsp;" + fE(e * 365)}</td>',
               f'<td>{"—" if e == 0 else "+&nbsp;" + nf(e / panier)}</td>', f'<td>{"—" if e == 0 else "+&nbsp;" + fE(e / tkJour, 2)}</td>',
               f'<td class="g">{"—" if e == 0 else "+&nbsp;" + fE(caM * tmb)}</td>', f'<td class="res">{neg(resMois + caM * tmb)}</td>']
        if C:
            cel += [f'<td class="g">{"—" if e == 0 else "+&nbsp;" + fE(caM * tmbB)}</td>', f'<td class="res">{neg(cE - cN + caM * tmbB)}</td>',
                    f'<td class="res">{neg(cE - cN - C["remboursementsMois"] + caM * tmbB)}</td>']
        return f'<tr{" class=base" if e == 0 else ""}>{"".join(cel)}</tr>'
    lignesA = ''.join(ligneA(e) for e in [0] + PALIERS)
    entete2 = ('<th class="g" colspan="2">Récap, pages 1-2<small>food cost ' + (nfP(FOOD_RETENU) if FOOD_RETENU else 'mesuré') + (', main-d’œuvre ' + fE(MO_SEM) + '/sem.' if MO_SEM else '') + '</small></th>'
               + ('<th class="g" colspan="3">Bilan, page 3<small>achats + emballages ' + fP(cVar) + ' du CA</small></th>' if C else ''))
    entete3 = ('<th>Marge directe<small>par mois</small></th><th>Résultat<small>par mois</small></th>'
               + ('<th>Marge directe<small>par mois</small></th><th>EBITDA<small>Nathan payé</small></th><th>Après<small>remboursements</small></th>' if C else ''))
    budgetJ = (obj - caMois) / JM
    noteC = ''
    if C:
        manqueN = (cPM - caPerM) / JM; manqueB = (cPMb - caPerM) / JM
        noteC = (f' Sur la caisse ({fE(caPerM)} par mois de {PER_TXT}) et les coûts du bilan, '
                 + (f'Nathan est payé avec {fE(arr(-manqueN, 5))} par jour de marge, ' if manqueN <= 0 else f'il faut + {fE(arr(manqueN, 5))} par jour pour payer Nathan, ')
                 + (f'et il faut + {fE(arr(manqueB, 5))} par jour pour payer aussi la banque.' if manqueB > 0 else f'et la banque est couverte avec {fE(arr(-manqueB, 5))} par jour de marge.'))
    tabA = f"""<table class="t sens">
<tr><th rowspan="2" class="l">Par jour</th><th rowspan="2">CA en plus<small>par mois</small></th><th rowspan="2">CA en plus<small>par an</small></th><th rowspan="2">soit clients<small>par jour, au panier de {fE(panier, 2)}</small></th><th rowspan="2">ou panier<small>en plus, à {nf(tkJour)} clients par jour</small></th>{entete2}</tr>
<tr>{entete3}</tr>
{lignesA}
</table>"""
    # Les effets du plan matière, sur la base de la page 3
    bE = []; dCA = 0.0; dAch = 0.0
    for e in Bm['effets']:
        if not C: break
        if e['type'] == 'prix': v = e['pct'] * cC; dCA += v; bE.append((e['action'], f'+ {fE(arr(v, 10))} de CA'))
        else:
            v = e['pct'] * (cAch if e['type'] == 'achats' else fournM); dAch += v; bE.append((e['action'], f'− {fE(arr(v, 5))} d’achats'))
    if C:
        achApres = cAch - dAch; caApres = cC + dCA
        VB = dict(NR=NR, pctAch=nfP(dAch / cAch), achEur=fE(arr(dAch, 100)), b1=fE(arr(Bm['effets'][0]['pct'] * cC, 10)),
                  b2=fE(arr(Bm['effets'][1]['pct'] * cAch, 5)), b3=fE(arr(Bm['effets'][2]['pct'] * cAch, 5)), b4=fE(arr(Bm['effets'][3]['pct'] * fournM, 5)),
                  tgtg=fE(C.get('tooGoodToGo', 0)), tgtgMois=fE(arr(C.get('tooGoodToGo', 0) / NR, 1)), fourn=nf(C['achatsFournitures'] / 1000, 1) + '\u00a0k€',
                  fournMois=nf(fournM / 1000, 1) + '\u00a0k€', achPctApres=fP(achApres / caApres), gain=fE(arr(dCA + dAch, 100)))
        fmtB = lambda t: t.format(**VB)
        controle = (f'Sur la base de la page 3 : {fE(achApres)} d’achats pour {fE(caApres)} de CA, soit {fP(achApres / caApres)} ; la marge gagne {fE(dCA + dAch)} par mois. '
                    f'Sur la caisse ({fE(caPerM)} par mois), + 5 % de prix font ≈ {fE(arr(caPerM * 0.05, 10))}.')
    else:
        fmtB = lambda t: t; controle = ''
    lB = ''.join(f'<li><span class="ti">B{i + 1}. {H(b["titre"])}</span> {H(fmtB(b["texte"]))}{" " + H(b["cockpit"]) if b.get("cockpit") else ""} <span class="ef">{H(fmtB(b["effet"]))}</span></li>' for i, b in enumerate(Bm['actions']))
    eff = ''.join(f'<tr><td class="l">{H(a_)}</td><td>{H(t_)}</td></tr>' for a_, t_ in bE)
    eff += f'<tr class="tot"><td class="l">Résultat</td><td class="l2">{H(fmtB(Bm["resultat"]))}</td></tr>'
    p4 = f"""<div class="page">
{hd}
<div class="sec">A. Ce que rapportent quelques euros de plus par jour</div>
<p class="s">À personnel et frais généraux égaux, un euro vendu en plus ne coûte que sa matière : le reste tombe dans le résultat. Mois de {nf(JM, 1)} jours, ouvert 7 jours sur 7 ; prix inchangés. « Aujourd’hui » : moyenne de mars à septembre, pour le récap comme pour le bilan (page 3).</p>
{tabA}
<p class="s">Le budget de {fE(obj)} par mois demande + {fE(arr(budgetJ, 5))} par jour sur la base de la caisse ({fE(caMois)} par mois de mars à septembre).{noteC} Une vente faite avec une remise (formule, promotion) rapporte moins que la marge directe du tableau.</p>
<div class="sec" style="margin-top:5mm">B. {H(Bm['titre'])}</div>
<p class="s">{H(fmtB(Bm['intro']))}</p>
<div class="deux g21">
<ol class="pts plan">{lB}</ol>
<div>
<table class="t serre effets"><tr><th class="l">Action</th><th>Effet par mois</th></tr>{eff}</table>
<p class="s">{H(controle)}</p>
<div class="cadre"><div class="k">L’indicateur du lundi</div><div class="ind">{H(Bm['indicateur'])}</div></div>
</div>
</div>
{ft(4)}
</div>"""

# --- La page 5 : les mêmes mois, deux lectures — l'application (caisse et panel) et le bilan (fiduciaire), avril → septembre
p5 = ''
if C:
    AS = MPER; nA = len(AS)
    ap = dict(ca=S('ca', AS) / nA, tk=S('tickets', AS) / nA, mat=sum(x['ca'] * x['foodMes'] for x in AS) / nA,
              lab=S('labMes', AS) / nA, oh=S('overhead', AS) / nA)
    ap['mb'] = ap['ca'] - ap['mat']; ap['res'] = ap['mb'] - ap['lab'] - ap['oh']; ap['pm'] = (ap['lab'] + ap['oh']) / (1 - ap['mat'] / ap['ca'])
    bi = dict(ca=cC, mat=cV, mb=cMB, lab=cPerso, oh=cF - cPerso, res=cE, pm=cF / (1 - cVar))
    pont = [('Résultat de l’application', ap['res'], 'tot'), ('Écart de CA', bi['ca'] - ap['ca'], 'v'), ('Écart de matière', -(bi['mat'] - ap['mat']), 'v'),
            ('Écart de personnel', -(bi['lab'] - ap['lab']), 'v'), ('Écart de frais généraux', -(bi['oh'] - ap['oh']), 'v'), ('EBITDA du bilan', bi['res'], 'tot')]
    def lc(lib, a, b, fmt=fE, pct=True, note='', cls=''):
        ea = '' if a is None else fmt(a); eb = '' if b is None else fmt(b)
        pa = fP(a / ap['ca']) if pct and a is not None else ''; pb = fP(b / bi['ca']) if pct and b is not None else ''
        ec = '' if a is None or b is None else ('+&nbsp;' if b - a >= 0 else '−&nbsp;') + fmt(abs(b - a))
        return f'<tr{" class=" + cls if cls else ""}><td class="l">{lib}</td><td>{ea}</td><td class="p">{pa}</td><td>{eb}</td><td class="p">{pb}</td><td>{ec}</td><td class="l r">{note}</td></tr>'
    rows = ''.join([
        lc('Chiffre d’affaires', ap['ca'], bi['ca'], pct=False, note=f'caisse HT, contre CA comptable ; écart de {fP(bi["ca"] / ap["ca"] - 1, 1)}', cls='tot'),
        lc('Clients par mois · panier', None, None, pct=False, note=f'application seulement : {nf(ap["tk"])} tickets, {fE(ap["ca"] / ap["tk"], 2)} de panier'),
        lc('Matière', ap['mat'], bi['mat'], note=f'application : coût de recette des ventes (panel) ; bilan : achats + emballages. Le cockpit, ticket par ticket : {fP(foodT)} (août-sept.)'),
        lc('Marge brute', ap['mb'], bi['mb'], cls='sous'),
        lc('Personnel', ap['lab'], bi['lab'], note='heures planifiées × taux du panel, contre salaires ; Nathan absent des deux'),
        lc('Frais généraux', ap['oh'], bi['oh'], note=f'montant saisi au panel, contre factures ; panel d’octobre : {fE(arr(ohOct))}'),
        lc('Résultat · EBITDA', ap['res'], bi['res'], cls='tot', note='avant Nathan, amortissements, charges financières'),
        lc('Point mort (CA par mois)', ap['pm'], bi['pm'], pct=False, note='charges fixes ÷ (1 − matière en % du CA), Nathan non payé')])
    # La cascade : du résultat de l'application à l'EBITDA du bilan
    W2, H2, gx2, gy2, gb2 = 680, 175, 10, 18, 40
    _c = 0; _hauts = []
    for _l, _v, _k in pont:
        _c = _v if _k == 'tot' else _c + _v; _hauts.append(_c)
    vmax2 = max(_hauts + [ap['res']]) * 1.12; bw2 = (W2 - 2 * gx2) / len(pont)
    Y2 = lambda v: gy2 + (H2 - gy2 - gb2) * (1 - v / vmax2)
    sv = [f'<svg class="graph" viewBox="0 0 {W2} {H2}" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Du résultat de l’application à l’EBITDA du bilan">',
          f'<line x1="{gx2}" x2="{W2 - gx2}" y1="{Y2(0):.1f}" y2="{Y2(0):.1f}" stroke="#221E1A" stroke-width="1"/>']
    cum = 0.0
    for i, (lib, v, k) in enumerate(pont):
        x0 = gx2 + i * bw2 + bw2 * 0.2; w = bw2 * 0.6
        if k == 'tot': y_a, y_b, col = v, 0, '#221E1A'; cum = v
        else: y_a, y_b = cum, cum + v; col = '#2d7a3e' if v >= 0 else '#8D1D2C'; cum += v
        top, bot = Y2(max(y_a, y_b)), Y2(min(y_a, y_b))
        sv.append(f'<rect x="{x0:.1f}" y="{top:.1f}" width="{w:.1f}" height="{max(bot - top, 1):.1f}" rx="3" fill="{col}"/>')
        if i < len(pont) - 1:
            sv.append(f'<line x1="{x0 + w:.1f}" x2="{x0 + bw2:.1f}" y1="{Y2(cum):.1f}" y2="{Y2(cum):.1f}" stroke="#b8b1a6" stroke-width="1" stroke-dasharray="3 3"/>')
        val = (fE(v) if k == 'tot' else ('+&#8239;' if v >= 0 else '−&#8239;') + fE(abs(v)))
        sv.append(f'<text class="val" x="{x0 + w / 2:.1f}" y="{top - 5:.1f}" text-anchor="middle">{val}</text>')
        sv.append(f'<text class="axl" x="{x0 + w / 2:.1f}" y="{Y2(0) + 14:.1f}" text-anchor="middle">{lib}</text>')
    sv.append('</svg>')
    cascade = ''.join(sv)
    dCAb = bi['ca'] - ap['ca']
    constatCA = (f'<li>Le CA comptable et la caisse se tiennent : {fE(arr(abs(dCAb), 10))} d’écart par mois ({fP(dCAb / ap["ca"], 1)}). Sur sept mois, les deux sources racontent la même activité ; l’écart restant (TVA, annulations, factures) est à rapprocher avec la fiduciaire.</li>'
                 if abs(dCAb) / ap['ca'] < 0.05 else
                 f'<li>Le bilan compte {fE(arr(dCAb, 10))} de CA de {"plus" if dCAb > 0 else "moins"} par mois que la caisse ({fP(dCAb / ap["ca"], 0)}). Tant qu’il n’est pas expliqué, il fausse l’EBITDA d’autant.</li>')
    dL, dO = bi['lab'] - ap['lab'], bi['oh'] - ap['oh']
    constatPF = (f'<li>Personnel : {"le panel et les comptes se tiennent" if abs(dL) < 300 else ("le panel le sous-estime de " + fE(arr(dL, 10)) if dL > 0 else "le panel le surestime de " + fE(arr(-dL, 10)))} par mois. '
                 f'Frais généraux : {"le panel les sous-estime de " + fE(arr(dO, 10)) if dO > 0 else "le panel les surestime de " + fE(arr(-dO, 10))} par mois ; ceux encodés en octobre ({fE(arr(ohOct))}) dépassent le bilan ({fE(bi["oh"])}).</li>')
    p5 = f"""<div class="page">
{hd}
<div class="sec">L’application ou le bilan : les mêmes mois, deux lectures</div>
<p class="s">{PER_TXT.capitalize()} 2026, en € par mois. Application : la caisse et le P&amp;L du panel, ce que le franchisé y encode, et la matière mesurée par le panel sur les recettes vendues. Bilan : la situation au 30/09 de la fiduciaire, ramenée au mois à la page 3 (à valider).</p>
<table class="t comp">
<tr><th class="l">Poste</th><th>Application<small>€ par mois</small></th><th>% CA</th><th>Bilan<small>€ par mois</small></th><th>% CA</th><th>Écart<small>bilan − appli.</small></th><th class="l">Ce qui change d’une source à l’autre</th></tr>
{rows}
</table>
<div class="sec" style="margin-top:5mm">Du résultat de l’application à l’EBITDA du bilan</div>
<p class="s">Chaque barre : ce que le bilan ajoute ou retire au résultat que montre l’application, en € par mois.</p>
{cascade}
{ft(5 if PL else 4)}
</div>"""
doc = f'<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Atelier by – Halle · tableau récap</title><style>{css}</style></head><body>{page}{p3}{p4}{p5}</body></html>'

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
      'Matière (€)', 'Main-d’œuvre encodée (€)' if MO_SEM is None else 'Main-d’œuvre retenue (€)', 'Main-d’œuvre (% CA)', 'Heures planifiées', 'Coût horaire encodé (€/h)' if MO_SEM is None else 'Coût horaire retenu (€/h)', 'Frais généraux encodés (€)', 'Frais généraux (% CA)',
      'Résultat opérationnel encodé (€)', 'Résultat (% CA)', 'Food cost mesuré par le panel (% CA)', 'Main-d’œuvre encodée au panel (€)']
FOOD_CELL = "'Récap'!$B$31"
MO_CELL = "'Récap'!$C$28"     # la main-d'œuvre par semaine, une seule case (feuille Récap)   # l'hypothèse de food cost, une seule case (feuille Récap)
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
    if MO_SEM is None: cell(mm, f'L{r}', x['labour'], F_IN, EUR)
    else: cell(mm, f'L{r}', f'={MO_CELL}*{JOURS_CAL[x["m"]]}/7', F_LK, EUR, note=None if r > r0 else f'Main-d’œuvre par semaine (Récap!C28) × jours du mois ÷ 7.')
    cell(mm, f'U{r}', x['labMes'], F_IN, EUR); cell(mm, f'M{r}', f'=IF(C{r}>0,L{r}/C{r},0)', fmt=PCT)
    cell(mm, f'N{r}', x['heures'], F_IN, NB1); cell(mm, f'O{r}', f'=IF(N{r}>0,L{r}/N{r},0)', fmt=EUR2)
    cell(mm, f'P{r}', x['overhead'], F_IN, EUR); cell(mm, f'Q{r}', f'=IF(C{r}>0,P{r}/C{r},0)', fmt=PCT)
    cell(mm, f'R{r}', f'=C{r}-K{r}-L{r}-P{r}', fmt=EUR); cell(mm, f'S{r}', f'=IF(C{r}>0,R{r}/C{r},0)', fmt=PCT)
rN = r0 + n - 1; rT = rN + 1; rM = rN + 2; rR = rN + 3; rRa = r0 + MOIS_PLEINS.index(7)


def ligneTot(r, lib, plage, moy):
    cell(mm, f'A{r}', lib, F_TOT, fill=GRIS, border=B_T)
    agg = (lambda c: f'=AVERAGE({c}{plage[0]}:{c}{plage[1]})') if moy else (lambda c: f'=SUM({c}{plage[0]}:{c}{plage[1]})')
    for c, fmt in (('B', NB1 if moy else NB), ('C', EUR), ('D', EUR), ('F', NB), ('K', EUR), ('L', EUR), ('N', NB1), ('P', EUR), ('R', EUR), ('U', EUR)):
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
                                    ('Main-d’œuvre encodée', 'L', 'seuil réseau 33 % ; le franchisé est à 0 €/h au panel') if MO_SEM is None else ('Main-d’œuvre', 'L', f'{fE(MO_SEM)} par semaine (case C28) ; encodée au panel : {fE(labMesMois)} par mois ; seuil réseau 33 %'),
                                    ('Frais généraux encodés', 'P', 'montant mensuel saisi au panel, sans détail (voir « Modèle de charges »)')]):
    rr = r + 1 + i
    cell(rc, f'A{rr}', lib); cell(rc, f'B{rr}', f'={MP}{col}{rM}', F_LK, EUR); cell(rc, f'C{rr}', f'=IF({MP}C{rM}>0,B{rr}/{MP}C{rM},0)', fmt=PCT)
    cell(rc, f'D{rr}', f'={MP}{col}{rR}', F_LK, EUR); cell(rc, f'E{rr}', f'=IF({MP}C{rR}>0,D{rr}/{MP}C{rR},0)', fmt=PCT); cell(rc, f'F{rr}', nt, F_S)
rt = r + 4
cell(rc, f'A{rt}', 'Total des coûts', F_TOT, border=B_T)
for c_, f_, fmt in (('B', f'=SUM(B{r + 1}:B{r + 3})', EUR), ('C', f'=IF({MP}C{rM}>0,B{rt}/{MP}C{rM},0)', PCT), ('D', f'=SUM(D{r + 1}:D{r + 3})', EUR), ('E', f'=IF({MP}C{rR}>0,D{rt}/{MP}C{rR},0)', PCT)):
    cell(rc, f'{c_}{rt}', f_, F_TOT, fmt, border=B_T)
cell(rc, f'A{rt + 1}', 'Résultat opérationnel encodé' if MO_SEM is None else 'Résultat opérationnel', F_TOT)
for c_, f_, fmt in (('B', f'={MP}C{rM}-B{rt}', EUR), ('C', f'=IF({MP}C{rM}>0,B{rt + 1}/{MP}C{rM},0)', PCT), ('D', f'={MP}C{rR}-D{rt}', EUR), ('E', f'=IF({MP}C{rR}>0,D{rt + 1}/{MP}C{rR},0)', PCT)):
    cell(rc, f'{c_}{rt + 1}', f_, F_TOT, fmt)
cell(rc, f'F{rt + 1}', 'avant rémunération du gérant, charges financières et impôts' if MO_SEM is None else 'avant charges financières et impôts', F_S)

# Le point mort : lignes 28 à 41
r = rt + 3; sec(r, 'Le point mort (structure de coûts juillet → septembre)', ('Valeur', '', '', '', 'Comment'))
p0 = r + 1
assert FOOD_CELL == f"'Récap'!$B${p0 + 3}", 'la case du food cost a bougé'
assert MO_CELL == f"'Récap'!$C${p0}", 'la case de la main-d’œuvre a bougé'
pm_lignes = [
    ('Main-d’œuvre par mois', f'={MP}L{rR}', F_LK, EUR, JAUNE, 'hypothèse : remplacer pour tester') if MO_SEM is None else
    ('Main-d’œuvre par mois (la semaine en C, × 365/12 ÷ 7)', f'=C{p0}*B{rJM}/7', F_FX, EUR, None, f'hypothèse : {fE(MO_SEM)} par semaine (case C28) ; encodée au panel : {fE(sum(x["labMes"] for x in R) / len(R))} par mois en juillet → septembre'),
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
    ('Résultat encodé au CA actuel (par mois)' if MO_SEM is None else 'Résultat au CA actuel (par mois)', f'=B{rCA}*B{p0 + 5}-B{p0 + 4}', F_FX, EUR, None, 'avec ces hypothèses'),
    ('Résultat encodé à l’objectif (par mois)' if MO_SEM is None else 'Résultat à l’objectif (par mois)', f'=B{rObj}*B{p0 + 5}-B{p0 + 4}', F_FX, EUR, None, ''),
    ('Point mort en plus pour 1 000 € de charges fixes', f'=IF(B{p0 + 5}>0,1000/B{p0 + 5},0)', F_FX, EUR, None, ''),
    ('Résultat en plus pour 1 000 € de CA', f'=1000*B{p0 + 5}', F_FX, EUR, None, '')]
for i, (lib, v, fo, fmt, fi, nt) in enumerate(pm_lignes):
    rr = p0 + i
    cell(rc, f'A{rr}', lib, F_TOT if fo is F_TOT else (F(bold=True, color=BORD) if 'Point mort, CA par mois' in lib else F_FX),
         border=B_T if lib.startswith('Charges fixes') else None)
    cell(rc, f'B{rr}', v, fo, fmt, fi, border=B_T if lib.startswith('Charges fixes') else None); cell(rc, f'F{rr}', nt, F_S)
if MO_SEM is not None:
    cell(rc, f'C{p0 - 1}', '€ par semaine', F_H, al=Alignment(horizontal='right', vertical='bottom'), border=B_H)
    cell(rc, f'C{p0}', MO_SEM, F_IN, EUR, JAUNE, note=f'Main-d’œuvre par semaine, montant communiqué le {luTxt} ; remplace la main-d’œuvre encodée au panel, dans tous les mois (feuille « Mois par mois », colonne L) et dans le point mort.')

# Les hypothèses du point mort
r = p0 + len(pm_lignes) + 1; sec(r, 'Le point mort selon les hypothèses', ('Charges fixes', 'Food cost', 'Point mort / mois', 'Point mort / jour', 'Marge de sécurité'))
cell(rc, f'A{r + 1}', 'Coûts encodés au panel (juillet → septembre)' if FOOD_RETENU is None else 'Food cost retenu, charges juillet → septembre'); cell(rc, f'B{r + 1}', f'=$B${p0}+{MP}P{rR}', fmt=EUR); cell(rc, f'C{r + 1}', f'={MP}J{rR}', F_LK, PCT)
cell(rc, f'A{r + 2}', 'Frais généraux encodés en octobre (par jour × 31)' if FOOD_RETENU is None else 'Le même, avec les frais généraux d’octobre (par jour × 31)'); cell(rc, f'B{r + 2}', f'=$B${p0}+{MP}P{rO}*31', fmt=EUR); cell(rc, f'C{r + 2}', f'={MP}J{rR}', F_LK, PCT)
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
        'Main-d’œuvre encodée = heures planifiées × taux horaire du panel (8 à 16 €/h selon les mois) : le franchisé y compte pour 0 €/h et les charges sociales n’y sont vraisemblablement pas toutes. Résultat et point mort sont donc avant la rémunération du gérant : l’ajouter en « Autres charges fixes ».' if MO_SEM is None else
        f'Main-d’œuvre retenue : {fE(MO_SEM)} par semaine (case C28), au prorata des jours de chaque mois. Elle remplace la main-d’œuvre encodée au panel ({fE(labMesMois)} par mois en moyenne, colonne U de « Mois par mois »). Si la rémunération du gérant n’y est pas, l’ajouter en « Autres charges fixes ».',
        'Les frais généraux encodés sont un montant mensuel saisi au panel, sans détail ; la feuille « Modèle de charges » donne le contenu attendu par le franchiseur (loyer, énergie, redevances, amortissements…).',
        'Ouverture en mars ; les ventes de février (du 20 au 28) et octobre, partiels, restent hors des moyennes. Juin et juillet : travaux sur le viaduc de Halle, effet estimé − 20 %.']):
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

# Feuille « Compte d'exploitation » : l'analyse (€ par mois sur les mois d'exploitation), le CA qu'il faut pour finir le mois à 0, 5 000 et
# 10 000 € après Nathan et la banque, le plan matière et + 15 % de CA avec matière à 40 %
if C:
    ce = wb.create_sheet('Compte d’exploitation')
    cell(ce, 'A1', f'Le compte d’exploitation comptable, {PER_TXT} 2026 (à valider)', F_T)
    cell(ce, 'A2', f'Situation au 30/09/2026 de la fiduciaire (Pardoen), ramenée au mois sur les {NR} mois d’exploitation (ouverture en mars) ; loyer, charges locatives, assurance et honoraires à leur coût mensuel. '
         'En bleu : montants de l’analyse ; fond jaune : paramètres et résultats visés ; en noir : calculs.', F_S)
    for col, w in zip('ABCDEFGHIJ', (52, 13, 8, 13, 13, 13, 15, 8, 15, 8)): ce.column_dimensions[col].width = w
    cell(ce, 'A4', 'Paramètres', F_SEC, border=B_H)
    par = [('Rémunération de Nathan, € par semaine', C['nathanSemaine'], EUR, JAUNE, 'absente des comptes'),
           ('Remboursements crédits et leasing, € par mois', C['remboursementsMois'], EUR, JAUNE, f'≈ {nf(C["credits"] / 1000)} k€ sur {C["creditsAns"]} ans et {nf(C["leasing"] / 1000)} k€ sur {C["leasingAns"]} ans'),
           ('Semaines par mois', '=52/12', NB1, None, ''), ('Jours par mois', '=365/12', NB1, None, 'ouvert 7 jours sur 7'),
           ('Mois d’exploitation (ouverture en mars)', NR, NB, None, 'pour ramener au mois les totaux de la période'),
           ('Hausse de prix moyenne (plan B1)', 0.05, PCT, JAUNE, 'baguette et tradition inchangées ; volumes supposés inchangés'),
           ('Commandes sur ventes réelles (B2), % des achats', 0.08, PCT, JAUNE, ''), ('Sorties tracées (B3), % des achats', 0.04, PCT, JAUNE, ''),
           ('Achats fournitures sur la période', C['achatsFournitures'], EUR, None, 'total de la période'), ('Audit fournitures (B4), % de la ligne', 0.35, PCT, JAUNE, ''),
           ('Hausse de CA du scénario', 0.15, PCT, JAUNE, 'les + 15 % visés'), ('Matière du scénario (% du CA)', 0.40, PCT, JAUNE, 'la cible du plan')]
    for i, (lib, v, fmt, fi, nt) in enumerate(par):
        rr = 5 + i
        cell(ce, f'A{rr}', lib); cell(ce, f'B{rr}', v, F_IN if not isinstance(v, str) else F_FX, fmt, fi); cell(ce, f'C{rr}', nt, F_S)
    # 5 Nathan · 6 remboursements · 7 semaines/mois · 8 jours/mois · 9 mois · 10 prix · 11 commandes · 12 sorties · 13 fournitures · 14 audit · 15 + CA · 16 matière
    rT = 18
    cell(ce, f'A{rT}', 'Résultat visé après Nathan et la banque (€ par mois)', F_H)
    for col, t in zip('DEF', CIBLES): cell(ce, f'{col}{rT}', t, F_IN, EUR, JAUNE)
    h0 = rT + 1
    for i, h in enumerate(['Poste (€ par mois)', 'Analyse', '% CA', 'CA pour le résultat visé', 'CA pour le résultat visé', 'CA pour le résultat visé', 'Plan matière (B1 → B4)', '% CA', '+ 15 % de CA, matière 40 %', '% CA']):
        cell(ce, f'{chr(65 + i)}{h0}', h, F_H, al=Alignment(horizontal='left' if i == 0 else 'right', wrap_text=True, vertical='bottom'), border=B_H)
    ce.row_dimensions[h0].height = 30
    rCAc, rAch, rEmb, rMB = h0 + 1, h0 + 2, h0 + 3, h0 + 4
    rF0 = rMB + 1; rF1 = rF0 + len(C['fixes']) - 1
    rEb, rNa, rEbN, rRb, rAp, rPl, rPlJ, rPM, rPMb = rF1 + 1, rF1 + 2, rF1 + 3, rF1 + 4, rF1 + 5, rF1 + 6, rF1 + 7, rF1 + 9, rF1 + 10
    cell(ce, f'A{rCAc}', 'Chiffre d’affaires', F_TOT); cell(ce, f'A{rAch}', C['variables'][0]['poste']); cell(ce, f'A{rEmb}', C['variables'][1]['poste'])
    cell(ce, f'A{rMB}', 'Marge brute', F_TOT, border=B_T)
    cell(ce, f'B{rCAc}', round(cC, 2), F_IN, EUR); cell(ce, f'B{rAch}', round(cAch, 2), F_IN, EUR); cell(ce, f'B{rEmb}', round(VARR[1], 2), F_IN, EUR)
    tvB = f'($B${rAch}+$B${rEmb})/$B${rCAc}'
    for col in 'DEF':
        cell(ce, f'{col}{rCAc}', f'=(SUM($B${rF0}:$B${rF1})+$B$5*$B$7+$B$6+{col}${rT})/(1-{tvB})', F(bold=True, color=BORD), EUR)
        cell(ce, f'{col}{rAch}', f'={col}{rCAc}*$B${rAch}/$B${rCAc}', fmt=EUR); cell(ce, f'{col}{rEmb}', f'={col}{rCAc}*$B${rEmb}/$B${rCAc}', fmt=EUR)
    cell(ce, f'G{rCAc}', f'=B{rCAc}*(1+$B$10)', F_TOT, EUR); cell(ce, f'G{rAch}', f'=B{rAch}*(1-$B$11-$B$12)-$B$13/$B$9*$B$14', fmt=EUR); cell(ce, f'G{rEmb}', f'=B{rEmb}', fmt=EUR)
    cell(ce, f'I{rCAc}', f'=B{rCAc}*(1+$B$15)', F_TOT, EUR); cell(ce, f'I{rAch}', f'=I{rCAc}*$B$16', fmt=EUR); cell(ce, f'I{rEmb}', f'=IF(B{rCAc}>0,B{rEmb}/B{rCAc}*I{rCAc},0)', fmt=EUR)
    for i, x in enumerate(C['fixes']):
        rr = rF0 + i
        cell(ce, f'A{rr}', x['poste']); cell(ce, f'B{rr}', round(FIXR[i], 2), F_IN, EUR)
        for col in 'DEFGI': cell(ce, f'{col}{rr}', f'=$B{rr}', fmt=EUR)
    lib2 = {rEb: 'EBITDA d’exploitation', rNa: 'Rémunération de Nathan', rEbN: 'EBITDA, Nathan payé', rRb: 'Remboursements crédits et leasing', rAp: 'Après Nathan et la banque',
            rPl: 'CA en plus par mois', rPlJ: 'CA en plus par jour', rPM: 'Point mort, Nathan payé (CA par mois)', rPMb: 'Point mort avec les remboursements (CA par mois)'}
    for rr, lib in lib2.items():
        cell(ce, f'A{rr}', lib, F_TOT if rr in (rEb, rEbN, rAp) else (F(bold=True, color=BORD) if rr in (rPM, rPMb) else F_FX), border=B_T if rr in (rEb, rAp) else None)
    for col in 'BDEFGI':
        cell(ce, f'{col}{rMB}', f'={col}{rCAc}-{col}{rAch}-{col}{rEmb}', F_TOT, EUR, border=B_T)
        cell(ce, f'{col}{rEb}', f'={col}{rMB}-SUM({col}{rF0}:{col}{rF1})', F_TOT, EUR, border=B_T)
        cell(ce, f'{col}{rNa}', '=-$B$5*$B$7', fmt=EUR)
        cell(ce, f'{col}{rEbN}', f'={col}{rEb}+{col}{rNa}', F_TOT, EUR)
        cell(ce, f'{col}{rRb}', '=-$B$6', fmt=EUR)
        cell(ce, f'{col}{rAp}', f'={col}{rEbN}+{col}{rRb}', F_TOT, EUR, border=B_T)
        if col != 'B':
            cell(ce, f'{col}{rPl}', f'={col}{rCAc}-$B${rCAc}', fmt=EUR); cell(ce, f'{col}{rPlJ}', f'={col}{rPl}/$B$8', fmt=EUR)
    for col in 'BGI':
        tv = f'({col}{rAch}+{col}{rEmb})/{col}{rCAc}'
        cell(ce, f'{col}{rPM}', f'=IF({col}{rCAc}>0,(SUM({col}{rF0}:{col}{rF1})-{col}{rNa})/(1-{tv}),0)', F(bold=True, color=BORD), EUR)
        cell(ce, f'{col}{rPMb}', f'=IF({col}{rCAc}>0,(SUM({col}{rF0}:{col}{rF1})-{col}{rNa}-{col}{rRb})/(1-{tv}),0)', F(bold=True, color=BORD), EUR)
    for col, pc in (('B', 'C'), ('G', 'H'), ('I', 'J')):
        for rr in [rCAc, rAch, rEmb, rMB] + list(range(rF0, rF1 + 1)) + [rEb, rNa, rEbN, rRb, rAp]:
            cell(ce, f'{pc}{rr}', f'=IF({col}${rCAc}<>0,{col}{rr}/{col}${rCAc},0)', F_TOT if rr in (rCAc, rMB, rEb, rEbN, rAp) else F_FX, PCT)
    cell(ce, f'A{rPMb + 1}', 'Colonnes D à F : le CA qui donne le résultat visé (ligne 18) ; achats et emballages suivent le CA, les autres charges restent les mêmes. '
         'Point mort : charges fixes (Nathan et remboursements compris) ÷ (1 − achats et emballages en % du CA).', F_S)
    # Le rapprochement avec la caisse
    r = rPMb + 3
    cell(ce, f'A{r}', 'Le rapprochement avec la caisse', F_SEC, border=B_H)
    cell(ce, f'A{r + 1}', 'CA comptable de la période (€ par mois × mois d’exploitation)'); cell(ce, f'B{r + 1}', f'=B{rCAc}*$B$9', fmt=EUR)
    cell(ce, f'A{r + 2}', f'CA de caisse HT, {PER_TXT}'); cell(ce, f'B{r + 2}', f"=SUM({MP}C{rN - len(MPER) + 1}:C{rN})", F_LK, EUR)
    cell(ce, f'A{r + 3}', 'Écart comptable − caisse'); cell(ce, f'B{r + 3}', f'=B{r + 1}-B{r + 2}', fmt=EUR); cell(ce, f'C{r + 3}', f'=IF(B{r + 2}>0,B{r + 3}/B{r + 2},0)', fmt=PCT)
    # La perte
    r = r + 5
    cell(ce, f'A{r}', 'D’où vient la perte de la situation au 30/09', F_SEC, border=B_H)
    cell(ce, f'D{r}', 'Charges courues par mois sans CA', F_S); cell(ce, f'G{r}', C['chargesCouruesMois'], F_IN, EUR)
    cell(ce, f'D{r + 1}', 'Mois sans CA (octobre → février)', F_S); cell(ce, f'G{r + 1}', C['chargesCouruesMoisSansCA'], F_IN, NB)
    for i, x in enumerate(C['perte']):
        rr = r + 1 + i; o = x['origine']
        if o.startswith('Charges courues'):
            cell(ce, f'A{rr}', 'Charges courues sans CA, d’octobre à février'); cell(ce, f'B{rr}', f'=-$G${r}*$G${r + 1}', fmt=EUR)
        elif o.startswith('Exploitation'):
            cell(ce, f'A{rr}', f'Exploitation réelle de {PER_TXT}'); cell(ce, f'B{rr}', f'=B{rEb}*$B$9', fmt=EUR)
        else:
            cell(ce, f'A{rr}', o); cell(ce, f'B{rr}', x['montant'], F_IN, EUR)
    rs = r + 1 + len(C['perte'])
    cell(ce, f'A{rs}', 'Somme des lignes', F_TOT, border=B_T); cell(ce, f'B{rs}', f'=SUM(B{r + 1}:B{rs - 1})', F_TOT, EUR, border=B_T)
    cell(ce, f'A{rs + 1}', 'Perte de la situation au 30/09'); cell(ce, f'B{rs + 1}', C['perteSituation'], F_IN, EUR)
    ce.freeze_panes = 'B20'

# Feuille « Application ou bilan » : avril → septembre, l'application (feuille Mois par mois) face au bilan (feuille Compte d'exploitation)
if C:
    ab = wb.create_sheet('Application ou bilan')
    CE = "'Compte d’exploitation'!"
    a1, a2 = rN - len(MPER) + 1, rN                     # la période comptable (mars → septembre) dans « Mois par mois »
    cell(ab, 'A1', f'L’application ou le bilan : {PER_TXT} 2026, en € par mois', F_T)
    cell(ab, 'A2', 'Application : la caisse et le P&L du panel (feuille « Mois par mois », main-d’œuvre encodée, food cost mesuré par le panel). Bilan : la situation au 30/09 de la fiduciaire, ramenée au mois (feuille « Compte d’exploitation », colonne Analyse, à valider). En vert : liens ; en noir : calculs.', F_S)
    for col, w in zip('ABCDEFG', (34, 15, 9, 15, 9, 15, 70)): ab.column_dimensions[col].width = w
    for i, h in enumerate(['Poste', 'Application', '% CA', 'Bilan', '% CA', 'Écart (bilan − application)', 'Ce qui change d’une source à l’autre']):
        cell(ab, f'{chr(65 + i)}4', h, F_H, al=Alignment(horizontal='left' if i in (0, 6) else 'right', wrap_text=True, vertical='bottom'), border=B_H)
    ab.row_dimensions[4].height = 30
    L = [('Chiffre d’affaires', f'=AVERAGE({MP}C{a1}:C{a2})', f'={CE}B{rCAc}', 'caisse HT, contre CA comptable'),
         ('Matière', f'=SUMPRODUCT({MP}C{a1}:C{a2},{MP}T{a1}:T{a2})/{a2 - a1 + 1}', f'={CE}B{rAch}+{CE}B{rEmb}', 'application : coût de recette des ventes (panel) ; bilan : achats + emballages'),
         ('Marge brute', '=B5-B6', '=D5-D6', ''),
         ('Personnel', f'=AVERAGE({MP}U{a1}:U{a2})', f'={CE}B{rF0}', 'heures planifiées × taux du panel, contre salaires ; Nathan absent des deux'),
         ('Frais généraux', f'=AVERAGE({MP}P{a1}:P{a2})', f'=SUM({CE}B{rF0 + 1}:B{rF1})', 'montant saisi au panel, contre factures'),
         ('Résultat · EBITDA', '=B7-B8-B9', '=D7-D8-D9', 'avant Nathan, amortissements, charges financières'),
         ('Point mort (CA par mois)', '=IF(B5>0,(B8+B9)/(1-B6/B5),0)', '=IF(D5>0,(D8+D9)/(1-D6/D5),0)', 'charges fixes ÷ (1 − matière en % du CA), Nathan non payé')]
    for i, (lib, fa, fb, nt) in enumerate(L):
        rr = 5 + i; tot = lib in ('Chiffre d’affaires', 'Résultat · EBITDA')
        cell(ab, f'A{rr}', lib, F_TOT if tot else F_FX)
        cell(ab, f'B{rr}', fa, F_LK if fa.startswith(f'=AVERAGE({MP}') or fa.startswith('=SUMPRODUCT') else F_FX, EUR)
        cell(ab, f'D{rr}', fb, F_LK if CE in fb else F_FX, EUR)
        if rr not in (5, 11):
            cell(ab, f'C{rr}', f'=IF(B$5>0,B{rr}/B$5,0)', fmt=PCT); cell(ab, f'E{rr}', f'=IF(D$5>0,D{rr}/D$5,0)', fmt=PCT)
        cell(ab, f'F{rr}', f'=D{rr}-B{rr}', F_TOT if tot else F_FX, EUR); cell(ab, f'G{rr}', nt, F_S)
    cell(ab, 'A12', 'Clients par mois (application)'); cell(ab, 'B12', f'=AVERAGE({MP}F{a1}:F{a2})', F_LK, NB)
    cell(ab, 'A13', 'Panier moyen (application)'); cell(ab, 'B13', '=IF(B12>0,B5/B12,0)', fmt=EUR2)
    cell(ab, 'A15', 'Du résultat de l’application à l’EBITDA du bilan', F_SEC, border=B_H)
    P_ = [('Résultat de l’application', '=B10'), ('Écart de CA', '=D5-B5'), ('Écart de matière', '=-(D6-B6)'),
          ('Écart de personnel', '=-(D8-B8)'), ('Écart de frais généraux', '=-(D9-B9)')]
    for i, (lib, f_) in enumerate(P_):
        cell(ab, f'A{16 + i}', lib); cell(ab, f'B{16 + i}', f_, fmt=EUR)
    cell(ab, 'A21', 'EBITDA du bilan (somme des lignes)', F_TOT, border=B_T); cell(ab, 'B21', '=SUM(B16:B20)', F_TOT, EUR, border=B_T)
    cell(ab, 'C21', '=IF(ABS(B21-D10)<1,"= bilan","écart")', F_S)
    ab.freeze_panes = 'A5'

CE = "'Compte d’exploitation'!"
# Feuille « Euros par jour » : + 50 → + 250 € par jour, le CA, la marge directe et le résultat — à personnel et frais généraux égaux
ej = wb.create_sheet('Euros par jour')
RC = "'Récap'!"
cell(ej, 'A1', 'Ce que rapportent quelques euros de plus par jour', F_T)
cell(ej, 'A2', 'À personnel et frais généraux égaux, un euro vendu en plus ne coûte que sa matière. Paliers en bleu (modifiables) ; liens en vert vers « Récap » et « Compte d’exploitation ».', F_S)
for col, w in zip('ABCDEFGHIJ', (16, 14, 14, 13, 13, 15, 15, 15, 15, 16)): ej.column_dimensions[col].width = w
cell(ej, 'A4', 'Jours par mois'); cell(ej, 'B4', f'={RC}B13', F_LK, NB1)
cell(ej, 'A5', 'Panier moyen'); cell(ej, 'B5', f'={RC}B9', F_LK, EUR2)
cell(ej, 'A6', 'Clients par jour'); cell(ej, 'B6', f'={RC}B8', F_LK, NB)
cell(ej, 'A7', 'Taux de marge, récap'); cell(ej, 'B7', f'=1-{RC}B31', F_LK, PCT)
cell(ej, 'A8', 'Résultat actuel, récap'); cell(ej, 'B8', f'={RC}B25', F_LK, EUR)
if C:
    cell(ej, 'D4', 'Taux de marge, bilan'); cell(ej, 'F4', f'=1-({CE}B{rAch}+{CE}B{rEmb})/{CE}B{rCAc}', F_LK, PCT)
    cell(ej, 'D5', 'EBITDA Nathan payé, bilan'); cell(ej, 'F5', f'={CE}B{rEbN}', F_LK, EUR)
    cell(ej, 'D6', 'Après remboursements, bilan'); cell(ej, 'F6', f'={CE}B{rAp}', F_LK, EUR)
hh = ['€ par jour en plus', 'CA en plus par mois', 'CA en plus par an', 'Clients par jour en plus', 'ou panier en plus', 'Marge directe par mois (récap)', 'Résultat par mois (récap)']
if C: hh += ['Marge directe par mois (bilan)', 'EBITDA Nathan payé (bilan)', 'Après remboursements (bilan)']
for i, h in enumerate(hh):
    cell(ej, f'{chr(65 + i)}10', h, F_H, al=Alignment(horizontal='right', wrap_text=True, vertical='bottom'), border=B_H)
ej.row_dimensions[10].height = 40
for i, e in enumerate([0] + PALIERS):
    rr = 11 + i
    cell(ej, f'A{rr}', e, F_IN, EUR)
    cell(ej, f'B{rr}', f'=A{rr}*$B$4', fmt=EUR); cell(ej, f'C{rr}', f'=A{rr}*365', fmt=EUR)
    cell(ej, f'D{rr}', f'=IF($B$5>0,A{rr}/$B$5,0)', fmt=NB1); cell(ej, f'E{rr}', f'=IF($B$6>0,A{rr}/$B$6,0)', fmt=EUR2)
    cell(ej, f'F{rr}', f'=B{rr}*$B$7', fmt=EUR); cell(ej, f'G{rr}', f'=$B$8+F{rr}', F_TOT, EUR)
    if C:
        cell(ej, f'H{rr}', f'=B{rr}*$F$4', fmt=EUR); cell(ej, f'I{rr}', f'=$F$5+H{rr}', F_TOT, EUR); cell(ej, f'J{rr}', f'=$F$6+H{rr}', F_TOT, EUR)
cell(ej, 'A18', 'Ligne 0 : aujourd’hui, moyenne de mars à septembre (le bilan de la page 3). Une vente faite avec une remise rapporte moins que la marge directe.', F_S)
ej.freeze_panes = 'B11'

for ws in wb.worksheets:
    ws.sheet_view.showGridLines = False
wb.save(os.path.join(ICI, 'Halle-tableau-recap.xlsx'))
print('Excel écrit')
