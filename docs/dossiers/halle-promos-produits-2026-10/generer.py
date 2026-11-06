# Halle, octobre 2026 : les 6 promotions du panel qui vendent le plus et les 6 produits les plus vendus (en pièces).
# Lit donnees.json (relevé de GET /api/cockpit/ventes/stats?shop=4&vue=mois&date=2026-10-31), écrit le classeur Excel
# (les deux classements, puis toutes les promotions, le détail de leurs produits et tous les produits) et le PDF A4
# (gabarit des documents de gestion, version A du design system, §13.4).
#   python3 generer.py        (le PDF passe par le Chromium de Playwright : node et playwright requis)
import json, html, datetime, base64, subprocess, tempfile, collections, pathlib
from openpyxl import Workbook, load_workbook
from openpyxl.styles import Font, Alignment, Border, Side
from openpyxl.utils import get_column_letter

ICI = pathlib.Path(__file__).resolve().parent
RACINE = ICI.parents[2]
NOM = 'Halle-promotions-produits-octobre-2026'
N = 6                                    # 6 promotions, 6 produits
d = json.load(open(ICI / 'donnees.json', encoding='utf-8'))
LU = datetime.datetime.fromisoformat(d['lu'])
T = d['totaux']; PR = d['promos']; CA = T['ca']
MOIS = 'octobre 2026'

# ---------- formats
def nf(v, dd=2):
    if v is None: return '—'
    return f'{v:,.{dd}f}'.replace(',', ' ').replace('.', ',')
eur = lambda v: '—' if v is None else nf(v) + ' €'
pct = lambda v, dd=1: '—' if v is None else nf(v * 100, dd) + ' %'
dfr = lambda s: datetime.date.fromisoformat(s).strftime('%d/%m/%Y')

# ---------- la TVA de chaque produit : celle de la liste des coefficients de Halle (6 % sinon)
TVA = {}
try:
    ws = load_workbook(RACINE / 'docs/dossiers/halle-produits-2026-10/Halle-produits-coefficients.xlsx', data_only=True)['Produits']
    for r in ws.iter_rows(min_row=5, values_only=True):
        if r[2] and r[4] is not None: TVA[r[2]] = float(r[4])
except Exception:
    pass

# ---------- les produits, portions regroupées sous leur produit (une tarte vendue entière, en moitiés ou en quarts)
NOMS = {}; CAT = {}
for c in d['categories']:
    for x in c['produits']:
        NOMS[str(x['id'])] = x['nom']; CAT[str(x['id'])] = (c['nom'], c['groupe'])
P = {}
for c in d['categories']:
    for x in c['produits']:
        k = str(x['id']); b = k.split(':')[0]; portion = ':' in k
        p = P.setdefault(b, dict(id=b, nom=None, cat=c['nom'], groupe=c['groupe'], q=0, qEntier=0, parts=collections.Counter(), v=0.0, c=0.0, sansCout=False))
        if not portion: p['nom'] = x['nom']; p['cat'] = c['nom']; p['groupe'] = c['groupe']
        elif p['nom'] is None: p['nomPortion'] = x['nom'].split(' — ')[0]
        p['q'] += x['q']; p['v'] += x['v']
        if portion: p['parts'][x['nom'].split(' — ')[-1]] += x['q']
        else: p['qEntier'] += x['q']
        if x['c'] is None: p['sansCout'] = True
        else: p['c'] += x['c']
for p in P.values():
    if p['nom'] is None: p['nom'] = p.get('nomPortion') or p['id']
    p['tva'] = TVA.get(p['nom'], 0.06)
    p['vht'] = p['v'] / (1 + p['tva'])
    p['marge'] = (p['vht'] - p['c']) / p['vht'] if p['vht'] > 0 and not p['sansCout'] else None
    p['prixMoyen'] = p['v'] / p['q'] if p['q'] else None
    p['part'] = p['v'] / CA if CA else None
