"""Le classeur des prix, marges et ventes des produits du réseau (20/11/2026).

    python3 -I excel_prix.py <dossier des réponses du cockpit> <prix-produits.json> <sortie.xlsx> [tous]

Sans « tous » : les 50 produits les plus vendus. Avec : tous les produits — les 400 de la grille du cockpit (elle
s'arrête là : le 400e fait 60 pièces en 12 mois dans le réseau), puis le reste des tarifs, sans ventes mois par mois.
"""
import json, os, sys
from openpyxl import Workbook
from openpyxl.styles import Font, Alignment, PatternFill, Border, Side
from openpyxl.utils import get_column_letter as L
from openpyxl.comments import Comment

src_dir, prix_json, sortie = sys.argv[1], sys.argv[2], sys.argv[3]
TOUS = len(sys.argv) > 4 and sys.argv[4] == 'tous'
G = json.load(open(os.path.join(src_dir, 'grille12.json')))
P = json.load(open(prix_json))['produits']
T = {s: {p['id']: p for p in json.load(open(os.path.join(src_dir, f'tarif-{s}.json')))['produits']} for s in ('2', '3', '4', '5')}
GP = {p['pid']: p for p in G['produits']}
if TOUS:
    # Tous les produits : la grille par volume, puis les tarifs hors grille, par catégorie et par nom.
    P = [{'pid': p['pid'], 'nom': p['nom'], 'cat': p['cat'], 'total': p['total']} for p in sorted(G['produits'], key=lambda p: -p['total'])]
    vus = {p['pid'] for p in P}
    hors = {}
    for s in ('5', '2', '3', '4'):
        for pid, x in T[s].items():
            if pid not in vus and pid not in hors:
                hors[pid] = {'pid': pid, 'nom': x['nom'], 'cat': x.get('cat') or '', 'total': None}
    P += sorted(hors.values(), key=lambda p: (p['cat'], p['nom']))
NGRILLE = sum(1 for p in P if p['pid'] in GP)
MAG = [('2', 'Corbais'), ('3', 'Gosselies'), ('4', 'Halle'), ('5', 'Sombreffe')]
MOIS = [t.replace('Jan', 'Janv.').replace('Fév', 'Févr.').replace('Avr', 'Avr.').replace('Juil', 'Juil.').replace('Sep', 'Sept.').replace('Oct', 'Oct.').replace('Nov', 'Nov.').replace('Déc', 'Déc.').replace('Mar ', 'Mars ').replace('Mai', 'Mai').replace('Juin', 'Juin') for t in G['tranches']]
NM = len(MOIS)                     # 12 : déc. 2025 → nov. 2026 (le dernier en cours)
N0, N1 = 5, 5 + len(P) - 1         # lignes des produits

F = 'Arial'
f_norm, f_bold = Font(name=F, size=10), Font(name=F, size=10, bold=True)
f_in, f_link = Font(name=F, size=10, color='0000FF'), Font(name=F, size=10, color='008000')
f_titre, f_sous = Font(name=F, size=14, bold=True, color='8D1D2C'), Font(name=F, size=9, italic=True, color='7A736A')
f_head = Font(name=F, size=9, bold=True, color='1C1A17')
fill_head, fill_grp, fill_moi = PatternFill('solid', fgColor='F3EEE7'), PatternFill('solid', fgColor='E9E1D6'), PatternFill('solid', fgColor='FBF3F4')
fill_tot = PatternFill('solid', fgColor='F8F4EE')
fin = Side(style='thin', color='D9D2C8')
bord = Border(bottom=fin)
EUR, EUR0, PCT, NB = '#,##0.00 "€";-#,##0.00 "€";"-"', '#,##0 "€";-#,##0 "€";"-"', '0.0%;-0.0%;"-"', '#,##0;-#,##0;"-"'
centre = Alignment(horizontal='center', vertical='center', wrap_text=True)

def tete(ws, titre, sous):
    ws['A1'] = titre; ws['A1'].font = f_titre
    ws['A2'] = sous; ws['A2'].font = f_sous
    ws.sheet_view.showGridLines = False

def entete(ws, row, col, txt, fill=fill_head):
    c = ws.cell(row=row, column=col, value=txt); c.font = f_head; c.fill = fill; c.alignment = centre; c.border = bord
    return c

