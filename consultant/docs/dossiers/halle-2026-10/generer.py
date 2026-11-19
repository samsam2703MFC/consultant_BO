# Le dossier investisseur de Halle, version 2 : HTML A4 rendu en PDF par Chromium, en français puis en néerlandais (mêmes pages,
# mêmes chiffres). Données : fichiers JSON lus sur le cockpit en ligne le 9 octobre 2026. Sans gras dans le texte courant ;
# logo de la marque ; juin et juillet expliqués par les travaux du viaduc de Halle ; le food cost du P&L du cockpit en regard.
import json, html, base64, re
SP = '/tmp/claude-0/-home-user-consultant-BO/64c06f7c-f933-5fd1-a986-9b6771ad42a2/scratchpad'
H = lambda s: html.escape(str(s))
LOGO = 'data:image/png;base64,' + base64.b64encode(open('/home/user/consultant_BO/public/assets/img/logo.png', 'rb').read()).decode()
def nf(v, d=0):
    if v is None: return '—'
    return f'{v:,.{d}f}'.replace(',', ' ').replace('.', ',')
fE = lambda v, d=0: nf(v, d) + ' €'
fP = lambda v, d=1: nf(v, d) + ' %'

pnl = json.load(open(SP + '/halle/pnl-mois.json'))
perf = json.load(open(SP + '/mat/perf.json')); perf = perf if isinstance(perf, list) else perf.get('perf') or perf.get('data')
bud = json.load(open(SP + '/halle/budgets.json')); bud = bud if isinstance(bud, list) else bud.get('budgets') or bud.get('data')
stats = json.load(open(SP + '/halle/stats-2026-09.json')); sem = json.load(open(SP + '/halle/semaine-type.json'))
sc3 = json.load(open(SP + '/halle/scoring-2026-T3.json')); photos = json.load(open(SP + '/halle/photos-meta.json'))
Hm = [m for m in pnl['magasins'] if m['id'] == '4'][0]; autres = [m for m in pnl['magasins'] if m['id'] != '4']
perfH = {r['mois']: r for r in perf if str(r['storeId']) == '4' and r['annee'] == 2026}
perfA = {}
for r in perf:
    if str(r['storeId']) != '4' and r['annee'] == 2026: perfA.setdefault(r['mois'], []).append(r)
budget = [x for x in bud if str(x.get('storeId')) == '4'][0]
# Le food cost du P&L du cockpit (recettes vendues, ticket par ticket) : août, septembre, semaine du 5 octobre
def plH(f):
    d = json.load(open(f)); return [x for x in d['magasins'] if x.get('shopId') == '4'][0]
PL = {'aout': plH(SP + '/halle/moisPL/2026-08.json'), 'sept': plH(SP + '/halle/moisPL/2026-09.json'), 'sem': plH(SP + '/halle/sem/2026-10-05.json')}

MOIS = {'fr': {2: 'Février', 3: 'Mars', 4: 'Avril', 5: 'Mai', 6: 'Juin', 7: 'Juillet', 8: 'Août', 9: 'Septembre', 10: 'Octobre'},
        'nl': {2: 'Februari', 3: 'Maart', 4: 'April', 5: 'Mei', 6: 'Juni', 7: 'Juli', 8: 'Augustus', 9: 'September', 10: 'Oktober'}}
COURT = {'fr': {3: 'Mars', 4: 'Avr.', 5: 'Mai', 6: 'Juin', 7: 'Juil.', 8: 'Août', 9: 'Sept.'}, 'nl': {3: 'Mrt', 4: 'Apr', 5: 'Mei', 6: 'Jun', 7: 'Jul', 8: 'Aug', 9: 'Sep'}}
JOURS = {'fr': {'lundi': 'lun.', 'mardi': 'mar.', 'mercredi': 'mer.', 'jeudi': 'jeu.', 'vendredi': 'ven.', 'samedi': 'sam.', 'dimanche': 'dim.'},
         'nl': {'lundi': 'ma', 'mardi': 'di', 'mercredi': 'wo', 'jeudi': 'do', 'vendredi': 'vr', 'samedi': 'za', 'dimanche': 'zo'}}
CH = {'nl': {'Matière première': 'Grondstoffen', 'Emballage': 'Verpakking', 'Loyer + charges locatives': 'Huur + huurlasten', 'Énergie (eau, gaz, électricité)': 'Energie (water, gas, elektriciteit)',
             'Maintenance & nettoyage': 'Onderhoud & schoonmaak', 'Assurances, licences, etc.': 'Verzekeringen, licenties, enz.', 'Royalties franchise': 'Franchiseroyalty’s', 'Redevance assistance': 'Bijstandsvergoeding',
             'Marketing national / local': 'Nationale / lokale marketing', 'Amortissements / leasing matériel': 'Afschrijvingen / leasing materiaal', 'Autres (comptabilité, banque, divers)': 'Andere (boekhouding, bank, diversen)',
             'Matière,première & Emballage': 'Grondstoffen & verpakking', 'Occupation': 'Huisvesting', 'Redevances de marque': 'Merkvergoedingen', 'Investissement': 'Investering', 'Frais généraux': 'Algemene kosten',
             'Selon emplacement': 'Volgens locatie', 'Variable selon la saison': 'Variabel volgens het seizoen', 'Entretien du matériel': 'Onderhoud van het materiaal', 'Redevance marque': 'Merkvergoeding',
             'Accompagnement franchiseur': 'Begeleiding door de franchisegever', 'Communication': 'Communicatie', 'Machines, mobilier': 'Machines, meubilair'}}

# --- Les mois
mois = []
for x in Hm['mois']:
    if x.get('indispo'): continue
    m = int(x['mois'][5:7]); p = perfH.get(m, {}); fc = p.get('foodCostPct'); ca = x['ca']
    mat = ca * fc / 100 if fc is not None else None
    res = ca - mat - x['labour'] - x['overhead'] if mat is not None else None
    mois.append(dict(m=m, jours=x['joursVentes'], ca=ca, budget=p.get('caBudget'), tickets=p.get('tickets'), panier=p.get('panierMoyen'), food=fc, mat=mat,
                     lab=x['labour'], labPct=x['labourPct'], oh=x['overhead'], ohPct=x['overheadPct'], res=res, resPct=(100 * res / ca if res is not None and ca else None), partiel=(m in (2, 10))))
pleins = [x for x in mois if 3 <= x['m'] <= 9]
cum = dict(ca=sum(x['ca'] for x in pleins), mat=sum(x['mat'] for x in pleins), lab=sum(x['lab'] for x in pleins), oh=sum(x['oh'] for x in pleins), tk=sum(x['tickets'] for x in pleins), bud=sum(x['budget'] for x in pleins), j=sum(x['jours'] for x in pleins))
cum['res'] = cum['ca'] - cum['mat'] - cum['lab'] - cum['oh']
caMois = cum['ca'] / 7
M = lambda m: [x for x in pleins if x['m'] == m][0]
sansTravaux = sum(x['ca'] / 0.8 - x['ca'] for x in pleins if x['m'] in (6, 7))
# --- Le réseau (parts seulement)
aCA = sum(x['ca'] for m in autres for x in m['mois'] if not x.get('indispo') and '2026-03' <= x['mois'] <= '2026-09')
aLab = sum(x['labour'] for m in autres for x in m['mois'] if not x.get('indispo') and '2026-03' <= x['mois'] <= '2026-09')
aOh = sum(x['overhead'] for m in autres for x in m['mois'] if not x.get('indispo') and '2026-03' <= x['mois'] <= '2026-09')
aFoodL = [r['foodCostPct'] for mm in range(3, 10) for r in perfA.get(mm, []) if r.get('foodCostPct') and r['foodCostPct'] < 60]
aFood = sum(aFoodL) / len(aFoodL)
aPanL = [r['panierMoyen'] for mm in range(3, 10) for r in perfA.get(mm, []) if r.get('panierMoyen')]
aPan = sum(aPanL) / len(aPanL)
rangs = sorted([(m['court'], sum(x['ca'] for x in m['mois'] if not x.get('indispo') and '2026-03' <= x['mois'] <= '2026-09')) for m in pnl['magasins']], key=lambda t: -t[1])
rangH = [i + 1 for i, t in enumerate(rangs) if t[0] == 'Halle'][0]
hs = [m for m in sc3['magasins'] if str(m['id']) == '4'][0]; po = hs['postes']
cats = sorted(stats.get('categories') or [], key=lambda c: -(c.get('v') or 0)); tv = sum(c.get('v') or 0 for c in cats)
fam = {}
for c in cats: fam[c.get('groupe') or 'Autres'] = fam.get(c.get('groupe') or 'Autres', 0) + (c.get('v') or 0)
fam = sorted(fam.items(), key=lambda t: -t[1])
# --- Marges par catégorie : septembre, coût de recette du panel produit par produit ; une recette invraisemblable (coût au-dessus du
# prix ou sous 5 % de celui-ci) est écartée, la couverture dit sur quelle part du chiffre de la catégorie la marge est mesurée.
def plausible(c, v): return c is not None and v and v > 0 and 0.05 * v <= c < v
MCall = []; VK = 0.0; CK = 0.0
for c in cats:
    v = c.get('v') or 0
    if v <= 0: continue
    vk = sum((x.get('v') or 0) for x in c['produits'] if plausible(x.get('c'), x.get('v'))); ck = sum((x.get('c') or 0) for x in c['produits'] if plausible(x.get('c'), x.get('v')))
    VK += vk; CK += ck
    MCall.append(dict(nom=c['nom'], groupe=c.get('groupe') or '—', v=v, q=c.get('q') or 0, refs=c.get('refs') or 0, part=100 * v / tv, couv=100 * vk / v, vk=vk, ck=ck,
                      food=100 * ck / vk if vk else None, marge=100 * (1 - ck / vk) if vk else None, coef=vk / ck if ck else None))