LQ = sorted(P.values(), key=lambda p: (-p['q'], -p['v']))      # les plus vendus : au nombre de pièces
LV = sorted(P.values(), key=lambda p: -p['v'])                 # au chiffre d'affaires, pour comparer
for i, p in enumerate(LQ, 1): p['rangQ'] = i
for i, p in enumerate(LV, 1): p['rangCa'] = i
TOP = LQ[:N]; TOPV = LV[:N]
QTOT = sum(p['q'] for p in P.values())
def detail_parts(p):
    if not p['parts']: return ''
    return ', '.join([f"{p['qEntier']} entières"] + [f"{n} × {lib}" for lib, n in sorted(p['parts'].items())])

# ---------- les promotions : les règles déclenchées (par ventes), la remise non reconnue, celles restées sans usage
def lib_ligne(k): return NOMS.get(k) or NOMS.get(k.split(':')[0]) or k
PARP = collections.defaultdict(list)   # règle → [(clé, q, v, r, n)]
for k, m in PR['produits'].items():
    for rid, (q, v, r, n) in m.items(): PARP[str(rid)].append((k, q, v, r, n))
def resume(lignes, n=3):
    agg = collections.Counter()
    for k, q, v, r, _ in lignes: agg[lib_ligne(k)] += q
    return ', '.join(f"{nom} ({nf(q, 0)})" for nom, q in agg.most_common(n)) + (' …' if len(agg) > n else '')
PROMOS = []
for r in PR['regles']:
    lg = PARP.get(str(r['id']), [])
    PROMOS.append(dict(r, utilisee=True, taux=(r['r'] / (r['v'] + r['r'])) if (r['v'] + r['r']) else None,
                       remises=[x for x in lg if x[3] > 0.004], avec=[x for x in lg if x[3] <= 0.004], lignes=lg))
for a in PR['autres']:
    PROMOS.append(dict(a, utilisee=False, fois=0, tickets=0, q=0, v=0.0, r=0.0, taux=None, remises=[], avec=[], lignes=[]))
PROMOS.sort(key=lambda x: (0 if x['utilisee'] and x['id'] != 'x' else (1 if x['id'] == 'x' else 2), -x['v']))
PANEL = [x for x in PROMOS if x['id'] != 'x']
TOPP = [x for x in PANEL if x['utilisee']][:N]                 # les 6 promotions du panel qui vendent le plus
RESTE = [x for x in PANEL if x not in TOPP]
X = next((x for x in PROMOS if x['id'] == 'x'), None)
S6 = dict(fois=sum(x['fois'] for x in TOPP), q=sum(x['q'] for x in TOPP), v=sum(x['v'] for x in TOPP), r=sum(x['r'] for x in TOPP))

# chiffres repris dans le texte
cookies = [x for x in TOPP if 'ookie' in x['nom']]
ck = dict(fois=sum(x['fois'] for x in cookies), q=sum(x['q'] for x in cookies), v=sum(x['v'] for x in cookies), r=sum(x['r'] for x in cookies))
cher = max((x for x in TOPP if x['fois'] >= 10), key=lambda x: x['taux'] or 0)   # parmi celles qui ont vraiment servi
minis = [l for l in (X['lignes'] if X else []) if lib_ligne(l[0]).startswith('Mini - ') and l[1] >= 100]
mi = dict(q=sum(l[1] for l in minis), v=sum(l[2] for l in minis), r=sum(l[3] for l in minis))
topq = sum(p['q'] for p in TOP); topv = sum(p['v'] for p in TOP)
print('promotions du panel', len(PANEL), '· 6 premières', [x['nom'] for x in TOPP])
print('6 produits (pièces)', [p['nom'] for p in TOP], '· 6 au CA', [p['nom'] for p in TOPV])

