<?php
declare(strict_types=1);

/**
 * Les questions de la checklist, génériques et bilingues (26/10/2026, Sam : « rendre les paramètres le plus générique
 * possible, question type de réponse, multi select… » puis « tout doit être possible en bilingue »).
 *
 * Une question (un point d'un module) porte :
 *  - `type` : la façon d'y répondre (VI_TYPES_REPONSE). Sans type, l'ancienne règle : `pct` → pourcentage, sinon
 *    OK / Écart / N/A. Le drapeau `pct` reste écrit (le planogramme et ses causes le lisent).
 *  - `libelle` (français, la langue de référence) et `libelle_nl` ; `aide` / `aide_nl` : une précision facultative.
 *  - `choix` (choix unique ou multiples) : [{code, fr, nl, ko}] — `ko` : cette réponse est un écart.
 *  - `min` / `max` / `unite` (nombre) : hors des bornes, c'est un écart.
 * Chaque réponse pose aussi l'état du point (ok, ko, na) : tout ce qui compte les points faits, les écarts, les
 * revisites et les points à travailler continue de lire `etat`, quel que soit le type. La valeur elle-même va dans
 * `reponse` (con_visite_point.reponse, JSON : le code choisi, la liste des codes, le nombre, le texte).
 *
 * Pur : ni base, ni réglage — bin/tests_suivi.php le vérifie tel quel.
 */

/** Les types de réponse, dans l'ordre du sélecteur des réglages. */
const VI_TYPES_REPONSE = [
    'etat' => ['fr' => 'OK / Écart / N/A', 'nl' => 'OK / Afwijking / N.v.t.'],
    'ouinon' => ['fr' => 'Oui / Non', 'nl' => 'Ja / Nee'],
    'note' => ['fr' => 'Note de 1 à 5', 'nl' => 'Score van 1 tot 5'],
    'pct' => ['fr' => 'Pourcentage', 'nl' => 'Percentage'],
    'nombre' => ['fr' => 'Nombre', 'nl' => 'Getal'],
    'choix' => ['fr' => 'Choix unique', 'nl' => 'Eén keuze'],
    'multi' => ['fr' => 'Choix multiples', 'nl' => 'Meerdere keuzes'],
    'texte' => ['fr' => 'Texte libre', 'nl' => 'Vrije tekst'],
];
const VI_CHOIX_MAX = 12;

/** Un code de choix : minuscules, chiffres et _, 24 caractères. */
function viCodeChoix(string $s): string
{
    $a = function_exists('iconv') ? (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) : $s;
    $c = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($a)), '_');
    return substr($c !== '' ? $c : 'c' . substr(md5($s), 0, 8), 0, 24);
}

/** Une question telle qu'elle s'enregistre, ou null (sans référence ou sans libellé). */
function viPointNormalise(mixed $p): ?array
{
    if (!is_array($p)) { return null; }
    $ref = (string) preg_replace('/[^\w-]/', '', (string) ($p['ref'] ?? ''));
    $lib = mb_substr(trim((string) ($p['libelle'] ?? '')), 0, 190);
    if ($ref === '' || $lib === '') { return null; }
    $type = (string) ($p['type'] ?? '');
    if (!isset(VI_TYPES_REPONSE[$type])) { $type = !empty($p['pct']) ? 'pct' : 'etat'; }
    $out = ['ref' => mb_substr($ref, 0, 60), 'libelle' => $lib, 'photo' => !empty($p['photo']), 'pct' => $type === 'pct', 'type' => $type];
    foreach (['libelle_nl' => 190, 'aide' => 240, 'aide_nl' => 240] as $k => $n) {
        $x = mb_substr(trim((string) ($p[$k] ?? '')), 0, $n);
        if ($x !== '') { $out[$k] = $x; }
    }
    if ($type === 'choix' || $type === 'multi') {
        $ch = []; $vus = [];
        foreach (is_array($p['choix'] ?? null) ? $p['choix'] : [] as $c) {
            if (!is_array($c)) { continue; }
            $fr = mb_substr(trim((string) ($c['fr'] ?? '')), 0, 80);
            if ($fr === '') { continue; }
            $code = (string) preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($c['code'] ?? '')));
            $code = substr($code !== '' ? $code : viCodeChoix($fr), 0, 24);
            if (isset($vus[$code])) { $code = substr($code, 0, 20) . '_' . count($ch); }
            $vus[$code] = true;
            $x = ['code' => $code, 'fr' => $fr];
            $nl = mb_substr(trim((string) ($c['nl'] ?? '')), 0, 80);
            if ($nl !== '') { $x['nl'] = $nl; }
            if (!empty($c['ko'])) { $x['ko'] = true; }
            $ch[] = $x;
            if (count($ch) >= VI_CHOIX_MAX) { break; }
        }
        $out['choix'] = $ch;
    }
    if ($type === 'nombre') {
        foreach (['min', 'max'] as $k) { if (isset($p[$k]) && $p[$k] !== '' && is_numeric($p[$k])) { $out[$k] = (float) $p[$k]; } }
        if (isset($out['min'], $out['max']) && $out['min'] > $out['max']) { [$out['min'], $out['max']] = [$out['max'], $out['min']]; }
        $u = mb_substr(trim((string) ($p['unite'] ?? '')), 0, 12);
        if ($u !== '') { $out['unite'] = $u; }
    }
    return $out;
}

