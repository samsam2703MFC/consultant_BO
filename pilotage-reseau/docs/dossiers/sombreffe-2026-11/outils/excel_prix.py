"""Le classeur des prix, marges et ventes des 50 produits les plus vendus du réseau (20/11/2026)."""
import json, os, sys
from openpyxl import Workbook
from openpyxl.styles import Font, Alignment, PatternFill, Border, Side
from openpyxl.utils import get_column_letter as L
from openpyxl.comments import Comment

src_dir, prix_json, sortie = sys.argv[1], sys.argv[2], sys.argv[3]
G = json.load(open(os.path.join(src_dir, 'grille12.json')))
P = json.load(open(prix_json))['produits']
T = {s: {p['id']: p for p in json.load(open(os.path.join(src_dir, f'tarif-{s}.json')))['produits']} for s in ('2', '3', '4', '5')}
GP = {p['pid']: p for p in G['produits']}
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
    for col, v in ((1, i), (2, p['nom']), (3, p['cat'])):
        c = ws.cell(row=row, column=col, value=v); c.font = f_norm
    ws.cell(row=row, column=1).alignment = Alignment(horizontal='center')

wb = Workbook()

# --- 1. Tarifs (les entrées) : par magasin, prix TVAC, TVA, prix d'achat HT ; le prix HTVA calculé. ---
ws = wb.active; ws.title = 'Tarifs'
tete(ws, 'Tarifs des quatre magasins — les 50 produits les plus vendus du réseau',
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

# --- 2. Ventes par mois (les entrées) : les pièces de chaque magasin, mois par mois. ---
wv = wb.create_sheet('Ventes par mois')
tete(wv, 'Pièces vendues par mois — les 50 produits, les quatre magasins',
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
    wv.cell(row=r, column=1, value=r - N0 + 1).font = f_norm
    wv.cell(row=r, column=2, value=p['nom']).font = f_norm
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
tete(wp, 'Sombreffe — prix, marge brute et ventes des 50 produits les plus vendus du réseau',
     'Prix HTVA. Marge brute = prix de vente HTVA − prix d’achat HT. Ventes moyennes : la moyenne des mois clos (déc. 2025 → oct. 2026) où le produit s’est vendu. Noir : calculé ; vert : lien vers un autre onglet.')
H = ['#', 'Produit', 'Catégorie', 'Pièces réseau, 12 mois',
     'Corbais prix HTVA', 'Gosselies prix HTVA', 'Halle prix HTVA', 'Sombreffe prix HTVA', 'Médiane des magasins', 'Écart Sombreffe vs médiane',
     'Sombreffe prix d’achat HT', 'Marge brute € / pièce', 'Marge brute %', 'Ventes moyennes / mois (pièces)', 'CA HTVA / mois', 'Marge brute / mois', 'Manque à gagner / mois au prix médian']
ws3 = wp
ws3.merge_cells('E3:J3'); entete(ws3, 3, 5, 'Prix de vente HTVA', fill_grp)
ws3.merge_cells('K3:Q3'); entete(ws3, 3, 11, 'Sombreffe : marge et ventes', fill_grp)
for k, t in enumerate(H): entete(ws3, 4, k + 1, t)
for r, p in enumerate(P, N0):
    produit_cols(ws3, r, r - N0 + 1, p)
    x = ws3.cell(row=r, column=4, value=f'=SUM({tout(r)})'); x.font = f_link; x.number_format = NB
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
        (15, f'=IF(OR(H{r}="",N{r}=""),"",N{r}*H{r})', EUR0, f_norm),
        (16, f'=IF(OR(L{r}="",N{r}=""),"",N{r}*L{r})', EUR0, f_norm),
        (17, f'=IF(OR(J{r}="",N{r}=""),"",IF(J{r}<0,(I{r}-H{r})*N{r},0))', EUR0, f_norm),
    ]
    for col, form, fmt, font in f:
        x = ws3.cell(row=r, column=col, value=form); x.font = font; x.number_format = fmt
rt = N1 + 1
ws3.cell(row=rt, column=2, value='Total des 50 produits').font = f_bold
for col, form, fmt in ((14, f'=SUM(N{N0}:N{N1})', '#,##0'), (15, f'=SUM(O{N0}:O{N1})', EUR0), (16, f'=SUM(P{N0}:P{N1})', EUR0), (17, f'=SUM(Q{N0}:Q{N1})', EUR0),
                       (13, f'=IF(SUMIFS(O{N0}:O{N1},P{N0}:P{N1},"<>")=0,"",SUM(P{N0}:P{N1})/SUMIFS(O{N0}:O{N1},P{N0}:P{N1},"<>"))', PCT)):
    x = ws3.cell(row=rt, column=col, value=form); x.font = f_bold; x.number_format = fmt
for col in range(1, 18): ws3.cell(row=rt, column=col).fill = fill_tot
ws3.cell(row=rt, column=13).comment = Comment('Marge brute moyenne pondérée par le CA, sur les produits qui ont un prix d’achat.', 'Cockpit')
ws3.cell(row=rt + 2, column=2, value='Notes').font = f_bold
for k, n in enumerate([
    'Prix de vente : le tarif de la caisse de chaque magasin, ramené hors TVA (onglet Tarifs). Vide : pas à la carte du magasin.',
    'Prix d’achat : le coût de recette HT du panel (matière + emballage) pour une pièce, à Sombreffe. Vide : pas de recette au panel.',
    'Manque à gagner : ce qu’apporterait le prix médian du réseau, à volume égal, quand Sombreffe est en dessous.',
    'Traiteur (à la personne) et Fromage à la pièce / à la coupe : un prix de 1 € l’unité, pas un prix de produit.']):
    ws3.cell(row=rt + 3 + k, column=2, value=n).font = Font(name=F, size=9, color='5C5852')
widths = [4, 34, 20, 11, 10, 10, 10, 10, 10, 10, 10, 10, 9, 11, 11, 11, 13]
for k, w in enumerate(widths): ws3.column_dimensions[L(k + 1)].width = w
ws3.row_dimensions[4].height = 42
ws3.freeze_panes = 'D5'

# --- 4. Réseau : les quatre magasins côte à côte — prix HTVA, prix d'achat, marge brute %, ventes moyennes / mois. ---
wr = wb.create_sheet('Réseau', 1)
tete(wr, 'Les quatre magasins — prix, prix d’achat, marge brute et ventes moyennes par mois',
     'Prix HTVA ; marge brute % = (prix HTVA − prix d’achat HT) ÷ prix HTVA ; ventes moyennes : la moyenne des mois clos où le produit s’est vendu.')
for k, t in enumerate(('#', 'Produit', 'Catégorie')): entete(wr, 4, k + 1, t)
for i, (s, nom) in enumerate(MAG):
    b = 4 + i * 4
    wr.merge_cells(start_row=3, start_column=b, end_row=3, end_column=b + 3)
    entete(wr, 3, b, nom, fill_grp)
    for k, t in enumerate(('Prix HTVA', 'Prix d’achat HT', 'Marge brute %', 'Ventes / mois')): entete(wr, 4, b + k, t)
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

for w in wb.worksheets:
    w.sheet_properties.pageSetUpPr.fitToPage = True
    w.page_setup.orientation = 'landscape'; w.page_setup.fitToWidth = 1; w.page_setup.fitToHeight = 0
wb.save(sortie)
print('écrit', sortie)