# ---------- Excel
wb = Workbook()
F_T = Font(name='Arial', size=13, bold=True, color='8D1D2C'); F_S = Font(name='Arial', size=9, color='7A736A')
F_H = Font(name='Arial', size=8, bold=True, color='7A736A'); F_B = Font(name='Arial', size=9); F_BB = Font(name='Arial', size=9, bold=True)
BD = Border(bottom=Side(style='thin', color='E6E0D8')); BH = Border(bottom=Side(style='thin', color='1C1A17'))
def feuille(ws, titre, sous, entetes, lignes, largeurs, fmts, gauche, total=None):
    ws['A1'] = titre; ws['A1'].font = F_T
    ws['A2'] = sous; ws['A2'].font = F_S; ws['A2'].alignment = Alignment(wrap_text=True, vertical='top')
    ws.merge_cells(start_row=2, start_column=1, end_row=2, end_column=len(entetes)); ws.row_dimensions[2].height = 40
    for i, c in enumerate(entetes, 1):
        x = ws.cell(4, i, c); x.font = F_H; x.border = BH
        x.alignment = Alignment(horizontal='left' if i in gauche else 'right', vertical='bottom', wrap_text=True)
    ws.row_dimensions[4].height = 28
    r = 5
    for l in lignes + ([total] if total else []):
        for i, v in enumerate(l, 1):
            x = ws.cell(r, i, v); x.font = F_BB if (total and l is total) else F_B; x.border = BD
            x.alignment = Alignment(horizontal='left' if i in gauche else 'right', vertical='top', wrap_text=i in gauche)
            if i in fmts and isinstance(v, (int, float)): x.number_format = fmts[i]
        r += 1
    for i, w in enumerate(largeurs, 1): ws.column_dimensions[get_column_letter(i)].width = w
    ws.freeze_panes = 'C5'; ws.auto_filter.ref = f'A4:{get_column_letter(len(entetes))}{4 + len(lignes)}'
SOUS = (f'Atelier by – Halle, du {dfr(d["du"])} au {dfr(d["au"])} : tickets du panel lus jour par jour par le cockpit '
        f'({PR["joursLus"]} jours sur {d["nJoursOuverts"]}), le {LU.strftime("%d/%m/%Y à %H:%M")}. Montants TTC, après remise.')
REGLE = (' Une ligne remisée est rattachée à la promotion du panel dont la récompense explique la remise au centime près ; '
         'sinon elle reste « remise non reconnue ». Ventes = les pièces achetées et offertes du déclenchement, après remise.')
EPR = ['N°', 'Promotion', 'Règle du panel', 'Type', 'Statut', 'Déclenchée (fois)', 'Tickets', 'Pièces', 'Ventes TTC', 'Remise accordée', 'Taux de remise', 'Produits remisés', 'Achetés avec']
FPR = {6: '0', 7: '0', 8: '0', 9: '#,##0.00 €', 10: '#,##0.00 €', 11: '0.0 %'}
LPR = [5, 30, 40, 10, 9, 10, 8, 8, 11, 11, 9, 46, 46]
def ligne_promo(i, x):
    return [i, x['nom'], x['texte'], x['type'], x.get('statut') or '', x['fois'], x['tickets'], x['q'], x['v'], x['r'], x['taux'],
            resume(x['remises'], 6) if x['id'] != 'x' else resume(x['lignes'], 6), resume(x['avec'], 6) if x['id'] != 'x' else '']
ws = wb.active; ws.title = '6 promotions'
feuille(ws, f'Halle · les {N} promotions qui vendent le plus · {MOIS}', SOUS + REGLE, EPR, [ligne_promo(i, x) for i, x in enumerate(TOPP, 1)], LPR, FPR, {2, 3, 4, 5, 12, 13},
        total=['', f'Les {N} promotions', '', '', '', S6['fois'], None, S6['q'], S6['v'], S6['r'], S6['r'] / (S6['v'] + S6['r']), '', ''])
def ligne_p(p):
    return [p['rangQ'], p['nom'], p['cat'], p['groupe'], p['q'], detail_parts(p), p['v'], p['part'], p['prixMoyen'], p['c'] if not p['sansCout'] else None,
            p['tva'], p['marge'], p['rangCa']]