MC = MCall[:20]; reste = MCall[20:]
autresV = sum(x['v'] for x in reste); autresQ = sum(x['q'] for x in reste); autresVK = sum(x['vk'] for x in reste); autresCK = sum(x['ck'] for x in reste)
autresM = 100 * (1 - autresCK / autresVK) if autresVK else None
margeT = 100 * (1 - CK / VK); foodT = 100 * CK / VK; coefT = VK / CK; couvT = 100 * VK / tv
def zoneM(m): return 'mu' if m is None else ('ok' if m >= 60 else ('att' if m >= 40 else 'ko'))
ZCOL = {'ok': '#2d7a3e', 'att': '#C96A1B', 'ko': '#C0182B', 'mu': '#b8b1a6'}
json.dump(dict(categories=MCall, autres=dict(v=autresV, q=autresQ, part=100 * autresV / tv, marge=autresM, n=len(reste)), total=dict(v=tv, marge=margeT, food=foodT, coef=coefT, couv=couvT)), open(SP + '/halle/marges-cat.json', 'w'), ensure_ascii=False, indent=1)
choix = ['2026-10-05-1207', '2026-10-05-1211', '2026-10-05-1209', '2026-09-30-1206', '2026-10-08-1206', '2026-10-05-1213', '2026-10-01-1216', '2026-10-05-1267', '2026-09-21-1211']
meta = {m['fichier'][:-4]: m for m in photos}
LIB = {'fr': {'CO-01': 'Ouverture du magasin', 'CQ-02': 'Contrôle qualité d’ouverture', 'CO-10': 'Contrôle opérationnel boulangerie'},
       'nl': {'CO-01': 'Opening van de winkel', 'CQ-02': 'Kwaliteitscontrole bij opening', 'CO-10': 'Operationele controle bakkerij'}}
def fDj(s): return s[8:10] + '/' + s[5:7] + '/' + s[0:4]

