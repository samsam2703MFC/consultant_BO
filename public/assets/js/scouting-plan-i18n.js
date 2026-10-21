/* Le plan d'expansion en deux langues — français et néerlandais.
 *
 * Le plan se remet à des partenaires flamands (21/10/2026, demande de Sam) :
 * le document — l'écran, l'impression, le PDF, le CSV — doit pouvoir sortir en
 * néerlandais. Les réglages de l'écran restent en français : c'est le document
 * qui voyage, pas l'outil.
 *
 * Tout ce que le plan écrit passe par ici : les libellés, les phrases
 * construites (méthode, écarts, voisins), les noms des provinces et des
 * arrondissements, la forme des nombres et des montants. Les noms de communes
 * restent ceux d'OpenStreetMap — en Flandre ils sont déjà néerlandais, en
 * Wallonie français, à Bruxelles bilingues.
 */

export const LANGUES = [['fr', 'Français'], ['nl', 'Nederlands']];

const PROV_NL = {
  BRU: 'Brussels Hoofdstedelijk Gewest', VAN: 'Antwerpen', VBR: 'Vlaams-Brabant', VWV: 'West-Vlaanderen',
  VOV: 'Oost-Vlaanderen', VLI: 'Limburg', WBR: 'Waals-Brabant', WHT: 'Henegouwen', WLG: 'Luik',
  WLX: 'Luxemburg', WNA: 'Namen'
};
const REGION_NL = { Bruxelles: 'Brussel', Flandre: 'Vlaanderen', Wallonie: 'Wallonië' };
// Les arrondissements administratifs, par leur nom français tel que le
// scouting le porte (table ARR de scouting.js).
const ARR_NL = {
  'Anvers': 'Antwerpen', 'Malines': 'Mechelen', 'Turnhout': 'Turnhout',
  'Bruxelles-Capitale': 'Brussel-Hoofdstad', 'Hal-Vilvorde': 'Halle-Vilvoorde', 'Louvain': 'Leuven', 'Nivelles': 'Nijvel',
  'Bruges': 'Brugge', 'Dixmude': 'Diksmuide', 'Ypres': 'Ieper', 'Courtrai': 'Kortrijk', 'Ostende': 'Oostende',
  'Roulers': 'Roeselare', 'Tielt': 'Tielt', 'Furnes': 'Veurne',
  'Alost': 'Aalst', 'Termonde': 'Dendermonde', 'Eeklo': 'Eeklo', 'Gand': 'Gent', 'Audenarde': 'Oudenaarde', 'Saint-Nicolas': 'Sint-Niklaas',
  'Ath': 'Aat', 'Charleroi': 'Charleroi', 'Mons': 'Bergen', 'Mouscron': 'Moeskroen', 'Soignies': 'Zinnik', 'Thuin': 'Thuin',
  'Tournai': 'Doornik', 'La Louvière': 'La Louvière',
  'Huy': 'Hoei', 'Liège': 'Luik', 'Verviers': 'Verviers', 'Waremme': 'Borgworm',
  'Hasselt': 'Hasselt', 'Maaseik': 'Maaseik', 'Tongres': 'Tongeren',
  'Arlon': 'Aarlen', 'Bastogne': 'Bastenaken', 'Marche-en-Famenne': 'Marche-en-Famenne', 'Neufchâteau': 'Neufchâteau', 'Virton': 'Virton',
  'Dinant': 'Dinant', 'Namur': 'Namen', 'Philippeville': 'Philippeville'
};

const pl = (n, un, plusieurs) => (n > 1 ? plusieurs : un);

// La forme des nombres : les séparateurs de la langue, le symbole euro à sa
// place (« 1 234 € » en français de Belgique, « € 1.234 » en néerlandais).
function formes(lang){
  const loc = lang === 'nl' ? 'nl-BE' : 'fr-BE';
  const nf = n => Math.round(n).toLocaleString(loc);
  const dec = (n, d) => Number(n).toFixed(d).replace('.', ',');
  return {
    loc: loc,
    nf: nf,
    dec: dec,
    eur: n => lang === 'nl' ? '€ ' + nf(n) : nf(n) + ' €',
    pct: (v, d) => dec(v, d == null ? 1 : d) + (lang === 'nl' ? '%' : ' %'),
    km: v => dec(v, 1) + ' km',
    date: d => (d || new Date()).toLocaleDateString(loc, { day: 'numeric', month: 'long', year: 'numeric' }),
    // « 2025-10 » → « oct. 2025 » / « okt 2025 »
    mois: ym => { const m = /^(\d{4})-(\d{2})$/.exec(String(ym || '')); return m ? new Date(+m[1], +m[2] - 1, 1).toLocaleDateString(loc, { month: 'short', year: 'numeric' }) : String(ym || ''); }
  };
}