EP = ['Rang (pièces)', 'Produit', 'Catégorie', 'Groupe', 'Pièces vendues', 'Dont portions', 'CA TTC', 'Part du CA du mois', 'Prix moyen TTC', 'Coût matière HT', 'TVA', 'Marge brute', 'Rang (CA)']
FP = {1: '0', 5: '#,##0', 7: '#,##0.00 €', 8: '0.0 %', 9: '0.00 €', 10: '#,##0.00 €', 11: '0 %', 12: '0.0 %', 13: '0'}
LP = [8, 30, 22, 14, 9, 24, 11, 9, 9, 11, 6, 9, 9]
MARGE = ' Les portions (moitiés, quarts de tarte) comptent avec leur produit. Marge brute = (CA HT − coût matière HT des recettes du panel) ÷ CA HT.'
feuille(wb.create_sheet(f'{N} produits'), f'Halle · les {N} produits les plus vendus (en pièces) · {MOIS}', SOUS + MARGE, EP, [ligne_p(p) for p in TOP], LP, FP, {2, 3, 4, 6},
        total=['', f'Les {N} produits', '', '', topq, '', topv, topv / CA, None, None, None, None, None])
feuille(wb.create_sheet('Toutes les promotions'), f'Halle · toutes les promotions · {MOIS}',
        SOUS + REGLE + f' Les {len(PANEL)} promotions du panel actives à Halle, la remise non reconnue, puis celles restées sans usage.',
        EPR, [ligne_promo(i, x) for i, x in enumerate(PROMOS, 1)], LPR, FPR, {2, 3, 4, 5, 12, 13},
        total=['', 'Total, remises non reconnues comprises', f'{PR["tickets"]} tickets distincts', '', '', sum(x['fois'] for x in PROMOS), PR['tickets'], PR['q'], PR['v'], PR['r'],
               PR['r'] / (PR['v'] + PR['r']), '', ''])
lp = []
for x in PROMOS:
    for k, q, v, r, n in sorted(x['lignes'], key=lambda l: -l[1]):
        cat = CAT.get(k) or CAT.get(k.split(':')[0]) or ('', '')
        lp.append([x['nom'], lib_ligne(k), cat[0], 'remisé' if r > 0.004 else 'acheté', q, v, r, n])
feuille(wb.create_sheet('Promotions × produits'), f'Halle · les produits de chaque promotion · {MOIS}', SOUS + ' « Tickets » : les tickets où le produit est passé dans la promotion.',
        ['Promotion', 'Produit', 'Catégorie', 'Rôle', 'Pièces', 'Ventes TTC', 'Remise', 'Tickets'], lp,
        [30, 40, 22, 9, 8, 11, 10, 8], {5: '0', 6: '#,##0.00 €', 7: '#,##0.00 €', 8: '0'}, {1, 2, 3, 4})
feuille(wb.create_sheet('Tous les produits'), f'Halle · tous les produits vendus · {MOIS}',
        SOUS + MARGE + f' {len(LQ)} produits, triés par pièces vendues. Marge vide : une partie des ventes sans coût de recette au panel.',
        EP, [ligne_p(p) for p in LQ], LP, FP, {2, 3, 4, 6})
wb.save(ICI / f'{NOM}.xlsx'); print('xlsx écrit')