def produit_cols(ws, row, i, p):
    i = i if p['pid'] in GP else None            # le rang : celui des ventes du réseau, dans la grille
    for col, v in ((1, i), (2, p['nom']), (3, p['cat'])):
        c = ws.cell(row=row, column=col, value=v); c.font = f_norm
    ws.cell(row=row, column=1).alignment = Alignment(horizontal='center')

wb = Workbook()

# --- 1. Tarifs (les entrées) : par magasin, prix TVAC, TVA, prix d'achat HT ; le prix HTVA calculé. ---
ws = wb.active; ws.title = 'Tarifs'
tete(ws, 'Tarifs des quatre magasins — ' + (f'tous les produits ({len(P)})' if TOUS else 'les 50 produits les plus vendus du réseau'),
     'Entrées en bleu : prix TVAC et TVA de la caisse, prix d’achat HT (coût de recette : matière + emballage). Le prix HTVA est calculé. Source : cockpit, lu le 20/11/2026.')
for k, t in enumerate(('#', 'Produit', 'Catégorie', 'PID')):
    entete(ws, 4, k + 1, t); ws.merge_cells(start_row=3, start_column=k + 1, end_row=4, end_column=k + 1) if False else None
TCOL = {}
for i, (s, nom) in enumerate(MAG):
    b = 5 + i * 4
    TCOL[s] = {'ttc': b, 'tva': b + 1, 'achat': b + 2, 'ht': b + 3}
    ws.merge_cells(start_row=3, start_column=b, end_row=3, end_column=b + 3)
    entete(ws, 3, b, nom, fill_grp)
    for k, t in enumerate(('Prix TVAC', 'TVA', 'Prix d’achat HT', 'Prix de vente HTVA')):
        entete(ws, 4, b + k, t)
for r, p in enumerate(P, N0):
    produit_cols(ws, r, r - N0 + 1, p)
    ws.cell(row=r, column=4, value=p['pid']).font = f_norm
    for s, _ in MAG:
        t, c = T[s].get(p['pid']), TCOL[s]
        if t and t.get('prixTtc') is not None:
            x = ws.cell(row=r, column=c['ttc'], value=t['prixTtc']); x.font = f_in; x.number_format = EUR
            x = ws.cell(row=r, column=c['tva'], value=(t['tva'] or 0) / 100); x.font = f_in; x.number_format = '0%'
        if t and t.get('cout') is not None:
            x = ws.cell(row=r, column=c['achat'], value=round(t['cout'], 4)); x.font = f_in; x.number_format = EUR
        a, v = L(c['ttc']), L(c['tva'])
        x = ws.cell(row=r, column=c['ht'], value=f'=IF({a}{r}="","",{a}{r}/(1+{v}{r}))'); x.font = f_norm; x.number_format = EUR
ws.column_dimensions['A'].width = 4; ws.column_dimensions['B'].width = 34; ws.column_dimensions['C'].width = 20; ws.column_dimensions['D'].width = 9
for col in range(5, 21): ws.column_dimensions[L(col)].width = 11
ws.row_dimensions[4].height = 30
ws.freeze_panes = 'E5'
ws.auto_filter.ref = f'A4:T{N1}'

# --- 2. Ventes par mois (les entrées) : les pièces de chaque magasin, mois par mois. ---
wv = wb.create_sheet('Ventes par mois')
tete(wv, 'Pièces vendues par mois — ' + ('tous les produits' if TOUS else 'les 50 produits') + ', les quatre magasins',
     f'Entrées en bleu. {MOIS[0]} → {MOIS[-1]} ; {MOIS[-1]} : {G["jours"][-1]} jours (mois en cours). Source : cockpit, /analyse/produits?mois=12, lu le 20/11/2026.')
entete(wv, 4, 1, '#'); entete(wv, 4, 2, 'Produit')
VCOL = {}
for i, (s, nom) in enumerate(MAG):
    b = 3 + i * NM
    VCOL[s] = b
    wv.merge_cells(start_row=3, start_column=b, end_row=3, end_column=b + NM - 1)
    entete(wv, 3, b, nom, fill_grp)
    for k, m in enumerate(MOIS):
        entete(wv, 4, b + k, m + ('*' if k == NM - 1 else ''))
