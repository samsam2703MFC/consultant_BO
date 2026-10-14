# La note pour la banque d'Atelier by Harmonie – Sombreffe (société ANTALARMO), octobre 2026, sur UNE page : les six premiers mois de
# l'exercice 2026-2027 (avril → septembre 2026) lus dans le tableau d'analyse de la comptabilité, et la demande d'une ligne de crédit
# pour porter les factures des clients professionnels (B2B). HTML A4 rendu en PDF par Chromium ; sans gras dans le texte courant.
# Données : pnl.json (extrait tel quel du fichier p-and-l-ANTALARMO-2026-2027.xlsx). Les chiffres B2B ne sont pas dans la comptabilité :
# ils se posent dans B2B ci-dessous ; tant qu'ils manquent, la note les marque « à compléter ».
#   python3 docs/dossiers/sombreffe-banque-2026-10/generer.py
import json, base64, os, subprocess
ICI = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(ICI, '..', '..', '..'))
CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome'

# --- À poser avec Harmonie (None = à compléter) : CA B2B des 6 mois hors TVA (ventes facturées aux clients pro : journal des ventes de la
# fiduciaire ou tickets pro du panel), délai de paiement moyen des clients pro (jours), montant demandé. Le comptoir = CA total − B2B.
B2B = dict(ca6mHT=None, delaiJours=None, montant=None)

LOGO = 'data:image/png;base64,' + base64.b64encode(open(os.path.join(REPO, 'public/assets/img/logo.png'), 'rb').read()).decode()
def nf(v, d=0):
    if v is None: return '—'
    s = f'{abs(v):,.{d}f}'.replace(',', ' ').replace('.', ',')   # espaces insécables : un montant ne se coupe pas en fin de ligne
    return ('− ' if round(v, d) < 0 else '') + s
fE = lambda v, d=0: nf(v, d) + ' €'
fP = lambda v, d=1: nf(v, d) + ' %'
AC = '<span class="ac">à compléter</span>'

d = json.load(open(os.path.join(ICI, 'pnl.json')))
C = {x['compte']: x for x in d['lignes'] if x['compte']}
G = {x['libelle']: x for x in d['lignes'] if not x['compte']}
s = lambda cs, k='n': sum(C[c][k] for c in cs if c in C)
g = lambda lib, k='n': G[lib][k]

MOIS = 6
ca = g("Chiffre d'affaires"); ach = g('Coût des ventes et prestations'); autres = g("Autres produits d'exploitation")
sbd = g('Services et biens divers'); pers = g('Rémunérations, charges sociales, ...'); acx = g("Autres charges d'exploitation")
amo = g('Amortissements / immobilisés'); fin = g('Produits financiers') - g('Charges financières')
ebitda = g('Ventes et prestations') - ach - sbd - pers - acx
rex = g("Bénéfice/Perte d'exploitation"); rai = g('Bénéfice/Perte avant impôts')
assert abs(ebitda - amo - rex) < 0.05 and abs(rex + fin - rai) < 0.05, 'le tableau ne se recoupe pas'
interets = s(['650000', '650085', '650100', '650102'])        # crédit, leasings, découvert
caMois = ca / MOIS
pc = lambda v: 100 * v / ca
b2bMois = B2B['ca6mHT'] / MOIS if B2B['ca6mHT'] is not None else None
partB2B = 100 * B2B['ca6mHT'] / ca if B2B['ca6mHT'] is not None else None
connu = b2bMois is not None and B2B['delaiJours'] is not None
encours = b2bMois * 1.06 * B2B['delaiJours'] / 30 if connu else None   # factures B2B en attente, TVA de 6 % comprise

def tuile(k, v, sous): return f'<div class="tile"><div class="k">{k}</div><div class="v">{v}</div><div class="s">{sous}</div></div>'
def L(lib, v, sous='', tot=False):
    return f'<tr class="{"tot" if tot else ""}"><td class="l">{lib}{f"<small>{sous}</small>" if sous else ""}</td><td>{fE(v)}</td><td>{fP(pc(v))}</td></tr>'

part = f" ({fP(partB2B, 0)} du chiffre)" if partB2B is not None else ''
def tuile_canaux():
    # Comptoir et B2B sur les 6 mois : la part B2B en grand, les deux montants et la barre de partage dessous
    if partB2B is None:
        return tuile('Comptoir et B2B', AC, 'part du B2B dans le chiffre des 6 mois')
    barre = f'<div class="split"><i class="c" style="width:{100 - partB2B:.1f}%"></i><i class="b" style="width:{partB2B:.1f}%"></i></div>'
    return tuile('Comptoir et B2B', f"{fP(partB2B, 0)} B2B", f"B2B {fE(B2B['ca6mHT'])}<br>comptoir {fE(ca - B2B['ca6mHT'])}{barre}")
