# Note de marché « La boulangerie en Belgique : ce qui aura de la valeur dans 5 et 10 ans », octobre 2026. Une page A4 en
# français et la même page en néerlandais, deux PDF séparés, rendus par le Chromium de Playwright (rendre.js, à côté de ce
# fichier), qui refuse un rendu qui déborderait de la page.
# Demandes du 31 octobre 2026 : sources belges seulement ; un texte simple, écrit naturellement ; mettre en avant ce qui aura de
# la valeur dans 5 et 10 ans (l'IA et l'automatisation changent beaucoup de métiers, mais il faudra toujours manger) ; conclure
# sur une entreprise solide qui tient elle-même sa production, sa vente et sa logistique.
# Les sources sont citées en pied de page, lues le 31 octobre 2026 ; les vues à 5 et 10 ans sont une lecture de ces sources,
# pas une prévision chiffrée.
#   python3 generer.py   → Marche-boulangerie-2026-FR.pdf et Markt-bakkerij-2026-NL.pdf dans ce dossier
import base64, os, re, subprocess

ICI = os.path.dirname(os.path.abspath(__file__))
RACINE = os.path.abspath(os.path.join(ICI, '..', '..', '..'))
FONTES = 'file://' + RACINE + '/public/assets/ds/fonts/'
LOGO = 'data:image/png;base64,' + base64.b64encode(open(RACINE + '/public/assets/img/logo.png', 'rb').read()).decode()
TMP = '/tmp/marche-boulangerie'