for r, p in enumerate(P, N0):
    wv.cell(row=r, column=1, value=(r - N0 + 1) if p['pid'] in GP else None).font = f_norm
    wv.cell(row=r, column=2, value=p['nom']).font = f_norm
    if p['pid'] not in GP:
        continue                                   # hors de la grille du cockpit : pas de ventes mois par mois
    ps = GP[p['pid']]['parShop']
    for s, _ in MAG:
        v = ps.get(s) or [0] * NM
        for k in range(NM):
            x = wv.cell(row=r, column=VCOL[s] + k, value=v[k]); x.font = f_in; x.number_format = NB
wv.column_dimensions['A'].width = 4; wv.column_dimensions['B'].width = 34
for col in range(3, 3 + 4 * NM): wv.column_dimensions[L(col)].width = 8.5
wv.row_dimensions[4].height = 28
wv.freeze_panes = 'C5'
def clos(s, r):     # les 11 mois clos d'un magasin, sur la ligne r
    return f"'Ventes par mois'!{L(VCOL[s])}{r}:{L(VCOL[s] + NM - 2)}{r}"
def tout(r):
    return f"'Ventes par mois'!{L(VCOL['2'])}{r}:{L(VCOL['5'] + NM - 1)}{r}"

# --- 3. Prix et marges : Sombreffe. ---
wp = wb.create_sheet('Prix et marges', 0)
tete(wp, 'Sombreffe — prix, marge brute et ventes ' + (f'de tous les produits ({len(P)})' if TOUS else 'des 50 produits les plus vendus du réseau'),
     'Prix HTVA. Marge brute = prix de vente HTVA − prix d’achat HT. Ventes moyennes : la moyenne des mois clos (déc. 2025 → oct. 2026) où le produit s’est vendu. Noir : calculé ; vert : lien vers un autre onglet.')
H = ['#', 'Produit', 'Catégorie', 'Pièces réseau, 12 mois',
     'Corbais prix HTVA', 'Gosselies prix HTVA', 'Halle prix HTVA', 'Sombreffe prix HTVA', 'Médiane des magasins', 'Écart Sombreffe vs médiane',
     'Sombreffe prix d’achat HT', 'Marge brute € / pièce', 'Marge brute %', 'Ventes moyennes / mois (mois vendus)', 'Ventes / mois (moyenne des 11 mois)',
     'CA HTVA / mois', 'Marge brute / mois', 'Manque à gagner / mois au prix médian']
ws3 = wp
ws3.merge_cells('E3:J3'); entete(ws3, 3, 5, 'Prix de vente HTVA', fill_grp)
ws3.merge_cells('K3:R3'); entete(ws3, 3, 11, 'Sombreffe : marge et ventes', fill_grp)
for k, t in enumerate(H): entete(ws3, 4, k + 1, t)
for r, p in enumerate(P, N0):
    produit_cols(ws3, r, r - N0 + 1, p)
    x = ws3.cell(row=r, column=4, value=f'=IF(COUNT({tout(r)})=0,"",SUM({tout(r)}))'); x.font = f_link; x.number_format = NB
    for k, (s, _) in enumerate(MAG):
        x = ws3.cell(row=r, column=5 + k, value=f"=Tarifs!{L(TCOL[s]['ht'])}{r}"); x.font = f_link; x.number_format = EUR
        if s == '5': x.fill = fill_moi
    f = [
        (9, f'=IF(COUNT(E{r}:H{r})=0,"",MEDIAN(E{r}:H{r}))', EUR, f_norm),
        (10, f'=IF(OR(H{r}="",I{r}=""),"",H{r}/I{r}-1)', PCT, f_norm),
        (11, f'=IF(Tarifs!{L(TCOL["5"]["achat"])}{r}="","",Tarifs!{L(TCOL["5"]["achat"])}{r})', EUR, f_link),
        (12, f'=IF(OR(H{r}="",K{r}=""),"",H{r}-K{r})', EUR, f_norm),
        (13, f'=IF(L{r}="","",L{r}/H{r})', PCT, f_norm),
        (14, f'=IFERROR(AVERAGEIF({clos("5", r)},">0"),"")', '#,##0', f_link),
        (15, f'=IF(COUNT({clos("5", r)})=0,"",SUM({clos("5", r)})/COLUMNS({clos("5", r)}))', '#,##0', f_link),
        (16, f'=IF(OR(H{r}="",O{r}=""),"",O{r}*H{r})', EUR0, f_norm),
        (17, f'=IF(OR(L{r}="",O{r}=""),"",O{r}*L{r})', EUR0, f_norm),
        (18, f'=IF(OR(J{r}="",O{r}=""),"",IF(J{r}<0,(I{r}-H{r})*O{r},0))', EUR0, f_norm),
    ]
    for col, form, fmt, font in f:
        x = ws3.cell(row=r, column=col, value=form); x.font = font; x.number_format = fmt