const FR = {
  lang: 'fr',
  province: p => p.name,
  typeProv: p => (p.code === 'BRU' ? 'Région' : 'Province') + ' · ' + p.reg,
  arr: a => a,
  ville: v => v,
  // le titre et le bandeau
  titre: (n, p) => 'Plan d’expansion — ' + n + ' ' + pl(n, 'ouverture', 'ouvertures') + ' dans ' + p + ' ' + pl(p, 'province', 'provinces'),
  titreProv: nom => 'Plan d’expansion — ' + nom,
  edite: d => 'édité le ' + d,
  parProvMax: N => N + ' par province au plus',
  scoreMin: x => 'score ≥ ' + x,
  sansScore: 'sans score minimum',
  caMin: e => 'CA ≥ ' + e,
  ecartCourt: m => m + ' min de voiture au moins entre elles',
  caAnnuel: 'CA annuel estimé',
  // le résumé
  rCa: 'CA annuel estimé TTC, toutes ouvertures', parSemaine: e => e + ' par semaine',
  rOuv: 'Ouvertures retenues', entreElles: m => m + ' min entre elles', parRoute: ' par la route',
  ecartees: n => n + ' ' + pl(n, 'écartée', 'écartées') + ' pour proximité',
  rMen: 'Ménages accessibles cumulés', parPoint: x => x + ' par point en moyenne',
  rRes: 'Le réseau aujourd’hui', resAjout: (n, p) => n + ' ' + pl(n, 'magasin ouvert', 'magasins ouverts') + ' · le plan ajouterait + ' + p + ' %',
  rMoy: 'CA moyen par ouverture', surM2: s => 'sur ' + s + ' m²',
  // les voisins et l'écart
  duMagasin: v => 'du magasin de ' + v,
  de: n => (/^[aeiouyàâéèêëîïôûùh]/i.test(n) ? 'd’' : 'de ') + n,
  voisinRoute: (min, de, approx) => 'à ' + min + ' min ' + de + (approx ? ' (estimé)' : ''),
  voisinVol: (km, de) => 'à ' + km + ' km ' + de + ' à vol d’oiseau',
  rienAMoins: km => 'rien à moins de ' + km + ' km',
  sansEcart: 'sans écart minimum entre elles',
  ecartBase: m => 'à ' + m + ' min de voiture au moins les unes des autres',
  etOuverts: ' et des magasins ouverts',
  ecartRoute: (n, approx, cut) => ' — minutes de route du service Valhalla sur OpenStreetMap, ' + n + ' trajets calculés' + (approx ? ', ' + approx + ' ' + pl(approx, 'estimé', 'estimés') + ' à vol d’oiseau faute de réponse' : '') + ' ; au-delà de ' + cut + ' km à vol d’oiseau, l’écart est acquis sans calcul',
  ecartVol: km => ' (' + km + ' km à vol d’oiseau, à 45 km/h de moyenne)',
  // les provinces dans le plan
  detailProv: (c, s) => c + ' communes · ' + s + ' commerces dans la sélection',
  ecarteesProv: n => ' · ' + n + ' ' + pl(n, 'zone écartée', 'zones écartées') + ' pour proximité',
  carteNote: 'Les ronds verts numérotés : les zones retenues, dans l’ordre du plan. Les carrés noirs : les magasins du réseau déjà ouverts. Fond de carte © OpenStreetMap.',
  carteNoteProv: 'Les ronds verts numérotés : les zones retenues. Les carrés noirs : les magasins du réseau. Les petits points : les concurrents de la sélection, en bordeaux les forts. Fond de carte © OpenStreetMap.',
  methode: o => 'Chaque province cochée est balayée sur toute son emprise, à la maille d’un rayon ; en chaque point, les ménages du recensement dans ' + o.R + ', les concurrents et leur pression, l’emprise et le CA du modèle de la fiche. Une zone par commune, hors des rayons d’exclusion des concurrents forts, les ' + o.N + ' meilleures au score par province'
    + (o.seuil ? ', au-dessus du score ' + o.minScore : ' — sans score minimum, le score dit ce qu’elles valent') + (o.caMin ? ', au CA annuel estimé d’au moins ' + o.caMin : '') + ', ' + o.ecartTxt
    + (o.ecartes ? ' ; ' + o.ecartes + ' ' + pl(o.ecartes, 'zone mieux classée a', 'zones mieux classées ont') + ' cédé la place pour cause de proximité' : '')
    + '. Les CA s’additionnent comme si chaque ouverture était seule : deux zones voisines se partageraient une partie du marché.',
  hyp: {
    depense: 'Dépense par ménage', parAn: e => e + ' / an', passage: 'Part du passage', surface: 'Surface nette cible',
    emprise: 'Emprise', imposee: p => 'imposée ' + p, calculee: p => 'calculée, max ' + p, compK: 'Sensibilité à la concurrence',
    rayon: 'Rayon', weak: 'Concurrent dès', thresh: 'Concurrent fort dès', minScore: 'Score minimum',
    ecart: 'Écart entre ouvertures', ecartV: (m, route, km, res) => m + ' min de voiture' + (route ? ', temps de route' : ', ≈ ' + km + ' km') + (res ? ', magasins ouverts compris' : ''),
    caMin: 'CA annuel minimum', aucun: 'aucun'
  },
  sources: o => 'Commerces et communes : OpenStreetMap' + (o.osm ? ', cache du serveur relu le ' + o.osm : '') + ' · population : grille 1 km² du recensement 2021 (StatBel, diffusion Eurostat)'
    + ' · dépense par ménage, emprise et surface : étude GeoConsulting (Halle, 28/08/2024)' + (o.google ? ' · notes : Google Places' : '') + (o.route ? ' · temps de route : Valhalla (FOSSGIS) sur OpenStreetMap' : '')
    + ' · CA réel du réseau : ventes TTC du P&L mensuel du panel, complétées par les ventes caisse (montants bruts après remises, TTC) pour les mois sans P&L, douze derniers mois clos.',
  // la page
  h: {
    degager: 'Ce que le plan peut dégager', ouOuvrir: 'Où ouvrir', attenteCarte: 'Carte en cours d’assemblage…',
    parProvince: 'Par province', dixMeilleures: 'Les dix meilleures, toutes provinces',
    methode: 'Méthode.', hypotheses: 'Les hypothèses au moment de l’édition', sources: 'Sources.',
    ouOuvrirProv: p => p.code === 'BRU' ? 'Où ouvrir dans la région' : 'Où ouvrir dans la province',
    concurrence: 'La concurrence', notes: 'Les notes Google, coupées par les deux seuils', chaines: 'Les enseignes de chaîne',
    plusForts: 'Les concurrents les plus forts', parArr: 'Par arrondissement',
    reseauProv: p => p.code === 'BRU' ? 'Le réseau dans la région' : 'Le réseau dans la province'
  },
  col: {
    num: '#', commune: 'Commune', arr: 'Arrondis&shy;sement', arrPlein: 'Arrondissement', score: 'Score', menages: 'Ménages',
    concurrents: 'Concur&shy;rents', concurrentsPlein: 'Concurrents', chaines: 'Chaînes', emprise: 'Emprise',
    caTtc: 'CA estimé TTC', ca: 'CA estimé', m2: '€/m²', province: 'Province', ouvertures: 'Ouver&shy;tures',
    forts: 'Forts', parPoint: 'Ménages par point de vente', reseau: 'Magasins du réseau', caOuv: 'CA estimé des ouvertures',
    communes: 'Communes', noteMoy: 'Note moy.', enseigne: 'Enseigne', points: 'Points de vente', nom: 'Nom', note: 'Note',
    avis: 'Avis', force: 'Force', magasin: 'Magasin', ville: 'Ville', caReel: 'CA réel TTC, 12 mois', periode: 'Période',
    commerces: 'Commerces', part: 'Part'
  },
  fort: n => '(' + n + ' ' + pl(n, 'fort', 'forts') + ')',
  nOuv: n => n + ' ' + pl(n, 'ouverture', 'ouvertures'),
  total: 'Total',
  aucuneZone: seuil => 'Aucune zone hors des rayons d’exclusion dans cette province' + (seuil ? ', au-dessus du score ' + seuil : '') + '.',
  // la page d'une province
  pOuvRetenues: (n, d) => n + ' ' + pl(n, 'ouverture retenue', 'ouvertures retenues') + (d ? ' · édité le ' + d : ''),
  pCaOuv: 'CA annuel estimé des ouvertures',
  tMenages: 'Ménages', tMenagesS: (pop, marche) => pop + ' habitants · marché ' + marche,
  tConc: 'Concurrents', tConcS: (f, c, moy) => f + ' ' + pl(f, 'fort', 'forts') + ' · ' + c + ' de chaîne' + (moy ? ' · note moyenne ' + moy : ''),
  tDens: 'Ménages par point de vente', tDensS: (d, sel) => d + ' commerces pour 10 000 habitants' + (sel ? ' · ' + sel + ' sur toute la sélection' : ''),
  tRes: 'Le réseau', tResS: (n, du) => n + ' ' + pl(n, 'magasin', 'magasins') + ' · CA réel TTC' + (du ? ', ' + du : ''), tResVide: 'aucun magasin ouvert',
  tResVideS: 'le plan y serait une première implantation',
  notesNon: 'Sans note', notesSous: w => 'Sous ' + w + ' ★ — ne comptent pas', notesComptes: (w, t) => 'De ' + w + ' à ' + t + ' ★ — comptés', notesForts: t => 'Dès ' + t + ' ★ — forts',
  noteMoyenne: (m, n) => 'Note moyenne ' + m + ' sur ' + n + ' ' + pl(n, 'commerce noté', 'commerces notés'),
  aucuneChaine: 'Aucune enseigne de chaîne relevée dans la sélection.',
  chaine: 'chaîne',
  aucunConc: 'Aucun concurrent dans la sélection.',
  aucunReseau: p => 'Aucun magasin du réseau dans ' + (p.code === 'BRU' ? 'cette région' : 'cette province') + ' : le plan y serait une première implantation.',
  periode: (du, au) => du + ' – ' + au,
  aucuneProv: 'Aucune province cochée : choisissez au moins une province dans la liste.',
  // le CSV
  csvNom: 'plan_expansion',
  csvEntete: ['numero', 'province', 'rang_province', 'commune', 'arrondissement', 'score', 'menages', 'concurrents', 'concurrents_forts', 'chaines', 'emprise_pct', 'ca_annuel_ttc', 'ca_par_m2', 'lat', 'lng', 'plus_proche', 'plus_proche_km_vol_oiseau', 'plus_proche_min_route'],
  csvTotal: 'TOTAL'
};