T = {'fr': dict(
    dr='Dossier investisseur · {t}', pied='Chiffres lus le 9 octobre 2026 sur le panel et le cockpit du réseau · à lire avec la page « Sources, méthode et limites »', lang='FR',
    p1='synthèse', p2='les ventes', p3='les coûts et le résultat', p4='le réseau et le modèle', p5='l’assortiment et les canaux', p6='la qualité', p7='sources, méthode et limites',
    sur='Dossier investisseur', sous='Le magasin depuis son ouverture, le 20 février 2026, jusqu’au 8 octobre 2026',
    t1='Chiffre d’affaires, mars → septembre', t1s='7 mois pleins · {m} par mois en moyenne · {p} du budget', t2='Clients servis', t2s='tickets sur 7 mois · {j} par jour · panier moyen {pm}',
    t3='Food cost', t3s='matière mesurée sur les recettes vendues · modèle du réseau 36 % + 2 % d’emballage', t4='Résultat opérationnel encodé', t4s='{p} du CA · CA − matière − main-d’œuvre encodée − frais généraux encodés',
    s1='Le magasin en bref',
    b1='Ouvert le 20 février 2026, 7 jours sur 7 depuis mars : {j} jours de vente sur les 7 mois pleins.',
    b2='Quatre magasins dans le réseau Atelier by en Belgique : Corbais, Gosselies, Sombreffe et Halle. Halle est le dernier ouvert.',
    b3='Étude de marché : {men} ménages dans la zone, potentiel à maturité {pot} par an, montée en régime 70 % en année 1, 80 % en année 2, 90 % en année 3. CA théorique année 1 : {ca1}.',
    b4='Budget 2026 validé avec le franchisé : 62 000 € par mois de mars à juillet, 61 000 € d’août à décembre.',
    b5='Le week-end fait la semaine : samedi {sa} et dimanche {di} du chiffre, soit {we} sur deux jours.',
    b6='Qualité : note Google {g} / 5 sur {avis} avis, client mystère {msp} / {mspmax}, 2<sup>e</sup> du réseau au scoring du 3<sup>e</sup> trimestre.',
    s2='Ce que montrent les sept mois pleins',
    c1='Le chiffre s’est installé entre 46 000 et 60 000 € par mois, avec un pic en mai ({mai}) et un creux en juin et juillet, les deux mois des travaux sur le viaduc de Halle (effet estimé à − 20 %).',
    c2='Le budget n’est atteint aucun mois : {cum} en cumul, de 75 % en juillet à 97 % en mai.',
    c3='Le food cost baisse : {m3} en mars, {m9} en septembre. Il reste au-dessus du seuil de 32 % du réseau.',
    c4='La main-d’œuvre encodée au panel pèse {lab} du CA, les frais généraux encodés {oh} : voir la page des coûts pour ce que ces deux lignes contiennent.',
    c5='Le panier moyen monte de {p3} en mars à {p9} en septembre ; le nombre de clients, lui, stagne autour de 4 200 par mois.',
    n1='Un mois partiel n’entre pas dans les cumuls : février (9 jours d’ouverture, {fev}) et octobre (8 jours). Tous les montants sont hors TVA.',
    h2='Les ventes, mois par mois', th2=['Mois', 'Jours de vente', 'Chiffre d’affaires', 'Budget', 'Atteinte', 'Clients (tickets)', 'Panier moyen', 'CA par jour'], viaduc='travaux viaduc', tot='Mars → septembre',
    g1='Le chiffre face au budget', lg1='chiffre d’affaires du mois', lg2='budget du mois', lg3='juin et juillet : niveau estimé sans les travaux du viaduc de Halle (− 20 %)', pbud='% du budget',
    semt='La semaine type', sems='CA moyen par jour de semaine, mars → septembre', semd='Le dimanche vaut {x} fois un lundi. Meilleure journée depuis l’ouverture : le dimanche 10 mai, {v}.',
    dit='Ce que ça dit',
    d1='Le magasin a trouvé son rythme dès le deuxième mois : + 14 % d’avril sur mars, puis le pic de mai.',
    d2='Juin et juillet retombent à 47 000 € : les travaux sur le viaduc de Halle ont gêné l’accès au magasin sur ces deux mois, pour un effet estimé à − 20 %. Sans eux, chacun aurait approché 58 000 €, soit quelque {st} de chiffre sur l’exercice. L’été pèse aussi dans les trois autres magasins (− 10 à − 19 % en juillet).',
    d3='La rentrée tient : août et septembre reviennent à 51 à 52 000 €.',
    d4='Le budget de 62 000 € suppose 4 900 à 5 000 clients par mois au panier actuel : il en manque 700 à 800 chaque mois. C’est la clé du dossier : la fréquentation, pas le panier.',
    h3='Les coûts et le résultat opérationnel', th3=['Mois', 'CA', 'Food cost', 'Matière', 'Main-d’œuvre encodée', '%', 'Frais généraux encodés', '%', 'Résultat opérationnel', '%'],
    g3='Les trois postes en part du chiffre', ser=['Food cost', 'Main-d’œuvre', 'Frais généraux'], seuils=['seuil matière 32 %', 'seuil main-d’œuvre 33 %', 'seuil frais généraux 13,5 %'],
    lirek='Comment lire ces chiffres',
    e1='Food cost : le coût matière des recettes vendues, tel que le panel le mesure sur ses ventes de chaque jour. C’est une mesure, pas une estimation. Il baisse de 39,8 % à 36,2 % entre mars et septembre.',
    e2='Main-d’œuvre encodée : les heures de l’équipe planifiées au panel, au taux horaire. À {lab} du CA, loin du seuil de 33 % du réseau : la ligne ne porte vraisemblablement ni la rémunération du gérant ni toutes les charges sociales. À vérifier avec le franchisé.',
    e3='Frais généraux encodés : un montant mensuel saisi au panel ({oh} par mois depuis juillet, 9 360 € en mars), sans détail ; le modèle de charges de la page suivante dit ce qu’ils devraient contenir.',
    e4='Résultat opérationnel encodé : CA − matière − main-d’œuvre encodée − frais généraux encodés. Avant royalties, amortissements, charges financières et impôts, sous la réserve faite sur la main-d’œuvre.',
    plk='Le food cost du P&amp;L du cockpit', pl='Depuis le 1<sup>er</sup> août, le cockpit lit chaque ticket et recompose le coût matière des recettes vendues au coût de recette du jour. Cette lecture donne {a} en août, {s} en septembre et {w} sur la semaine du 5 octobre, au-dessus des 36 % mesurés par le panel sur les mêmes mois : l’écart tient à la méthode et reste à expliquer. Les deux lectures disent la même chose : la matière reste au-dessus des 32 % du réseau.',
    fevt='Février, le mois d’ouverture', f1='9 jours d’ouverture du 20 au 28 février : {ca} de ventes, {tk} clients.', f2='Les frais encodés ce mois-là, {oh}, portent les coûts de démarrage : résultat de {res}.', f3='Ce mois est tenu hors des cumuls et des moyennes.',
    invt='Les invendus', inv='Du 1<sup>er</sup> mars au 8 octobre : 8 169 pièces jetées, un coût matière de 5 874 € (1,5 % du CA) et 14 560 € de chiffre non réalisé. 99 % en fin de journée, le reste en dégustation et casse.',
    h4='Halle face au réseau', th4=['Mars → septembre 2026', 'Halle', 'Moyenne des 3 autres magasins', 'Seuil du réseau'],
    r1='Food cost (matière, part du CA)', r2='Main-d’œuvre encodée (part du CA)', r3='Frais généraux encodés (part du CA)', r4='Panier moyen', r5='Rang par chiffre d’affaires sur la période', r5v='{r}<sup>e</sup> sur 4',
    r5s='le magasin le plus proche en taille fait 4 % de plus, le plus grand trois fois plus', r6='Scoring qualité du 3<sup>e</sup> trimestre (sur 20)', r6r='{v} (réseau)', r6s='rang {r} sur 4',
    r7='Les trois autres magasins : Corbais, Gosselies, Sombreffe. Mêmes sources et mêmes règles que pour Halle ; un mois dont le food cost mesuré dépasse 60 % (anomalie de recette) est écarté de leur moyenne.',
    h4b='Le modèle de charges du franchiseur', m4='Les postes du budget 2026 de Halle, en part du chiffre, et ce qu’ils représentent au rythme actuel ({ca} de CA par mois). Ce sont les hypothèses du modèle, pas des montants constatés : le loyer réel vient du bail, l’énergie des factures.',
    th4b=['Poste', 'Budget', 'Théorique', '€ par mois au rythme actuel'], tot4='Total des postes budgétés',
    n4='Le loyer et les charges locatives sont budgétés à 7 % du CA, « selon emplacement », soit environ {l} par mois au rythme actuel. Le panel ne porte pas le bail : ce montant est à confirmer avec le franchisé. La matière (36 %) et l’emballage (2 %) ne figurent qu’en théorique dans ce modèle : le food cost mesuré de la page précédente en tient lieu.',
    h5='L’assortiment : ce qui se vend en septembre', th5=['Catégorie', 'CA', 'Part', '', 'Pièces', 'Réf.'],
    a5='Septembre 2026, {ca} € de ventes sur {j} jours, 27 catégories, {refs} références vendues. Les 14 premières catégories font {p} du chiffre.', famt='Par famille', cant='Les canaux',
    k1='Le comptoir fait tout : en septembre, 99 % du chiffre passe en caisse ; le click &amp; collect et la livraison sont marginaux.',
    k2='Clients professionnels : 755 € en septembre, 1,5 % du CA, 5 sociétés. Le réseau tolère jusqu’à 40 % de CA pro ; ce levier est presque vierge à Halle.',
    k3='Heure la plus forte : 11 h, avec {v} de ventes cumulées sur le mois ; après 19 h, presque rien.',
    p8='les marges par catégorie', h8='Les marges par catégorie et leur poids dans le chiffre',
    s8='Septembre 2026, ventes de Halle. Marge brute = chiffre − coût de recette, telle que le panel la chiffre produit par produit. Les recettes invraisemblables (coût au-dessus du prix ou sous 5 % de celui-ci) sont écartées ; « coût connu » dit sur quelle part du chiffre de la catégorie la marge est mesurée.',
    th8=['Catégorie', 'Famille', 'Part du CA', 'CA', 'Pièces', 'Marge brute', 'Coefficient', 'Coût connu'], tot8='Toutes catégories', aut8='Autres catégories',
    g8='Le poids de chaque catégorie, coloré par sa marge', lg8=['marge ≥ 60 %', '40 à 60 %', 'sous 40 %', 'sans coût connu'],
    x1='Trois familles font {p} du chiffre : {l}.',
    x2='Sur les ventes dont la recette est chiffrée ({c} du chiffre), la marge brute de septembre est de {m}, soit un food cost de {f} et un coefficient moyen de × {k}.',
    x3='Les catégories à 60 % de marge ou plus pèsent {a} du chiffre ; celles sous 50 % en pèsent {b} : {l}.',
    x4='Le levier le plus lourd : {n}, {p} du chiffre à {m} de marge. À 60 % de marge, ce serait {e} de marge en plus par mois, par le prix ou par la recette.',
    h6='La qualité au quotidien', q1='Note Google', q1s='{avis} avis au {d}', q2='Client mystère', q2s='3<sup>e</sup> trimestre 2026', q3='Contrôles rendus', q3s='{f} tâches rendues sur {a} attendues au 3<sup>e</sup> trimestre', q4='Scoring du trimestre', q4s='{r}<sup>e</sup> magasin sur 4 · réseau {v} / 20',
    q5='Chaque matin, l’équipe photographie les vitrines, les pains et la salle à l’ouverture (« contrôle qualité d’ouverture ») et le consultant du réseau note ce qu’il voit. Les photos ci-dessous sont celles des contrôles des trois dernières semaines, telles qu’elles ont été rendues.',
    note='noté {n} / 5 par le consultant',
    q6='Le poste « contrôles rendus » compte les tâches rendues sur toutes celles attendues par le panel, photos comprises ; une tâche obligatoire manquée met la journée à zéro. Halle rend ses contrôles d’ouverture avec régularité mais pas l’ensemble des tâches attendues : c’est le point d’amélioration du scoring.',
    p9='les outils de gestion', h9='Les outils de gestion du magasin',
    o9='Le franchisé et le réseau pilotent le magasin avec trois outils reliés à la caisse : le panel (caisse, recettes, matières, tâches), le dashboard du magasin, sur ordinateur et sur téléphone, et le cockpit du réseau, celui du consultant. Les écrans ci-dessous sont ceux de Halle, pris le 9 octobre 2026.',
    oc=['Le dashboard du jour, sur ordinateur : chiffre, clients et panier face à l’objectif, résultat du jour, vitrine, invendus et contrôles.', 'Au téléphone : quatre onglets, Opérationnel, Exploitation, Contrôle et Semaine ; la réclamation fournisseur se fait avec une photo et le code-barres.', 'L’opérationnel : le chiffre heure par heure face à la même journée de la semaine passée, jauge double piste.', 'La fiche d’un produit : recette, coût matière, coefficient et prix à pratiquer pour atteindre l’objectif.'],
    ob=['Vue du jour : chiffre, clients et panier face à l’objectif et à la même journée de la semaine passée, heure par heure.', 'La vitrine : ce qui reste à vendre, catégorie par catégorie, avec la projection des ventes de la journée.', 'Le P&amp;L du jour : matière mesurée sur les recettes vendues, main-d’œuvre, frais généraux, invendus.', 'La fiche produit : recette ligne par ligne, coût matière du jour, coefficient, prix à pratiquer, prix des matières chez les fournisseurs.', 'Les contrôles en photo : ouverture, vitrines, pains, notés par le consultant ; le scoring du trimestre.'],
    h7='Sources, méthode et limites', srck='D’où vient chaque chiffre',
    s71='Chiffre d’affaires, clients, panier : la caisse du magasin, lue par l’API du panel (statistiques de ventes par mois et P&amp;L quotidien). Hors TVA.',
    s72='Food cost : la marge que le panel mesure sur les ventes de chaque jour (recettes et prix des matières du panel), agrégée par mois ; les anomalies de recette sont filtrées. Depuis août, le cockpit recompose aussi le coût matière ticket par ticket. Le modèle du réseau prévoit 36 % de matière et 2 % d’emballage.',
    s73='Main-d’œuvre et frais généraux : les lignes « labour » et « overhead » du P&amp;L quotidien du panel, additionnées par mois. Elles valent ce que le franchisé y a encodé : les heures planifiées de l’équipe et un montant mensuel de frais fixes.',
    s74='Budget et modèle de charges : le budget 2026 validé avec le franchisé et l’étude de marché, tels qu’encodés au cockpit.',
    s75='Qualité : la fiche Google du magasin (note et nombre d’avis), le client mystère du trimestre, les tâches du panel et les photos des contrôles d’ouverture.',
    s76='Réseau : les mêmes sources pour les trois autres magasins, rendues en parts du chiffre seulement.',
    limt='Ce que le dossier ne dit pas',
    l1='Le loyer réel, les contrats d’énergie, les amortissements et les charges financières ne sont pas dans le panel. Le modèle de charges donne les hypothèses du réseau ; le bail et la comptabilité du franchisé donnent le réel.',
    l2='La rémunération du gérant et le détail des charges sociales ne sont pas visibles dans la ligne main-d’œuvre encodée. Le résultat opérationnel présenté est donc un résultat avant ces éléments.',
    l3='Les résultats comptables (bilan, compte de résultat) ne sont pas dans ce dossier. Il décrit l’exploitation telle que les outils du réseau la mesurent, pas les comptes de la société.',
    l4='Les travaux sur le viaduc de Halle en juin et juillet 2026 sont une information du réseau ; leur effet de − 20 % est une estimation, pas une mesure. Les montants « sans travaux » du dossier en découlent.',
    l5='Octobre est en cours (8 jours) et février est un mois d’ouverture : ni l’un ni l’autre n’entre dans les moyennes.',
    plust='Pour aller plus loin', pl1='Le classeur Excel joint reprend chaque tableau du dossier, mois par mois, avec les parts et les montants, pour refaire les calculs.', pl2='Le cockpit du réseau permet de suivre le magasin jour par jour : ventes à l’heure, recettes et marges par produit, contrôles en photo.',
), 'nl': dict(
    dr='Investeerdersdossier · {t}', pied='Cijfers gelezen op 9 oktober 2026 in het panel en de cockpit · te lezen met de pagina “Bronnen, methode en beperkingen”', lang='NL',
    p1='samenvatting', p2='de verkoop', p3='de kosten en het resultaat', p4='het netwerk en het model', p5='het assortiment en de kanalen', p6='de kwaliteit', p7='bronnen, methode en beperkingen',
    sur='Investeerdersdossier', sous='De winkel sinds de opening op 20 februari 2026 tot 8 oktober 2026',
    t1='Omzet, maart → september', t1s='7 volle maanden · gemiddeld {m} per maand · {p} van het budget', t2='Bediende klanten', t2s='kastickets over 7 maanden · {j} per dag · gemiddeld ticket {pm}',
    t3='Foodcost', t3s='grondstoffen gemeten op de verkochte recepten · netwerkmodel 36 % + 2 % verpakking', t4='Ingegeven operationeel resultaat', t4s='{p} van de omzet · omzet − grondstoffen − ingegeven personeelskost − ingegeven algemene kosten',
    s1='De winkel in het kort',
    b1='Geopend op 20 februari 2026, 7 dagen op 7 sinds maart: {j} verkoopdagen over de 7 volle maanden.',
    b2='Vier winkels in het netwerk Atelier by in België: Corbais, Gosselies, Sombreffe en Halle. Halle is de jongste.',
    b3='Marktstudie: {men} gezinnen in de zone, potentieel op kruissnelheid {pot} per jaar, opbouw 70 % in jaar 1, 80 % in jaar 2, 90 % in jaar 3. Theoretische omzet jaar 1: {ca1}.',
    b4='Budget 2026, gevalideerd met de franchisenemer: 62 000 € per maand van maart tot juli, 61 000 € van augustus tot december.',
    b5='Het weekend maakt de week: zaterdag {sa} en zondag {di} van de omzet, samen {we} op twee dagen.',
    b6='Kwaliteit: Google-score {g} / 5 op {avis} beoordelingen, mysterieklant {msp} / {mspmax}, 2<sup>e</sup> van het netwerk in de scoring van het 3<sup>e</sup> kwartaal.',
    s2='Wat de zeven volle maanden tonen',
    c1='De omzet ligt tussen 46 000 en 60 000 € per maand, met een piek in mei ({mai}) en een dip in juni en juli, de twee maanden van de werken aan het viaduct van Halle (geschat effect − 20 %).',
    c2='Het budget wordt geen enkele maand gehaald: {cum} cumulatief, van 75 % in juli tot 97 % in mei.',
    c3='De foodcost daalt: {m3} in maart, {m9} in september. Hij blijft boven de netwerkdrempel van 32 %.',
    c4='De in het panel ingegeven personeelskost weegt {lab} van de omzet, de ingegeven algemene kosten {oh}: zie de kostenpagina voor wat die twee lijnen bevatten.',
    c5='Het gemiddelde ticket stijgt van {p3} in maart naar {p9} in september; het aantal klanten blijft rond 4 200 per maand hangen.',
    n1='Een onvolledige maand telt niet mee in de cumuls: februari (9 openingsdagen, {fev}) en oktober (8 dagen). Alle bedragen zijn exclusief btw.',
    h2='De verkoop, maand per maand', th2=['Maand', 'Verkoopdagen', 'Omzet', 'Budget', 'Realisatie', 'Klanten (tickets)', 'Gemiddeld ticket', 'Omzet per dag'], viaduc='werken viaduct', tot='Maart → september',
    g1='De omzet tegenover het budget', lg1='omzet van de maand', lg2='budget van de maand', lg3='juni en juli: geschat niveau zonder de werken aan het viaduct van Halle (− 20 %)', pbud='% van het budget',
    semt='De typische week', sems='Gemiddelde omzet per weekdag, maart → september', semd='Een zondag is {x} keer een maandag waard. Beste dag sinds de opening: zondag 10 mei, {v}.',
    dit='Wat dit zegt',
    d1='De winkel vond zijn ritme vanaf de tweede maand: + 14 % in april tegenover maart, daarna de piek van mei.',
    d2='Juni en juli vallen terug op 47 000 €: de werken aan het viaduct van Halle bemoeilijkten in die twee maanden de toegang tot de winkel, met een geschat effect van − 20 %. Zonder die werken had elke maand bijna 58 000 € gehaald, zo’n {st} omzet over het boekjaar. De zomer weegt ook in de drie andere winkels (− 10 tot − 19 % in juli).',
    d3='Het najaar houdt stand: augustus en september komen terug op 51 à 52 000 €.',
    d4='Het budget van 62 000 € veronderstelt 4 900 à 5 000 klanten per maand bij het huidige ticket: er ontbreken er elke maand 700 à 800. Dat is de sleutel van dit dossier: het aantal klanten, niet het ticket.',
    h3='De kosten en het operationeel resultaat', th3=['Maand', 'Omzet', 'Foodcost', 'Grondstoffen', 'Ingegeven personeelskost', '%', 'Ingegeven algemene kosten', '%', 'Operationeel resultaat', '%'],
    g3='De drie posten als aandeel van de omzet', ser=['Foodcost', 'Personeelskost', 'Algemene kosten'], seuils=['drempel grondstoffen 32 %', 'drempel personeel 33 %', 'drempel algemene kosten 13,5 %'],
    lirek='Hoe deze cijfers te lezen',
    e1='Foodcost: de grondstoffenkost van de verkochte recepten, zoals het panel die elke dag op zijn verkopen meet. Een meting, geen schatting. Hij daalt van 39,8 % naar 36,2 % tussen maart en september.',
    e2='Ingegeven personeelskost: de in het panel geplande uren van het team, aan het uurtarief. Met {lab} van de omzet ver onder de netwerkdrempel van 33 %: de lijn bevat wellicht noch de vergoeding van de zaakvoerder noch alle sociale lasten. Na te kijken met de franchisenemer.',
    e3='Ingegeven algemene kosten: een maandelijks bedrag ingegeven in het panel ({oh} per maand sinds juli, 9 360 € in maart), zonder detail; het kostenmodel op de volgende pagina zegt wat ze zouden moeten bevatten.',
    e4='Ingegeven operationeel resultaat: omzet − grondstoffen − ingegeven personeelskost − ingegeven algemene kosten. Vóór royalty’s, afschrijvingen, financiële lasten en belastingen, onder het voorbehoud bij de personeelskost.',
    plk='De foodcost in de P&amp;L van de cockpit', pl='Sinds 1 augustus leest de cockpit elk kasticket en stelt hij de grondstoffenkost van de verkochte recepten samen tegen de receptkost van de dag. Die lezing geeft {a} in augustus, {s} in september en {w} in de week van 5 oktober, boven de 36 % die het panel voor dezelfde maanden meet: het verschil ligt aan de methode en moet nog verklaard worden. Beide lezingen zeggen hetzelfde: de grondstoffen blijven boven de 32 % van het netwerk.',
    fevt='Februari, de openingsmaand', f1='9 openingsdagen van 20 tot 28 februari: {ca} verkoop, {tk} klanten.', f2='De die maand ingegeven kosten, {oh}, dragen de opstartkosten: resultaat van {res}.', f3='Deze maand blijft buiten de cumuls en de gemiddelden.',
    invt='De onverkochte producten', inv='Van 1 maart tot 8 oktober: 8 169 weggegooide stuks, een grondstoffenkost van 5 874 € (1,5 % van de omzet) en 14 560 € niet-gerealiseerde omzet. 99 % op het einde van de dag, de rest in proeverij en breuk.',
    h4='Halle tegenover het netwerk', th4=['Maart → september 2026', 'Halle', 'Gemiddelde van de 3 andere winkels', 'Netwerkdrempel'],
    r1='Foodcost (grondstoffen, aandeel van de omzet)', r2='Ingegeven personeelskost (aandeel van de omzet)', r3='Ingegeven algemene kosten (aandeel van de omzet)', r4='Gemiddeld ticket', r5='Rang op omzet over de periode', r5v='{r}<sup>e</sup> van 4',
    r5s='de winkel die qua omvang het dichtst ligt doet 4 % meer, de grootste drie keer meer', r6='Kwaliteitsscoring 3<sup>e</sup> kwartaal (op 20)', r6r='{v} (netwerk)', r6s='rang {r} van 4',
    r7='De drie andere winkels: Corbais, Gosselies, Sombreffe. Dezelfde bronnen en regels als voor Halle; een maand waarvan de gemeten foodcost boven 60 % ligt (receptanomalie) blijft buiten hun gemiddelde.',
    h4b='Het kostenmodel van de franchisegever', m4='De posten van het budget 2026 van Halle, als aandeel van de omzet, en wat ze vertegenwoordigen op het huidige ritme ({ca} omzet per maand). Dit zijn de hypothesen van het model, geen vastgestelde bedragen: de werkelijke huur staat in de huurovereenkomst, de energie op de facturen.',
    th4b=['Post', 'Budget', 'Theoretisch', '€ per maand op het huidige ritme'], tot4='Totaal van de gebudgetteerde posten',
    n4='Huur en huurlasten zijn gebudgetteerd op 7 % van de omzet, “volgens locatie”, ongeveer {l} per maand op het huidige ritme. Het panel bevat de huurovereenkomst niet: dit bedrag is te bevestigen met de franchisenemer. Grondstoffen (36 %) en verpakking (2 %) staan in dit model enkel theoretisch: de gemeten foodcost van de vorige pagina vervangt ze.',
    h5='Het assortiment: wat verkoopt in september', th5=['Categorie', 'Omzet', 'Aandeel', '', 'Stuks', 'Ref.'],
    a5='September 2026, {ca} € verkoop op {j} dagen, 27 categorieën, {refs} verkochte referenties. De 14 eerste categorieën maken {p} van de omzet.', famt='Per familie', cant='De kanalen',
    k1='De toonbank doet alles: in september gaat 99 % van de omzet via de kassa; click &amp; collect en levering zijn marginaal.',
    k2='Professionele klanten: 755 € in september, 1,5 % van de omzet, 5 bedrijven. Het netwerk laat tot 40 % B2B-omzet toe; deze hefboom is in Halle nog bijna onaangeroerd.',
    k3='Sterkste uur: 11 u, met {v} gecumuleerde verkoop over de maand; na 19 u bijna niets.',
    p8='de marges per categorie', h8='De marges per categorie en hun gewicht in de omzet',
    s8='September 2026, verkoop van Halle. Brutomarge = omzet − receptkost, zoals het panel die product per product becijfert. Onwaarschijnlijke recepten (kost boven de prijs of onder 5 % ervan) blijven buiten beschouwing; “kost gekend” zegt op welk deel van de omzet van de categorie de marge gemeten is.',
    th8=['Categorie', 'Familie', 'Aandeel omzet', 'Omzet', 'Stuks', 'Brutomarge', 'Coëfficiënt', 'Kost gekend'], tot8='Alle categorieën', aut8='Andere categorieën',
    g8='Het gewicht van elke categorie, gekleurd volgens haar marge', lg8=['marge ≥ 60 %', '40 tot 60 %', 'onder 40 %', 'kost niet gekend'],
    x1='Drie families maken {p} van de omzet: {l}.',
    x2='Op de verkoop met een becijferd recept ({c} van de omzet) bedraagt de brutomarge van september {m}, een foodcost van {f} en een gemiddelde coëfficiënt van × {k}.',
    x3='De categorieën met 60 % marge of meer wegen {a} van de omzet; die onder 50 % wegen {b}: {l}.',
    x4='De zwaarste hefboom: {n}, {p} van de omzet aan {m} marge. Aan 60 % marge zou dat {e} extra marge per maand zijn, via de prijs of via het recept.',
    h6='Kwaliteit, elke dag', q1='Google-score', q1s='{avis} beoordelingen op {d}', q2='Mysterieklant', q2s='3<sup>e</sup> kwartaal 2026', q3='Uitgevoerde controles', q3s='{f} uitgevoerde taken op {a} verwachte in het 3<sup>e</sup> kwartaal', q4='Kwartaalscoring', q4s='{r}<sup>e</sup> winkel van 4 · netwerk {v} / 20',
    q5='Elke ochtend fotografeert het team bij de opening de vitrines, de broden en de zaal (“kwaliteitscontrole bij opening”) en beoordeelt de consultant van het netwerk wat hij ziet. De foto’s hieronder zijn die van de controles van de laatste drie weken, zoals ze werden ingediend.',
    note='door de consultant beoordeeld met {n} / 5',
    q6='De post “uitgevoerde controles” telt de uitgevoerde taken op alle door het panel verwachte taken, foto’s inbegrepen; een gemiste verplichte taak zet de dag op nul. Halle voert zijn openingscontroles regelmatig uit, maar niet alle verwachte taken: dat is het verbeterpunt van de scoring.',
    p9='de beheertools', h9='De beheertools van de winkel',
    o9='De franchisenemer en het netwerk sturen de winkel met drie tools die aan de kassa gekoppeld zijn: het panel (kassa, recepten, grondstoffen, taken), het dashboard van de winkel, op computer en op telefoon, en de cockpit van het netwerk, die van de consultant. De schermen hieronder zijn die van Halle, genomen op 9 oktober 2026.',
    oc=['Het dagdashboard op computer: omzet, klanten en ticket tegenover het doel, resultaat van de dag, vitrine, onverkochte producten en controles.', 'Op telefoon: vier tabbladen, Operationeel, Uitbating, Controle en Week; een klacht aan de leverancier gaat met een foto en de barcode.', 'Het operationele scherm: de omzet uur per uur tegenover dezelfde dag van vorige week, dubbele meter.', 'De productfiche: recept, grondstoffenkost, coëfficiënt en de prijs om het doel te halen.'],
    ob=['Dagoverzicht: omzet, klanten en ticket tegenover het doel en dezelfde dag van vorige week, uur per uur.', 'De vitrine: wat er nog te verkopen is, categorie per categorie, met de projectie van de verkoop van de dag.', 'De P&amp;L van de dag: grondstoffen gemeten op de verkochte recepten, personeel, algemene kosten, onverkochte producten.', 'De productfiche: recept lijn per lijn, grondstoffenkost van de dag, coëfficiënt, te hanteren prijs, grondstofprijzen bij de leveranciers.', 'De controles op foto: opening, vitrines, broden, beoordeeld door de consultant; de kwartaalscoring.'],
    h7='Bronnen, methode en beperkingen', srck='Waar elk cijfer vandaan komt',
    s71='Omzet, klanten, ticket: de kassa van de winkel, gelezen via de API van het panel (verkoopstatistieken per maand en dagelijkse P&amp;L). Exclusief btw.',
    s72='Foodcost: de marge die het panel op de verkoop van elke dag meet (recepten en grondstofprijzen van het panel), per maand samengeteld; receptanomalieën worden gefilterd. Sinds augustus stelt de cockpit de grondstoffenkost ook ticket per ticket samen. Het netwerkmodel voorziet 36 % grondstoffen en 2 % verpakking.',
    s73='Personeelskost en algemene kosten: de lijnen “labour” en “overhead” van de dagelijkse P&amp;L van het panel, per maand opgeteld. Ze zijn wat de franchisenemer erin heeft ingegeven: de geplande uren van het team en een maandelijks bedrag aan vaste kosten.',
    s74='Budget en kostenmodel: het budget 2026 gevalideerd met de franchisenemer en de marktstudie, zoals ingegeven in de cockpit.',
    s75='Kwaliteit: de Google-fiche van de winkel (score en aantal beoordelingen), de mysterieklant van het kwartaal, de taken van het panel en de foto’s van de openingscontroles.',
    s76='Netwerk: dezelfde bronnen voor de drie andere winkels, enkel als aandeel van de omzet weergegeven.',
    limt='Wat het dossier niet zegt',
    l1='De werkelijke huur, de energiecontracten, de afschrijvingen en de financiële lasten zitten niet in het panel. Het kostenmodel geeft de hypothesen van het netwerk; de huurovereenkomst en de boekhouding van de franchisenemer geven de werkelijkheid.',
    l2='De vergoeding van de zaakvoerder en het detail van de sociale lasten zijn niet zichtbaar in de ingegeven personeelskost. Het voorgestelde operationeel resultaat is dus een resultaat vóór die elementen.',
    l3='De boekhoudkundige resultaten (balans, resultatenrekening) zitten niet in dit dossier. Het beschrijft de uitbating zoals de tools van het netwerk ze meten, niet de rekeningen van de vennootschap.',
    l4='De werken aan het viaduct van Halle in juni en juli 2026 zijn informatie van het netwerk; hun effect van − 20 % is een schatting, geen meting. De bedragen “zonder werken” in het dossier volgen daaruit.',
    l5='Oktober is nog bezig (8 dagen) en februari is een openingsmaand: geen van beide telt mee in de gemiddelden.',
    plust='Om verder te gaan', pl1='De bijgevoegde Excel-werkmap herneemt elke tabel van het dossier, maand per maand, met de aandelen en de bedragen, om de berekeningen over te doen.', pl2='De cockpit van het netwerk laat toe de winkel dag per dag te volgen: verkoop per uur, recepten en marges per product, controles op foto.',
)}

