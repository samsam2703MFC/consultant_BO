# La note de marché « Boulangerie et terminaux de cuisson haut de gamme », octobre 2026 : une page A4 en français et la même page
# en néerlandais, deux PDF séparés. HTML rendu en PDF par le Chromium de Playwright (rendre.js, à côté de ce fichier), qui refuse
# un rendu qui déborderait de la page.
# Les chiffres viennent des sources publiques citées en pied de page, lues le 31 octobre 2026 ; les perspectives à 5 et 10 ans
# sont une lecture prospective fondée sur ces sources, pas une prévision chiffrée d'institut.
#   python3 generer.py   → Marche-boulangerie-2026-FR.pdf et Markt-bakkerij-2026-NL.pdf dans ce dossier
import base64, os, re, subprocess

ICI = os.path.dirname(os.path.abspath(__file__))
RACINE = os.path.abspath(os.path.join(ICI, '..', '..', '..'))
FONTES = 'file://' + RACINE + '/public/assets/ds/fonts/'
LOGO = 'data:image/png;base64,' + base64.b64encode(open(RACINE + '/public/assets/img/logo.png', 'rb').read()).decode()
TMP = '/tmp/marche-boulangerie'


def insec(s):
    """Espaces insécables avant les unités et dans les nombres, pour qu'une ligne ne coupe jamais « 2 640 » ou « 16,2 Md€ »."""
    s = re.sub(r'(\d) (?=(%|€|\$|Md|M€|mld|mln|m²|ans|jaar))', '\\1\u00a0', s)
    s = re.sub(r'(\d) (?=\d{3}\b)', '\\1\u00a0', s)
    s = re.sub(r'([+−]) (?=\d)', '\\1\u00a0', s)
    s = re.sub(r' ([:;?!])', '\u00a0\\1', s)
    return s