const NL = {
  lang: 'nl',
  province: p => PROV_NL[p.code] || p.name,
  typeProv: p => (p.code === 'BRU' ? 'Gewest' : 'Provincie') + ' · ' + (REGION_NL[p.reg] || p.reg),
  arr: a => ARR_NL[a] || a,
  ville: v => v,
  titre: (n, p) => 'Uitbreidingsplan — ' + n + ' ' + pl(n, 'opening', 'openingen') + ' in ' + p + ' ' + pl(p, 'provincie', 'provincies'),
  titreProv: nom => 'Uitbreidingsplan — ' + nom,
  edite: d => 'opgemaakt op ' + d,
  parProvMax: N => 'max. ' + N + ' per provincie',
  scoreMin: x => 'score ≥ ' + x,
  sansScore: 'zonder minimumscore',
  caMin: e => 'omzet ≥ ' + e,
  ecartCourt: m => 'minstens ' + m + ' min rijden van elkaar',
  caAnnuel: 'Geschatte jaaromzet',
  rCa: 'Geschatte jaaromzet incl. btw, alle openingen', parSemaine: e => e + ' per week',
  rOuv: 'Weerhouden openingen', entreElles: m => m + ' min tussen elkaar', parRoute: ' over de weg',
  ecartees: n => n + ' geweerd wegens nabijheid',
  rMen: 'Bereikbare huishoudens, opgeteld', parPoint: x => 'gemiddeld ' + x + ' per locatie',
  rRes: 'Het netwerk vandaag', resAjout: (n, p) => n + ' ' + pl(n, 'open winkel', 'open winkels') + ' · het plan zou + ' + p + '% toevoegen',
  rMoy: 'Gemiddelde omzet per opening', surM2: s => 'op ' + s + ' m²',
  duMagasin: v => 'van de winkel in ' + v,
  de: n => 'van ' + n,
  voisinRoute: (min, de, approx) => 'op ' + min + ' min ' + de + (approx ? ' (geschat)' : ''),
  voisinVol: (km, de) => 'op ' + km + ' km ' + de + ' in vogelvlucht',
  rienAMoins: km => 'niets binnen ' + km + ' km',
  sansEcart: 'zonder minimale afstand tussen de openingen',
  ecartBase: m => 'op minstens ' + m + ' minuten rijden van elkaar',
  etOuverts: ' en van de open winkels',
  ecartRoute: (n, approx, cut) => ' — rijtijden van de Valhalla-dienst op OpenStreetMap, ' + n + ' trajecten berekend' + (approx ? ', ' + approx + ' in vogelvlucht geschat bij gebrek aan antwoord' : '') + '; boven ' + cut + ' km in vogelvlucht is de afstand zonder berekening verzekerd',
  ecartVol: km => ' (' + km + ' km in vogelvlucht, aan gemiddeld 45 km/u)',
  detailProv: (c, s) => c + ' gemeenten · ' + s + ' zaken in de selectie',
  ecarteesProv: n => ' · ' + n + ' ' + pl(n, 'zone', 'zones') + ' geweerd wegens nabijheid',
  carteNote: 'Groene genummerde cirkels: de weerhouden zones, in de volgorde van het plan. Zwarte vierkanten: de reeds open winkels van het netwerk. Achtergrondkaart © OpenStreetMap.',
  carteNoteProv: 'Groene genummerde cirkels: de weerhouden zones. Zwarte vierkanten: de winkels van het netwerk. Kleine stippen: de concurrenten in de selectie, in bordeaux de sterke. Achtergrondkaart © OpenStreetMap.',
  methode: o => 'Elke aangevinkte provincie wordt over haar volledige oppervlakte afgetast, in een raster ter grootte van de straal; op elk punt de huishoudens uit de volkstelling binnen ' + o.R + ', de concurrenten en hun druk, het marktaandeel en de omzet volgens het model van de fiche. Eén zone per gemeente, buiten de uitsluitingsstralen van sterke concurrenten, de ' + o.N + ' beste op score per provincie'
    + (o.seuil ? ', boven score ' + o.minScore : ' — zonder minimumscore; de score zegt wat ze waard zijn') + (o.caMin ? ', met een geschatte jaaromzet van minstens ' + o.caMin : '') + ', ' + o.ecartTxt
    + (o.ecartes ? '; ' + o.ecartes + ' beter gerangschikte ' + pl(o.ecartes, 'zone moest', 'zones moesten') + ' wijken wegens nabijheid' : '')
    + '. De omzetten worden opgeteld alsof elke opening alleen staat: twee naburige zones zouden een deel van de markt delen.',
  hyp: {
    depense: 'Uitgave per huishouden', parAn: e => e + ' / jaar', passage: 'Aandeel passanten', surface: 'Netto doeloppervlakte',
    emprise: 'Marktaandeel', imposee: p => 'opgelegd ' + p, calculee: p => 'berekend, max. ' + p, compK: 'Gevoeligheid voor concurrentie',
    rayon: 'Straal', weak: 'Concurrent vanaf', thresh: 'Sterke concurrent vanaf', minScore: 'Minimumscore',
    ecart: 'Afstand tussen openingen', ecartV: (m, route, km, res) => m + ' min rijden' + (route ? ', rijtijd' : ', ≈ ' + km + ' km') + (res ? ', open winkels inbegrepen' : ''),
    caMin: 'Minimale jaaromzet', aucun: 'geen'
  },
  sources: o => 'Zaken en gemeenten: OpenStreetMap' + (o.osm ? ', servercache herlezen op ' + o.osm : '') + ' · bevolking: raster van 1 km² van de volkstelling 2021 (Statbel, verspreid door Eurostat)'
    + ' · uitgave per huishouden, marktaandeel en oppervlakte: studie GeoConsulting (Halle, 28/08/2024)' + (o.google ? ' · scores: Google Places' : '') + (o.route ? ' · rijtijden: Valhalla (FOSSGIS) op OpenStreetMap' : '')
    + ' · werkelijke omzet van het netwerk: verkopen incl. btw uit de maandelijkse resultatenrekening van het panel, aangevuld met de kassaverkopen (brutobedragen na korting, incl. btw) voor de maanden zonder resultatenrekening, laatste twaalf afgesloten maanden.',
  h: {
    degager: 'Wat het plan kan opbrengen', ouOuvrir: 'Waar openen', attenteCarte: 'Kaart wordt samengesteld…',
    parProvince: 'Per provincie', dixMeilleures: 'De tien beste, alle provincies',
    methode: 'Methode.', hypotheses: 'De hypotheses bij het opmaken', sources: 'Bronnen.',
    ouOuvrirProv: p => p.code === 'BRU' ? 'Waar openen in het gewest' : 'Waar openen in de provincie',
    concurrence: 'De concurrentie', notes: 'De Google-scores, verdeeld door de twee drempels', chaines: 'De ketens',
    plusForts: 'De sterkste concurrenten', parArr: 'Per arrondissement',
    reseauProv: p => p.code === 'BRU' ? 'Het netwerk in het gewest' : 'Het netwerk in de provincie'
  },
  col: {
    num: '#', commune: 'Gemeente', arr: 'Arrondis&shy;sement', arrPlein: 'Arrondissement', score: 'Score', menages: 'Huis&shy;houdens',
    concurrents: 'Concur&shy;renten', concurrentsPlein: 'Concurrenten', chaines: 'Ketens', emprise: 'Markt&shy;aandeel',
    caTtc: 'Geschatte omzet incl. btw', ca: 'Geschatte omzet', m2: '€/m²', province: 'Provincie', ouvertures: 'Ope&shy;ningen',
    forts: 'Sterke', parPoint: 'Huishoudens per verkooppunt', reseau: 'Netwerk&shy;winkels', caOuv: 'Geschatte omzet openingen',
    communes: 'Gemeenten', noteMoy: 'Gem. score', enseigne: 'Keten', points: 'Verkooppunten', nom: 'Naam', note: 'Score',
    avis: 'Reviews', force: 'Kracht', magasin: 'Winkel', ville: 'Plaats', caReel: 'Werkelijke omzet incl. btw, 12 maanden', periode: 'Periode',
    commerces: 'Zaken', part: 'Aandeel'
  },
  fort: n => '(' + n + ' sterke)',
  nOuv: n => n + ' ' + pl(n, 'opening', 'openingen'),
  total: 'Totaal',
  aucuneZone: seuil => 'Geen zone buiten de uitsluitingsstralen in deze provincie' + (seuil ? ', boven score ' + seuil : '') + '.',
  pOuvRetenues: (n, d) => n + ' weerhouden ' + pl(n, 'opening', 'openingen') + (d ? ' · opgemaakt op ' + d : ''),
  pCaOuv: 'Geschatte jaaromzet van de openingen',
  tMenages: 'Huishoudens', tMenagesS: (pop, marche) => pop + ' inwoners · markt ' + marche,
  tConc: 'Concurrenten', tConcS: (f, c, moy) => f + ' sterke · ' + c + ' van een keten' + (moy ? ' · gemiddelde score ' + moy : ''),
  tDens: 'Huishoudens per verkooppunt', tDensS: (d, sel) => d + ' zaken per 10.000 inwoners' + (sel ? ' · ' + sel + ' in de hele selectie' : ''),
  tRes: 'Het netwerk', tResS: (n, du) => n + ' ' + pl(n, 'winkel', 'winkels') + ' · werkelijke omzet incl. btw' + (du ? ', ' + du : ''), tResVide: 'geen open winkel',
  tResVideS: 'het plan zou er een eerste vestiging zijn',
  notesNon: 'Zonder score', notesSous: w => 'Onder ' + w + ' ★ — tellen niet mee', notesComptes: (w, t) => 'Van ' + w + ' tot ' + t + ' ★ — meegeteld', notesForts: t => 'Vanaf ' + t + ' ★ — sterk',
  noteMoyenne: (m, n) => 'Gemiddelde score ' + m + ' op ' + n + ' ' + pl(n, 'beoordeelde zaak', 'beoordeelde zaken'),
  aucuneChaine: 'Geen keten gevonden in de selectie.',
  chaine: 'keten',
  aucunConc: 'Geen concurrent in de selectie.',
  aucunReseau: p => 'Geen netwerkwinkel in ' + (p.code === 'BRU' ? 'dit gewest' : 'deze provincie') + ': het plan zou er een eerste vestiging zijn.',
  periode: (du, au) => du + ' – ' + au,
  aucuneProv: 'Geen provincie aangevinkt: kies minstens één provincie in de lijst.',
  csvNom: 'uitbreidingsplan',
  csvEntete: ['nummer', 'provincie', 'rang_provincie', 'gemeente', 'arrondissement', 'score', 'huishoudens', 'concurrenten', 'sterke_concurrenten', 'ketens', 'marktaandeel_pct', 'jaaromzet_incl_btw', 'omzet_per_m2', 'lat', 'lng', 'dichtstbij', 'dichtstbij_km_vogelvlucht', 'dichtstbij_min_rijden'],
  csvTotal: 'TOTAAL'
};

/** Les textes et les formes d'une langue ; le français par défaut. */
export function planTextes(lang){
  const t = lang === 'nl' ? NL : FR;
  return Object.assign({}, t, { f: formes(t.lang) });
}