delai = f"{B2B['delaiJours']} jours" if B2B['delaiJours'] else 'plusieurs semaines'
montant = fE(B2B['montant']) if B2B['montant'] else AC
page = f'''<section class="page">
<div class="hd"><span class="marque"><img class="logo" src="{LOGO}" alt="">Atelier by Harmonie – Sombreffe</span><span>Note pour la banque · octobre 2026</span></div>
<h1>ANTALARMO : les six derniers mois</h1>
<div class="sous">L’Atelier by Sombreffe · gérante Harmonie Thiry · avril → septembre 2026 · hors TVA</div>
<div class="grille">
{tuile('Chiffre d’affaires', fE(ca), f"6 mois · {fE(caMois)} par mois")}
{tuile_canaux()}
{tuile('Résultat avant impôts', fE(rai), f"perte de {fE(-g('Bénéfice/Perte avant impôts', 'premier'))} au premier exercice")}
{tuile('Intérêts couverts', nf(ebitda / interets, 1) + ' fois', f"EBITDA {fE(ebitda)} ÷ intérêts {fE(interets)}")}
</div>
<div class="deux">
<div><div class="sec">Le compte de résultats, 6 mois</div>
<table class="t"><thead><tr><th class="l"></th><th>Montant</th><th>% du CA</th></tr></thead>
{L('Chiffre d’affaires', ca, tot=True)}
{L('Achats', -ach, 'marchandises, fournitures, emballages')}
{L('Autres produits d’exploitation', autres, f"dont indemnité d’assurance ponctuelle {fE(s(['749300']))}")}
{L('Services et biens divers', -sbd, 'loyer, énergie, redevances, gérante, honoraires')}
{L('Personnel', -pers)}
{L('Autres charges', -acx)}
{L('EBITDA', ebitda, tot=True)}
{L('Amortissements', -amo)}
{L('Résultat financier', fin, 'intérêts du crédit, des leasings et du découvert')}
{L('Résultat avant impôts', rai, tot=True)}
</table></div>
<div><div class="sec">Pourquoi une ligne de crédit</div><ul class="pts">
<li>Une part importante du chiffre se fait avec des clients professionnels{part}, livrés puis payés sur facture, {delai} après la livraison.</li>
<li>Le magasin, lui, paie au fil du mois ses achats ({fP(pc(ach), 0)} du chiffre), son équipe, son loyer et ses redevances : il avance l’argent de chaque facture B2B.</li>
<li>Les comptes le montrent : {fE(s(['700021']))} de frais de livraison facturés en six mois ({fE(s(['700021'], 'premier'))} sur tout le premier exercice) et {fE(s(['650000']))} d’intérêts débiteurs, inexistants avant.</li>
<li>Le magasin dégage un EBITDA positif, même sans l’indemnité d’assurance : le besoin vient du décalage des paiements, pas d’une perte.</li>
</ul></div>
</div>
<div class="demande"><div class="k">La demande</div>
<div class="dl">Une ligne de crédit de caisse de {montant}, renouvelable, pour porter les factures B2B en attente de paiement. Elle se rembourse à chaque paiement client.</div>
<div class="calc">Factures B2B en attente, en moyenne : CA B2B du mois {fE(b2bMois) if b2bMois is not None else AC} × 1,06 (TVA) × délai {delai if B2B['delaiJours'] else AC} ÷ 30 = {fE(round(encours, -2)) if connu else AC}</div></div>
<div class="ft">Source : tableau d’analyse de la comptabilité, situation intermédiaire au 30 septembre 2026, avant écritures de clôture (stock, provisions).</div>
</section>'''

