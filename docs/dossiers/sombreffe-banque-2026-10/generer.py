# La note pour la banque d'Atelier by Harmonie – Sombreffe (société ANTALARMO), octobre 2026, sur UNE page : les six premiers mois de
# l'exercice 2026-2027 (avril → septembre 2026) lus dans le tableau d'analyse de la comptabilité, et la demande d'une ligne de crédit
# pour porter les factures des clients professionnels (B2B). Le texte parle au nom d'Harmonie (« nous »), simplement. HTML A4 rendu en
# PDF par Chromium ; sans gras dans le texte courant. Données : pnl.json (extrait tel quel du fichier p-and-l-ANTALARMO-2026-2027.xlsx).
#   python3 docs/dossiers/sombreffe-banque-2026-10/generer.py
import json, base64, math, os, subprocess
ICI = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(ICI, '..', '..', '..'))
CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome'

# --- Le B2B n'est pas dans la comptabilité : estimation de la gérante (14/10/2026) — environ 25 000 € par mois hors TVA, la moitié du
# chiffre, payés à 30 jours. La ligne = factures pro en attente (TVA de 6 % comprise) + 10 % pour les mois chargés et les retards,
# arrondie aux 5 000 € supérieurs ; `montant` la remplace si la gérante veut demander autre chose.
B2B = dict(caMoisHT=25000, delaiJours=30, montant=None)

LOGO = 'data:image/png;base64,' + base64.b64encode(open(os.path.join(REPO, 'public/assets/img/logo.png'), 'rb').read()).decode()
def nf(v, d=0):
    if v is None: return '—'
    s = f'{abs(v):,.{d}f}'.replace(',', ' ').replace('.', ',')   # espaces insécables : un montant ne se coupe pas en fin de ligne
    return ('− ' if round(v, d) < 0 else '') + s
fE = lambda v, d=0: nf(v, d) + ' €'
fP = lambda v, d=1: nf(v, d) + ' %'

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
# Le B2B et la ligne
b2b6 = B2B['caMoisHT'] * MOIS                                  # environ, sur les 6 mois
partB2B = 100 * b2b6 / ca
encours = B2B['caMoisHT'] * 1.06 * B2B['delaiJours'] / 30      # factures pro en attente en moyenne, TVA comprise
ligne = B2B['montant'] or math.ceil(encours * 1.10 / 5000) * 5000
environ = lambda v: fE(round(v, -3 if v >= 10000 else -2))

def tuile(k, v, sous): return f'<div class="tile"><div class="k">{k}</div><div class="v">{v}</div><div class="s">{sous}</div></div>'
def L(lib, v, sous='', tot=False):
    return f'<tr class="{"tot" if tot else ""}"><td class="l">{lib}{f"<small>{sous}</small>" if sous else ""}</td><td>{fE(v)}</td><td>{fP(pc(v))}</td></tr>'
barre = f'<div class="split"><i class="c" style="width:{100 - partB2B:.1f}%"></i><i class="b" style="width:{partB2B:.1f}%"></i></div>'

page = f'''<section class="page">
<div class="hd"><span class="marque"><img class="logo" src="{LOGO}" alt="">Atelier by Harmonie – Sombreffe</span><span>Octobre 2026</span></div>
<h1>Point sur les six derniers mois</h1>
<div class="sous">ANTALARMO · Harmonie Thiry, gérante · avril à septembre 2026 · montants hors TVA</div>
<div class="grille">
{tuile('Chiffre d’affaires', fE(ca), f"sur 6 mois, soit {fE(caMois)} par mois")}
{tuile('Part du B2B', f"≈\u00a0{fP(round(partB2B / 5) * 5, 0)}", f"B2B environ {environ(b2b6)}<br>comptoir environ {environ(ca - b2b6)}{barre}")}
{tuile('Résultat avant impôts', fE(rai), f"contre une perte de {fE(-g('Bénéfice/Perte avant impôts', 'premier'))} au premier exercice")}
{tuile('Intérêts couverts', nf(ebitda / interets, 1) + ' fois', f"EBITDA de {fE(ebitda)} pour {fE(interets)} d’intérêts")}
</div>
<div class="deux">
<div><div class="sec">Compte de résultats, 6 mois</div>
<table class="t"><thead><tr><th class="l"></th><th>Montant</th><th>% du CA</th></tr></thead>
{L('Chiffre d’affaires', ca, tot=True)}
{L('Achats', -ach, 'marchandises, fournitures, emballages')}
{L('Autres produits d’exploitation', autres, f"dont {fE(s(['749300']))} d’indemnité d’assurance (ponctuel)")}
{L('Services et biens divers', -sbd, 'loyer, énergie, franchise, gérante, honoraires')}
{L('Personnel', -pers)}
{L('Autres charges', -acx)}
{L('EBITDA', ebitda, tot=True)}
{L('Amortissements', -amo)}
{L('Résultat financier', fin, 'intérêts du crédit, des leasings et du découvert')}
{L('Résultat avant impôts', rai, tot=True)}
</table></div>
<div><div class="sec">Pourquoi une ligne de crédit</div><ul class="pts">
<li>La moitié de notre chiffre vient de clients professionnels, environ {fE(B2B['caMoisHT'])} par mois.</li>
<li>Ces clients sont livrés, puis paient sur facture à {B2B['delaiJours']} jours.</li>
<li>De notre côté, les achats, les salaires, le loyer et la franchise se paient chaque mois. Nous avançons donc l’argent de ces commandes en attendant d’être payés.</li>
<li>Ça se voit dans les comptes : {fE(s(['650000']))} d’intérêts débiteurs sur la période. Il n’y en avait pas au premier exercice.</li>
<li>L’activité est saine. L’EBITDA est positif, même sans l’indemnité d’assurance.</li>
</ul></div>
</div>
<div class="demande"><div class="k">Notre demande</div>
<div class="dl">Une ligne de crédit de caisse de {fE(ligne)}, renouvelable chaque année. Elle sert à financer les factures pro en attente et se rembourse au fur et à mesure que les clients paient.</div>
<div class="calc">Le calcul : {fE(B2B['caMoisHT'])} de B2B par mois × 1,06 (TVA) × {B2B['delaiJours']} jours ÷ 30 = {fE(round(encours, -2))} de factures en attente en moyenne. On arrondit à {fE(ligne)} pour les mois plus chargés et les retards de paiement.</div></div>
<div class="ft">Chiffres de la fiduciaire : situation intermédiaire au 30 septembre 2026, avant clôture (stock, provisions). Part du B2B et délai de paiement : estimation de la gérante.</div>
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
'''
doc = f'<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Atelier by Harmonie – Sombreffe · note pour la banque</title><style>{css}</style></head><body>{page}</body></html>'
assert '<b>' not in doc, 'du gras a échappé'
SORTIE = os.path.join(ICI, 'Sombreffe-note-banque.pdf')
src = os.path.join(os.environ.get('TMPDIR_DOSSIER') or '/tmp', 'sombreffe-note-banque.html'); open(src, 'w', encoding='utf-8').write(doc)
subprocess.run([CHROME, '--headless', '--no-sandbox', '--disable-gpu', '--no-pdf-header-footer', f'--print-to-pdf={SORTIE}', 'file://' + src],
               check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
print(os.path.basename(SORTIE), '· CA', round(ca), '· EBITDA', round(ebitda), '· amortissements', round(amo), '· RAI', round(rai),
      '· couverture', round(ebitda / interets, 1), '· encours B2B', round(encours), '· ligne', ligne)
