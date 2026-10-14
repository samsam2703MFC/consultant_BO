# Le tableau récap de Halle, trois pages A4 et une seule source par chiffre : les ventes et les clients viennent de la caisse
# (donnees.json, lu sur le cockpit), les coûts, le résultat et le point mort des comptes de la fiduciaire ramenés au mois
# (compta.json) ; le plan matière vient de plan.json. Page 1 : la synthèse et le compte du mois (à 0, 5 000, 10 000 € après
# Nathan et la banque) ; page 2 : les ventes mois par mois et l'objectif ; page 3 : les euros par jour et le plan matière.
# Le classeur Excel garde le détail, avec ses formules (dont les hypothèses du panel : --food, --mo).
#
#   python3 generer.py --relire   relit les chiffres sur le cockpit en ligne et les fige dans donnees.json
#   python3 generer.py            refait le PDF et l'Excel depuis donnees.json, compta.json et plan.json
#   --food 45 | --food mesure     food cost des feuilles Récap et Mois par mois de l'Excel (défaut : 45 %)
#   --mo 2650 | --mo encodee      main-d'œuvre par semaine des mêmes feuilles (défaut : 2 650 €)
#   puis : python3 <skill xlsx>/scripts/recalc.py Halle-tableau-recap.xlsx   (les formules n'ont de valeur en cache qu'après recalcul)
#
# Page 4 : la réunion avec Nathan (reunion.json), si le fichier est là.
# Ouverture en mars 2026 ; mois pleins : mars → septembre (février : ventes du 20 au 28 ; octobre : en cours, jusqu'à la veille).
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
.pts.decisions{{font-size:8.8pt;line-height:1.55;columns:2;column-gap:8mm}}.t.remb td.l{{white-space:normal;line-height:1.3}}.vignette{{background:#8D1D2C;color:#fff;border-radius:8px;padding:3.2mm 5mm;margin:2.5mm 0 3mm;font-family:Vank,Georgia,serif;font-size:14pt;line-height:1.25}}
.effort{{display:grid;grid-template-columns:1fr 1fr;gap:3mm;margin:0 0 3mm}}.effort .intro{{grid-column:1 / -1;font-size:9pt;font-weight:500;color:#8D1D2C}}.effort .jr{{border:1px solid #e6e0d8;border-left:3px solid #8D1D2C;border-radius:6px;background:#fbf9f5;padding:2mm 3mm}}.effort .k{{font-size:6.6pt;letter-spacing:.08em;text-transform:uppercase;color:#8D1D2C;font-weight:500}}.effort .t{{font-size:8.6pt;font-weight:500;margin:.6mm 0}}.effort .q{{font-size:7.6pt;color:#5a534b;line-height:1.4}}.deuxv{{display:grid;grid-template-columns:1fr 1fr;gap:4mm}}
.rythme{{display:grid;grid-template-columns:repeat(3,1fr);gap:3mm;margin-bottom:1mm}}.rythme .jr{{border:1px solid #e6e0d8;border-left:3px solid #8D1D2C;border-radius:6px;background:#fbf9f5;padding:2mm 3mm}}.rythme .k{{font-size:6.6pt;letter-spacing:.08em;text-transform:uppercase;color:#8D1D2C;font-weight:500}}.rythme .t{{font-size:8.6pt;font-weight:500;margin:.6mm 0}}.rythme .q{{font-size:7.6pt;color:#5a534b;line-height:1.4}}.t.remb tr.hors td{{color:#C0182B}}.pts.decisions li{{margin-bottom:1.6mm;break-inside:avoid}}
.t.plan2 td{{padding:1.4mm 1.4mm;font-size:7.8pt}}.t.plan2 td.l small{{font-size:6.9pt;line-height:1.35}}.t.plan2 td{{vertical-align:top}}.t.plan2 tr.tot td{{vertical-align:middle}}.t.compta td.l small{{display:block;font-size:6pt;color:#9a938a;white-space:normal}}
.t.sens th{{text-align:right}}.t.sens th.g{{text-align:center;border-bottom:.5pt solid #b8b1a6;color:#8D1D2C}}.t.sens td{{padding:1.2mm 1.3mm;font-size:7.6pt}}.t.sens td.g{{color:#5a534b}}.t.sens td.res{{font-weight:500}}.t.sens tr.base td{{background:#FCFAF7;color:#5a534b}}.neg{{color:#C0182B}}.cadre .ind{{font-size:7.4pt;line-height:1.45}}
.pts{{margin:0;padding-left:4mm;font-size:7.3pt;line-height:1.45;color:#221E1A}}.pts li{{margin-bottom:1.1mm}}
.ft{{position:absolute;left:11mm;right:11mm;bottom:5mm;display:flex;justify-content:space-between;font-size:6.4pt;color:#9a938a;border-top:.5pt solid #e6e0d8;padding-top:1.2mm}}
'''

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
hd = f'<div class="hd"><span class="marque"><img class="logo" src="{LOGO}">Atelier by – Halle</span><span>Tableau récap · chiffres lus le {luTxt}</span></div>'

# --- Le compte du mois (comptes de la fiduciaire, ramenés au mois) : le CA qu'il faut pour finir le mois à 0, 5 000 ou 10 000 €
#     après Nathan et la banque — achats et emballages suivent le CA, le reste ne bouge pas
PL = json.load(open(os.path.join(ICI, 'plan.json'))) if os.path.exists(os.path.join(ICI, 'plan.json')) else None
assert C and PL, 'compta.json et plan.json sont nécessaires au récap'
CIBLES = [0, 5000, 10000]
remb = C['remboursementsMois']
caCible = lambda t: (cF + cN + remb + t) / (1 - cVar)
COLS = [dict(ca=cC, ach=cAch, emb=VARR[1])] + [dict(ca=caCible(t), ach=caCible(t) * cAch / cC, emb=caCible(t) * VARR[1] / cC) for t in CIBLES]
for c_ in COLS:
    c_['mb'] = c_['ca'] - c_['ach'] - c_['emb']; c_['eb'] = c_['mb'] - cF; c_['ebN'] = c_['eb'] - cN; c_['ap'] = c_['ebN'] - remb
plus = [c_['ca'] - cC for c_ in COLS[1:]]
# --- Les leviers : les paliers d'euros par jour, et le plan matière (plan.json) chiffré sur la base des comptes
PALIERS = [50, 100, 150, 200, 250]
Bm = PL['matiere']
neg = lambda v, d=0: f'<span class="neg">{fE(v, d)}</span>' if v < 0 else fE(v, d)
bE = []; dCA = 0.0; dAch = 0.0
for e in Bm['effets']:
    if e['type'] == 'prix': v = e['pct'] * cC; dCA += v; bE.append((e['action'], f'+ {fE(arr(v, 10))} de CA'))
    else:
        v = e['pct'] * (cAch if e['type'] == 'achats' else fournM); dAch += v; bE.append((e['action'], f'− {fE(arr(v, 5))} d’achats'))
achApres = cAch - dAch; caApres = cC + dCA
VB = dict(NR=NR, pctAch=nfP(dAch / cAch), achEur=fE(arr(dAch, 100)),
          tgtg=fE(C.get('tooGoodToGo', 0)), tgtgMois=fE(arr(C.get('tooGoodToGo', 0) / NR, 1)), fourn=nf(C['achatsFournitures'] / 1000, 1) + '\u00a0k€',
          fournMois=nf(fournM / 1000, 1) + '\u00a0k€', achPctApres=fP(achApres / caApres), gain=fE(arr(dCA + dAch, 100)))
fmtB = lambda t: t.format(**VB)

# --- 3b. La version simplifiée, trois pages et une seule source par chiffre (demande du 13/10/2026) :
#     les ventes et les clients viennent de la caisse ; les coûts, le résultat et le point mort, des comptes de la fiduciaire
#     ramenés au mois.
def nomMois(d):
    return 'octobre' if d['part'] else {v: NOMS[k].lower() for k, v in COURT.items()}[d['lib']]

def graphCA(pmJ, libPM):
    svg = [f'<svg class="graph" viewBox="0 0 {W} {Hh}" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="CA par jour de chaque mois face à l’objectif et au point mort">',
           '<defs><pattern id="hach2" width="5" height="5" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="5" height="5" fill="#E9D3D6"/><line x1="0" y1="0" x2="0" y2="5" stroke="#8D1D2C" stroke-width="2.4"/></pattern></defs>']
    for v in range(0, vmax + 1, pas):
        svg.append(f'<line x1="{gx}" x2="{gx + pw}" y1="{Y(v):.1f}" y2="{Y(v):.1f}" stroke="#EAE3D8" stroke-width="{1 if v else 1.2}"/>'
                   f'<text class="ax" x="{gx - 6}" y="{Y(v) + 3:.1f}" text-anchor="end">{nf(v)}&#8239;€</text>')
    for i, d in enumerate(serie):
        x0 = gx + i * bw + bw * 0.22; w = bw * 0.56; y = Y(d['v']); r = 3
        svg.append(f'<path d="M{x0:.1f},{Y(0):.1f} V{y + r:.1f} Q{x0:.1f},{y:.1f} {x0 + r:.1f},{y:.1f} H{x0 + w - r:.1f} Q{x0 + w:.1f},{y:.1f} {x0 + w:.1f},{y + r:.1f} V{Y(0):.1f} Z" fill="{"url(#hach2)" if d["part"] else "#8D1D2C"}"/>')
        yo = Y(d['obj'])
        svg.append(f'<line x1="{x0 - 5:.1f}" x2="{x0 + w + 5:.1f}" y1="{yo:.1f}" y2="{yo:.1f}" stroke="#221E1A" stroke-width="2" stroke-linecap="round"/>')
        cx = x0 + w / 2
        svg.append(f'<text class="axl" x="{cx:.1f}" y="{Y(0) + 13:.1f}" text-anchor="middle">{d["lib"]}</text>'
                   f'<text class="axv" x="{cx:.1f}" y="{Y(0) + 25:.1f}" text-anchor="middle">{nf(d["v"])}&#8239;€</text>')
    ypm = Y(pmJ); lx = gx + pw + 10; yo_last = Y(serie[-2]['obj'])
    svg.append(f'<line x1="{gx}" x2="{gx + pw}" y1="{ypm:.1f}" y2="{ypm:.1f}" stroke="#7a736a" stroke-width="1.5" stroke-dasharray="5 4"/>')
    svg.append(f'<line x1="{lx}" x2="{lx + 14}" y1="{yo_last - 22:.1f}" y2="{yo_last - 22:.1f}" stroke="#221E1A" stroke-width="2" stroke-linecap="round"/>'
               f'<text class="lg" x="{lx + 19}" y="{yo_last - 19:.1f}">objectif (budget</text><text class="lg" x="{lx + 19}" y="{yo_last - 8:.1f}">du mois par jour)</text>')
    svg.append(f'<line x1="{lx}" x2="{lx + 14}" y1="{ypm:.1f}" y2="{ypm:.1f}" stroke="#7a736a" stroke-width="1.5" stroke-dasharray="5 4"/>'
               f'<text class="lg" x="{lx + 19}" y="{ypm + 10:.1f}">{libPM}</text><text class="lg" x="{lx + 19}" y="{ypm + 21:.1f}">{nf(arr(pmJ))}&#8239;€ par jour</text>')
    svg.append(f'<rect x="{lx}" y="{Y(500) - 5:.1f}" width="14" height="9" rx="2" fill="url(#hach2)"/><text class="lg" x="{lx + 19}" y="{Y(500) + 3:.1f}">octobre, en cours</text>')
    svg.append('</svg>')
    return ''.join(svg)

NPAGES = 4 if os.path.exists(os.path.join(ICI, 'reunion.json')) else 3
ft = lambda n: f'<div class="ft"><span>Atelier by – Halle · tableau récap · mars → septembre 2026 · caisse du panel, comptes de la fiduciaire au 30/09</span><span>{n} / {NPAGES}</span></div>'
remb = C['remboursementsMois']; resA = COLS[0]['ap']; pm0 = COLS[1]['ca']; pm0J = pm0 / JM
autresF = cF - cPerso - FIXR[1]
# Page 1 — la synthèse
tu1 = ''.join([
    tuile('CA moyen par mois', fE(caMois), f'{fP(ca / bud, 0)} du budget · {fE(caJour)} par jour'),
    tuile('Clients par jour', nf(tkJour), f'{nf(tkMois)} tickets par mois'),
    tuile('Panier moyen', fE(panier, 2), f'les 3 autres magasins : {fE(D["panierAutres"]["moyenne"], 2)}'),
    tuile('Résultat du mois', fE(resA), f'après Nathan et la banque · à 0 € avec {fE(arr(pm0, 100))} de CA'),
])
def lq(lib, vals, cls='', pct=True, signe=False, fixe=False, small=''):
    f = (lambda v: ('− ' + fE(abs(v))) if signe else fE(v))
    cel = [f'<td>{f(vals[0])}</td><td class="p">{fP(vals[0] / cC) if pct else ""}</td>'] + [f'<td class="{"fx" if fixe else "cb"}">{f(v)}</td>' for v in vals[1:]]
    return f'<tr{" class=" + cls if cls else ""}><td class="l">{lib}{"<small>" + small + "</small>" if small else ""}</td>{"".join(cel)}</tr>'
K = lambda k: [c_[k] for c_ in COLS]
compte = ''.join([
    lq('Chiffre d’affaires', K('ca'), 'tot'),
    lq('Matière et emballages', [c_['ach'] + c_['emb'] for c_ in COLS], small='achats de marchandises, fournitures, boissons, emballages'),
    lq('Marge brute', K('mb'), 'sous'),
    lq('Personnel', [cPerso] * 4, fixe=True, small='sans la rémunération de Nathan'),
    lq('Loyer, charges locatives, assurance', [FIXR[1]] * 4, fixe=True),
    lq('Autres frais fixes', [autresF] * 4, fixe=True, small='énergie, honoraires, containers, voiture, entretien, administration, publicité, matériel, redevances'),
    lq('EBITDA', K('eb'), 'tot'),
    lq(f'Rémunération de Nathan ({fE(C["nathanSemaine"])} par semaine)', [cN] * 4, pct=False, signe=True, fixe=True),
    lq('Remboursements crédits et leasing', [remb] * 4, pct=False, signe=True, fixe=True),
    lq('Résultat après Nathan et la banque', K('ap'), 'tot')])
compte += ('<tr class="plus"><td class="l">CA en plus</td><td></td><td></td>'
           + ''.join(f'<td class="cb">+&nbsp;{fE(v)}<small>+&nbsp;{fE(v / JM)} par jour</small></td>' for v in plus) + '</tr>')
tabCompte = ('<table class="t serre compta"><tr><th class="l" rowspan="2">€ par mois</th><th rowspan="2">Aujourd’hui</th><th rowspan="2">% CA</th>'
             '<th class="g" colspan="3">Le CA qu’il faut pour finir le mois à…<small>achats et emballages suivent le CA ; le reste ne bouge pas</small></th></tr>'
             '<tr>' + ''.join(f'<th>{fE(t)}</th>' for t in CIBLES) + f'</tr>{compte}</table>')
q1 = f"""<div class="page">
{hd}
<h1>Halle — tableau récap</h1>
<div class="sous">Les sept mois depuis l’ouverture en mars 2026 (mars → septembre), et octobre en cours. Montants hors TVA, par mois.</div>
<div class="tiles">{tu1}</div>
<div class="cadre"><div class="k">À retenir</div><ul>
<li>Les ventes : {fE(arr(caMois, 100))} par mois, {fP(ca / bud, 0)} du budget de {fE(obj)}. Il manque {fE(arr(obj - caMois, 100))} par mois : {nf(cliObjJour - tkJour)} clients de plus par jour, ou {fE(panObj - panier, 2)} de plus par ticket. Octobre démarre mieux : {fP(oAtt, 0)} de l’objectif au {jusqua.day}.</li>
<li>La matière : achats et emballages à {fP(cVar)} du CA, contre ≈ 40&nbsp;% dans la structure du réseau. C’est ≈ {nf((cVar - 0.40) * cC / 1000, 1)}&nbsp;k€ de marge qui partent chaque mois, plus que l’EBITDA.</li>
<li>Le mois : {fE(arr(cE - cN, 100))} Nathan payé, {fE(arr(resA, 100))} après la banque. Pour finir à 0, il faut {fE(arr(pm0, 100))} de CA par mois, + {fE(plus[0] / JM)} par jour ; pour 5 000 €, + {fE(plus[1] / JM)} par jour.</li>
</ul></div>
<div class="sec">Le compte du mois</div>
{tabCompte}
<p class="s">Ventes et clients : la caisse du panel, lue le {luTxt}. Coûts : la situation au 30/09/2026 de la fiduciaire, ramenée au mois sur les {NR} mois d’exploitation (à valider) ; son CA ({fE(cC)} par mois) et la caisse ({fE(caPerM)}) diffèrent de {fP(abs(cC / caPerM - 1), 0)}.</p>
{ft(1)}
</div>"""
# Page 2 — les ventes
lv = ''
for x in M:
    a = x['ca'] / x['budget']
    lv += (f'<tr><td class="l">{NOMS[x["m"]]}{"<sup>*</sup>" if x["m"] in (6, 7) else ""}</td><td>{x["jours"]}</td><td>{fE(x["ca"])}</td><td>{fE(x["budget"])}</td>'
           f'<td class="bar"><span class="barre"><i style="width:{min(100, 100 * a):.1f}%"></i></span><em>{fP(a, 0)}</em></td>'
           f'<td>{nf(x["tickets"])}</td><td>{nf(x["tickets"] / x["jours"])}</td><td>{fE(x["ca"] / x["tickets"], 2)}</td><td>{fE(x["ca"] / x["jours"])}</td></tr>')
lv += (f'<tr class="tot"><td class="l">Mars → septembre</td><td>{jr}</td><td>{fE(ca)}</td><td>{fE(bud)}</td>'
       f'<td class="bar"><span class="barre"><i style="width:{100 * ca / bud:.1f}%"></i></span><em>{fP(ca / bud, 0)}</em></td>'
       f'<td>{nf(tk)}</td><td>{nf(tkJour)}</td><td>{fE(panier, 2)}</td><td>{fE(caJour)}</td></tr>')
lv += (f'<tr class="part"><td class="l">Octobre 1 → {jusqua.day}<sup>**</sup></td><td>{O["jours"]}</td><td>{fE(O["ca"])}</td><td>{fE(O["objectifADate"])}</td>'
       f'<td class="bar"><span class="barre"><i style="width:{min(100, 100 * oAtt):.1f}%"></i></span><em>{fP(oAtt, 0)}</em></td>'
       f'<td>{nf(O["tickets"])}</td><td>{nf(oTkJ)}</td><td>{fE(oPan, 2)}</td><td>{fE(oCaJ)}</td></tr>')
dessus = [nomMois(d) for d in serie if d['v'] >= pm0J]
q2 = f"""<div class="page">
{hd}
<div class="sec">Les ventes, mois par mois</div>
<table class="t mois">
<tr><th class="l">Mois</th><th>Jours</th><th>CA</th><th>Budget</th><th>Atteinte</th><th>Clients</th><th>Clients / jour</th><th>Panier</th><th>CA / jour</th></tr>
{lv}
</table>
<p class="s"><sup>*</sup> Juin et juillet : travaux sur le viaduc de Halle, effet estimé à − 20&nbsp;% sur le chiffre. <sup>**</sup> Octobre : jusqu’au {jusqua.day} inclus, face à l’objectif à date (le budget du mois réparti par jour de semaine).</p>
<div class="sec" style="margin-top:4mm">Le CA par jour, face à l’objectif et au point mort</div>
<p class="s">Chaque barre : le CA moyen par jour de vente du mois. Le trait noir : le budget du mois ramené au jour. Le pointillé : le CA par jour qu’il faut pour finir le mois à 0 € après Nathan et la banque. {(", ".join(dessus[:-1]) + " et " + dessus[-1] + " passent au-dessus" if len(dessus) > 1 else dessus[0] + " passe au-dessus").capitalize() if dessus else "Aucun mois ne passe au-dessus"} ; aucun mois n’atteint l’objectif.</p>
{graphCA(pm0J, 'point mort')}
<div class="sec" style="margin-top:4mm">Pour atteindre le budget</div>
{ventes}
{ft(2)}
</div>"""
# Page 3 — les leviers
la = ''
for e in [0] + PALIERS:
    caM = e * JM; mg = caM * (1 - cVar)
    la += (f'<tr{" class=base" if e == 0 else ""}><td class="l">{"Aujourd’hui" if e == 0 else "+&nbsp;" + fE(e)}</td>'
           f'<td>{"—" if e == 0 else "+&nbsp;" + fE(caM)}</td><td>{"—" if e == 0 else "+&nbsp;" + fE(e * 365)}</td>'
           f'<td>{"—" if e == 0 else "+&nbsp;" + nf(e / panier)}</td><td>{"—" if e == 0 else "+&nbsp;" + fE(e / tkJour, 2)}</td>'
           f'<td class="g">{"—" if e == 0 else "+&nbsp;" + fE(mg)}</td><td class="res">{neg(resA + mg)}</td></tr>')
tabA3 = (f'<table class="t sens"><tr><th class="l">Par jour</th><th>CA en plus<small>par mois</small></th><th>CA en plus<small>par an</small></th>'
         f'<th>soit clients<small>par jour, au panier de {fE(panier, 2)}</small></th><th>ou panier<small>en plus, à {nf(tkJour)} clients par jour</small></th>'
         f'<th>Marge directe<small>par mois, à {fP(1 - cVar)}</small></th><th>Résultat<small>après Nathan et la banque</small></th></tr>{la}</table>')
lb = ''
for i, b in enumerate(Bm['actions']):
    lb += f'<tr><td class="n">B{i + 1}</td><td class="l">{H(b.get("titreCourt", b["titre"]))}<small>{H(fmtB(b.get("court", b["texte"])))}</small></td><td>{H(bE[i][1])}</td></tr>'
gainB = dCA + dAch
lb += (f'<tr class="tot"><td></td><td class="l">Achats à {fP(achApres / caApres)} du CA, au lieu de {fP(cAch / cC)}</td><td>+&nbsp;{fE(arr(gainB, 10))} de marge</td></tr>')
q3 = f"""<div class="page">
{hd}
<div class="sec">Ce que rapportent quelques euros de plus par jour</div>
<p class="s">À personnel et frais égaux, un euro vendu en plus ne coûte que sa matière et son emballage ({fP(cVar)}) : le reste tombe dans le résultat. Prix inchangés ; une vente faite avec une remise rapporte moins.</p>
{tabA3}
<p class="s">Le budget de {fE(obj)} par mois demande + {fE(arr((obj - caMois) / JM, 5))} par jour sur la base de la caisse.</p>
<div class="sec" style="margin-top:5mm">{H(Bm['titre'])}</div>
<table class="t plan plan2"><tr><th></th><th class="l">Action</th><th>Effet par mois</th></tr>{lb}</table>
<p class="s">Avec {'ces deux actions' if len(Bm['actions']) == 2 else 'ces ' + str(len(Bm['actions'])) + ' actions'}, le résultat après Nathan et la banque passe de {fE(arr(resA, 10))} à ≈ {fE(arr(resA + gainB, 10))} par mois.</p>
<div class="cadre" style="margin-top:3mm"><div class="k">L’indicateur du lundi</div><div class="ind">{H(Bm['indicateur'])}</div></div>
{ft(3)}
</div>"""

# Page 4 — la réunion avec Nathan (reunion.json) : les décisions, le calendrier des versements sur le compte tiers, l'ordre de remboursement
RE = json.load(open(os.path.join(ICI, 'reunion.json'))) if os.path.exists(os.path.join(ICI, 'reunion.json')) else None
q4 = ''
if RE:
    fD = lambda d_: d_.strftime('%d/%m/%Y')
    vD = []; d_ = datetime.date.fromisoformat(RE['versement']['premier']); fin_ = datetime.date.fromisoformat(RE['versement']['dernier'])
    while d_ <= fin_: vD.append(d_); d_ += datetime.timedelta(days=7)
    mV = RE['versement']['montant']
    moitie = (len(vD) + 1) // 2
    demi = lambda L_, o_: ''.join(f'<tr><td class="n">{o_ + i + 1}</td><td class="l">{fD(x)}</td><td>{fE(mV * (o_ + i + 1))}</td></tr>' for i, x in enumerate(L_))
    tete = '<tr><th></th><th class="l">Lundi</th><th>Cumul</th></tr>'
    lvv = (f'<div class="deuxv"><table class="t serre">{tete}{demi(vD[:moitie], 0)}</table><table class="t serre">{tete}{demi(vD[moitie:], moitie)}</table></div>'
           f'<table class="t serre"><tr class="tot"><td class="l">{len(vD)} versements de {fE(mV)}</td><td>{fE(mV * len(vD))}</td></tr></table>')
    cumD = 0; lrr = ''
    for r_ in RE['remboursements']:
        cumD += r_['montant']; k_ = next((i for i in range(len(vD)) if mV * (i + 1) >= cumD), None)
        if k_ is not None:
            quand = 'lundi ' + fD(vD[k_]) + ' (' + str(k_ + 1) + '<sup>e</sup> versement)'
        else:                                                   # au-delà du 18/01 : le lundi où il serait soldé si les versements continuaient
            n_ = -(-cumD // mV); quand = 'après la période : lundi ' + fD(vD[0] + datetime.timedelta(days=7 * (n_ - 1))) + ' si les versements continuent'
        lrr += (f'<tr{" class=hors" if k_ is None else ""}><td class="n">{r_["rang"]}</td><td class="l">{H(r_["creancier"])}</td><td>{fE(r_["montant"])}</td>'
                f'<td>{fE(cumD)}</td><td class="l">{quand}</td></tr>')
    rest_ = mV * len(vD) - cumD
    lrr += (f'<tr class="tot"><td></td><td class="l">Dettes prioritaires</td><td>{fE(cumD)}</td><td></td><td class="l">'
            + (f'reste {fE(rest_)} sur le compte tiers au {fD(vD[-1])}' if rest_ >= 0 else f'manque {fE(-rest_)} au {fD(vD[-1])}') + '</td></tr>')
    dReu = datetime.date.fromisoformat(RE['date'])
    q4 = f"""<div class="page">
{hd}
<div class="sec">{H(RE['titre'])}</div>
<p class="s">Réunion du {dReu.day} {MOISL[dReu.month]} {dReu.year} · {H(RE['auteur'])}</p>
{('<div class="vignette">' + H(RE['vignette']) + '</div>') if RE.get('vignette') else ''}
{('<div class="effort"><div class="intro">' + H(RE['effort']['intro']) + '</div>' + ''.join(f'<div class="jr"><div class="k">Côté {H(c_["qui"])}</div><div class="t">{H(c_["titre"])}</div><div class="q">{H(c_["quoi"])}</div></div>' for c_ in RE['effort']['cotes']) + '</div>') if RE.get('effort') else ''}
<div class="sec petit" style="margin-top:3mm">Décisions</div>
<ul class="pts decisions">{''.join(f'<li>{H(t)}</li>' for t in RE['decisions'])}</ul>
{('<div class="sec petit" style="margin-top:3mm">Chaque semaine, jusqu’au ' + H(RE.get('rythmeJusqua', '')) + '</div><div class="rythme">' + ''.join(f'<div class="jr"><div class="k">{H(r_["jour"])}</div><div class="t">{H(r_["titre"])}</div><div class="q">{H(r_["quoi"])}</div></div>' for r_ in RE['rythme']) + '</div>') if RE.get('rythme') else ''}
<div class="deux g11 bas">
<div><div class="sec petit">Les versements sur le compte tiers, chaque lundi</div>
{lvv}
<div class="cadre" style="margin-top:4mm"><div class="k">Repères</div><ul>
<li>CA de la caisse, mars → septembre : {fE(caMois)} par mois. + 10&nbsp;% : {fE(caMois * 1.10)} ; + 15&nbsp;% : {fE(caMois * 1.15)}, soit {fP(caMois * 1.15 / obj, 0)} du budget de {fE(obj)}.</li>
<li>Nathan : {fE(C['nathanSemaine'])} par semaine, {fE(cN)} par mois, déjà compté dans le compte du mois (page 1).</li>
<li>Les versements sur le compte tiers : {fE(mV)} par semaine, ≈ {fE(arr(mV * 52 / 12, 10))} par mois.</li>
</ul></div></div>
<div><div class="sec petit">Ordre de remboursement</div>
<table class="t serre remb"><tr><th></th><th class="l">Créancier</th><th>Montant</th><th>Cumul</th><th class="l">Soldé le</th></tr>{lrr}</table>
{('<div class="sec petit" style="margin-top:4mm">Chez l’avocat, hors compte tiers</div><table class="t serre">' + ''.join(f'<tr><td class="l">{H(a_["dette"])}</td><td class="l">{H(a_.get("note", "à confier à l’avocat"))}</td><td>{fE(a_["montant"]) if a_.get("montant") else ""}</td></tr>' for a_ in RE.get('avocat', [])) + '</table>') if RE.get('avocat') else ''}
</div>
</div>
{ft(4)}
</div>"""

doc = f'<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Atelier by – Halle · tableau récap</title><style>{css}</style></head><body>{q1}{q2}{q3}{q4}</body></html>'

with tempfile.TemporaryDirectory() as tmp:
    f = os.path.join(tmp, 'recap.html'); open(f, 'w', encoding='utf-8').write(doc)
    if os.environ.get('RECAP_HTML'): open(os.environ['RECAP_HTML'], 'w', encoding='utf-8').write(doc)
    subprocess.run([os.environ.get('CHROMIUM', '/opt/pw-browsers/chromium'), '--headless', '--no-sandbox', '--disable-gpu', '--no-pdf-header-footer',
                    '--print-to-pdf=' + os.path.join(ICI, 'Halle-tableau-recap.pdf'), 'file://' + f], check=True, capture_output=True)
print('PDF · CA moyen', round(caMois), '· clients/jour', round(tkJour), '· panier', round(panier, 2), '· résultat après banque', round(COLS[0]['ap']), '· CA pour 0 €', round(COLS[1]['ca']), '· octobre', round(100 * oAtt), '%')


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
           ('Commandes sur ventes réelles (B2), % des achats', 0.08, PCT, JAUNE, ''),
           ('Hausse de CA du scénario', 0.15, PCT, JAUNE, 'les + 15 % visés'), ('Matière du scénario (% du CA)', 0.40, PCT, JAUNE, 'la cible du plan')]
    for i, (lib, v, fmt, fi, nt) in enumerate(par):
        rr = 5 + i
        cell(ce, f'A{rr}', lib); cell(ce, f'B{rr}', v, F_IN if not isinstance(v, str) else F_FX, fmt, fi); cell(ce, f'C{rr}', nt, F_S)
    # 5 Nathan · 6 remboursements · 7 semaines/mois · 8 jours/mois · 9 mois · 10 prix · 11 commandes · 12 + CA · 13 matière
    rT = 18
    cell(ce, f'A{rT}', 'Résultat visé après Nathan et la banque (€ par mois)', F_H)
    for col, t in zip('DEF', CIBLES): cell(ce, f'{col}{rT}', t, F_IN, EUR, JAUNE)
    h0 = rT + 1
    for i, h in enumerate(['Poste (€ par mois)', 'Analyse', '% CA', 'CA pour le résultat visé', 'CA pour le résultat visé', 'CA pour le résultat visé', 'Plan achats (B1 + B2)', '% CA', '+ 15 % de CA, matière 40 %', '% CA']):
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
    cell(ce, f'G{rCAc}', f'=B{rCAc}*(1+$B$10)', F_TOT, EUR); cell(ce, f'G{rAch}', f'=B{rAch}*(1-$B$11)', fmt=EUR); cell(ce, f'G{rEmb}', f'=B{rEmb}', fmt=EUR)
    cell(ce, f'I{rCAc}', f'=B{rCAc}*(1+$B$12)', F_TOT, EUR); cell(ce, f'I{rAch}', f'=I{rCAc}*$B$13', fmt=EUR); cell(ce, f'I{rEmb}', f'=IF(B{rCAc}>0,B{rEmb}/B{rCAc}*I{rCAc},0)', fmt=EUR)
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