css = f'''
@font-face{{font-family:'Gotham';src:url('file://{REPO}/public/assets/ds/fonts/Gotham_Light.otf');font-weight:400}}
@font-face{{font-family:'Gotham';src:url('file://{REPO}/public/assets/ds/fonts/Gotham_Medium.otf');font-weight:500}}
@font-face{{font-family:'Vank';src:url('file://{REPO}/public/assets/ds/fonts/GC_Vank.ttf')}}
''' + '''
@page{size:A4;margin:0}
*{box-sizing:border-box}
body{margin:0;font-family:Gotham,Helvetica,Arial,sans-serif;color:#221E1A;font-size:9.5pt;line-height:1.5;background:#fff}
.page{width:210mm;height:297mm;padding:14mm 16mm 12mm;position:relative;overflow:hidden;background:#fff}
.hd{display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #8D1D2C;padding-bottom:2.5mm;margin-bottom:9mm;font-size:7.5pt;letter-spacing:.08em;text-transform:uppercase;color:#7a736a}
.hd .marque{color:#8D1D2C;font-weight:500;display:flex;align-items:center;gap:2.5mm}.hd .logo{height:5mm;width:auto}
h1{font-family:Vank,Georgia,serif;font-weight:400;font-size:24pt;margin:0;line-height:1.1}
.sous{font-size:9.5pt;color:#7a736a;margin:2mm 0 8mm}
.grille{display:grid;grid-template-columns:repeat(4,1fr);gap:3.5mm;margin:0 0 9mm}
.tile{border:1px solid #e6e0d8;border-radius:8px;background:#fbf9f5;padding:3.5mm 4mm}
.tile .k{font-size:7pt;letter-spacing:.08em;text-transform:uppercase;color:#7a736a}
.tile .v{font-family:Vank,Georgia,serif;font-size:18pt;margin-top:1.5mm;white-space:nowrap;color:#8D1D2C}
.tile .s{font-size:7.4pt;color:#7a736a;margin-top:1mm;line-height:1.4}
.split{display:flex;gap:1px;height:2.2mm;border-radius:1.1mm;overflow:hidden;margin-top:1.8mm}.split .c{background:#b8ad9f}.split .b{background:#8D1D2C}
.deux{display:grid;grid-template-columns:1.1fr 1fr;gap:9mm}
.sec{font-family:Vank,Georgia,serif;font-size:13pt;margin:0 0 3mm;padding-bottom:1.2mm;border-bottom:1.2pt solid #8D1D2C}
.pts{margin:0;padding-left:4mm}
.pts li{margin-bottom:2.6mm;line-height:1.5}
table.t{width:100%;border-collapse:collapse}
.t th{font-size:6.8pt;letter-spacing:.06em;text-transform:uppercase;color:#7a736a;font-weight:500;text-align:right;padding:1.2mm 1.5mm;border-bottom:1pt solid #221E1A}
.t td{font-size:8.8pt;text-align:right;padding:1.5mm 1.5mm;border-bottom:.5pt solid #EAE3D8;white-space:nowrap;font-variant-numeric:tabular-nums}
.t .l{text-align:left;white-space:normal}
.t td small{display:block;font-size:6.9pt;color:#9a938a;line-height:1.3}
.t tr.tot td{border-top:1pt solid #221E1A;font-weight:500;background:#FCFAF7}
.demande{border:1.2pt solid #8D1D2C;border-radius:8px;padding:4mm 5mm;margin-top:10mm}
.demande .k{color:#8D1D2C;font-weight:500;font-size:7.5pt;letter-spacing:.1em;text-transform:uppercase;margin-bottom:1.5mm}
.demande .dl{font-size:10.5pt;line-height:1.55}
.demande .calc{font-size:8.4pt;color:#5a534b;margin-top:2.5mm;padding-top:2mm;border-top:.5pt solid #EAE3D8}
.ft{position:absolute;left:16mm;right:16mm;bottom:9mm;font-size:7.2pt;color:#9a938a;border-top:.5pt solid #e6e0d8;padding-top:1.5mm}
.ac{display:inline-block;border:1px dashed #C96A1B;color:#C96A1B;background:#FFF6EC;border-radius:3px;padding:0 1.5mm;font-size:.92em;font-weight:500}
'''
doc = f'<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Atelier by Harmonie – Sombreffe · note pour la banque</title><style>{css}</style></head><body>{page}</body></html>'
assert '<b>' not in doc, 'du gras a échappé'
SORTIE = os.path.join(ICI, 'Sombreffe-note-banque.pdf')
src = os.path.join(os.environ.get('TMPDIR_DOSSIER') or '/tmp', 'sombreffe-note-banque.html'); open(src, 'w', encoding='utf-8').write(doc)
subprocess.run([CHROME, '--headless', '--no-sandbox', '--disable-gpu', '--no-pdf-header-footer', f'--print-to-pdf={SORTIE}', 'file://' + src],
               check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
print(os.path.basename(SORTIE), '· CA', round(ca), '· EBITDA', round(ebitda), '· amortissements', round(amo), '· RAI', round(rai),
      '· couverture', round(ebitda / interets, 1), '· B2B', 'connu' if connu else 'à compléter')
