# Le classeur Excel du dossier Halle, version 2 : les six feuilles de la v1 (mêmes chiffres), la note des travaux du viaduc sur juin
# et juillet, et une feuille « Food cost P&L » (lecture ticket par ticket du cockpit, semaine par semaine et mois par mois).
import json, glob
from openpyxl import Workbook
from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
from openpyxl.utils import get_column_letter
SP = '/tmp/claude-0/-home-user-consultant-BO/64c06f7c-f933-5fd1-a986-9b6771ad42a2/scratchpad'
V1 = json.load(open(SP + '/halle/xlsx-v1-dump.json'))
wb = Workbook(); wb.remove(wb.active)
F_T = Font(name='Arial', size=12, bold=True, color='8D1D2C'); F_S = Font(name='Arial', size=9, color='7A736A'); F_H = Font(name='Arial', size=9, bold=True); F_B = Font(name='Arial', size=9)
FILL_H = PatternFill('solid', fgColor='F3EEE8'); thin = Side(style='thin', color='E6E0D8'); BD = Border(bottom=thin)
def feuille(titre, rows, widths, fmts, entete, notes=None, extra_col=None):
    ws = wb.create_sheet(titre)
    for r, row in enumerate(rows, 1):
        for c, v in enumerate(row, 1):
            if v is None: continue
            x = ws.cell(r, c, v)
            x.font = F_T if r == 1 else (F_H if r == entete else (F_S if r < entete else F_B))
            if r == entete: x.fill = FILL_H
            if r > entete: x.border = BD
            coord = f'{get_column_letter(c)}{r}'
            if coord in fmts: x.number_format = fmts[coord]
            x.alignment = Alignment(horizontal='left' if c == 1 or isinstance(v, str) else 'right', vertical='top', wrap_text=(r == 2))
    if notes:
        col = len(rows[entete - 1]) + 1
        h = ws.cell(entete, col, 'Note'); h.font = F_H; h.fill = FILL_H
        for r, txt in notes.items(): x = ws.cell(r, col, txt); x.font = F_B; x.border = BD
        ws.column_dimensions[get_column_letter(col)].width = 46
    for k, w in widths.items(): ws.column_dimensions[k].width = w
    ws.row_dimensions[2].height = 30 if rows[1][0] else None
    if rows[1][0]: ws.merge_cells(start_row=2, start_column=1, end_row=2, end_column=max(4, len(rows[entete - 1])))
    return ws
# 1. Mois par mois (+ note juin / juillet)
m = V1['Mois par mois']; rows = m['rows']
rows[1][0] = rows[1][0] + ' Juin et juillet 2026 : travaux sur le viaduc de Halle, effet estimé − 20 % (information du réseau, pas une mesure).'
notes = {}
for i, row in enumerate(rows, 1):
    if row[0] in ('Juin', 'Juillet'): notes[i] = 'Travaux sur le viaduc de Halle : effet estimé − 20 % ; sans eux, environ ' + f'{round(row[2] / 0.8):,}'.replace(',', ' ') + ' €'
ws = feuille('Mois par mois', rows, m['widths'], m['fmts'], 4, notes); ws.freeze_panes = 'B5'
# 2 à 6 : à l'identique
for titre in ('Face au réseau', 'Modèle de charges', 'Assortiment (sept.)', 'Semaine type', 'Qualité T3 2026'):
    x = V1[titre]; feuille(titre, x['rows'], x['widths'], x['fmts'], 4 if titre == 'Modèle de charges' else 3)
# 7. Food cost P&L du cockpit
def H(f):
    d = json.load(open(f)); l = [x for x in d['magasins'] if x.get('shopId') == '4'][0]; r = d.get('reseau') or {}
    return d, l, r
rows = [['Le food cost lu dans le P&L du cockpit (recettes vendues, ticket par ticket) — Halle et réseau', None, None, None, None, None, None, None],
        ['Depuis le 1er août 2026, le cockpit lit chaque ticket et recompose le coût matière des recettes vendues au coût de recette du jour. Avant août, les tickets ne sont pas lus : le dossier utilise la marge mesurée par le panel (feuille Mois par mois). Les deux mesures diffèrent : voir la colonne de comparaison.', None, None, None, None, None, None, None],
        [None] * 8, ['Période', 'Du', 'Au', 'CA Halle', 'Matière Halle (P&L)', 'Food cost Halle (P&L)', 'Food cost réseau (P&L)', 'Food cost Halle (panel, dossier)']]
perf = json.load(open(SP + '/mat/perf.json')); perf = perf if isinstance(perf, list) else perf.get('perf') or perf.get('data')
pH = {r['mois']: r for r in perf if str(r['storeId']) == '4' and r['annee'] == 2026}
for f in sorted(glob.glob(SP + '/halle/moisPL/2026-*.json')):
    d, l, r = H(f); mm = int(d['du'][5:7])
    lu = str(l.get('coutMatiereSource') or '').startswith('recettes vendues')
    rows.append([f'Mois {d["du"][:7]}', d['du'], d['jusqua'] or d['au'], l.get('realise'), l.get('coutMatiere') if lu else None, (l['coutMatierePct'] / 100) if lu and l.get('coutMatierePct') is not None else 'tickets non lus', (r['coutMatierePct'] / 100) if lu and r.get('coutMatierePct') else None, (pH.get(mm, {}).get('foodCostPct') or 0) / 100 or None])
for f in sorted(glob.glob(SP + '/halle/sem/2026-*.json')):
    d, l, r = H(f)
    rows.append([f'Semaine du {d["du"]}', d['du'], d['jusqua'] or d['au'], l.get('realise'), l.get('coutMatiere'), (l['coutMatierePct'] / 100) if l.get('coutMatierePct') is not None else None, (r['coutMatierePct'] / 100) if r.get('coutMatierePct') else None, None])
fm = {}
for i in range(5, len(rows) + 1):
    fm[f'D{i}'] = '#,##0 €'; fm[f'E{i}'] = '#,##0 €'; fm[f'F{i}'] = '0.0 %'; fm[f'G{i}'] = '0.0 %'; fm[f'H{i}'] = '0.0 %'
feuille('Food cost P&L', rows, {'A': 24, 'B': 12, 'C': 12, 'D': 12, 'E': 16, 'F': 18, 'G': 18, 'H': 24}, fm, 4)
wb.save(SP + '/halle/Halle-chiffres-2026.xlsx'); print('xlsx v2 écrit :', [w.title for w in wb.worksheets])