# ---------- HTML A4 (version A : Gotham seule, Ruby au filet d'en-tête et au filet des encadrés) → PDF
H = lambda s: html.escape(str(s))
FONTS = (RACINE / 'public/assets/ds/fonts').as_uri()
LOGO = 'data:image/png;base64,' + base64.b64encode(open(RACINE / 'public/assets/img/logo.png', 'rb').read()).decode()
css = f'''
@font-face{{font-family:'Gotham';src:url('{FONTS}/Gotham_Light.otf');font-weight:300;font-display:block}}
@font-face{{font-family:'Gotham';src:url('{FONTS}/Gotham_Medium.otf');font-weight:400;font-display:block}}
@font-face{{font-family:'Gotham';src:url('{FONTS}/Gotham_Regular.otf');font-weight:700;font-display:block}}
@page{{size:A4;margin:14mm 14mm 18mm}}
*{{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
body{{margin:0;font:400 11px/1.5 'Gotham',Helvetica,Arial,sans-serif;color:#1c1a17;background:#fff}}
.head{{display:flex;justify-content:space-between;align-items:flex-end;border-bottom:2px solid #8D1D2C;padding-bottom:12px;margin-bottom:18px}}
.head img{{height:34px;background:#fff}}
.type{{text-align:right;font-size:10px;color:#7a736a;line-height:1.5}}
.type b{{display:block;font-size:15px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#1c1a17}}
.label{{font-size:8.5px;letter-spacing:.07em;text-transform:uppercase;color:#7a736a;margin-bottom:3px}}
.refs{{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;padding:10px 0;border-top:1px solid #e6e0d8;border-bottom:1px solid #e6e0d8;margin-bottom:18px}}
.refs .v{{font-size:11px;font-weight:700}} .refs .s{{font-size:9.5px;color:#7a736a}}
h2{{font-size:13px;font-weight:700;margin:0 0 2px}}
.intro{{color:#7a736a;font-size:10px;margin:0 0 8px}}
table{{width:100%;border-collapse:collapse;font-size:10px}} thead{{display:table-header-group}}
th{{padding:5px 6px;border-bottom:1px solid #e6e0d8;font-size:8px;letter-spacing:.06em;text-transform:uppercase;color:#7a736a;font-weight:400;text-align:right;vertical-align:bottom}}
th.l,td.l{{text-align:left}}
td{{padding:5px 6px;border-bottom:1px solid rgba(34,34,34,.06);vertical-align:top;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}}
td.l{{white-space:normal}} td b{{font-weight:700}}
.sub{{display:block;font-size:9px;color:#7a736a;margin-top:1px;line-height:1.35}}
.n{{color:#7a736a;width:16px}}
tr{{break-inside:avoid}}
tr.tot td{{border-top:1px solid #e6e0d8;border-bottom:0;font-weight:700;padding-top:8px}}
.box{{border-left:3px solid #8D1D2C;padding:2px 0 2px 12px;margin:12px 0 0;break-inside:avoid}}
.box p{{margin:0 0 3px;font-size:10px;line-height:1.45}} .box p:last-child{{margin:0}}
.note{{font-size:9px;color:#7a736a;margin:14px 0 0;line-height:1.5}}
.bloc{{break-inside:avoid}}
'''
def sub_promo(x):
    s = [H(x['texte'])]
    parts = ([('Remisés : ' + H(resume(x['remises'])))] if x['remises'] else []) + ([('avec : ' + H(resume(x['avec'])))] if x['avec'] else [])
    if parts: s.append(' · '.join(parts))
    return '<span class="sub">' + '<br>'.join(s) + '</span>'
rows = ''.join(f'<tr><td class="l n">{i}</td><td class="l"><b>{H(x["nom"])}</b>{sub_promo(x)}</td><td>{nf(x["fois"], 0)}</td><td>{nf(x["tickets"], 0)}</td>'
               f'<td>{nf(x["q"], 0)}</td><td>{eur(x["v"])}</td><td>{eur(x["r"])}</td><td>{pct(x["taux"])}</td></tr>' for i, x in enumerate(TOPP, 1))
rows += (f'<tr class="tot"><td></td><td class="l">Les {N} promotions</td><td>{nf(S6["fois"], 0)}</td><td></td><td>{nf(S6["q"], 0)}</td>'
         f'<td>{eur(S6["v"])}</td><td>{eur(S6["r"])}</td><td>{pct(S6["r"] / (S6["v"] + S6["r"]))}</td></tr>')
prow = ''.join(f'<tr><td class="l n">{p["rangQ"]}</td><td class="l"><b>{H(p["nom"])}</b><span class="sub">{H(p["cat"])}{(" · " + H(detail_parts(p))) if p["parts"] else ""}</span></td>'
               f'<td>{nf(p["q"], 0)}</td><td>{eur(p["v"])}</td><td>{pct(p["part"])}</td><td>{eur(p["prixMoyen"])}</td><td>{pct(p["marge"])}</td><td>{p["rangCa"]}</td></tr>' for p in TOP)