T = {
 'fr': dict(
  lang='fr', fichier='Marche-boulangerie-2026-FR.pdf', titreDoc='L’Atelier by · Note de marché · boulangerie et terminaux de cuisson haut de gamme',
  hd='Note de marché · octobre 2026', code='FR',
  sur='Note de marché', h1='Boulangerie et terminaux de cuisson haut de gamme',
  sous='L’état du marché en octobre 2026, son évolution, et ce qui aura de la valeur dans 5 ans (2031) et dans 10 ans (2036).',
  tiles=[
   ('Marché français 2025', '+ 2 %', '16,2 Md€, après + 20 % en 2021, + 9 % en 2022 et + 5 % en 2023.'),
   ('Chaînes françaises 2025', '+ 8 %', '3,8 Md€ : 10 % des magasins font 23 % du chiffre du marché.'),
   ('Boulangers en Flandre · 10 ans', '− 817', 'Il en reste 2 640, près d’un quart a disparu. Bruxelles : − 11 % de 2018 à 2022.'),
   ('Snacking · terminaux de cuisson', '35 %', 'du chiffre d’un terminal de cuisson, contre 16 % chez l’artisan (France).'),
  ],
  s1='1 · L’état général du marché',
  etat=[
   ('Un marché mûr, qui grandit par les prix.', 'Moins de clients, un panier plus élevé, le pain recule dans l’assiette. La France, marché de référence voisin, revient à + 2 % en 2025 après l’élan de l’après-Covid.'),
   ('Les réseaux prennent la croissance.', 'Les chaînes progressent quatre fois plus vite que le marché et vendent 1,14 M€ par magasin en moyenne, plus que les indépendants.'),
   ('L’artisan de quartier s’efface.', 'Pas de personnel pour les nuits et les week-ends, pas de repreneur, pain de supermarché bon marché : un quart des boulangeries belges a disparu en 15 ans (Unizo).'),
   ('Les réseaux se coupent en deux.', 'D’un côté le volume à petit prix (Marie Blachère, n° 1 en France) ; de l’autre le haut de gamme qui vend une expérience : Paul, Kayser, ou Copains, annoncé à Bruxelles à l’automne 2025 aux prix de Paris. Le milieu sans identité est pris en étau.'),
   ('La Belgique, pôle du surgelé haut de gamme.', 'La Lorraine Bakery Group (Panos) : 1,57 Md€ en 2025, environ + 10 % par an sur dix ans. En Europe, le surgelé de boulangerie croît de 3,6 à 7,7 % par an : les pâtons ne freinent plus le haut de gamme.'),
   ('Des coûts sous tension.', 'Beurre près de + 40 % en un an (2025), cacao monté au-delà de 10 000 $ la tonne, salaires indexés, énergie des fours. Xerfi attend + 4 à 6 % par an sur les prix agricoles jusqu’en 2028.'),
  ],
  s2='2 · Comment le marché va évoluer',
  h5='D’ici 5 ans', a5='2031', h10='D’ici 10 ans', a10='2036',
  evo5=[
   ('Consolidation.', 'Les artisans qui ferment libèrent emplacements et clients ; les réseaux structurés les reprennent, les petits réseaux s’adossent aux grands.'),
   ('Polarisation.', 'Discount et premium gagnent, le milieu perd. Le premium se justifie par un produit signature, la fraîcheur à toute heure et le lieu.'),
   ('Toute la journée.', 'Petit-déjeuner, midi chaud, goûter, café de spécialité, places assises : le pain attire, snacking, pâtisserie et boisson font la marge.'),
   ('Des cuissons pilotées par les ventes.', 'Fournées calées heure par heure, invendus sous 2 % du chiffre, fin de journée revendue ; commande en ligne et clients pros en appoint.'),
  ],
  evo10=[
   ('La pénurie de boulangers devient structurelle.', 'La pâte se fait en atelier central ou chez l’industriel, la finition en magasin : le terminal haut de gamme devient la boulangerie normale du centre-ville.'),
   ('Des magasins plus sobres.', 'Plus compacts, fours électriques connectés et programmés : moins de m², d’énergie et d’heures qualifiées par euro vendu.'),
   ('Santé et preuve.', 'Levain, farines locales, moins de sucre, étiquette courte, origine affichée ; emballages réemployables pour l’emporté (règlement européen PPWR, dès 2028).'),
   ('Un paysage resserré.', 'Quelques grandes marques, des artisans d’excellence, peu de place entre les deux ; beurre, chocolat et farine se sécurisent par contrat.'),
  ],
  s3='3 · Ce qui aura de la valeur',
  v5t='Dans 5 ans', v10t='Dans 10 ans',
  val5=[
   ('Les emplacements à flux', 'centres-villes, gares, axes domicile-travail, repris aux artisans qui ferment.'),
   ('Une marque premium reconnaissable', 'et deux ou trois produits signatures qu’on vient chercher.'),
   ('Le pilotage quotidien', 'cuissons, invendus, matière et heures, magasin par magasin.'),
   ('Le snacking et le café', 'la meilleure marge et la raison de revenir l’après-midi.'),
   ('Des équipes stables', 'le personnel est le premier goulot du métier.'),
  ],
  val10=[
   ('La maîtrise de la pâte', 'atelier propre ou partenaire exclusif : qui tient la recette tient la marge.'),
   ('La relation directe avec le client', 'fidélité, application, abonnements, données.'),
   ('La taille du réseau', 'achats, notoriété, force de reprendre les meilleurs emplacements.'),
   ('Les savoir-faire rares', 'boulangers et pâtissiers formés en interne.'),
   ('La preuve', 'farines tracées, emballages réemployables, magasins sobres.'),
  ],
  perdk='Ce qui perdra de la valeur :',
  perd='le milieu de gamme sans identité, le loyer cher sans flux, une offre qui s’arrête au pain, un fournisseur unique, le pilotage sans données.',
  srck='Sources',
  src='Food Service Vision via Républik Retail (février 2026) · FranceAgriMer, étude des boulangeries 2022 · Provincies in Cijfers (mai 2024) · NSZ via BRUZZ (juin 2025) · Unizo via VRT (avril 2026) · L’Écho via Paris Match Belgique (septembre 2025) · RetailDetail (La Lorraine Bakery Group, 2025) · MarketsandMarkets, Fortune Business Insights, Credence Research, MarketDataForecast (surgelé, Europe) · FranceAgriMer (beurre) · Xerfi (prix agricoles).',
  lim='Les chiffres français servent de référence : il n’existe pas de série publique récente du marché belge de la boulangerie. Les perspectives à 5 et 10 ans sont une lecture prospective fondée sur ces sources, pas une prévision chiffrée.',
  pied='L’Atelier by · note de marché · sources lues le 31 octobre 2026',
 ),
 'nl': dict(
  lang='nl', fichier='Markt-bakkerij-2026-NL.pdf', titreDoc='L’Atelier by · Marktnota · bakkerij en premium afbakpunten',
  hd='Marktnota · oktober 2026', code='NL',
  sur='Marktnota', h1='Bakkerij en premium afbakpunten',
  sous='De stand van de markt in oktober 2026, de evolutie, en wat over 5 jaar (2031) en over 10 jaar (2036) waarde zal hebben.',
  tiles=[
   ('Franse markt 2025', '+ 2 %', '16,2 mld €, na + 20 % in 2021, + 9 % in 2022 en + 5 % in 2023.'),
   ('Franse ketens 2025', '+ 8 %', '3,8 mld €: 10 % van de winkels haalt 23 % van de marktomzet.'),
   ('Bakkers in Vlaanderen · 10 jaar', '− 817', 'Nog 2 640, bijna een kwart is verdwenen. Brussel: − 11 % van 2018 tot 2022.'),
   ('Snacking · afbakpunten', '35 %', 'van de omzet van een afbakpunt, tegen 16 % bij de ambachtelijke bakker (FR).'),
  ],
  s1='1 · De algemene stand van de markt',
  etat=[
   ('Een volwassen markt, die groeit via de prijzen.', 'Minder klanten, een hoger ticket, brood verliest terrein op het bord. Frankrijk, de naburige referentiemarkt, valt in 2025 terug op + 2 % na de covidopleving.'),
   ('De ketens pakken de groei.', 'Ze groeien vier keer sneller dan de markt en verkopen gemiddeld 1,14 mln € per winkel, meer dan de zelfstandigen.'),
   ('De buurtbakker verdwijnt.', 'Geen personeel voor nachten en weekends, geen overnemer, goedkoop supermarktbrood: in 15 jaar is een kwart van de Belgische bakkerijen verdwenen (Unizo).'),
   ('De ketens splitsen zich in tweeën.', 'Aan de ene kant volume tegen een lage prijs (Marie Blachère, nr. 1 in Frankrijk); aan de andere kant het topsegment dat een beleving verkoopt: Paul, Kayser, of Copains, in het najaar van 2025 aangekondigd in Brussel tegen Parijse prijzen. Het midden zonder eigen gezicht zit klem.'),
   ('België, draaischijf van premium diepvriesbakkerij.', 'La Lorraine Bakery Group (Panos): 1,57 mld € in 2025, zowat + 10 % per jaar over tien jaar. In Europa groeit diepvriesbakkerij met 3,6 tot 7,7 % per jaar: de deegstukken remmen het topsegment niet langer af.'),
   ('Kosten onder druk.', 'Boter bijna + 40 % op een jaar (2025), cacao boven 10 000 $ per ton, geïndexeerde lonen, energie voor de ovens. Xerfi verwacht tot 2028 + 4 tot 6 % per jaar op de landbouwprijzen.'),
  ],
  s2='2 · Hoe de markt zal evolueren',
  h5='Binnen 5 jaar', a5='2031', h10='Binnen 10 jaar', a10='2036',
  evo5=[
   ('Consolidatie.', 'Bakkers die sluiten laten locaties en klanten vrij; gestructureerde ketens nemen ze over, kleine ketens sluiten aan bij grote.'),
   ('Polarisatie.', 'Discount en premium winnen, het midden verliest. Premium verantwoordt zich met een signatuurproduct, versheid op elk uur en de plek zelf.'),
   ('De hele dag.', 'Ontbijt, warme lunch, vieruurtje, specialty koffie, zitplaatsen: brood lokt, snacking, patisserie en dranken maken de marge.'),
   ('Afbakken gestuurd door de verkoop.', 'Ovenladingen van uur tot uur, minder dan 2 % van de omzet onverkocht, het einde van de dag doorverkocht; online bestellen en zakelijke klanten als aanvulling.'),
  ],
  evo10=[
   ('Het tekort aan bakkers wordt structureel.', 'Het deeg komt uit een centraal atelier of van de industrie, de afwerking gebeurt in de winkel: het premium afbakpunt wordt de gewone bakkerij in de stad.'),
   ('Zuiniger winkels.', 'Compacter, verbonden en geprogrammeerde elektrische ovens: minder m², energie en geschoolde uren per verkochte euro.'),
   ('Gezondheid en bewijs.', 'Zuurdesem, lokale granen, minder suiker, korte etiketten, zichtbare herkomst; herbruikbare verpakkingen voor afhaal (Europese verordening PPWR, vanaf 2028).'),
   ('Een smaller landschap.', 'Enkele grote merken, uitmuntende ambachtelijke bakkers, weinig ruimte daartussen; boter, chocolade en bloem worden in contracten vastgelegd.'),
  ],
  s3='3 · Wat waarde zal hebben',
  v5t='Over 5 jaar', v10t='Over 10 jaar',
  val5=[
   ('Locaties met passage', 'stadscentra, stations, woon-werkassen, overgenomen van sluitende bakkers.'),
   ('Een herkenbaar premiummerk', 'met twee, drie signatuurproducten.'),
   ('Dagelijkse sturing', 'afbakken, onverkocht, grondstoffen, uren per winkel.'),
   ('Snacking en koffie', 'de hoogste marge van de winkel.'),
   ('Stabiele teams', 'personeel is de eerste flessenhals.'),
  ],
  val10=[
   ('Controle over het deeg', 'eigen atelier of exclusieve partner: wie het recept heeft, heeft de marge.'),
   ('De directe band met de klant', 'trouw, app, abonnementen, data.'),
   ('De omvang van het net', 'aankoop, bekendheid, de beste locaties.'),
   ('Zeldzame vakkennis', 'intern opgeleide bakkers en patissiers.'),
   ('Het bewijs', 'traceerbare bloem, herbruikbare verpakkingen, zuinige winkels.'),
  ],
  perdk='Wat waarde zal verliezen:',
  perd='het middensegment zonder eigen gezicht, dure huur zonder passage, een aanbod dat bij brood stopt, één enkele leverancier, sturen zonder data.',
  srck='Bronnen',
  src='Food Service Vision via Républik Retail (februari 2026) · FranceAgriMer, studie bakkerijen 2022 · Provincies in Cijfers (mei 2024) · NSZ via BRUZZ (juni 2025) · Unizo via VRT (april 2026) · L’Echo via Paris Match België (september 2025) · RetailDetail (La Lorraine Bakery Group, 2025) · MarketsandMarkets, Fortune Business Insights, Credence Research, MarketDataForecast (diepvries, Europa) · FranceAgriMer (boter) · Xerfi (landbouwprijzen).',
  lim='De Franse cijfers dienen als referentie: er bestaat geen recente openbare reeks voor de Belgische bakkerijmarkt. De vooruitzichten op 5 en 10 jaar zijn een prospectieve lezing op basis van deze bronnen, geen becijferde voorspelling.',
  pied='L’Atelier by · marktnota · bronnen gelezen op 31 oktober 2026',
 ),
}

