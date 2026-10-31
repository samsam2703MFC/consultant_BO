# Note de marché « La boulangerie en Belgique : ce qui aura de la valeur dans 5 et 10 ans », octobre 2026. Une page A4 en
# français et la même page en néerlandais, deux PDF séparés, rendus par le Chromium de Playwright (rendre.js, à côté de ce
# fichier), qui refuse un rendu qui déborderait de la page.
# Demandes du 31 octobre 2026 : sources belges seulement ; un texte simple, écrit naturellement ; montrer, sources à l'appui,
# que les boulangers vont manquer, que la qualité compte, que la franchise aide à gérer et limite les erreurs, et qu'une marque
# compte ; mettre en avant ce qui aura de la valeur dans 5 et 10 ans (l'IA et l'automatisation changent beaucoup de métiers,
# mais il faudra toujours manger) ; finir sur ce que nous offrons (réseau solide aux finances saines, vingt ans d'expérience,
# deuxième génération qui a traversé les crises, passion du développement, investissements faits chez nous, et on mange ce
# qu'on produit).
# Les sources sont citées sur la page, lues le 31 octobre 2026 ; les vues à 5 et 10 ans sont une lecture de ces sources, pas
# une prévision chiffrée.
#   python3 generer.py   → Marche-boulangerie-2026-FR.pdf et Markt-bakkerij-2026-NL.pdf dans ce dossier
import base64, os, re, subprocess

ICI = os.path.dirname(os.path.abspath(__file__))
RACINE = os.path.abspath(os.path.join(ICI, '..', '..', '..'))
FONTES = 'file://' + RACINE + '/public/assets/ds/fonts/'
LOGO = 'data:image/png;base64,' + base64.b64encode(open(RACINE + '/public/assets/img/logo.png', 'rb').read()).decode()
TMP = '/tmp/marche-boulangerie'


def insec(s):
    """Espaces insécables avant les unités, dans les nombres et devant la ponctuation haute."""
    s = re.sub(r'(\d) (?=(%|€|ans|jaar|millions|miljoen|milliard|miljard|fois|keer))', '\\1\u00a0', s)
    s = re.sub(r'(\d) (?=\d{3}\b)', '\\1\u00a0', s)
    s = re.sub(r' ([:;?!»])', '\u00a0\\1', s)
    s = re.sub(r'(«) ', '\\1\u00a0', s)
    return s