prow += (f'<tr class="tot"><td></td><td class="l">Les {N} produits</td><td>{nf(topq, 0)}</td><td>{eur(topv)}</td><td>{pct(topv / CA)}</td>'
         f'<td></td><td>{pct(sum(p["vht"] - p["c"] for p in TOP) / sum(p["vht"] for p in TOP))}</td><td></td></tr>')
une = [x for x in RESTE if x['utilisee']]; zero = [x for x in RESTE if not x['utilisee']]
retenir_promos = [
    f'<b>{H(TOPP[0]["nom"])}</b> ({H(TOPP[0]["texte"])}) fait le plus de ventes : {nf(TOPP[0]["fois"], 0)} fois, {eur(TOPP[0]["v"])} pour {eur(TOPP[0]["r"])} de remise.',
    f'Les deux promotions <b>cookies</b> ensemble : {nf(ck["fois"], 0)} déclenchements, {nf(ck["q"], 0)} cookies, {eur(ck["v"])} de ventes pour {eur(ck["r"])} de remise.',
    f'<b>{H(cher["nom"])}</b> est la plus généreuse de celles qui servent : {eur(cher["r"])} de remise sur {eur(cher["v"] + cher["r"])} au prix plein, soit {pct(cher["taux"])}.',
    f'Les {len(RESTE)} autres promotions du panel ne pèsent presque rien : '
    + (', '.join(f'« {H(x["nom"])} » ({nf(x["fois"], 0)} fois)' for x in une) + ' ; ' if une else '')
    + ', '.join(f'« {H(x["nom"])} »' for x in zero) + ' jamais.',
]
if X and minis:
    retenir_promos.append(f'Hors promotion du panel, {eur(X["r"])} de <b>remises faites en caisse</b>, dont {eur(mi["r"])} sur des mini-viennoiseries vendues en grosses quantités '
                          f'({nf(mi["q"], 0)} pièces, {pct(mi["r"] / (mi["v"] + mi["r"]))} de remise).')
quitte = [p for p in TOP if p not in TOPV]; entre = [p for p in TOPV if p not in TOP]
retenir_prod = [
    f'Les {N} produits font {nf(topq, 0)} pièces, {pct(topq / QTOT)} des {nf(QTOT, 0)} pièces vendues dans le mois, et {eur(topv)} de chiffre ({pct(topv / CA)} du CA).',
]
if quitte:
    retenir_prod.append('Au chiffre d’affaires, ' + ', '.join(H(p['nom']) for p in quitte) + ' laisseraient la place à '
                        + ', '.join(f'<b>{H(p["nom"])}</b> ({eur(p["v"])})' for p in entre) + ' : des pains plus chers, vendus en moins grand nombre.')
petits = [p for p in TOP if p['prixMoyen'] is not None and p['prixMoyen'] < 1]
if petits:
    mg = sorted((p for p in TOP if p['marge'] is not None), key=lambda p: -p['marge'])[:len(petits)]
    retenir_prod.append(' et '.join(f'<b>{H(p["nom"])}</b>' for p in petits) + f' : {nf(sum(p["q"] for p in petits), 0)} pièces à moins de 1 €, '
                        f'{eur(sum(p["v"] for p in petits))} de chiffre'
                        + (', avec les meilleures marges du classement.' if set(map(id, mg)) == set(map(id, petits)) else '.'))
