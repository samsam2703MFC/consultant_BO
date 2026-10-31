# La note de marché « Boulangerie et terminaux de cuisson haut de gamme », octobre 2026 : une page A4 en français et la même page
# en néerlandais, deux PDF séparés. HTML rendu en PDF par le Chromium de Playwright (rendre.js, à côté de ce fichier), qui refuse
# un rendu qui déborderait de la page.
# La note conclut sur ce qu'il faut pour tenir dans ce marché : une entreprise solide qui tient elle-même sa production, sa vente
# et sa logistique, à l'abri des concurrents et des pressions des fournisseurs (demande du 31 octobre 2026), preuves sourcées.
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
    s = re.sub(r'(\d) (?=(%|€|\$|Md|M€|mld|mln|m²|ans|jaar|usines|fabrieken))', '\\1\u00a0', s)
    s = re.sub(r'(\d) (?=\d{3}\b)', '\\1\u00a0', s)
    s = re.sub(r'([+−]) (?=\d)', '\\1\u00a0', s)
    s = re.sub(r' ([:;?!»])', '\u00a0\\1', s)
    s = re.sub(r'(«) ', '\\1\u00a0', s)
    return s


T = {
 'fr': dict(
  lang='fr', fichier='Marche-boulangerie-2026-FR.pdf', titreDoc='L’Atelier by · Note de marché · boulangerie et terminaux de cuisson haut de gamme',
  hd='Note de marché · octobre 2026', code='FR',
  sur='Note de marché', h1='Boulangerie et terminaux de cuisson haut de gamme',
  sous='L’état du marché en octobre 2026, son évolution, ce qui aura de la valeur dans 5 et 10 ans, et ce qu’il faut pour la capter.',
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
   ('Les réseaux se coupent en deux.', 'Le volume à petit prix d’un côté (Marie Blachère, n° 1 en France) ; de l’autre le haut de gamme qui vend une expérience : Paul, Kayser, ou Copains, annoncé à Bruxelles en 2025 aux prix de Paris. Le milieu est pris en étau.'),
   ('Les fournisseurs se concentrent.', 'Vandemoortele rachète Délifrance en 2025 (930 M€, 14 usines) ; La Lorraine pèse 1,57 Md€. Qui achète ses pâtons dépend de quelques géants.'),
   ('Des coûts sous tension.', 'Beurre près de + 40 % en un an (2025), cacao au-delà de 10 000 $ la tonne, salaires indexés ; Xerfi attend + 4 à 6 % par an sur les prix agricoles d’ici 2028.'),
  ],
  s2='2 · Comment le marché va évoluer',
  h5='D’ici 5 ans', a5='2031', h10='D’ici 10 ans', a10='2036',
  evo5=[
   ('Consolidation et polarisation.', 'Les artisans qui ferment libèrent emplacements et clients ; les réseaux structurés les reprennent. Discount et premium gagnent, le milieu perd.'),
   ('Toute la journée.', 'Petit-déjeuner, midi chaud, goûter, café de spécialité, places assises : le pain attire, snacking, pâtisserie et boisson font la marge.'),
   ('Des cuissons pilotées par les ventes.', 'Fournées calées heure par heure, invendus sous 2 % du chiffre, fin de journée revendue ; commande en ligne et clients pros en appoint.'),
  ],
  evo10=[
   ('La pénurie de boulangers devient structurelle.', 'La pâte se fait en atelier central, le sien ou celui d’un industriel, et la finition en magasin : qui n’a pas d’atelier dépend de celui des autres.'),
   ('Un paysage resserré.', 'Quelques groupes intégrés, des artisans d’excellence, peu de place entre les deux ; magasins plus compacts, fours électriques connectés.'),
   ('Santé et preuve.', 'Levain, farines locales, moins de sucre, étiquette courte, origine affichée ; emballages réemployables pour l’emporté (règlement européen PPWR, dès 2028).'),
  ],
  s3='3 · Ce qui aura de la valeur',
  v5t='Dans 5 ans', v10t='Dans 10 ans',
  val5=[
   ('Les emplacements à flux', 'centres-villes, gares, axes de passage.'),
   ('Une marque premium', 'deux ou trois produits signatures.'),
   ('Le pilotage quotidien', 'cuissons, invendus, matière, heures.'),
   ('Des équipes stables', 'le premier goulot du métier.'),
  ],
  val10=[
   ('La maîtrise de la pâte', 'qui tient la recette tient la marge.'),
   ('La relation directe', 'fidélité, application, données clients.'),
   ('La taille du réseau', 'achats, notoriété, emplacements.'),
   ('La preuve', 'farines tracées, emballages réemployables.'),
  ],
  ck='4 · Ce qu’il faut',
  ch='Une entreprise solide, qui tient elle-même sa production, sa vente et sa logistique.',
  piliers=[
   ('Produire', 'Un atelier central à soi : recettes, pâte et qualité tenues en interne ; matières achetées en volume, sous contrat.'),
   ('Vendre', 'Un réseau de magasins à sa marque, piloté chaque jour : prix, assortiment et relation client sans intermédiaire.'),
   ('Livrer', 'Sa propre logistique, froid compris : des livraisons calées sur les cuissons, pas sur le planning d’un fournisseur.'),
  ],
  pk='Pourquoi',
  preuves=[
   ('Le fournisseur répercute ses hausses.', 'En 2022, La Lorraine annonçait à ses clients des hausses « sans précédent », à répercuter « autant que possible » (La Libre).'),
   ('Les gagnants sont intégrés.', 'La Lorraine produit et vend sous sa propre enseigne, Panos ; Marie Blachère, n° 1 en France, fabrique en magasin et achète par sa propre centrale.'),
   ('L’intégration protège.', 'Elle renforce la sécurité d’approvisionnement et retire les marges des intermédiaires (Strategy&amp;, PwC, The vertical advantage).'),
   ('Condition : la taille.', 'Un atelier et une flotte ne se rentabilisent qu’avec assez de magasins : c’est la raison de grandir en réseau.'),
  ],
  srck='Sources',
  src='Food Service Vision via Républik Retail (février 2026) · FranceAgriMer, étude des boulangeries 2022 · Provincies in Cijfers (mai 2024) · NSZ via BRUZZ (juin 2025) · Unizo via VRT (avril 2026) · L’Écho via Paris Match Belgique (septembre 2025) · Brussels Times (Vandemoortele – Délifrance, mars 2025) · La Libre Belgique (La Lorraine, mai 2022) · RetailDetail (La Lorraine Bakery Group, 2025) · Observatoire de la franchise (Marie Blachère) · Strategy&amp; (PwC), The vertical advantage · FranceAgriMer (beurre) · Xerfi (prix agricoles).',
  lim='Les chiffres français servent de référence : il n’existe pas de série publique récente du marché belge de la boulangerie. Les perspectives à 5 et 10 ans sont une lecture prospective fondée sur ces sources, pas une prévision chiffrée.',
  pied='L’Atelier by · note de marché · sources lues le 31 octobre 2026',
 ),
 'nl': dict(
  lang='nl', fichier='Markt-bakkerij-2026-NL.pdf', titreDoc='L’Atelier by · Marktnota · bakkerij en premium afbakpunten',
  hd='Marktnota · oktober 2026', code='NL',
  sur='Marktnota', h1='Bakkerij en premium afbakpunten',
  sous='De stand van de markt in oktober 2026, de evolutie, wat over 5 en 10 jaar waarde zal hebben, en wat nodig is om die te halen.',
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
   ('De ketens splitsen zich in tweeën.', 'Volume tegen een lage prijs aan de ene kant (Marie Blachère, nr. 1 in Frankrijk); aan de andere het topsegment dat een beleving verkoopt: Paul, Kayser, of Copains, in 2025 aangekondigd in Brussel tegen Parijse prijzen. Het midden zit klem.'),
   ('Leveranciers concentreren zich.', 'Vandemoortele koopt Délifrance in 2025 (930 mln €, 14 fabrieken); La Lorraine weegt 1,57 mld €. Wie zijn deegstukken aankoopt, hangt af van enkele reuzen.'),
   ('Kosten onder druk.', 'Boter bijna + 40 % op een jaar (2025), cacao boven 10 000 $ per ton, geïndexeerde lonen; Xerfi verwacht tot 2028 + 4 tot 6 % per jaar op de landbouwprijzen.'),
  ],
  s2='2 · Hoe de markt zal evolueren',
  h5='Binnen 5 jaar', a5='2031', h10='Binnen 10 jaar', a10='2036',
  evo5=[
   ('Consolidatie en polarisatie.', 'Bakkers die sluiten laten locaties en klanten vrij; gestructureerde ketens nemen ze over. Discount en premium winnen, het midden verliest.'),
   ('De hele dag.', 'Ontbijt, warme lunch, vieruurtje, specialty koffie, zitplaatsen: brood lokt, snacking, patisserie en dranken maken de marge.'),
   ('Afbakken gestuurd door de verkoop.', 'Ovenladingen van uur tot uur, onverkocht onder 2 % van de omzet, restjes doorverkocht; online bestellen en zakelijke klanten als aanvulling.'),
  ],
  evo10=[
   ('Het tekort aan bakkers wordt structureel.', 'Het deeg komt uit een centraal atelier, het eigen of dat van een industrieel; de winkel werkt af. Wie geen atelier heeft, hangt af van een ander.'),
   ('Een smaller landschap.', 'Enkele geïntegreerde groepen, uitmuntende ambachtelijke bakkers, weinig ruimte daartussen; compactere winkels, verbonden elektrische ovens.'),
   ('Gezondheid en bewijs.', 'Zuurdesem, lokale granen, minder suiker, korte etiketten, zichtbare herkomst; herbruikbare verpakkingen voor afhaal (Europese verordening PPWR, vanaf 2028).'),
  ],
  s3='3 · Wat waarde zal hebben',
  v5t='Over 5 jaar', v10t='Over 10 jaar',
  val5=[
   ('Locaties met passage', 'stadscentra, stations, drukke assen.'),
   ('Een premiummerk', 'twee, drie signatuurproducten.'),
   ('Dagelijkse sturing', 'afbakken, onverkocht, grondstoffen, uren.'),
   ('Stabiele teams', 'de eerste flessenhals van het vak.'),
  ],
  val10=[
   ('Controle over het deeg', 'wie het recept heeft, heeft de marge.'),
   ('De directe klantrelatie', 'trouw, app, klantendata.'),
   ('De omvang van het net', 'aankoop, bekendheid, locaties.'),
   ('Het bewijs', 'traceerbare bloem, herbruikbare verpakkingen.'),
  ],
  ck='4 · Wat nodig is',
  ch='Een solide onderneming die zelf haar productie, verkoop en logistiek in handen heeft.',
  piliers=[
   ('Produceren', 'Een eigen centraal atelier: recepten, deeg en kwaliteit in eigen huis; grondstoffen in volume en onder contract aangekocht.'),
   ('Verkopen', 'Een net van winkels onder eigen merk, dagelijks gestuurd: prijs, assortiment en klantrelatie zonder tussenpersoon.'),
   ('Leveren', 'Eigen logistiek, koeling inbegrepen: leveringen afgestemd op het afbakken, niet op de planning van een leverancier.'),
  ],
  pk='Waarom',
  preuves=[
   ('De leverancier rekent zijn stijgingen door.', 'In 2022 kondigde La Lorraine haar klanten ‘ongeziene’ prijsstijgingen aan, ‘zoveel mogelijk’ door te rekenen (La Libre).'),
   ('De winnaars zijn geïntegreerd.', 'La Lorraine produceert en verkoopt onder haar eigen keten, Panos; Marie Blachère, nr. 1 in Frankrijk, bakt in de winkel en koopt via een eigen aankoopcentrale.'),
   ('Integratie beschermt.', 'Ze versterkt de bevoorradingszekerheid en haalt de marges van tussenschakels weg (Strategy&amp;, PwC, The vertical advantage).'),
   ('Voorwaarde: schaal.', 'Een atelier en een vloot zijn pas rendabel met genoeg winkels: de reden om als net te groeien.'),
  ],
  srck='Bronnen',
  src='Food Service Vision via Républik Retail (februari 2026) · FranceAgriMer, studie bakkerijen 2022 · Provincies in Cijfers (mei 2024) · NSZ via BRUZZ (juni 2025) · Unizo via VRT (april 2026) · L’Echo via Paris Match België (september 2025) · Brussels Times (Vandemoortele – Délifrance, maart 2025) · La Libre Belgique (La Lorraine, mei 2022) · RetailDetail (La Lorraine Bakery Group, 2025) · Observatoire de la franchise (Marie Blachère) · Strategy&amp; (PwC), The vertical advantage · FranceAgriMer (boter) · Xerfi (landbouwprijzen).',
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
body{font-family:Gotham,Helvetica,Arial,sans-serif;color:#221E1A;font-size:7.1pt;line-height:1.37;background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.page{width:210mm;height:297mm;padding:8mm 12mm 0;position:relative;overflow:hidden;display:flex;flex-direction:column}
.hd{display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #8D1D2C;padding-bottom:1.6mm;margin-bottom:3.2mm;font-size:6.8pt;letter-spacing:.08em;text-transform:uppercase;color:#7a736a}
.hd .logo{height:4.4mm;width:auto;display:block}
.sur{font-size:7pt;letter-spacing:.12em;text-transform:uppercase;color:#8D1D2C;font-weight:500;margin-bottom:.8mm}
h1{font-family:Vank,Georgia,serif;font-weight:400;font-size:23pt;margin:0;letter-spacing:-.01em;line-height:1.05}
.sous{font-size:8.2pt;color:#7a736a;margin:1.2mm 0 3mm;line-height:1.36}
.tiles{display:grid;grid-template-columns:repeat(4,1fr);gap:2.6mm;margin-bottom:3.2mm}
.tile{border:1px solid #e6e0d8;border-radius:7px;background:#fbf9f5;padding:2mm 3mm 2.2mm}
.tile .k{font-size:6pt;letter-spacing:.07em;text-transform:uppercase;color:#7a736a;line-height:1.3;white-space:nowrap}
.tile .v{font-family:Vank,Georgia,serif;font-size:17pt;color:#8D1D2C;margin:.6mm 0 .3mm;white-space:nowrap;line-height:1.1}
.tile .s{font-size:6.4pt;color:#5a534b;line-height:1.35}
.sec{font-family:Vank,Georgia,serif;font-size:11.5pt;margin:0 0 1.8mm;padding-bottom:.8mm;border-bottom:1.2pt solid #8D1D2C;line-height:1.15}
.cols{display:grid;grid-template-columns:1fr 1fr;column-gap:6mm}
.pts{margin:0 0 2.6mm;padding:0;list-style:none}
.pts li{position:relative;padding-left:3.2mm;margin-bottom:1.1mm}
.pts li:before{content:'';position:absolute;left:0;top:1.3mm;width:1.4mm;height:1.4mm;border-radius:50%%;background:#8D1D2C}
.ld{color:#8D1D2C;font-weight:500}
.carte{border:1px solid #e6e0d8;border-radius:7px;padding:2mm 3mm .3mm;background:#fff}
.carte .tt{display:flex;align-items:center;justify-content:space-between;border-bottom:.5pt solid #e6e0d8;padding-bottom:.9mm;margin-bottom:1.4mm}
.carte .tt span{font-family:Vank,Georgia,serif;font-size:10.5pt;line-height:1.1}
.carte .tt em{font-style:normal;font-size:6.4pt;letter-spacing:.1em;color:#fff;background:#8D1D2C;border-radius:3mm;padding:.4mm 2.2mm}
.carte .pts{margin-bottom:.5mm}
.evo{margin-bottom:3mm}
.vals{display:grid;grid-template-columns:1fr 1fr;column-gap:6mm}
.vk{font-size:6.6pt;letter-spacing:.1em;text-transform:uppercase;color:#8D1D2C;font-weight:500;margin-bottom:.6mm}
.num{margin:0;padding:0;list-style:none;counter-reset:n}
.num li{counter-increment:n;position:relative;padding:.65mm 0 .65mm 5.5mm;border-bottom:.5pt solid #EAE3D8}
.num li:last-child{border-bottom:none}
.num li:before{content:counter(n);position:absolute;left:0;top:.5mm;font-family:Vank,Georgia,serif;font-size:9.5pt;color:#8D1D2C;line-height:1.1}
.concl{border:1.4pt solid #8D1D2C;border-radius:8px;background:#fbf9f5;padding:2.4mm 3.4mm 1.2mm;margin-top:2.8mm}
.concl .k{font-size:6.6pt;letter-spacing:.12em;text-transform:uppercase;color:#8D1D2C;font-weight:500}
.concl h2{font-family:Vank,Georgia,serif;font-weight:400;font-size:13pt;margin:.4mm 0 2mm;line-height:1.12;color:#221E1A}
.piliers{display:grid;grid-template-columns:repeat(3,1fr);gap:2.4mm;margin-bottom:2mm}
.pilier{background:#fff;border:1px solid #e6e0d8;border-top:2.4pt solid #8D1D2C;border-radius:5px;padding:1.5mm 2.4mm 1.7mm}
.pilier .t{font-family:Vank,Georgia,serif;font-size:10.5pt;color:#8D1D2C;line-height:1.1;margin-bottom:.5mm}
.concl .pk{font-size:6.3pt;letter-spacing:.1em;text-transform:uppercase;color:#7a736a;font-weight:500;margin-bottom:.8mm}
.concl .pts{margin-bottom:.6mm}
.bas{margin-top:auto;padding:1.6mm 0 5.5mm}
.src{font-size:5.8pt;color:#8a8379;line-height:1.4;border-top:.5pt solid #e6e0d8;padding-top:1.4mm}
.src .k{color:#7a736a;font-weight:500;letter-spacing:.08em;text-transform:uppercase;margin-right:1.2mm}
.src p{margin:0 0 .6mm}
.pied{display:flex;justify-content:space-between;font-size:6.1pt;color:#9a938a;margin-top:1.1mm}
'''


def pts(items):
    return '<ul class="pts">' + ''.join(f'<li><span class="ld">{a}</span> {b}</li>' for a, b in items) + '</ul>'


def num(items):
    return '<ol class="num">' + ''.join(f'<li><span class="ld">{a}</span> — {b}</li>' for a, b in items) + '</ol>'


def page(t):
    tiles = ''.join(f'<div class="tile"><div class="k">{k}</div><div class="v">{v}</div><div class="s">{s}</div></div>' for k, v, s in t['tiles'])
    piliers = ''.join(f'<div class="pilier"><div class="t">{a}</div>{b}</div>' for a, b in t['piliers'])
    e, p = t['etat'], t['preuves']
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
<div class="concl"><div class="k">{t['ck']}</div><h2>{t['ch']}</h2>
<div class="piliers">{piliers}</div>
<div class="pk">{t['pk']}</div>
<div class="cols">{pts(p[:2])}{pts(p[2:])}</div></div>
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