rt = N1 + 1
ws3.cell(row=rt, column=2, value=('Total des ' + str(len(P)) + ' produits') if TOUS else 'Total des 50 produits').font = f_bold
for col, form, fmt in ((15, f'=SUM(O{N0}:O{N1})', '#,##0'), (16, f'=SUM(P{N0}:P{N1})', EUR0), (17, f'=SUM(Q{N0}:Q{N1})', EUR0), (18, f'=SUM(R{N0}:R{N1})', EUR0),
                       (13, f'=IF(SUMPRODUCT(--(Q{N0}:Q{N1}<>""),P{N0}:P{N1})=0,"",SUM(Q{N0}:Q{N1})/SUMPRODUCT(--(Q{N0}:Q{N1}<>""),P{N0}:P{N1}))', PCT)):
    x = ws3.cell(row=rt, column=col, value=form); x.font = f_bold; x.number_format = fmt
for col in range(1, 19): ws3.cell(row=rt, column=col).fill = fill_tot
ws3.cell(row=rt, column=13).comment = Comment('Marge brute moyenne pondérée par le CA, sur les produits qui ont un prix d’achat.', 'Cockpit')
ws3.cell(row=rt + 2, column=2, value='Notes').font = f_bold
for k, n in enumerate([
    'Prix de vente : le tarif de la caisse de chaque magasin, ramené hors TVA (onglet Tarifs). Vide : pas à la carte du magasin.',
    'Prix d’achat : le coût de recette HT du panel (matière + emballage) pour une pièce, à Sombreffe. Vide : pas de recette au panel.',
    'Ventes : « mois vendus » = la moyenne des mois clos où le produit s’est vendu (son rythme) ; « 11 mois » = le total des mois clos ÷ 11 — c’est elle qui sert au CA, à la marge et au manque à gagner, pour que les totaux s’additionnent.',
    'Manque à gagner : ce qu’apporterait le prix médian du réseau, à volume égal, quand Sombreffe est en dessous.',
    'Traiteur (à la personne) et Fromage à la pièce / à la coupe : un prix de 1 € l’unité, pas un prix de produit.',
    *( [f'Lignes {N0 + NGRILLE} à {N1} (sans rang) : les produits des tarifs hors de la grille du cockpit — moins de 60 pièces en 12 mois dans le réseau, ou pas vendus ; pas de ventes mois par mois.'] if TOUS and NGRILLE < len(P) else [] )]):
    ws3.cell(row=rt + 3 + k, column=2, value=n).font = Font(name=F, size=9, color='5C5852')
widths = [4, 34, 20, 11, 10, 10, 10, 10, 10, 10, 10, 10, 9, 11, 11, 11, 11, 13]
for k, w in enumerate(widths): ws3.column_dimensions[L(k + 1)].width = w
ws3.row_dimensions[4].height = 42
ws3.freeze_panes = 'D5'
ws3.auto_filter.ref = f'A4:R{N1}'

# --- 4. Réseau : les quatre magasins côte à côte — prix HTVA, prix d'achat, marge brute %, ventes moyennes / mois. ---
wr = wb.create_sheet('Réseau', 1)
tete(wr, 'Les quatre magasins — prix, prix d’achat, marge brute et ventes moyennes par mois',
     'Prix HTVA ; marge brute % = (prix HTVA − prix d’achat HT) ÷ prix HTVA ; ventes moyennes : la moyenne des mois clos où le produit s’est vendu.')