/** Un module : son identifiant (déjà vérifié par l'appelant), ses noms, ses questions. */
function viModuleNormalise(array $m, string $id): array
{
    $out = ['id' => $id, 'nom' => mb_substr(trim((string) ($m['nom'] ?? $id)), 0, 60) ?: $id];
    $nl = mb_substr(trim((string) ($m['nom_nl'] ?? '')), 0, 60);
    if ($nl !== '') { $out['nom_nl'] = $nl; }
    $out['points'] = array_values(array_filter(array_map('viPointNormalise', is_array($m['points'] ?? null) ? $m['points'] : [])));
    return $out;
}

/** La réponse telle qu'elle s'enregistre (JSON), ou null : un code, une liste de codes, un nombre, un texte. */
function viReponseNormalise(mixed $r): ?string
{
    if (is_array($r)) {
        $l = array_values(array_unique(array_filter(array_map(static fn ($x) => substr((string) preg_replace('/[^a-z0-9_]/', '', strtolower((string) $x)), 0, 24), $r))));
        return $l ? json_encode(array_slice($l, 0, VI_CHOIX_MAX)) : null;
    }
    if (is_int($r) || is_float($r)) { return is_finite((float) $r) ? json_encode($r) : null; }
    if (is_string($r) && trim($r) !== '') { return json_encode(mb_substr(trim($r), 0, 1000), JSON_UNESCAPED_UNICODE); }
    return null;
}

/** La réponse lue, dans la langue demandée : « Sale, Rangé », « 12 kg », le texte. Vide si rien. */
function viReponseTexte(array $pt, mixed $r, string $l = 'fr'): string
{
    if ($r === null || $r === '' || $r === []) { return ''; }
    $type = (string) ($pt['type'] ?? '');
    if ($type === 'choix' || $type === 'multi') {
        $noms = [];
        foreach ((array) $r as $code) {
            $c = null;
            foreach ((array) ($pt['choix'] ?? []) as $x) { if (($x['code'] ?? null) === $code) { $c = $x; break; } }
            $noms[] = $c ? (string) (($l === 'nl' && !empty($c['nl'])) ? $c['nl'] : $c['fr']) : (string) $code;
        }
        return implode(', ', $noms);
    }
    if ($type === 'nombre' && is_numeric($r)) {
        $n = (float) $r;
        return str_replace('.', ',', (string) (floor($n) == $n ? (int) $n : round($n, 2))) . (!empty($pt['unite']) ? ' ' . $pt['unite'] : '');
    }
    return is_scalar($r) ? (string) $r : '';
}

/** Le libellé d'une question dans une langue : le néerlandais s'il est écrit, sinon le français. */
function viLibelle(array $pt, string $l = 'fr'): string
{
    return (string) ($l === 'nl' && !empty($pt['libelle_nl']) ? $pt['libelle_nl'] : ($pt['libelle'] ?? ''));
}