corps = f'''
<div class="head"><img src="{LOGO}" alt="L’Atelier by"><div class="type"><b>Promotions et produits</b>Atelier by – Halle · {MOIS} · lu le {LU.strftime("%d/%m/%Y")}</div></div>
<div class="refs">
 <div><div class="label">Période</div><div class="v">{dfr(d["du"])} → {dfr(d["au"])}</div><div class="s">{PR["joursLus"]} jours lus sur {d["nJoursOuverts"]}</div></div>
 <div><div class="label">Tickets</div><div class="v">{nf(T["tickets"], 0)}</div><div class="s">panier moyen {eur(T["panier"])}</div></div>
 <div><div class="label">CA TTC du mois</div><div class="v">{eur(CA)}</div><div class="s">{nf(QTOT, 0)} pièces, {len(LQ)} produits</div></div>
 <div><div class="label">Remises des promotions</div><div class="v">{eur(PR["r"])}</div><div class="s">{pct(PR["r"] / CA)} du CA, caisse comprise</div></div>
</div>
<section class="bloc">
<h2>Les {N} promotions qui vendent le plus</h2>
<p class="intro">Sur les {len(PANEL)} promotions du panel actives à Halle, classées par ventes. Une ligne de ticket remisée va à la promotion dont la récompense explique la remise au centime près. Ventes : pièces achetées et offertes, après remise ; taux : la remise sur le prix plein.</p>
<table><thead><tr><th class="l"></th><th class="l">Promotion</th><th>Fois</th><th>Tickets</th><th>Pièces</th><th>Ventes TTC</th><th>Remise</th><th>Taux</th></tr></thead><tbody>{rows}</tbody></table>
<div class="box"><div class="label">À retenir</div>{"".join(f"<p>{t}</p>" for t in retenir_promos)}</div>
</section>
<section class="bloc">
<h2 style="margin-top:20px">Les {N} produits les plus vendus</h2>
<p class="intro">Classés au nombre de pièces vendues dans le mois. Les portions (moitiés, quarts de tarte) comptent avec leur produit. Marge brute : (CA HT − coût matière HT des recettes du panel) ÷ CA HT. Rang au CA : la place du produit au chiffre d’affaires.</p>
<table><thead><tr><th class="l"></th><th class="l">Produit</th><th>Pièces</th><th>CA TTC</th><th>Part du CA</th><th>Prix moyen</th><th>Marge brute</th><th>Rang au CA</th></tr></thead><tbody>{prow}</tbody></table>
<div class="box"><div class="label">À retenir</div>{"".join(f"<p>{t}</p>" for t in retenir_prod)}</div>
</section>
<p class="note">Source : les tickets du panel de Halle, lus jour par jour par le cockpit (GET /api/cockpit/ventes/stats?shop=4&amp;vue=mois&amp;date=2026-10-31) le {LU.strftime("%d/%m/%Y à %H:%M")} ; les règles de promotion sont lues au panel. Montants TTC après remise. Le classeur Excel joint reprend les deux classements, toutes les promotions avec le détail de leurs produits, et tous les produits vendus.</p>
'''
doc = f'<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Halle · promotions et produits · {MOIS}</title><style>{css}</style></head><body>{corps}</body></html>'
with tempfile.TemporaryDirectory() as tmp:
    src = pathlib.Path(tmp) / 'doc.html'; src.write_text(doc, encoding='utf-8')
    pied = ('<div style="width:100%;margin:0 14mm;padding-top:6px;border-top:1px solid #e6e0d8;font:8.5px Helvetica,Arial,sans-serif;color:#7a736a;'
            'display:flex;justify-content:space-between"><span>Atelier by – Halle · promotions et produits · ' + MOIS + ' · tickets du panel lus le '
            + LU.strftime('%d/%m/%Y') + '</span><span>Page <span class="pageNumber"></span> / <span class="totalPages"></span></span></div>')
    js = ("const {chromium}=require('playwright');(async()=>{const b=await chromium.launch();const p=await b.newPage();"
          "await p.goto('file://'+process.argv[1]);await p.evaluate(()=>document.fonts.ready);"
          "await p.pdf({path:process.argv[2],format:'A4',printBackground:true,preferCSSPageSize:true,displayHeaderFooter:true,"
          "headerTemplate:'<span></span>',footerTemplate:process.argv[3]});await b.close();})();")
    subprocess.run(['node', '-e', js, str(src), str(ICI / f'{NOM}.pdf'), pied], check=True)
print('pdf écrit')