CSS = '''
@font-face{font-family:'Gotham';src:url('%(f)sGotham_Light.otf');font-weight:400}
@font-face{font-family:'Gotham';src:url('%(f)sGotham_Medium.otf');font-weight:500}
@font-face{font-family:'Vank';src:url('%(f)sGC_Vank.ttf')}
@page{size:A4;margin:0}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{font-family:Gotham,Helvetica,Arial,sans-serif;color:#221E1A;font-size:7.2pt;line-height:1.38;background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.page{width:210mm;height:297mm;padding:8mm 12mm 0;position:relative;overflow:hidden;display:flex;flex-direction:column}
.hd{display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #8D1D2C;padding-bottom:1.8mm;margin-bottom:3.6mm;font-size:6.8pt;letter-spacing:.08em;text-transform:uppercase;color:#7a736a}
.hd .logo{height:4.4mm;width:auto;display:block}
.sur{font-size:7pt;letter-spacing:.12em;text-transform:uppercase;color:#8D1D2C;font-weight:500;margin-bottom:1mm}
h1{font-family:Vank,Georgia,serif;font-weight:400;font-size:24pt;margin:0;letter-spacing:-.01em;line-height:1.05}
.sous{font-size:8.2pt;color:#7a736a;margin:1.4mm 0 3.2mm;line-height:1.38}
.tiles{display:grid;grid-template-columns:repeat(4,1fr);gap:2.6mm;margin-bottom:3.8mm}
.tile{border:1px solid #e6e0d8;border-radius:7px;background:#fbf9f5;padding:2.2mm 3mm 2.4mm}
.tile .k{font-size:6pt;letter-spacing:.07em;text-transform:uppercase;color:#7a736a;line-height:1.3;white-space:nowrap}
.tile .v{font-family:Vank,Georgia,serif;font-size:18pt;color:#8D1D2C;margin:.8mm 0 .4mm;white-space:nowrap;line-height:1.1}
.tile .s{font-size:6.5pt;color:#5a534b;line-height:1.36}
.sec{font-family:Vank,Georgia,serif;font-size:12pt;margin:0 0 2mm;padding-bottom:.9mm;border-bottom:1.2pt solid #8D1D2C;line-height:1.15}
.cols{display:grid;grid-template-columns:1fr 1fr;column-gap:6mm}
.pts{margin:0 0 3mm;padding:0;list-style:none}
.pts li{position:relative;padding-left:3.2mm;margin-bottom:1.2mm}
.pts li:before{content:'';position:absolute;left:0;top:1.3mm;width:1.4mm;height:1.4mm;border-radius:50%%;background:#8D1D2C}
.ld{color:#8D1D2C;font-weight:500}
.carte{border:1px solid #e6e0d8;border-radius:7px;padding:2.2mm 3mm .4mm;background:#fff}
.carte .tt{display:flex;align-items:center;justify-content:space-between;border-bottom:.5pt solid #e6e0d8;padding-bottom:1mm;margin-bottom:1.6mm}
.carte .tt span{font-family:Vank,Georgia,serif;font-size:10.5pt;line-height:1.1}
.carte .tt em{font-style:normal;font-size:6.4pt;letter-spacing:.1em;color:#fff;background:#8D1D2C;border-radius:3mm;padding:.4mm 2.2mm}
.carte .pts{margin-bottom:.6mm}
.evo{margin-bottom:3.4mm}
.vals{display:grid;grid-template-columns:1fr 1fr;column-gap:6mm}
.vk{font-size:6.6pt;letter-spacing:.1em;text-transform:uppercase;color:#8D1D2C;font-weight:500;margin-bottom:.8mm}
.num{margin:0;padding:0;list-style:none;counter-reset:n}
.num li{counter-increment:n;position:relative;padding:.75mm 0 .75mm 5.5mm;border-bottom:.5pt solid #EAE3D8}
.num li:last-child{border-bottom:none}
.num li:before{content:counter(n);position:absolute;left:0;top:.6mm;font-family:Vank,Georgia,serif;font-size:9.5pt;color:#8D1D2C;line-height:1.1}
.perd{border-left:3px solid #8D1D2C;background:#fbf9f5;padding:1.6mm 3mm;margin-top:2.2mm;color:#5a534b}
.bas{margin-top:auto;padding:1.6mm 0 5.5mm}
.src{font-size:5.9pt;color:#8a8379;line-height:1.42;border-top:.5pt solid #e6e0d8;padding-top:1.5mm}
.src .k{color:#7a736a;font-weight:500;letter-spacing:.08em;text-transform:uppercase;margin-right:1.2mm}
.src p{margin:0 0 .7mm}
.pied{display:flex;justify-content:space-between;font-size:6.1pt;color:#9a938a;margin-top:1.2mm}
'''