/**
 * Le néerlandais des checklists livrées (viChecklistDefaut, vcTypesDefaut), par module et par référence : [français,
 * néerlandais]. Il ne s'applique que si la question n'a pas son libellé néerlandais ET que son français est resté celui
 * livré — un libellé réécrit dans les réglages ne reçoit pas une traduction qui ne lui correspond plus.
 */
const VI_NL_MODULES = ['produit' => 'Product', 'hygiene' => 'Hygiëne', 'visuel' => 'Visueel', 'planogramme' => 'Planogram', 'assortiment' => 'Assortiment',
    'msp' => 'Mystery shopper', 'mise_place' => 'Mise-en-place', 'cuisson' => 'Bakken', 'recuissons' => 'Nabaksels', 'invendus' => 'Onverkochte producten',
    'qualite' => 'Kwaliteit', 'nettoyage' => 'Reiniging', 'bilan' => 'Balans', 'prepa' => 'Voorbereiding', 'equipe' => 'Team', 'lancement' => 'Lancering'];
const VI_NL_POINTS = [
    'produit:pain' => ['Pain blanc — tête, croûte, cuisson', 'Wit brood — vorm, korst, bakken'],
    'produit:viennoiserie' => ['Croissants — feuilletage, température', 'Croissants — gelaagdheid, temperatuur'],
    'produit:sables' => ['Sablés et biscuits — fraîcheur', 'Zandkoekjes en koekjes — versheid'],
    'produit:tartes' => ['Tartes — aspect, fraîcheur', 'Taarten — uitzicht, versheid'],
    'produit:sandwiches' => ['Sandwiches — garniture, fraîcheur', 'Broodjes — beleg, versheid'],
    'produit:gamme' => ['Gamme d’ouverture complète', 'Volledig openingsassortiment'],
    'produit:recettes' => ['Recettes et fiches techniques disponibles', 'Recepten en technische fiches beschikbaar'],
    'produit:cuisson' => ['Premières cuissons validées', 'Eerste baksels goedgekeurd'],
    'produit:prix' => ['Prix et étiquettes posés', 'Prijzen en etiketten aangebracht'],
    'produit:obligatoires' => ['Références obligatoires présentes', 'Verplichte referenties aanwezig'],
    'hygiene:sol' => ['Sol zone production propre', 'Vloer productiezone proper'],
    'hygiene:frigo' => ['Frigo vitrine ≤ 4 °C (photo du thermomètre)', 'Koelvitrine ≤ 4 °C (foto van de thermometer)'],
    'hygiene:arriere' => ['Zone arrière et réserve', 'Achterruimte en voorraad'],
    'hygiene:sanitaires' => ['Sanitaires, lave-mains, savon', 'Sanitair, handwasbak, zeep'],
    'hygiene:tenues' => ['Tenues, cheveux, gants', 'Kledij, haar, handschoenen'],
    'visuel:facade' => ['Façade, enseigne, vitrine', 'Gevel, uithangbord, etalage'],
    'visuel:interieur' => ['Intérieur, propreté visible, éclairage', 'Interieur, zichtbare netheid, verlichting'],
    'visuel:prix' => ['Affichage des prix complet', 'Prijsaanduiding volledig'],
    'visuel:plv' => ['PLV et promotions du moment en place', 'POS-materiaal en lopende promoties aanwezig'],
    'planogramme:comptoir_pains' => ['Comptoir pains', 'Toonbank brood'],
    'planogramme:comptoir_viennoiserie' => ['Comptoir viennoiserie', 'Toonbank viennoiserie'],
    'planogramme:comptoir_tartes' => ['Comptoir tartes et sablés', 'Toonbank taarten en zandkoekjes'],
    'assortiment:obligatoires' => ['Références obligatoires toutes présentes en rayon', 'Alle verplichte referenties aanwezig in het rek'],
    'assortiment:ruptures' => ['Ruptures du jour — cause connue, parade en place', 'Tekorten van de dag — oorzaak gekend, oplossing voorzien'],
    'assortiment:etiquettes' => ['Étiquettes et prix conformes au référentiel', 'Etiketten en prijzen conform de referentielijst'],
    'msp:msp_hygiene' => ['Hygiène : ce que le rapport a relevé est corrigé', 'Hygiëne: wat het rapport vaststelde is verholpen'],
    'msp:msp_accueil' => ['Accueil : conforme au rapport', 'Onthaal: conform het rapport'],
    'msp:msp_ambiance' => ['Ambiance : bruit, musique, propreté visible', 'Sfeer: geluid, muziek, zichtbare netheid'],
    'msp:accueil' => ['Accueil : bonjour, sourire, disponibilité', 'Onthaal: goeiedag, glimlach, beschikbaarheid'],
    'msp:attente' => ['Temps d’attente et gestion de la file', 'Wachttijd en beheer van de rij'],
    'msp:conseil' => ['Conseil et connaissance des produits', 'Advies en productkennis'],
    'msp:additionnelle' => ['Vente additionnelle proposée', 'Bijverkoop voorgesteld'],
    'msp:proprete' => ['Propreté visible depuis la file', 'Netheid zichtbaar vanuit de rij'],
    'msp:presentation' => ['Produits présentés et étiquetés', 'Producten gepresenteerd en geëtiketteerd'],
    'msp:encaissement' => ['Encaissement : ticket, monnaie, carte', 'Afrekenen: kasticket, wisselgeld, kaart'],
    'msp:aurevoir' => ['Au revoir et invitation à revenir', 'Afscheid en uitnodiging om terug te komen'],
    'mise_place:plan_jour' => ['Plan de production du jour affiché et suivi', 'Productieplan van de dag uitgehangen en gevolgd'],
    'mise_place:pesees' => ['Pesées et recettes respectées', 'Afwegingen en recepten gerespecteerd'],
    'mise_place:levee' => ['Levée et façonnage : temps et température', 'Rijzen en vormen: tijd en temperatuur'],
    'mise_place:stock_mat' => ['Stock matières : rotation, DLC', 'Grondstoffenvoorraad: rotatie, houdbaarheidsdatum'],
    'cuisson:pain_cuit' => ['Pain : couleur, croûte, cuisson à cœur', 'Brood: kleur, korst, doorbakken'],
    'cuisson:vien_cuit' => ['Viennoiserie : feuilletage, dorure', 'Viennoiserie: gelaagdheid, goudbruine kleur'],
    'cuisson:fours' => ['Fours : températures et programmes', 'Ovens: temperaturen en programma’s'],
    'cuisson:sortie' => ['Sortie de four : refroidissement, grilles', 'Uit de oven: afkoelen, roosters'],
    'recuissons:recuisson' => ['Recuisson de l’après-midi faite, quantités notées', 'Namiddagbaksel gedaan, hoeveelheden genoteerd'],
    'recuissons:vitrine16' => ['Vitrine pleine à 16 h', 'Vitrine vol om 16 u'],
    'recuissons:ecart' => ['Écart production / ventes de la semaine commenté', 'Verschil productie / verkoop van de week besproken'],
    'invendus:comptage' => ['Comptage du soir fait, avec motif', 'Avondtelling gedaan, met reden'],
    'invendus:taux' => ['Invendus sous 4 % du CA de la semaine', 'Onverkochte producten onder 4 % van de weekomzet'],
    'invendus:causes' => ['Causes des pertes nommées, parade en place', 'Oorzaken van verlies benoemd, oplossing voorzien'],
    'qualite:recette' => ['Produits du jour conformes à la recette et au visuel', 'Producten van de dag conform recept en visual'],
    'qualite:dlc' => ['DLC et étiquetage des préparations', 'Houdbaarheidsdatum en etikettering van de bereidingen'],
    'qualite:froid' => ['Chaîne du froid : relevés du jour tenus', 'Koudeketen: metingen van de dag bijgehouden'],
    'qualite:allergenes' => ['Allergènes affichés et à jour', 'Allergenen uitgehangen en up-to-date'],
    'nettoyage:plan_net' => ['Plan de nettoyage affiché et coché', 'Schoonmaakplan uitgehangen en afgevinkt'],
    'nettoyage:materiel' => ['Matériel et plans de travail propres', 'Materiaal en werkbladen proper'],
    'nettoyage:dechets' => ['Déchets : tri, local, fréquence', 'Afval: sortering, lokaal, frequentie'],
    'bilan:scoring' => ['Scoring du trimestre présenté et commenté', 'Scoring van het kwartaal voorgesteld en besproken'],
    'bilan:google' => ['Note Google : avis, réponses, cible', 'Google-score: reviews, antwoorden, doel'],
    'bilan:taches' => ['Tâches du panel : obligatoires manquées, photos', 'Taken van het panel: gemiste verplichte taken, foto’s'],
    'bilan:msp_bilan' => ['Client mystère : points relevés, corrigés', 'Mystery shopper: vastgestelde en verholpen punten'],
    'bilan:budget' => ['Budget : CA face au budget, mois par mois', 'Budget: omzet t.o.v. budget, maand per maand'],
    'bilan:pnl' => ['P&L : food cost, main-d’œuvre, résultat', 'P&L: food cost, loonkost, resultaat'],
    'bilan:invendus' => ['Invendus : taux et causes', 'Onverkochte producten: percentage en oorzaken'],
    'bilan:plan_dev' => ['Plan de développement : jalons du trimestre', 'Ontwikkelingsplan: mijlpalen van het kwartaal'],
    'bilan:objectifs' => ['Objectifs du trimestre suivant posés', 'Doelstellingen voor het volgende kwartaal vastgelegd'],
    'bilan:engagement' => ['Engagement du franchisé noté', 'Engagement van de franchisenemer genoteerd'],
    'prepa:travaux' => ['Travaux et agencement conformes au plan', 'Werken en inrichting conform het plan'],
    'prepa:materiel' => ['Matériel livré, installé, testé', 'Materiaal geleverd, geïnstalleerd, getest'],
    'prepa:caisse' => ['Caisse, panel et dashboard reliés', 'Kassa, panel en dashboard gekoppeld'],
    'prepa:stock' => ['Stock de départ et matières livrés', 'Startvoorraad en grondstoffen geleverd'],
    'prepa:plano_init' => ['Planogramme monté', 'Planogram opgebouwd'],
    'prepa:enseigne' => ['Enseigne, façade, vitrine', 'Uithangbord, gevel, etalage'],
    'equipe:effectif' => ['Effectif recruté et planning posé', 'Personeel aangeworven en planning opgemaakt'],
    'equipe:formation' => ['Formation production et vente faite', 'Opleiding productie en verkoop gegeven'],
    'equipe:tenues' => ['Tenues et hygiène expliquées', 'Kledij en hygiëne uitgelegd'],
    'equipe:app' => ['App worker et tâches du panel en main', 'Worker-app en taken van het panel onder de knie'],
    'lancement:com' => ['Communication d’ouverture faite', 'Communicatie rond de opening gedaan'],
    'lancement:google_fiche' => ['Fiche Google ouverte et renseignée', 'Google-fiche geopend en ingevuld'],
    'lancement:j7' => ['Point à J+7 : ventes, équipe, retours clients', 'Opvolging op D+7: verkoop, team, klantenfeedback'],
    'lancement:j30' => ['Point à J+30 : food cost, invendus, planning', 'Opvolging op D+30: food cost, onverkochte producten, planning'],
];

/** Une checklist complétée de son néerlandais livré, là où il manque et où le français est celui d'origine. */
function viAvecNl(array $cl): array
{
    foreach ($cl as &$m) {
        if (!is_array($m)) { continue; }
        $id = (string) ($m['id'] ?? '');
        if (empty($m['nom_nl']) && isset(VI_NL_MODULES[$id])) { $m['nom_nl'] = VI_NL_MODULES[$id]; }
        if (!isset($m['points']) || !is_array($m['points'])) { continue; }
        // Par référence sur le tableau lui-même : « $m['points'] ?? [] » en serait une copie, perdue.
        foreach ($m['points'] as &$p) {
            $d = VI_NL_POINTS[$id . ':' . ($p['ref'] ?? '')] ?? null;
            if ($d !== null && empty($p['libelle_nl']) && trim((string) ($p['libelle'] ?? '')) === $d[0]) { $p['libelle_nl'] = $d[1]; }
        }
        unset($p);
    }
    unset($m);
    return $cl;
}