T = {
 'fr': dict(
  lang='fr', fichier='Marche-boulangerie-2026-FR.pdf', titreDoc='L’Atelier by · Note de marché · la boulangerie en Belgique',
  hd='Note de marché · octobre 2026', code='FR',
  h1='La boulangerie en Belgique', h1b='Ce qui aura de la valeur dans 5 et 10 ans',
  sous='Un état des lieux du marché belge, avec un regard sur les terminaux de cuisson haut de gamme.',
  s1='Où en est le marché',
  marche=[
   'Les Belges n’ont pas arrêté de manger du pain : 82 % des francophones en achètent chaque semaine, et les trois quarts n’en mangent pas moins que l’an dernier. Ce qui change, c’est l’endroit. La grande surface et le hard discount font maintenant jeu égal avec la boulangerie : 69 % des acheteurs passent par l’une, 69 % par l’autre. On va au supermarché pour le prix.',
   'Pendant ce temps, les boulangers de quartier ferment et les industriels grandissent. La Lorraine, qui possède Panos, a dépassé 1,5 milliard d’euros de chiffre d’affaires en 2025, et Vandemoortele a annoncé le rachat de Délifrance. Le haut de gamme arrive aussi, avec Copains qui s’installe à Bruxelles.',
  ],
  s2='Ce que montrent les chiffres',
  preuves=[
   ('Les boulangers vont manquer',
    'Le métier est sur la liste officielle des métiers en pénurie, en Flandre comme en Wallonie. Un quart des boulangeries a fermé en quinze ans, et la Flandre compte 817 boulangers de moins qu’il y a dix ans.',
    'VDAB 2026 · liste wallonne 2025-2026 · Unizo · Provincies in Cijfers'),
   ('La qualité fait la différence',
    'Pour choisir leur pain, les clients regardent d’abord la fraîcheur (83 %) et le goût (81 %). Le prix vient après (64 %). Et ceux qui vont chez le boulanger y vont d’abord pour la qualité (71 %).',
    'APAQ-W et Listen, étude sur le pain, 2024'),
   ('La franchise aide à gérer et évite des erreurs',
    'Près d’un commerce de détail sur deux lancé en 2020 avait déjà fermé fin 2024. Le franchisé démarre avec une méthode qui a fait ses preuves, l’appui du réseau et sa logistique. Dans l’alimentation, un magasin franchisé fait plus de six fois le chiffre d’un magasin non franchisé.',
    'Startersatlas 2025 · Fédération belge de la franchise, 2022'),
   ('Une marque, ça compte',
    'Quand l’avenir est incertain, les Belges restent fidèles aux marques installées, celles qu’ils jugent fiables. Une marque forte tient aussi son prix : Copains vend à Bruxelles au prix de Paris, 2,90 € le pain au chocolat. Et près d’un client sur deux achète toujours son pain au même endroit.',
    'Best Brands Belgium 2023 (GfK) · Paris Match Belgique · APAQ-W'),
  ],
  s3='Ce qui aura de la valeur dans 5 et 10 ans',
  intro='L’IA et l’automatisation vont changer beaucoup de métiers. Selon ING, deux emplois belges sur trois seront touchés, surtout dans les bureaux, beaucoup moins les métiers du geste. Un logiciel peut écrire un rapport, il ne fera pas le pain du matin. Et il faudra toujours manger : la Belgique comptait 11,8 millions d’habitants en 2025, et le Bureau fédéral du Plan en attend 13,1 millions en 2080.',
  h5='Dans 5 ans', a5='2031', h10='Dans 10 ans', a10='2036',
  val5=[
   'Les bons emplacements, qui se libèrent avec les fermetures.',
   'Un produit qu’on vient chercher exprès, sous une marque qu’on reconnaît.',
   'Des équipes formées, qui restent.',
   'L’IA pour prévoir les ventes et jeter moins.',
  ],
  val10=[
   'Le savoir-faire, quand les boulangers formés seront rares.',
   'Son propre atelier. Celui qui n’en a pas dépendra des autres.',
   'La confiance : savoir d’où vient la farine et qui a fait le pain.',
   'Le lieu, un endroit chaleureux que ni l’IA ni la livraison ne remplacent.',
  ],
  ck='Ce que nous offrons',
  ch='Un réseau qui fabrique lui-même ce qu’il vend.',
  offre=[
   'Un réseau solide, avec des finances saines.',
   'Vingt ans d’expérience dans le métier.',
   'Une deuxième génération aux commandes, qui a connu les crises et les a traversées.',
   'La passion du développement : ouvrir, améliorer, grandir.',
   'Des investissements toujours faits chez nous : nos usines, nos transports, nos outils.',
   'Résultat, on dépend peu des fournisseurs, qui annonçaient des hausses « sans précédent » en 2022 (La Libre).',
  ],
  fin='Et n’oublions pas : on mange ce qu’on produit. Et nous, on aime bien manger.',
  srck='Sources',
  src='APAQ-W et Listen, étude sur le pain (septembre 2024) · VDAB, Knelpuntberoepen 2026 (février 2026) · Liste wallonne des métiers en pénurie, 2025-2026 (septembre 2025) · Unizo via VRT (avril 2026) · Provincies in Cijfers (mai 2024) · Startersatlas 2025, Unizo, UCM et Graydon (juillet 2025) · Fédération belge de la franchise (octobre 2022) · GfK et NielsenIQ, Best Brands Belgium (mars 2023) · RetailDetail (La Lorraine, 2025) · Brussels Times (mars 2025) · L’Écho via Paris Match Belgique (septembre 2025) · La Libre Belgique (mai 2022) · ING Economic Focus (avril 2024) · Bureau fédéral du Plan (février 2026).',
  lim='Les chiffres de consommation portent sur les Belges francophones. Les vues à 5 et 10 ans sont notre lecture de ces sources, pas une prévision.',
  pied='L’Atelier by · note de marché · sources lues le 31 octobre 2026',
 ),
 'nl': dict(
  lang='nl', fichier='Markt-bakkerij-2026-NL.pdf', titreDoc='L’Atelier by · Marktnota · de bakkerij in België',
  hd='Marktnota · oktober 2026', code='NL',
  h1='De bakkerij in België', h1b='Wat over 5 en 10 jaar waarde heeft',
  sous='Een stand van zaken van de Belgische markt, met een blik op premium afbakpunten.',
  s1='Waar staat de markt',
  marche=[
   'De Belg is niet gestopt met brood eten: 82 % van de Franstalige Belgen koopt elke week brood, en drie op vier eten er niet minder van dan vorig jaar. Wat verandert, is waar ze het kopen. De supermarkt en de harddiscount doen het nu even goed als de bakker: 69 % van de kopers gaat naar de ene, 69 % naar de andere. Naar de supermarkt gaat men voor de prijs.',
   'Intussen sluiten de buurtbakkers en groeien de industriële bakkers. La Lorraine, de eigenaar van Panos, haalde in 2025 meer dan 1,5 miljard euro omzet, en Vandemoortele kondigde de overname van Délifrance aan. Ook het topsegment komt eraan, met Copains dat zich in Brussel vestigt.',
  ],
  s2='Wat de cijfers tonen',
  preuves=[
   ('Er komen bakkers tekort',
    'Het beroep staat op de officiële lijst van knelpuntberoepen, in Vlaanderen en in Wallonië. In vijftien jaar sloot een kwart van de bakkerijen, en Vlaanderen telt 817 bakkers minder dan tien jaar geleden.',
    'VDAB 2026 · Waalse lijst 2025-2026 · Unizo · Provincies in Cijfers'),
   ('Kwaliteit maakt het verschil',
    'Bij het kiezen van hun brood kijken klanten eerst naar versheid (83 %) en smaak (81 %). De prijs komt pas daarna (64 %). Wie naar de bakker gaat, doet dat in de eerste plaats voor de kwaliteit (71 %).',
    'APAQ-W en Listen, studie over brood, 2024'),
   ('Franchise helpt beheren en voorkomt fouten',
    'Van de winkels die in 2020 opstartten, was bijna de helft eind 2024 al dicht. Een franchisenemer start met een beproefde methode, de steun van het net en zijn logistiek. In de voeding draait een franchisewinkel meer dan zes keer de omzet van een winkel zonder franchise.',
    'Startersatlas 2025 · Belgische Federatie van de Franchise, 2022'),
   ('Een merk telt',
    'Als de toekomst onzeker is, blijven Belgen trouw aan gevestigde merken die ze betrouwbaar vinden. Een sterk merk houdt ook zijn prijs: Copains verkoopt in Brussel aan Parijse prijzen, 2,90 € voor een chocoladebroodje. En bijna één klant op twee koopt zijn brood altijd op dezelfde plek.',
    'Best Brands Belgium 2023 (GfK) · Paris Match België · APAQ-W'),
  ],
  s3='Wat over 5 en 10 jaar waarde heeft',
  intro='AI en automatisering gaan veel jobs veranderen. Volgens ING geldt dat voor twee op de drie Belgische jobs, vooral op kantoor, veel minder voor handwerk. Software kan een rapport schrijven, maar geen ochtendbrood bakken. En eten moeten we altijd: België telde 11,8 miljoen inwoners in 2025, en het Federaal Planbureau verwacht er 13,1 miljoen in 2080.',
  h5='Over 5 jaar', a5='2031', h10='Over 10 jaar', a10='2036',
  val5=[
   'Goede locaties, die vrijkomen door de sluitingen.',
   'Een product waarvoor klanten speciaal komen, onder een merk dat ze kennen.',
   'Opgeleide teams die blijven.',
   'AI om de verkoop te voorspellen en minder weg te gooien.',
  ],
  val10=[
   'Vakmanschap, wanneer opgeleide bakkers zeldzaam zijn.',
   'Een eigen atelier. Wie er geen heeft, hangt af van anderen.',
   'Vertrouwen: weten waar de bloem vandaan komt en wie het brood bakte.',
   'De plek zelf, een warme zaak die AI noch levering vervangt.',
  ],
  ck='Wat wij bieden',
  ch='Een net dat zelf maakt wat het verkoopt.',
  offre=[
   'Een solide net, met gezonde financiën.',
   'Twintig jaar ervaring in het vak.',
   'Een tweede generatie aan het roer, die de crisissen heeft meegemaakt en doorstaan.',
   'Een passie voor ontwikkeling: openen, verbeteren, groeien.',
   'Investeringen die altijd in eigen huis blijven: onze fabrieken, ons transport, onze tools.',
   'Daardoor hangen we weinig af van leveranciers, die in 2022 ‘ongeziene’ prijsstijgingen aankondigden (La Libre).',
  ],
  fin='En vergeet niet: we eten wat we maken. En wij eten graag.',
  srck='Bronnen',
  src='APAQ-W en Listen, studie over brood (september 2024) · VDAB, Knelpuntberoepen 2026 (februari 2026) · Waalse lijst van knelpuntberoepen, 2025-2026 (september 2025) · Unizo via VRT (april 2026) · Provincies in Cijfers (mei 2024) · Startersatlas 2025, Unizo, UCM en Graydon (juli 2025) · Belgische Federatie van de Franchise (oktober 2022) · GfK en NielsenIQ, Best Brands Belgium (maart 2023) · RetailDetail (La Lorraine, 2025) · Brussels Times (maart 2025) · L’Echo via Paris Match België (september 2025) · La Libre Belgique (mei 2022) · ING Economic Focus (april 2024) · Federaal Planbureau (februari 2026).',
  lim='De consumptiecijfers gaan over Franstalige Belgen. De blik op 5 en 10 jaar is onze lezing van deze bronnen, geen voorspelling.',
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
body{font-family:Gotham,Helvetica,Arial,sans-serif;color:#221E1A;font-size:7.4pt;line-height:1.4;background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.page{width:210mm;height:297mm;padding:8mm 13mm 0;position:relative;overflow:hidden;display:flex;flex-direction:column}
.hd{display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #8D1D2C;padding-bottom:1.6mm;margin-bottom:3.4mm;font-size:6.8pt;letter-spacing:.08em;text-transform:uppercase;color:#7a736a}
.hd .logo{height:4.4mm;width:auto;display:block}
h1{font-family:Vank,Georgia,serif;font-weight:400;font-size:24pt;margin:0;letter-spacing:-.01em;line-height:1.02}
h1 span{display:block;color:#8D1D2C;font-size:16pt;margin-top:.8mm}
.sous{font-size:8.2pt;color:#7a736a;margin:1.4mm 0 3.6mm}
.sec{font-family:Vank,Georgia,serif;font-size:12.5pt;margin:0 0 2mm;padding-bottom:.9mm;border-bottom:1.2pt solid #8D1D2C;line-height:1.15}
.prose{column-count:2;column-gap:7mm;margin-bottom:3.4mm}
.prose p{margin:0 0 1.4mm}
.preuves{display:grid;grid-template-columns:1fr 1fr;gap:2.6mm 4mm;margin-bottom:3.6mm}
.preuve{border:1px solid #e6e0d8;border-radius:7px;background:#fbf9f5;padding:2.2mm 3.2mm 2mm;display:flex;flex-direction:column}
.preuve h3{font-family:Vank,Georgia,serif;font-weight:400;font-size:11.5pt;color:#8D1D2C;margin:0 0 1mm;line-height:1.12}
.preuve .ps{margin-top:auto;padding-top:1.2mm;font-size:6.1pt;color:#8a8379;line-height:1.35}
.intro{border-left:3px solid #8D1D2C;background:#fbf9f5;padding:2mm 3.4mm;margin-bottom:2.6mm;font-size:7.7pt;line-height:1.42}
.cols{display:grid;grid-template-columns:1fr 1fr;column-gap:4mm;margin-bottom:3.4mm}
.carte{border:1px solid #e6e0d8;border-radius:7px;padding:2mm 3.2mm .8mm}
.carte .tt{display:flex;align-items:center;justify-content:space-between;border-bottom:.5pt solid #e6e0d8;padding-bottom:.9mm;margin-bottom:1.5mm}
.carte .tt span{font-family:Vank,Georgia,serif;font-size:11.5pt;line-height:1.1}
.carte .tt em{font-style:normal;font-size:6.4pt;letter-spacing:.1em;color:#fff;background:#8D1D2C;border-radius:3mm;padding:.4mm 2.2mm}
.pts{margin:0;padding:0;list-style:none}
.pts li{position:relative;padding-left:3.4mm;margin-bottom:1.1mm}
.pts li:before{content:'';position:absolute;left:0;top:1.35mm;width:1.4mm;height:1.4mm;border-radius:50%%;background:#8D1D2C}
.concl{border:1.4pt solid #8D1D2C;border-radius:8px;background:#fbf9f5;padding:2.4mm 3.8mm 1.2mm}
.concl .k{font-size:6.8pt;letter-spacing:.12em;text-transform:uppercase;color:#8D1D2C;font-weight:500}
.concl h2{font-family:Vank,Georgia,serif;font-weight:400;font-size:13.5pt;margin:.3mm 0 1.6mm;line-height:1.12}
.concl .offre{margin-bottom:1.2mm}
.concl .fin{font-family:Vank,Georgia,serif;font-size:12.5pt;color:#8D1D2C;line-height:1.2;border-top:.5pt solid #e6d6d2;padding:1.4mm 0 .8mm}
.bas{margin-top:auto;padding:1.6mm 0 5.5mm}
.src{font-size:5.9pt;color:#8a8379;line-height:1.42;border-top:.5pt solid #e6e0d8;padding-top:1.4mm}
.src .k{color:#7a736a;font-weight:500;letter-spacing:.08em;text-transform:uppercase;margin-right:1.2mm}
.src p{margin:0 0 .6mm}
.pied{display:flex;justify-content:space-between;font-size:6.1pt;color:#9a938a;margin-top:1mm}
'''


def pts(items):
    return '<ul class="pts">' + ''.join(f'<li>{x}</li>' for x in items) + '</ul>'


def prose(paras):
    return '<div class="prose">' + ''.join(f'<p>{x}</p>' for x in paras) + '</div>'


def page(t):
    preuves = ''.join(f'<div class="preuve"><h3>{h}</h3><div>{x}</div><div class="ps">{s}</div></div>' for h, x, s in t['preuves'])
    corps = f'''<section class="page">
<div class="hd"><img class="logo" src="{LOGO}" alt="L’Atelier by"><span>{t['hd']}</span><span>{t['code']}</span></div>
<h1>{t['h1']}<span>{t['h1b']}</span></h1>
<div class="sous">{t['sous']}</div>
<div class="sec">{t['s1']}</div>
{prose(t['marche'])}
<div class="sec">{t['s2']}</div>
<div class="preuves">{preuves}</div>
<div class="sec">{t['s3']}</div>
<div class="intro">{t['intro']}</div>
<div class="cols">
<div class="carte"><div class="tt"><span>{t['h5']}</span><em>{t['a5']}</em></div>{pts(t['val5'])}</div>
<div class="carte"><div class="tt"><span>{t['h10']}</span><em>{t['a10']}</em></div>{pts(t['val10'])}</div>
</div>
<div class="concl"><div class="k">{t['ck']}</div><h2>{t['ch']}</h2>
<div class="cols offre">{pts(t['offre'][:4])}{pts(t['offre'][4:])}</div>
<div class="fin">{t['fin']}</div></div>
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