def pts(items):
    return '<ul class="pts">' + ''.join(f'<li><span class="ld">{a}</span> {b}</li>' for a, b in items) + '</ul>'


def num(items):
    return '<ol class="num">' + ''.join(f'<li><span class="ld">{a}</span> — {b}</li>' for a, b in items) + '</ol>'


def page(t):
    tiles = ''.join(f'<div class="tile"><div class="k">{k}</div><div class="v">{v}</div><div class="s">{s}</div></div>' for k, v, s in t['tiles'])
    e = t['etat']
    corps = f'''<section class="page">
<div class="hd"><img class="logo" src="{LOGO}" alt="L’Atelier by"><span>{t['hd']}</span><span>{t['code']}</span></div>
<div class="sur">{t['sur']}</div>
<h1>{t['h1']}</h1>
<div class="sous">{t['sous']}</div>
<div class="tiles">{tiles}</div>
<div class="sec">{t['s1']}</div>
<div class="cols">{pts(e[:3])}{pts(e[3:])}</div>
<div class="sec">{t['s2']}</div>
<div class="cols evo">
<div class="carte"><div class="tt"><span>{t['h5']}</span><em>{t['a5']}</em></div>{pts(t['evo5'])}</div>
<div class="carte"><div class="tt"><span>{t['h10']}</span><em>{t['a10']}</em></div>{pts(t['evo10'])}</div>
</div>
<div class="sec">{t['s3']}</div>
<div class="vals">
<div><div class="vk">{t['v5t']} · {t['a5']}</div>{num(t['val5'])}</div>
<div><div class="vk">{t['v10t']} · {t['a10']}</div>{num(t['val10'])}</div>
</div>
<div class="perd"><span class="ld">{t['perdk']}</span> {t['perd']}</div>
<div class="bas"><div class="src"><p><span class="k">{t['srck']}</span>{t['src']}</p><p>{t['lim']}</p></div>
<div class="pied"><span>{t['pied']}</span><span>1 / 1</span></div></div>
</section>'''
    return insec(corps)


def html(L):
    t = T[L]
    doc = f'''<!doctype html><html lang="{t['lang']}"><head><meta charset="utf-8"><title>{t['titreDoc']}</title><style>{CSS % {'f': FONTES}}</style></head><body>{page(t)}</body></html>'''
    assert '<b>' not in doc, 'du gras a échappé'
    return doc


if __name__ == '__main__':
    os.makedirs(TMP, exist_ok=True)
    jobs = []
    for L in ('fr', 'nl'):
        h = TMP + '/' + L + '.html'
        open(h, 'w', encoding='utf-8').write(html(L))
        jobs += [h, os.path.join(ICI, T[L]['fichier'])]
    subprocess.run(['node', os.path.join(ICI, 'rendre.js')] + jobs, check=True)