def rendre(L):
    t = T[L]; mn = MOIS[L]; co = COURT[L]
    nom = lambda x: mn[x['m']]
    def barres_ca():
        W, Hh, ml, mb = 680, 200, 46, 34; xs = pleins; n = len(xs); maxv = max(max(x['ca'] for x in xs), max(x['budget'] for x in xs)) * 1.08
        bw = (W - ml - 10) / n; out = [f'<svg viewBox="0 0 {W} {Hh}" class="graph">']
        for i in range(5):
            v = maxv * i / 4; y = Hh - mb - (Hh - mb - 14) * v / maxv
            out.append(f'<line x1="{ml}" x2="{W-6}" y1="{y:.1f}" y2="{y:.1f}" stroke="#ece6de"/><text x="{ml-6}" y="{y+3.5:.1f}" text-anchor="end" class="ax">{nf(v/1000,0)} k</text>')
        for i, x in enumerate(xs):
            x0 = ml + i * bw + bw * 0.14; w = bw * 0.72
            hb = (Hh - mb - 14) * x['ca'] / maxv; yb = Hh - mb - hb
            out.append(f'<rect x="{x0:.1f}" y="{yb:.1f}" width="{w:.1f}" height="{hb:.1f}" rx="3" fill="#8D1D2C"/>')
            yB = Hh - mb - (Hh - mb - 14) * x['budget'] / maxv
            out.append(f'<line x1="{x0-2:.1f}" x2="{x0+w+2:.1f}" y1="{yB:.1f}" y2="{yB:.1f}" stroke="#221E1A" stroke-width="2"/>')
            if x['m'] in (6, 7):
                yg = Hh - mb - (Hh - mb - 14) * (x['ca'] / 0.8) / maxv
                out.append(f'<rect x="{x0:.1f}" y="{yg:.1f}" width="{w:.1f}" height="{yb-yg:.1f}" rx="3" fill="none" stroke="#8D1D2C" stroke-width="1.2" stroke-dasharray="3 2"/><text x="{x0+w/2:.1f}" y="{yg+11:.1f}" text-anchor="middle" class="ax" fill="#8D1D2C">− 20 %</text>')
            out.append(f'<text x="{x0+w/2:.1f}" y="{yb+16:.1f}" text-anchor="middle" class="val" fill="#fff">{nf(x["ca"]/1000,1)} k</text><text x="{x0+w/2:.1f}" y="{Hh-mb+14}" text-anchor="middle" class="ax">{co[x["m"]]}</text><text x="{x0+w/2:.1f}" y="{Hh-mb+26}" text-anchor="middle" class="axs">{nf(100*x["ca"]/x["budget"],0)} {t["pbud"]}</text>')
        out.append('</svg>'); return ''.join(out)
    def lignes_pct():
        W, Hh, ml, mb = 680, 148, 40, 26; xs = pleins; n = len(xs); maxv = 45
        out = [f'<svg viewBox="0 0 {W} {Hh}" class="graph">']
        for v in (0, 10, 20, 30, 40):
            y = Hh - mb - (Hh - mb - 12) * v / maxv
            out.append(f'<line x1="{ml}" x2="{W-6}" y1="{y:.1f}" y2="{y:.1f}" stroke="#ece6de"/><text x="{ml-6}" y="{y+3.5:.1f}" text-anchor="end" class="ax">{v} %</text>')
        def X(i): return ml + (W - ml - 20) * (i + 0.5) / n
        def Y(v): return Hh - mb - (Hh - mb - 12) * v / maxv
        series = [('food', '#8D1D2C', t['ser'][0]), ('labPct', '#2a78d6', t['ser'][1]), ('ohPct', '#9a938a', t['ser'][2])]
        for k, col, lib in series:
            pts = ' '.join(f'{X(i):.1f},{Y(x[k]):.1f}' for i, x in enumerate(xs))
            out.append(f'<polyline points="{pts}" fill="none" stroke="{col}" stroke-width="2.4"/>')
            for i, x in enumerate(xs): out.append(f'<circle cx="{X(i):.1f}" cy="{Y(x[k]):.1f}" r="3" fill="{col}"/><text x="{X(i):.1f}" y="{Y(x[k])-7:.1f}" text-anchor="middle" class="axs" fill="{col}">{nf(x[k],1)}</text>')
        for i, x in enumerate(xs): out.append(f'<text x="{X(i):.1f}" y="{Hh-mb+14}" text-anchor="middle" class="ax">{co[x["m"]]}</text>')
        for v, lib, cote in ((32, t['seuils'][0], 'g'), (33, t['seuils'][1], 'd'), (13.5, t['seuils'][2], 'd')):
            out.append(f'<line x1="{ml}" x2="{W-6}" y1="{Y(v):.1f}" y2="{Y(v):.1f}" stroke="#c9c1b5" stroke-dasharray="3 4"/>')
            if cote == 'g': out.append(f'<text x="{ml+4}" y="{Y(v)+10:.1f}" class="axs">{lib}</text>')
            else: out.append(f'<text x="{W-8}" y="{Y(v)-3:.1f}" text-anchor="end" class="axs">{lib}</text>')
        lx = ml + 6
        for k, col, lib in series: out.append(f'<rect x="{lx}" y="4" width="10" height="4" fill="{col}"/><text x="{lx+14}" y="9" class="axs">{lib}</text>'); lx += 120
        out.append('</svg>'); return ''.join(out)
    def semaine_svg():
        W, Hh = 320, 150; jours = list(sem.items()); maxv = max(v['moyen'] for k, v in jours) * 1.15; bw = (W - 20) / 7
        out = [f'<svg viewBox="0 0 {W} {Hh}" class="graph">']
        for i, (k, v) in enumerate(jours):
            x0 = 10 + i * bw + bw * 0.15; w = bw * 0.7; hb = (Hh - 36) * v['moyen'] / maxv; yb = Hh - 22 - hb
            out.append(f'<rect x="{x0:.1f}" y="{yb:.1f}" width="{w:.1f}" height="{hb:.1f}" rx="3" fill="{"#8D1D2C" if k in ("samedi", "dimanche") else "#c9a9ad"}"/><text x="{x0+w/2:.1f}" y="{yb-4:.1f}" text-anchor="middle" class="axs">{nf(v["moyen"],0)}</text><text x="{x0+w/2:.1f}" y="{Hh-8}" text-anchor="middle" class="ax">{JOURS[L][k]}</text>')
        out.append('</svg>'); return ''.join(out)
    def tuile(k, v, s=''): return f'<div class="tile"><div class="k">{k}</div><div class="v">{v}</div><div class="s">{s}</div></div>'
    entete = lambda titre: f'<div class="hd"><span class="marque"><img class="logo" src="{LOGO}" alt="">Atelier by – Halle</span><span class="dr">{t["dr"].format(t=titre)}</span></div>'
    pied = lambda n: f'<div class="ft"><span>{t["pied"]}</span><span>{t["lang"]} {n} / 9</span></div>'
    th = lambda liste: '<thead><tr>' + ''.join(f'<th class="l">{x}</th>' if i == 0 or x == '' else f'<th>{x}</th>' for i, x in enumerate(liste)) + '</tr></thead>'
    pages = []
    res7 = cum['res']; first = [x for x in mois if x['m'] == 2][0]
    pages.append(f'''<section class="page couv {L}">{entete(t['p1'])}
<div class="titre"><img class="logoc" src="{LOGO}" alt="L’Atelier"><div class="sur">{t['sur']}</div><h1>Atelier by – Halle</h1><div class="sous">{t['sous']}</div></div>
<table class="grille"><tr>
<td>{tuile(t['t1'], fE(cum['ca']), t['t1s'].format(m=fE(caMois), p=fP(100*cum['ca']/cum['bud'],0)))}</td>
<td>{tuile(t['t2'], nf(cum['tk']), t['t2s'].format(j=nf(cum['tk']/cum['j'],0), pm=fE(cum['ca']/cum['tk'],2)))}</td>
<td>{tuile(t['t3'], fP(100*cum['mat']/cum['ca']), t['t3s'])}</td>
<td>{tuile(t['t4'], fE(res7), t['t4s'].format(p=fP(100*res7/cum['ca'])))}</td>
</tr></table>
<div class="deux">
<div><div class="sec">{t['s1']}</div><ul class="pts">
<li>{t['b1'].format(j=cum['j'])}</li><li>{t['b2']}</li>
<li>{t['b3'].format(men=nf(budget['etudeMarche']['potentielMenages']), pot=fE(budget['etudeMarche']['potentielMaturite']), ca1=fE(budget['caTheoriqueAn']))}</li>
<li>{t['b4']}</li>
<li>{t['b5'].format(sa=fP(sem['samedi']['part'],0), di=fP(sem['dimanche']['part'],0), we=fP(sem['samedi']['part']+sem['dimanche']['part'],0))}</li>
<li>{t['b6'].format(g=nf(po['google']['note'],1), avis=po['google']['avis'], msp=po['msp']['obtenu'], mspmax=po['msp']['maximum'])}</li>
</ul></div>
<div><div class="sec">{t['s2']}</div><ul class="pts">
<li>{t['c1'].format(mai=fE(M(5)['ca']))}</li><li>{t['c2'].format(cum=fP(100*cum['ca']/cum['bud'],0))}</li>
<li>{t['c3'].format(m3=fP(M(3)['food']), m9=fP(M(9)['food']))}</li>
<li>{t['c4'].format(lab=fP(100*cum['lab']/cum['ca']), oh=fP(100*cum['oh']/cum['ca']))}</li>
<li>{t['c5'].format(p3=fE(M(3)['panier'],2), p9=fE(M(9)['panier'],2))}</li>
</ul></div></div>
<div class="note">{t['n1'].format(fev=fE(first['ca']))}</div>
{pied(1)}</section>''')
    # 2. Les ventes
    def marque(x):
        if x['m'] == 2: return ' <small>20 → 28</small>'
        if x['m'] == 10: return ' <small>1 → 8</small>'
        return f' <small>{t["viaduc"]}</small>' if x['m'] in (6, 7) else ''
    lig = ''.join(f'<tr class="{"part" if x["partiel"] else ""}"><td class="l">{nom(x)}{marque(x)}</td><td>{x["jours"]}</td><td>{fE(x["ca"])}</td><td>{fE(x["budget"]) if x["budget"] else "—"}</td><td>{fP(100*x["ca"]/x["budget"],0) if x["budget"] and not x["partiel"] else "—"}</td><td>{nf(x["tickets"])}</td><td>{fE(x["panier"],2)}</td><td>{fE(x["ca"]/x["jours"]) if x["jours"] else "—"}</td></tr>' for x in mois)
    pages.append(f'''<section class="page {L}">{entete(t['p2'])}
<h2>{t['h2']}</h2>
<table class="t serre">{th(t['th2'])}<tbody>{lig}</tbody><tfoot><tr class="tot"><td class="l">{t['tot']}</td><td>{cum['j']}</td><td>{fE(cum['ca'])}</td><td>{fE(cum['bud'])}</td><td>{fP(100*cum['ca']/cum['bud'],0)}</td><td>{nf(cum['tk'])}</td><td>{fE(cum['ca']/cum['tk'],2)}</td><td>{fE(cum['ca']/cum['j'])}</td></tr></tfoot></table>
<div class="sec">{t['g1']}</div>
{barres_ca()}
<div class="legende"><span class="sw" style="background:#8D1D2C"></span> {t['lg1']} <span class="sw" style="background:#221E1A;height:2px;margin-left:10px"></span> {t['lg2']} <span class="sw" style="background:none;border:1px dashed #8D1D2C;height:7px;margin-left:10px"></span> {t['lg3']}</div>
<div class="deux" style="margin-top:2mm">
<div><div class="sec">{t['semt']}</div><div class="s">{t['sems']}</div>{semaine_svg()}
<div class="s">{t['semd'].format(x=nf(sem['dimanche']['moyen']/sem['lundi']['moyen'],1), v=fE(4336.5))}</div></div>
<div><div class="sec">{t['dit']}</div><ul class="pts">
<li>{t['d1']}</li><li>{t['d2'].format(st=fE(sansTravaux))}</li><li>{t['d3']}</li><li>{t['d4']}</li>
</ul></div></div>
{pied(2)}</section>''')
    # 3. Les coûts
    ligc = ''.join(f'<tr class="{"part" if x["partiel"] else ""}"><td class="l">{nom(x)}</td><td>{fE(x["ca"])}</td><td>{fP(x["food"]) if x["food"] is not None else "—"}</td><td>{fE(x["mat"]) if x["mat"] is not None else "—"}</td><td>{fE(x["lab"])}</td><td>{fP(x["labPct"])}</td><td>{fE(x["oh"])}</td><td>{fP(x["ohPct"])}</td><td>{fE(x["res"]) if x["res"] is not None else "—"}</td><td>{fP(x["resPct"]) if x["resPct"] is not None else "—"}</td></tr>' for x in mois if x['m'] != 2)
    fev = first
    pages.append(f'''<section class="page {L}">{entete(t['p3'])}
<h2>{t['h3']}</h2>
<table class="t serre">{th(t['th3'])}<tbody>{ligc}</tbody><tfoot><tr class="tot"><td class="l">{t['tot']}</td><td>{fE(cum['ca'])}</td><td>{fP(100*cum['mat']/cum['ca'])}</td><td>{fE(cum['mat'])}</td><td>{fE(cum['lab'])}</td><td>{fP(100*cum['lab']/cum['ca'])}</td><td>{fE(cum['oh'])}</td><td>{fP(100*cum['oh']/cum['ca'])}</td><td>{fE(cum['res'])}</td><td>{fP(100*cum['res']/cum['ca'])}</td></tr></tfoot></table>
<div class="sec">{t['g3']}</div>
{lignes_pct()}
<div class="deux" style="margin-top:2mm">
<div class="cadre"><div class="k">{t['lirek']}</div>
<span class="lg">{t['e1']}</span>
<span class="lg">{t['e2'].format(lab=fP(100*cum['lab']/cum['ca']))}</span>
<span class="lg">{t['e3'].format(oh=fE(13042.63))}</span>
<span class="lg">{t['e4']}</span></div>
<div><div class="cadre"><div class="k">{t['plk']}</div><span class="lg">{t['pl'].format(a=fP(PL['aout']['coutMatierePct']), s=fP(PL['sept']['coutMatierePct']), w=fP(PL['sem']['coutMatierePct']))}</span></div>
<div class="sec">{t['fevt']}</div><ul class="pts"><li>{t['f1'].format(ca=fE(fev['ca']), tk=nf(fev['tickets']))}</li><li>{t['f2'].format(oh=fE(fev['oh']), res=fE(fev['res']) if fev['res'] is not None else fE(fev['ca']-fev['lab']-fev['oh']))}</li><li>{t['f3']}</li></ul>
<div class="sec" style="margin-top:3mm">{t['invt']}</div><ul class="pts"><li>{t['inv']}</li></ul></div></div>
{pied(3)}</section>''')
    # 4. Face au réseau + modèle de charges
    tr = lambda s: CH['nl'].get(s, s) if L == 'nl' else s
    ch = ''.join(f'<tr><td class="l">{H(tr(c.get("poste")))}<small>{H(tr(c.get("categorie")))}{(" · " + H(tr(c.get("description")))) if c.get("description") else ""}</small></td><td>{fP(c.get("pctBudget") or 0,1)}</td><td>{fP(c.get("pctTheorique") or 0,1)}</td><td>{fE(caMois*(c.get("pctBudget") or 0)/100)}</td></tr>' for c in budget['charges'])
    tb = sum(c.get('pctBudget') or 0 for c in budget['charges'])
    pages.append(f'''<section class="page {L}">{entete(t['p4'])}
<h2>{t['h4']}</h2>
<table class="t">{th(t['th4'])}<tbody>
<tr><td class="l">{t['r1']}</td><td>{fP(100*cum['mat']/cum['ca'])}</td><td>{fP(aFood)}</td><td>32 %</td></tr>
<tr><td class="l">{t['r2']}</td><td>{fP(100*cum['lab']/cum['ca'])}</td><td>{fP(100*aLab/aCA)}</td><td>33 %</td></tr>
<tr><td class="l">{t['r3']}</td><td>{fP(100*cum['oh']/cum['ca'])}</td><td>{fP(100*aOh/aCA)}</td><td>13,5 %</td></tr>
<tr><td class="l">{t['r4']}</td><td>{fE(cum['ca']/cum['tk'],2)}</td><td>{fE(aPan,2)}</td><td>—</td></tr>
<tr><td class="l">{t['r5']}</td><td>{t['r5v'].format(r=rangH)}</td><td colspan="2" class="l">{t['r5s']}</td></tr>
<tr><td class="l">{t['r6']}</td><td>{nf(hs['total'],1)}</td><td>{t['r6r'].format(v=nf(sc3['reseau']['sur20'],1))}</td><td>{t['r6s'].format(r=hs['rang'])}</td></tr>
</tbody></table>
<div class="s">{t['r7']}</div>
<h2 style="margin-top:6mm">{t['h4b']}</h2>
<div class="s">{t['m4'].format(ca=fE(caMois))}</div>
<table class="t serre">{th(t['th4b'])}<tbody>{ch}</tbody>
<tfoot><tr class="tot"><td class="l">{t['tot4']}</td><td>{fP(tb,1)}</td><td></td><td>{fE(caMois*tb/100)}</td></tr></tfoot></table>
<div class="note">{t['n4'].format(l=fE(caMois*0.07))}</div>
{pied(4)}</section>''')
    # 5. L'assortiment et les canaux
    top = cats[:14]; maxv = top[0]['v']
    lc = ''.join(f'<tr><td class="l">{H(c["nom"])}<small>{H(c.get("groupe") or "")}</small></td><td>{fE(c["v"])}</td><td>{fP(100*c["v"]/tv)}</td><td class="l" style="width:34%"><span class="barre"><i style="width:{100*c["v"]/maxv:.0f}%;background:#8D1D2C"></i></span></td><td>{nf(c.get("q"))}</td><td>{c.get("refs")}</td></tr>' for c in top)
    lf = ''.join(f'<span class="lg">{H(k)} <span class="dr2">{fP(100*v/tv)}</span></span>' for k, v in fam[:8])
    pages.append(f'''<section class="page {L}">{entete(t['p5'])}
<h2>{t['h5']}</h2>
<div class="deux g31">
<div><table class="t">{th(t['th5'])}<tbody>{lc}</tbody></table>
<div class="s">{t['a5'].format(ca=nf(stats['totaux']['ca']), j=stats['nJoursOuverts'], refs=sum(c.get('refs') or 0 for c in cats), p=fP(100*sum(c['v'] for c in top)/tv,0))}</div></div>
<div><div class="cadre"><div class="k">{t['famt']}</div>{lf}</div>
<div class="sec" style="margin-top:4mm">{t['cant']}</div><ul class="pts">
<li>{t['k1']}</li><li>{t['k2']}</li><li>{t['k3'].format(v=fE(stats['meilleure']['res']))}</li>
</ul></div></div>
{pied(5)}</section>''')
    # 6. Les marges par catégorie et leur poids dans le chiffre (demande du 09/10/2026)
    def barres_cat():
        xs = MC[:12]; W, rh, ml = 340, 16, 128; Hh = rh * (len(xs) + 1) + 8; maxp = max(x['part'] for x in xs)
        out = [f'<svg viewBox="0 0 {W} {Hh}" class="graph">']
        for i, x in enumerate(xs):
            y = 4 + i * rh; w = (W - ml - 58) * x['part'] / maxp; col = ZCOL[zoneM(x['marge'])]
            out.append(f'<text x="{ml-5}" y="{y+10.5:.1f}" text-anchor="end" class="ax">{H(x["nom"][:26])}</text><rect x="{ml}" y="{y+2}" width="{w:.1f}" height="{rh-5}" rx="2" fill="{col}"/>'
                       f'<text x="{ml+w+4:.1f}" y="{y+10.5:.1f}" class="axs">{nf(x["part"],1)} % · {(nf(x["marge"],0) + " %") if x["marge"] is not None else "—"}</text>')
        pr = 100 * autresV / tv + sum(x['part'] for x in MC[12:]); y = 4 + len(xs) * rh; w = (W - ml - 58) * pr / maxp
        out.append(f'<text x="{ml-5}" y="{y+10.5:.1f}" text-anchor="end" class="ax">{t["aut8"]}</text><rect x="{ml}" y="{y+2}" width="{w:.1f}" height="{rh-5}" rx="2" fill="#d9d2c6"/><text x="{ml+w+4:.1f}" y="{y+10.5:.1f}" class="axs">{nf(pr,1)} %</text>')
        out.append('</svg>'); return ''.join(out)
    lm = ''.join(f'<tr><td class="l">{H(x["nom"])}</td><td class="l"><small style="display:inline">{H(x["groupe"])}</small></td><td>{fP(x["part"])}</td><td>{fE(x["v"])}</td><td>{nf(x["q"])}</td>'
                 f'<td style="color:{ZCOL[zoneM(x["marge"])]}">{fP(x["marge"],0) if x["marge"] is not None else "—"}</td><td>{("× " + nf(x["coef"],2)) if x["coef"] else "—"}</td><td>{fP(x["couv"],0)}</td></tr>' for x in MC)
    lm += f'<tr class="part"><td class="l">{t["aut8"]} ({len(reste)})</td><td></td><td>{fP(100*autresV/tv)}</td><td>{fE(autresV)}</td><td>{nf(autresQ)}</td><td>{fP(autresM,0) if autresM is not None else "—"}</td><td>{("× " + nf(autresVK/autresCK,2)) if autresCK else "—"}</td><td>{fP(100*autresVK/autresV,0) if autresV else "—"}</td></tr>'
    famT = fam[:3]; pF = 100 * sum(v for k, v in famT) / tv
    lF = ', '.join(f'{H(k)} ({fP(100*v/tv,0)})' for k, v in famT)
    hauts = [x for x in MC if x['marge'] is not None and x['marge'] >= 60]; bas = [x for x in MC if x['marge'] is not None and x['marge'] < 50]
    lB = ', '.join(f'{H(x["nom"])} ({fP(x["marge"],0)})' for x in sorted(bas, key=lambda x: -x['v'])[:4])
    lev = max((x for x in MC if x['marge'] is not None and x['marge'] < 60), key=lambda x: (60 - x['marge']) * x['part'], default=None)
    pages.append(f'''<section class="page {L}">{entete(t['p8'])}
<h2>{t['h8']}</h2><div class="s">{t['s8']}</div>
<div class="deux" style="margin-bottom:3mm">
<div><div class="sec">{t['g8']}</div>{barres_cat()}
<div class="legende"><span class="sw" style="background:{ZCOL['ok']}"></span> {t['lg8'][0]} <span class="sw" style="background:{ZCOL['att']};margin-left:8px"></span> {t['lg8'][1]} <span class="sw" style="background:{ZCOL['ko']};margin-left:8px"></span> {t['lg8'][2]} <span class="sw" style="background:{ZCOL['mu']};margin-left:8px"></span> {t['lg8'][3]}</div></div>
<div><div class="sec">{t['dit']}</div><ul class="pts">
<li>{t['x1'].format(p=fP(pF,0), l=lF)}</li>
<li>{t['x2'].format(c=fP(couvT,0), m=fP(margeT,1), f=fP(foodT,1), k=nf(coefT,2))}</li>
<li>{t['x3'].format(a=fP(sum(x['part'] for x in hauts),0), b=fP(sum(x['part'] for x in bas),0), l=lB or '—')}</li>
{('<li>' + t['x4'].format(n=H(lev['nom']), p=fP(lev['part'],0), m=fP(lev['marge'],0), e=fE(lev['v'] * (60 - lev['marge']) / 100)) + '</li>') if lev else ''}
</ul></div></div>
<table class="t serre">{th(t['th8'])}<tbody>{lm}</tbody><tfoot><tr class="tot"><td class="l">{t['tot8']}</td><td></td><td>100 %</td><td>{fE(tv)}</td><td>{nf(sum(x['q'] for x in MCall))}</td><td>{fP(margeT,0)}</td><td>× {nf(coefT,2)}</td><td>{fP(couvT,0)}</td></tr></tfoot></table>
{pied(6)}</section>''')
    # 7. La qualité et les photos
    grid = ''
    for c in choix:
        m = meta.get(c)
        if not m: continue
        code = (m['checklist'] or '')[:5].strip(' -—'); lib = LIB[L].get(code, m['checklist'] or '')
        note = m.get('avis', {}) or {}
        grid += f'<figure><img src="photos/{m["fichier"]}"><figcaption>{H(lib)} · {fDj(m["date"])}{(" · " + t["note"].format(n=note.get("note"))) if note.get("note") else ""}</figcaption></figure>'
    tache = po['taches']
    pages.append(f'''<section class="page {L}">{entete(t['p6'])}
<h2>{t['h6']}</h2>
<table class="grille"><tr>
<td>{tuile(t['q1'], nf(po['google']['note'],1) + ' / 5', t['q1s'].format(avis=po['google']['avis'], d=fDj(po['google']['le'][:10])))}</td>
<td>{tuile(t['q2'], f"{po['msp']['obtenu']} / {po['msp']['maximum']}", t['q2s'])}</td>
<td>{tuile(t['q3'], fP(tache['part'],0), t['q3s'].format(f=nf(tache['faites']), a=nf(tache['attendues'])))}</td>
<td>{tuile(t['q4'], f"{nf(hs['total'],1)} / 20", t['q4s'].format(r=hs['rang'], v=nf(sc3['reseau']['sur20'],1)))}</td>
</tr></table>
<div class="s">{t['q5']}</div>
<div class="photos">{grid}</div>
<div class="note">{t['q6']}</div>
{pied(7)}</section>''')
    # 8. Les outils de gestion : les écrans du dashboard de Halle (demande du 09/10/2026)
    ecr = [('outils/bureau-jour.png', '', t['oc'][0]), ('outils/tel-exploitation.png', 'tel', t['oc'][1]), ('outils/bureau-ops.png', '', t['oc'][2]), ('outils/modale-bureau.png', 'tel', t['oc'][3])]
    pages.append(f'''<section class="page {L}">{entete(t['p9'])}
<h2>{t['h9']}</h2><div class="s">{t['o9']}</div>
<div class="outils">{''.join(f'<figure class="{cl}"><img src="{src}" alt=""><figcaption>{cap}</figcaption></figure>' for src, cl, cap in ecr)}</div>
<div class="sec" style="margin-top:3mm">{t['dit']}</div>
<ul class="pts deuxcol">{''.join(f'<li>{x}</li>' for x in t['ob'])}</ul>
{pied(8)}</section>''')
    # 9. Sources
    pages.append(f'''<section class="page {L}">{entete(t['p7'])}
<h2>{t['h7']}</h2>
<div class="cadre"><div class="k">{t['srck']}</div>
<span class="lg">{t['s71']}</span><span class="lg">{t['s72']}</span><span class="lg">{t['s73']}</span><span class="lg">{t['s74']}</span><span class="lg">{t['s75']}</span><span class="lg">{t['s76']}</span></div>
<div class="sec" style="margin-top:5mm">{t['limt']}</div>
<ul class="pts"><li>{t['l1']}</li><li>{t['l2']}</li><li>{t['l3']}</li><li>{t['l4']}</li><li>{t['l5']}</li></ul>
<div class="sec" style="margin-top:5mm">{t['plust']}</div>
<ul class="pts"><li>{t['pl1']}</li><li>{t['pl2']}</li></ul>
{pied(9)}</section>''')
    return pages