for k, t in enumerate(('#', 'Produit', 'Catégorie')): entete(wr, 4, k + 1, t)
for i, (s, nom) in enumerate(MAG):
    b = 4 + i * 4
    wr.merge_cells(start_row=3, start_column=b, end_row=3, end_column=b + 3)
    entete(wr, 3, b, nom, fill_grp)
    for k, t in enumerate(('Prix HTVA', 'Prix d’achat HT', 'Marge brute %', 'Ventes / mois (mois vendus)')): entete(wr, 4, b + k, t)
    for r, p in enumerate(P, N0):
        if i == 0: produit_cols(wr, r, r - N0 + 1, p)
        pv, pa = L(b), L(b + 1)
        cells = [(b, f"=Tarifs!{L(TCOL[s]['ht'])}{r}", EUR, f_link),
                 (b + 1, f'=IF(Tarifs!{L(TCOL[s]["achat"])}{r}="","",Tarifs!{L(TCOL[s]["achat"])}{r})', EUR, f_link),
                 (b + 2, f'=IF(OR({pv}{r}="",{pa}{r}=""),"",({pv}{r}-{pa}{r})/{pv}{r})', PCT, f_norm),
                 (b + 3, f'=IFERROR(AVERAGEIF({clos(s, r)},">0"),"")', '#,##0', f_link)]
        for col, form, fmt, font in cells:
            x = wr.cell(row=r, column=col, value=form); x.font = font; x.number_format = fmt
            if s == '5': x.fill = fill_moi
wr.column_dimensions['A'].width = 4; wr.column_dimensions['B'].width = 34; wr.column_dimensions['C'].width = 20
for col in range(4, 20): wr.column_dimensions[L(col)].width = 10
wr.row_dimensions[4].height = 30
wr.freeze_panes = 'D5'
wr.auto_filter.ref = f'A4:{L(3 + 4 * 4)}{N1}'

# --- 5. Notes et sources. ---
wn = wb.create_sheet('Notes')
tete(wn, 'Sources et notes', '')
for k, n in enumerate([
    'Les 50 produits : les plus vendus du réseau en pièces, décembre 2025 → novembre 2026 (au 20/11), les quatre magasins ensemble.',
    'Pièces par mois : cockpit, GET /analyse/produits?mois=12 (la grille « Par référence »), lu le 20/11/2026.',
    'Tarifs et prix d’achat : cockpit, GET /exploitation/promos-magasin/produits?shop=… (le tarif de la caisse, la TVA, le coût de recette du panel), lu le 20/11/2026.',
    'Prix d’achat : le coût de recette HT d’une pièce (matière + emballage), tel que le panel le calcule aujourd’hui ; ce n’est pas une facture fournisseur.',
    'Couleurs : bleu = donnée d’entrée (modifiable) ; noir = calcul ; vert = lien vers un autre onglet. Modifier un prix ou un prix d’achat dans Tarifs recalcule tout.',
    'Magasins : Corbais (Atelier by Berlo), Gosselies (Atelier by Max & Sandra), Halle, Sombreffe (Atelier by Harmonie).']):
    wn.cell(row=3 + k, column=1, value=n).font = f_norm
wn.column_dimensions['A'].width = 140