def insec(s):
    """Espaces insécables avant les unités, dans les nombres et devant la ponctuation haute."""
    s = re.sub(r'(\d) (?=(%|€|ans|jaar|millions|miljoen|milliard|miljard))', '\\1\u00a0', s)
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
  tiles=[
   ('1 sur 4', 'C’est la part des boulangeries belges qui ont fermé en quinze ans (Unizo, 2026).'),
   ('69 %', 'des Belges francophones achètent leur pain en grande surface. Autant qu’en boulangerie (APAQ-W, 2024).'),
   ('65 %', 'des emplois belges vont être transformés par l’IA, surtout dans les bureaux (ING, 2024).'),
  ],
  s1='Où en est le marché',
  marche=[
   'Les Belges n’ont pas arrêté de manger du pain. 82 % des francophones en achètent chaque semaine, et les trois quarts disent qu’ils n’en mangent pas moins que l’an dernier. Ce qui change, c’est l’endroit où ils l’achètent. La grande surface et le hard discount font maintenant jeu égal avec la boulangerie. On y va pour le prix. Chez le boulanger, on vient pour la qualité et la fraîcheur, même si deux clients sur trois trouvent ça cher.',
   'Les boulangers de quartier, eux, s’en vont. Un quart a fermé en quinze ans. En Flandre, il en reste 2 640, soit 817 de moins qu’il y a dix ans, et Bruxelles en a perdu 11 % entre 2018 et 2022. Le plus souvent, il n’y a pas de repreneur, ou on ne trouve plus personne pour travailler la nuit et le week-end.',
   'En face, les industriels belges grandissent. La Lorraine, qui possède Panos, a dépassé 1,5 milliard d’euros de chiffre d’affaires en 2025, et Vandemoortele a annoncé le rachat de Délifrance. Le haut de gamme arrive aussi : Copains s’installe à Bruxelles avec les prix de Paris, 2,90 € le pain au chocolat.',
  ],
  s2='Ce qui aura de la valeur dans 5 et 10 ans',
  intro='L’IA et l’automatisation vont changer beaucoup de métiers. Selon ING, deux emplois belges sur trois seront touchés, surtout dans les bureaux, alors que les métiers du geste y échappent en grande partie. Un logiciel peut écrire un rapport, il ne fera pas le pain du matin. Et il faudra toujours manger : la Belgique comptait 11,8 millions d’habitants en 2025, et le Bureau fédéral du Plan en attend 13,1 millions en 2080. Ce qui se goûte, se sent et se partage garde sa valeur.',
  h5='Dans 5 ans', a5='2031', h10='Dans 10 ans', a10='2036',
  val5=[
   'Les bons emplacements, là où les gens passent. Beaucoup vont se libérer avec les fermetures.',
   'Un produit qu’on vient chercher exprès, et une marque qu’on reconnaît.',
   'Des équipes qui restent. Trouver et garder du monde sera le vrai problème.',
   'Un magasin qui vit toute la journée : petit-déjeuner, midi, café, goûter.',
   'L’IA au service du magasin, pour prévoir les ventes, commander juste et jeter moins.',
  ],
  val10=[
   'Le savoir-faire. Il y aura moins de boulangers formés, et ceux qui en ont auront une longueur d’avance.',
   'Son propre atelier. La pâte se fera de plus en plus en atelier central, et celui qui n’a pas le sien dépendra de celui des autres.',
   'La confiance du client : savoir d’où vient la farine, ce qu’il y a dedans et qui l’a fait.',
   'Le lieu. Un endroit chaleureux où l’on s’arrête, ce que ni l’IA ni la livraison ne remplacent.',
   'La taille du réseau, pour acheter mieux et faire tourner l’atelier.',
  ],
  ck='Ce qu’il faut pour tenir',
  ch='Une entreprise solide, qui fait elle-même sa production, sa vente et sa logistique.',
  concl=[
   'Ceux qui dépendent des autres paient le prix fort. En 2022, La Lorraine prévenait ses clients de hausses « sans précédent », qu’elle comptait répercuter « autant que possible » (La Libre). La même année, la facture d’énergie des entreprises belges a pris 35 à 57 % (Belfius), et seuls 7 % des indépendants arrivaient à faire suivre leurs prix (Unizo). Les fournisseurs se regroupent, et moins il en reste, moins on a de poids pour négocier.',
   'Avec son atelier, ses magasins et ses camions, on décide soi-même de la qualité, du prix et de l’heure de livraison. C’est ce qu’a fait La Lorraine, qui fabrique et vend sous sa propre enseigne, Panos. Il faut de la taille pour y arriver, parce qu’un atelier ne tourne que s’il y a assez de magasins derrière. C’est une bonne raison de grandir en réseau.',
  ],
  srck='Sources',
  src='APAQ-W et Listen, étude sur le pain (septembre 2024) · Unizo via VRT (septembre 2022 et avril 2026) · Provincies in Cijfers (mai 2024) · NSZ via BRUZZ (juin 2025) · RetailDetail (La Lorraine, 2025) · Brussels Times (Vandemoortele et Délifrance, mars 2025) · L’Écho via Paris Match Belgique (septembre 2025) · La Libre Belgique (mai 2022) · Belfius Research (mars 2024) · ING Economic Focus (avril 2024) · Bureau fédéral du Plan (février 2026).',
  lim='Les chiffres de consommation portent sur les Belges francophones. Les vues à 5 et 10 ans sont notre lecture de ces sources, pas une prévision.',
  pied='L’Atelier by · note de marché · sources lues le 31 octobre 2026',
 ),
 'nl': dict(
  lang='nl', fichier='Markt-bakkerij-2026-NL.pdf', titreDoc='L’Atelier by · Marktnota · de bakkerij in België',
  hd='Marktnota · oktober 2026', code='NL',
  h1='De bakkerij in België', h1b='Wat over 5 en 10 jaar waarde heeft',
  sous='Een stand van zaken van de Belgische markt, met een blik op premium afbakpunten.',
  tiles=[
   ('1 op 4', 'Belgische bakkerijen sloot de voorbije vijftien jaar de deuren (Unizo, 2026).'),
   ('69 %', 'van de Franstalige Belgen koopt brood in de supermarkt. Even veel als bij de bakker (APAQ-W, 2024).'),
   ('65 %', 'van de Belgische jobs zal door AI veranderen, vooral op kantoor (ING, 2024).'),
  ],
  s1='Waar staat de markt',
  marche=[
   'De Belg is niet gestopt met brood eten. 82 % van de Franstalige Belgen koopt elke week brood, en drie op vier zeggen dat ze er niet minder van eten dan vorig jaar. Wat wel verandert, is waar ze het kopen. De supermarkt en de harddiscount doen het nu even goed als de bakker. Daar gaat men voor de prijs. Bij de bakker komt men voor kwaliteit en versheid, ook al vinden twee op de drie klanten het duur.',
   'De buurtbakker verdwijnt intussen. In vijftien jaar sloot een kwart van de zaken. In Vlaanderen zijn er nog 2 640 bakkers, 817 minder dan tien jaar geleden, en Brussel verloor tussen 2018 en 2022 11 % van zijn bakkerijen. Meestal is er geen overnemer, of vindt men niemand meer die ’s nachts en in het weekend wil werken.',
   'Aan de andere kant groeien de Belgische industriële bakkers. La Lorraine, de eigenaar van Panos, haalde in 2025 meer dan 1,5 miljard euro omzet, en Vandemoortele kondigde de overname van Délifrance aan. Ook het topsegment komt eraan: Copains opent in Brussel met Parijse prijzen, 2,90 € voor een chocoladebroodje.',
  ],
  s2='Wat over 5 en 10 jaar waarde heeft',
  intro='AI en automatisering gaan veel jobs veranderen. Volgens ING geldt dat voor twee op de drie Belgische jobs, vooral op kantoor, terwijl handwerk grotendeels buiten schot blijft. Software kan een rapport schrijven, maar geen ochtendbrood bakken. En eten moeten we altijd: België telde 11,8 miljoen inwoners in 2025, en het Federaal Planbureau verwacht er 13,1 miljoen in 2080. Wat je kan proeven, ruiken en delen, houdt zijn waarde.',
  h5='Over 5 jaar', a5='2031', h10='Over 10 jaar', a10='2036',
  val5=[
   'Goede locaties, waar veel mensen passeren. Door de sluitingen komen er heel wat vrij.',
   'Een product waarvoor klanten speciaal komen, en een merk dat ze herkennen.',
   'Teams die blijven. Mensen vinden en houden wordt het echte probleem.',
   'Een winkel die de hele dag leeft: ontbijt, middag, koffie, vieruurtje.',
   'AI ten dienste van de winkel, om de verkoop te voorspellen, juist te bestellen en minder weg te gooien.',
  ],
  val10=[
   'Vakmanschap. Er zullen minder opgeleide bakkers zijn, en wie ze heeft, heeft een voorsprong.',
   'Een eigen atelier. Het deeg wordt steeds vaker centraal gemaakt, en wie geen eigen atelier heeft, hangt af van dat van een ander.',
   'Het vertrouwen van de klant: weten waar de bloem vandaan komt, wat erin zit en wie het gemaakt heeft.',
   'De plek zelf. Een warme zaak waar je even blijft, iets wat AI of levering niet vervangen.',
   'De omvang van het net, om beter in te kopen en het atelier te laten draaien.',
  ],
  ck='Wat nodig is om stand te houden',
  ch='Een solide onderneming die zelf haar productie, verkoop en logistiek in handen heeft.',
  concl=[
   'Wie van anderen afhangt, betaalt de prijs. In 2022 waarschuwde La Lorraine haar klanten voor ‘ongeziene’ prijsstijgingen, die ze ‘zoveel mogelijk’ wou doorrekenen (La Libre). Datzelfde jaar steeg de energiefactuur van de Belgische bedrijven met 35 tot 57 % (Belfius), terwijl amper 7 % van de ondernemers die kosten kon doorrekenen (Unizo). De leveranciers worden ook minder talrijk, en hoe minder er overblijven, hoe minder je kan onderhandelen.',
   'Met een eigen atelier, eigen winkels en eigen vrachtwagens beslis je zelf over kwaliteit, prijs en levertijd. Dat is wat La Lorraine deed: ze maakt en verkoopt onder haar eigen keten, Panos. Daarvoor is schaal nodig, want een atelier draait pas als er genoeg winkels achter staan. Een goede reden om als net te groeien.',
  ],
  srck='Bronnen',
  src='APAQ-W en Listen, studie over brood (september 2024) · Unizo via VRT (september 2022 en april 2026) · Provincies in Cijfers (mei 2024) · NSZ via BRUZZ (juni 2025) · RetailDetail (La Lorraine, 2025) · Brussels Times (Vandemoortele en Délifrance, maart 2025) · L’Echo via Paris Match België (september 2025) · La Libre Belgique (mei 2022) · Belfius Research (maart 2024) · ING Economic Focus (april 2024) · Federaal Planbureau (februari 2026).',
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
body{font-family:Gotham,Helvetica,Arial,sans-serif;color:#221E1A;font-size:7.6pt;line-height:1.42;background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.page{width:210mm;height:297mm;padding:9mm 13mm 0;position:relative;overflow:hidden;display:flex;flex-direction:column}
.hd{display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #8D1D2C;padding-bottom:1.8mm;margin-bottom:4mm;font-size:6.8pt;letter-spacing:.08em;text-transform:uppercase;color:#7a736a}
.hd .logo{height:4.4mm;width:auto;display:block}
h1{font-family:Vank,Georgia,serif;font-weight:400;font-size:26pt;margin:0;letter-spacing:-.01em;line-height:1.02}
h1 span{display:block;color:#8D1D2C;font-size:17pt;margin-top:1mm}
.sous{font-size:8.4pt;color:#7a736a;margin:1.6mm 0 3.8mm}
.tiles{display:grid;grid-template-columns:repeat(3,1fr);gap:3mm;margin-bottom:4.2mm}
.tile{border:1px solid #e6e0d8;border-radius:7px;background:#fbf9f5;padding:2.6mm 3.4mm 2.8mm}
.tile .v{font-family:Vank,Georgia,serif;font-size:20pt;color:#8D1D2C;line-height:1.05;margin-bottom:.8mm;white-space:nowrap}
.tile .s{font-size:7.1pt;color:#5a534b;line-height:1.4}
.sec{font-family:Vank,Georgia,serif;font-size:13pt;margin:0 0 2.2mm;padding-bottom:1mm;border-bottom:1.2pt solid #8D1D2C;line-height:1.15}
.prose{column-count:2;column-gap:7mm;margin-bottom:3.8mm}
.prose p{margin:0 0 1.6mm}
.intro{border-left:3px solid #8D1D2C;background:#fbf9f5;padding:2.2mm 3.4mm;margin-bottom:2.8mm;font-size:7.9pt;line-height:1.45}
.cols{display:grid;grid-template-columns:1fr 1fr;column-gap:5mm;margin-bottom:3.8mm}
.carte{border:1px solid #e6e0d8;border-radius:7px;padding:2.4mm 3.4mm 1mm}
.carte .tt{display:flex;align-items:center;justify-content:space-between;border-bottom:.5pt solid #e6e0d8;padding-bottom:1mm;margin-bottom:1.8mm}
.carte .tt span{font-family:Vank,Georgia,serif;font-size:12pt;line-height:1.1}
.carte .tt em{font-style:normal;font-size:6.6pt;letter-spacing:.1em;color:#fff;background:#8D1D2C;border-radius:3mm;padding:.4mm 2.3mm}
.pts{margin:0;padding:0;list-style:none}
.pts li{position:relative;padding-left:3.4mm;margin-bottom:1.3mm}
.pts li:before{content:'';position:absolute;left:0;top:1.45mm;width:1.5mm;height:1.5mm;border-radius:50%%;background:#8D1D2C}
.concl{border:1.4pt solid #8D1D2C;border-radius:8px;background:#fbf9f5;padding:2.8mm 3.8mm 1.4mm}
.concl .k{font-size:6.8pt;letter-spacing:.12em;text-transform:uppercase;color:#8D1D2C;font-weight:500}
.concl h2{font-family:Vank,Georgia,serif;font-weight:400;font-size:14pt;margin:.6mm 0 2.2mm;line-height:1.12}
.concl .prose{margin-bottom:0}
.bas{margin-top:auto;padding:2mm 0 6mm}
.src{font-size:6.1pt;color:#8a8379;line-height:1.45;border-top:.5pt solid #e6e0d8;padding-top:1.6mm}
.src .k{color:#7a736a;font-weight:500;letter-spacing:.08em;text-transform:uppercase;margin-right:1.2mm}
.src p{margin:0 0 .7mm}
.pied{display:flex;justify-content:space-between;font-size:6.2pt;color:#9a938a;margin-top:1.2mm}
'''


def pts(items):
    return '<ul class="pts">' + ''.join(f'<li>{x}</li>' for x in items) + '</ul>'


def prose(paras):
    return '<div class="prose">' + ''.join(f'<p>{x}</p>' for x in paras) + '</div>'


def page(t):
    tiles = ''.join(f'<div class="tile"><div class="v">{v}</div><div class="s">{s}</div></div>' for v, s in t['tiles'])
    corps = f'''<section class="page">
<div class="hd"><img class="logo" src="{LOGO}" alt="L’Atelier by"><span>{t['hd']}</span><span>{t['code']}</span></div>
<h1>{t['h1']}<span>{t['h1b']}</span></h1>
<div class="sous">{t['sous']}</div>
<div class="tiles">{tiles}</div>
<div class="sec">{t['s1']}</div>
{prose(t['marche'])}
<div class="sec">{t['s2']}</div>
<div class="intro">{t['intro']}</div>
<div class="cols">
<div class="carte"><div class="tt"><span>{t['h5']}</span><em>{t['a5']}</em></div>{pts(t['val5'])}</div>
<div class="carte"><div class="tt"><span>{t['h10']}</span><em>{t['a10']}</em></div>{pts(t['val10'])}</div>
</div>
<div class="concl"><div class="k">{t['ck']}</div><h2>{t['ch']}</h2>{prose(t['concl'])}</div>
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