css = '''
@font-face{font-family:'Gotham';src:url('file:///home/user/consultant_BO/public/assets/ds/fonts/Gotham_Light.otf');font-weight:400}
@font-face{font-family:'Gotham';src:url('file:///home/user/consultant_BO/public/assets/ds/fonts/Gotham_Medium.otf');font-weight:500}
@font-face{font-family:'Vank';src:url('file:///home/user/consultant_BO/public/assets/ds/fonts/GC_Vank.ttf')}
@page{size:A4;margin:0}
*{box-sizing:border-box}
body{margin:0;font-family:Gotham,Helvetica,Arial,sans-serif;color:#221E1A;font-size:9pt;line-height:1.45;background:#fff}
.page{width:210mm;height:297mm;padding:12mm 14mm 10mm;position:relative;page-break-after:always;overflow:hidden;background:#fff}
.page:last-child{page-break-after:auto}
.hd{display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #8D1D2C;padding-bottom:2mm;margin-bottom:5mm;font-size:7.5pt;letter-spacing:.08em;text-transform:uppercase;color:#7a736a}
.hd .marque{color:#8D1D2C;font-weight:500;display:flex;align-items:center;gap:2.5mm}.hd .logo{height:4.6mm;width:auto}
.ft{position:absolute;left:14mm;right:14mm;bottom:7mm;display:flex;justify-content:space-between;gap:4mm;font-size:7pt;color:#9a938a;border-top:.5pt solid #e6e0d8;padding-top:1.5mm}.ft span:last-child{white-space:nowrap}
h1{font-family:Vank,Georgia,serif;font-weight:400;font-size:34pt;margin:0;letter-spacing:-.01em;line-height:1.05}
h2{font-family:Vank,Georgia,serif;font-weight:400;font-size:17pt;margin:0 0 3.5mm;color:#221E1A}
.titre{margin:7mm 0 7mm}.logoc{height:12mm;width:auto;display:block;margin:0 0 6mm}
.sur{font-size:8pt;letter-spacing:.12em;text-transform:uppercase;color:#8D1D2C;font-weight:500;margin-bottom:2mm}
.sous{font-size:10.5pt;color:#7a736a;margin-top:2.5mm}
.sec{font-family:Vank,Georgia,serif;font-size:12.5pt;margin:0 0 2.5mm;padding-bottom:1.2mm;border-bottom:1.2pt solid #8D1D2C}
.s{font-size:7.8pt;color:#7a736a;line-height:1.5;margin:1mm 0 2mm}
table.grille{width:100%;border-collapse:separate;border-spacing:2mm 0;margin:0 -2mm 6mm}
.tile{border:1px solid #e6e0d8;border-radius:8px;background:#fbf9f5;padding:3mm 3.5mm;height:100%}
.tile .k{font-size:6.8pt;letter-spacing:.08em;text-transform:uppercase;color:#7a736a}
.tile .v{font-family:Vank,Georgia,serif;font-size:19pt;margin-top:1.5mm;white-space:nowrap;color:#8D1D2C}
.tile .s{font-size:7.2pt;color:#7a736a;margin-top:1mm;line-height:1.4}
.deux{display:grid;grid-template-columns:1fr 1fr;gap:7mm}
.deux.g31{grid-template-columns:1.35fr 1fr}
.pts{margin:0;padding-left:4mm;font-size:8.6pt}
.pts li{margin-bottom:1.3mm;line-height:1.42}
table.t{width:100%;border-collapse:collapse;margin-bottom:4mm}
.t th{font-size:6.6pt;letter-spacing:.06em;text-transform:uppercase;color:#7a736a;font-weight:500;text-align:right;padding:1.4mm 1.8mm;border-bottom:1pt solid #221E1A;vertical-align:bottom;line-height:1.25}
.t td{font-size:8.4pt;text-align:right;padding:1.3mm 1.8mm;border-bottom:.5pt solid #EAE3D8;white-space:nowrap;font-variant-numeric:tabular-nums}
.t .l{text-align:left;white-space:normal}
.t td small{display:block;font-size:6.8pt;color:#9a938a;line-height:1.3}
.t tr.tot td{border-top:1pt solid #221E1A;border-bottom:none;font-weight:500;background:#FCFAF7}
.t td{font-weight:400}
.t tr.part td{color:#9a938a}
.t.serre td{padding:0.9mm 1.8mm}.t.serre td small{font-size:6.4pt}
.graph{width:100%;height:auto;display:block}
.graph .ax{font-size:9px;fill:#7a736a}.graph .axs{font-size:8.5px;fill:#7a736a}.graph .val{font-size:9.5px;font-weight:500}
.legende{font-size:7.5pt;color:#7a736a;margin:.5mm 0 1.5mm}.legende .sw{display:inline-block;width:10px;height:6px;vertical-align:middle;margin-right:4px}
.cadre{border:1px solid #8D1D2C;border-radius:8px;padding:2.5mm 3.5mm;margin-bottom:3mm;font-size:8.2pt}
.cadre .k{color:#8D1D2C;font-weight:500;font-size:7pt;letter-spacing:.08em;text-transform:uppercase;margin-bottom:1mm}
.cadre .lg{display:block;padding:1mm 0;border-bottom:.5pt solid #EAE3D8;line-height:1.45}.cadre .lg:last-child{border-bottom:none}.cadre .dr2{color:#8D1D2C}
.note{border-left:3px solid #8D1D2C;background:#fbf9f5;padding:2mm 3mm;font-size:8pt;color:#5a534b;margin-top:3mm;line-height:1.5}
.barre{display:inline-block;position:relative;width:100%;height:2.6mm;border-radius:2mm;background:#EFE9DF;vertical-align:middle}.barre i{position:absolute;left:0;top:0;height:2.6mm;border-radius:2mm}
.photos{display:grid;grid-template-columns:repeat(3,1fr);gap:3mm;margin-top:3mm}
.photos figure{margin:0}.photos img{width:100%;height:46mm;object-fit:cover;border-radius:6px;display:block}
.photos figcaption{font-size:7pt;color:#7a736a;margin-top:1mm;line-height:1.3}
.couv .deux{margin-top:2mm}
.outils{display:grid;grid-template-columns:2fr 1fr;gap:3mm 4mm;margin-top:1mm}
.outils figure{margin:0}.outils img{width:100%;height:68mm;object-fit:cover;object-position:top;border:1px solid #e6e0d8;border-radius:6px;display:block;background:#fbf9f5}
.outils figure.tel img{object-fit:contain}.outils figcaption{font-size:7pt;color:#7a736a;margin-top:1mm;line-height:1.3}
.pts.deuxcol{columns:2;column-gap:7mm}.pts.deuxcol li{break-inside:avoid}
.nl table.t.serre td{padding:.7mm 1.8mm}
.nl .pts{font-size:8.3pt}.nl .cadre{font-size:7.9pt}.nl .s{font-size:7.6pt}.nl .note{font-size:7.8pt}
'''
pages = rendre('fr') + rendre('nl')
doc = f'<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Atelier by – Halle · dossier investisseur · investeerdersdossier</title><style>{css}</style></head><body>{"".join(pages)}</body></html>'
assert '<b>' not in doc, 'du gras a échappé'
open(SP + '/halle/dossier.html', 'w', encoding='utf-8').write(doc)
print('dossier.html', len(doc), 'octets ·', len(pages), 'pages · CA 7 mois', round(cum['ca']), '· résultat', round(cum['res']), '· sans travaux +', round(sansTravaux), '· P&L', PL['aout']['coutMatierePct'], PL['sept']['coutMatierePct'], PL['sem']['coutMatierePct'])