# --- 6. La proposition +5 % de marge brute (20/11/2026, Sam : « gagner +5 % en marge brute sans toucher aux produits les
#     plus vendus »). Le top 20 de Sombreffe (ventes des 11 mois clos) ne bouge pas. Sur les autres, dans l'ordre :
#     1. aligner sur la médiane du réseau ce que Sombreffe vend moins cher (+15 % au plus) ;
#     2. remonter à 50 % de marge ce qui est en dessous (+10 % au plus) ;
#     3. +3 % sur le reste de la carte.
#     Le nouveau prix TVAC s'arrondit aux 10 centimes au-dessus. Volume égal. Coût plus haut que le prix : à vérifier, à part.
if TOUS:
    import math
    up = lambda x: math.ceil(round(x / 0.10, 6)) * 0.10
    CAP_ALIGNE, CIBLE, CAP_MARGE, GEN, NTOP = 0.15, 0.50, 0.10, 0.03, 20
    EXCLUS = {'Sandwich du Jour'}            # pas le même produit d'un magasin à l'autre
    lignesP = []
    for idx, p in enumerate(P):
        r = N0 + idx
        t5 = T['5'].get(p['pid'])
        v = (GP[p['pid']]['parShop'].get('5') or [0] * NM)[:NM - 1] if p['pid'] in GP else None
        vol = sum(v) / len(v) if v else 0
        ht = {s: (T[s][p['pid']]['prixTtc'] / (1 + (T[s][p['pid']]['tva'] or 0) / 100)) for s, _ in MAG
              if p['pid'] in T[s] and T[s][p['pid']].get('prixTtc') is not None}
        lignesP.append({'r': r, 'p': p, 't5': t5, 'vol': vol, 'ht': ht})
    vendus = sorted([x for x in lignesP if x['vol'] > 0], key=lambda x: -x['vol'])
    top = vendus[:NTOP]; topR = {x['r'] for x in top}
    rangS = {x['r']: k + 1 for k, x in enumerate(vendus)}
    prop, anomalies = [], []
    for x in vendus:
        t5, p = x['t5'], x['p']
        if x['r'] in topR or p['nom'] in EXCLUS or not t5 or t5.get('prixTtc') is None or t5.get('cout') is None:
            continue
        tva = (t5['tva'] or 0) / 100; pr = t5['prixTtc'] / (1 + tva); c = t5['cout']
        if c >= pr:
            anomalies.append(x); continue
        meds = sorted(x['ht'].values()); n = len(meds)
        med = (meds[(n - 1) // 2] + meds[n // 2]) / 2 if n else None
        nv, motif = pr, None
        if med and pr < med * 0.995:
            nv, motif = min(med, pr * (1 + CAP_ALIGNE)), 'aligner sur le réseau'
        if (nv - c) / nv < CIBLE:
            n2 = min(c / (1 - CIBLE), pr * (1 + CAP_MARGE))
            if n2 > nv:
                nv, motif = n2, (motif + ' + marge' if motif else f'marge sous {CIBLE * 100:.0f} %')
        if motif is None:
            nv, motif = pr * (1 + GEN), f'+{GEN * 100:.0f} % sur le reste'
        nttc = round(up(nv * (1 + tva)), 2)
        if nttc > t5['prixTtc'] + 0.001:
            prop.append((x, motif, nttc))
    prop.sort(key=lambda z: -((z[2] / (1 + (z[0]['t5']['tva'] or 0) / 100)) - z[0]['t5']['prixTtc'] / (1 + (z[0]['t5']['tva'] or 0) / 100)) * z[0]['vol'])

    wq = wb.create_sheet('Proposition +5 %', 1)
    tete(wq, 'Sombreffe — gagner +5 % de marge brute sans toucher aux produits les plus vendus',
         f'Le top {NTOP} de Sombreffe ne bouge pas. Sur les autres : 1. aligner sur la médiane du réseau (+{CAP_ALIGNE*100:.0f} % au plus) ; 2. remonter à {CIBLE*100:.0f} % de marge (+{CAP_MARGE*100:.0f} % au plus) ; 3. +{GEN*100:.0f} % sur le reste. Prix TVAC arrondis aux 10 centimes. Volume égal. En bleu : les nouveaux prix, modifiables.')
    n0, n1 = 15, 15 + len(prop) - 1
    R_TOT = N1 + 1
    resume = [
        ('Marge brute actuelle / mois (produits avec prix d’achat)', f"='Prix et marges'!Q{R_TOT}", EUR0),
        ('CA HTVA actuel / mois (mêmes produits)', f"=SUMPRODUCT(--('Prix et marges'!Q{N0}:Q{N1}<>\"\"),'Prix et marges'!P{N0}:P{N1})", EUR0),
        ('Gain de marge brute / mois', f'=SUM(N{n0}:N{n1})', EUR0),
        ('Gain de marge brute, en % de la marge actuelle', '=IF(B4=0,"",B6/B4)', PCT),
        ('Gain de marge brute / an', '=B6*12', EUR0),
        ('Marge brute % avant', '=IF(B5=0,"",B4/B5)', PCT),
        ('Marge brute % après', '=IF(B5=0,"",(B4+B6)/(B5+B6))', PCT),
        ('Objectif : +5 % de marge brute / mois', '=B4*0.05', EUR0),
    ]
    for k, (lab, form, fmt) in enumerate(resume):
        a = wq.cell(row=4 + k, column=1, value=lab); a.font = f_bold if k in (2, 3) else f_norm
        b = wq.cell(row=4 + k, column=2, value=form); b.font = f_bold if k in (2, 3) else f_norm; b.number_format = fmt
        b.fill = fill_tot
    wq.merge_cells('A4:A4')
    HQ = ['Rang Sombreffe', 'Produit', 'Catégorie', 'Ce qu’on fait', 'Prix TVAC actuel', 'Nouveau prix TVAC', 'Hausse', 'TVA',
          'Nouveau prix HTVA', 'Prix d’achat HT', 'Marge % avant', 'Marge % après', 'Ventes / mois (11 mois)', 'Gain de marge / mois']
    for k, h in enumerate(HQ): entete(wq, 14, k + 1, h)
    for i, (x, motif, nttc) in enumerate(prop):
        rr, r = n0 + i, x['r']
        tt, tv = L(TCOL['5']['ttc']), L(TCOL['5']['tva'])
        vals = [(1, rangS[r], '0', f_norm), (2, x['p']['nom'], None, f_norm), (3, x['p']['cat'], None, f_norm), (4, motif, None, f_norm),
                (5, f'=Tarifs!{tt}{r}', EUR, f_link), (6, nttc, EUR, f_in), (7, f'=IF(E{rr}=0,"",F{rr}/E{rr}-1)', PCT, f_norm),
                (8, f'=Tarifs!{tv}{r}', '0%', f_link), (9, f'=F{rr}/(1+H{rr})', EUR, f_norm), (10, f"='Prix et marges'!K{r}", EUR, f_link),
                (11, f"='Prix et marges'!M{r}", PCT, f_link), (12, f'=IF(I{rr}=0,"",(I{rr}-J{rr})/I{rr})', PCT, f_norm),
                (13, f"='Prix et marges'!O{r}", '#,##0', f_link), (14, f"=(I{rr}-'Prix et marges'!H{r})*M{rr}", EUR0, f_norm)]
        for col, v, fmt, font in vals:
            c = wq.cell(row=rr, column=col, value=v); c.font = font
            if fmt: c.number_format = fmt
        wq.cell(row=rr, column=6).fill = PatternFill('solid', fgColor='FFF9DB')
    # Le top 20, qui ne bouge pas, et les prix à vérifier.
    rr = n1 + 3
    wq.cell(row=rr, column=1, value=f'Le top {NTOP} de Sombreffe : inchangé').font = f_bold
    for k, h in enumerate(('Rang Sombreffe', 'Produit', 'Catégorie', 'Ventes / mois (11 mois)', 'Prix TVAC', 'Marge %')): entete(wq, rr + 1, k + 1, h)
    for k, x in enumerate(top):
        r, ro = x['r'], rr + 2 + k
        for col, v, fmt, font in ((1, k + 1, '0', f_norm), (2, x['p']['nom'], None, f_norm), (3, x['p']['cat'], None, f_norm),
                                  (4, f"='Prix et marges'!O{r}", '#,##0', f_link), (5, f"=IF(Tarifs!{L(TCOL['5']['ttc'])}{r}=\"\",\"\",Tarifs!{L(TCOL['5']['ttc'])}{r})", EUR, f_link),
                                  (6, f"='Prix et marges'!M{r}", PCT, f_link)):
            c = wq.cell(row=ro, column=col, value=v); c.font = font
            if fmt: c.number_format = fmt
    ra = rr + 2 + len(top) + 2
    wq.cell(row=ra, column=1, value='À vérifier : le prix d’achat est plus haut que le prix de vente (erreur de prix en caisse, ou de recette au panel) — hors du calcul').font = f_bold
    for k, h in enumerate(('Rang Sombreffe', 'Produit', 'Catégorie', 'Ventes / mois (11 mois)', 'Prix HTVA', 'Prix d’achat HT', 'Médiane réseau HTVA')): entete(wq, ra + 1, k + 1, h)
    for k, x in enumerate(anomalies):
        r, ro = x['r'], ra + 2 + k
        for col, v, fmt, font in ((1, rangS[r], '0', f_norm), (2, x['p']['nom'], None, f_norm), (3, x['p']['cat'], None, f_norm),
                                  (4, f"='Prix et marges'!O{r}", '#,##0', f_link), (5, f"='Prix et marges'!H{r}", EUR, f_link),
                                  (6, f"='Prix et marges'!K{r}", EUR, f_link), (7, f"='Prix et marges'!I{r}", EUR, f_link)):
            c = wq.cell(row=ro, column=col, value=v); c.font = font
            if fmt: c.number_format = fmt
    for k, w in enumerate([46, 34, 20, 26, 11, 11, 8, 6, 11, 11, 10, 10, 11, 12]): wq.column_dimensions[L(k + 1)].width = w
    wq.row_dimensions[14].height = 30
    wq.freeze_panes = 'C15'
    wq.auto_filter.ref = f'A14:N{n1}'

for w in wb.worksheets:
    w.sheet_properties.pageSetUpPr.fitToPage = True
    w.page_setup.orientation = 'landscape'; w.page_setup.fitToWidth = 1; w.page_setup.fitToHeight = 0
wb.save(sortie)
print('écrit', sortie)
