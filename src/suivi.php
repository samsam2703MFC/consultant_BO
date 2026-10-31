<?php
declare(strict_types=1);

/**
 * Suivi des franchisés (24/10/2026) — le circuit d'une visite, de l'ordinateur à
 * la tablette et retour, et ce que le franchisé en reçoit.
 *
 *   1. PRÉPARER (ordinateur) : le dossier de visite s'assemble tout seul — santé
 *      du magasin, points ouverts, tâches et photos, scoring, client mystère et
 *      comptoir, dernière météo (interne). Le bureau coche ce qui part, ajoute
 *      ses points (ils ouvrent la checklist de la tablette) et l'envoie sur la
 *      tablette. Une visite ANNONCÉE reçoit son ordre du jour à J−n par
 *      courriel ; une visite INOPINÉE ne prévient personne.
 *   2. VISITER (tablette, visites/) : la checklist du type, les points du
 *      bureau, la review, les points proposés, la météo, la signature. Terminer
 *      ne notifie plus le franchisé : le brouillon du rapport part au bureau.
 *   3. ADAPTER ET ENVOYER (ordinateur) : le brouillon se relit, les points et
 *      les photos se choisissent (ce qui n'est pas coché devient interne), le
 *      rapport part par courriel, PDF joint, et sur la page du franchisé.
 *   4. LE FRANCHISÉ (suivi/?shop=&jeton=) lit ses rapports et envoie la photo de
 *      chaque correction ; le bureau valide ou renvoie.
 *
 * « Qui fait quoi » (réglage `suiviRegles`) : par magasin, qui prépare, qui
 * adapte, qui valide l'envoi, qui valide les corrections, qui est en copie,
 * l'adresse et la langue du franchisé ; les droits de chacun ; les délais ; ce
 * que voit le franchisé. Les modèles de courriel (`suiviModeles`) existent en
 * français, néerlandais, anglais et allemand : annonce, visite déplacée,
 * annulée, rapport, relance, lien de la page.
 *
 * Le client mystère n'a plus qu'un encodage : `con_scoring_msp`, un rapport par
 * passage rangé dans son trimestre, lu par le scoring, le feu, la tablette et la
 * page du franchisé.
 *
 * Colonnes ajoutées à `con_visite` : rapport_statut (brouillon | envoye),
 * rapport, rapport_envoye_le, rapport_envoye_par, rapport_lu_le,
 * rapport_relance_le, prep, prep_envoyee_le, odj_envoye_le, annoncee ; à
 * `con_scoring_msp` : passage_le, montrer, suites.
 */

const SU_SCHEMA = 2;
const SU_LANGUES = ['fr' => 'Français', 'nl' => 'Nederlands', 'en' => 'English', 'de' => 'Deutsch'];
const SU_BLOCS = ['sante' => 'Santé du magasin', 'points' => 'Points ouverts', 'taches' => 'Tâches et contrôles photo',
    'scoring' => 'Scoring du trimestre', 'msp' => 'Client mystère et comptoir', 'meteo' => 'Dernière météo du franchisé'];
const SU_DROITS = ['preparer', 'visiter', 'adapter', 'envoyer', 'valider', 'regler'];
const SU_MODELES = ['annonce', 'deplacee', 'annulee', 'rapport', 'relance', 'lien'];
const SU_RUBRIQUES_MSP = ['Accueil', 'Produits', 'Hygiène', 'Ambiance'];

/* --- schéma ------------------------------------------------------------------ */

function ensureSuivi(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    ensureVisites();
    if (function_exists('ensureVisitesCadre')) { ensureVisitesCadre(); }
    if (function_exists('ensureScoring')) { ensureScoring(); }
    if ((int) setting('suiviSchema', 0) >= SU_SCHEMA) { return; }
    foreach (['rapport_statut VARCHAR(12) NULL', 'rapport MEDIUMTEXT NULL', 'rapport_envoye_le DATETIME NULL', 'rapport_envoye_par VARCHAR(120) NULL',
        'rapport_lu_le DATETIME NULL', 'rapport_relance_le DATETIME NULL', 'prep MEDIUMTEXT NULL', 'prep_envoyee_le DATETIME NULL',
        'odj_envoye_le DATETIME NULL', 'annoncee TINYINT NULL', 'budget_ca TEXT NULL'] as $c) { suColonne('con_visite', $c); }
    foreach (['passage_le DATE NULL', 'montrer TINYINT NOT NULL DEFAULT 1', 'suites TEXT NULL'] as $c) { suColonne('con_scoring_msp', $c); }
    viSettingSet('suiviSchema', SU_SCHEMA);
}

function suColonne(string $table, string $def): void
{
    $nom = (string) strtok($def, ' ');
    try { if (Db::row('SHOW COLUMNS FROM `' . $table . '` LIKE \'' . $nom . '\'') !== null) { return; } } catch (Throwable $e) { /* pas MySQL : on tente l'ajout */ }
    try { Db::exec('ALTER TABLE `' . $table . '` ADD COLUMN ' . $def); } catch (Throwable $e) { /* déjà là */ }
}

function suJson(mixed $s): array
{
    if (is_array($s)) { return $s; }
    $d = is_string($s) && $s !== '' ? json_decode($s, true) : null;
    return is_array($d) ? $d : [];
}

function suCourt(string $shop): string { return viMagasins()[$shop]['court'] ?? $shop; }

/* --- les types : annoncé ou inopiné ------------------------------------------ */

function suTypesParCode(bool $frais = false): array
{
    static $m = null;
    if ($m === null || $frais) { $m = []; foreach (function_exists('vcTypes') ? vcTypes() : [] as $t) { $m[$t['code']] = $t; } }
    return $m;
}

/** Une visite annoncée prévient le franchisé (ordre du jour, déplacement, annulation) ; une inopinée, personne. */
function suAnnoncee(array $v): bool
{
    if (array_key_exists('annoncee', $v) && $v['annoncee'] !== null && $v['annoncee'] !== '') { return (bool) (int) $v['annoncee']; }
    $t = suTypesParCode()[(string) (($v['type_code'] ?? $v['type'] ?? '') ?: 'reguliere')] ?? null;
    return $t === null || !array_key_exists('annoncee', $t) || (bool) $t['annoncee'];
}

/* --- qui fait quoi ------------------------------------------------------------ */

function suReglesDefaut(): array
{
    return [
        'magasins' => [],
        'droits' => [],
        'admins' => [],
        'delais' => ['prepJours' => 2, 'odjAuto' => true, 'odjJours' => 2, 'rapportHeures' => 48, 'rapportTard' => 'rappel',
            'p0' => 2, 'p1' => 7, 'p2' => 14, 'validationHeures' => 24, 'relanceLuJours' => 3],
        'voit' => ['rapports' => true, 'points' => true, 'prochaine' => true, 'msp' => true, 'scoring' => true, 'journalier' => true, 'journal' => true],
    ];
}

/** Les règles telles qu'elles s'appliquent : le réglage sur les défauts. L'escalade reste celle des seuils des visites. */
function suRegles(): array
{
    $d = suReglesDefaut();
    $s = setting('suiviRegles', []);
    $s = is_array($s) ? $s : [];
    $out = $d;
    $out['magasins'] = is_array($s['magasins'] ?? null) ? $s['magasins'] : [];
    $out['droits'] = is_array($s['droits'] ?? null) ? $s['droits'] : [];
    $out['admins'] = array_values(array_filter(is_array($s['admins'] ?? null) ? $s['admins'] : [], fn ($a) => is_array($a) && (string) ($a['id'] ?? '') !== ''));
    foreach ($d['delais'] as $k => $v) {
        if (!is_array($s['delais'] ?? null) || !array_key_exists($k, $s['delais'])) { continue; }
        $x = $s['delais'][$k];
        $out['delais'][$k] = is_bool($v) ? (bool) $x : (is_int($v) ? max(0, (int) $x) : (string) $x);
    }
    if (!in_array($out['delais']['rapportTard'], ['rappel', 'tel_quel'], true)) { $out['delais']['rapportTard'] = 'rappel'; }
    foreach ($d['voit'] as $k => $v) { if (is_array($s['voit'] ?? null) && array_key_exists($k, $s['voit'])) { $out['voit'][$k] = (bool) $s['voit'][$k]; } }
    $out['delais']['escaladeJours'] = (int) viSeuils()['escaladeJours'];
    return $out;
}

/** La ligne d'un magasin : '' pour « le responsable », 'visite' pour « le consultant de la visite ». */
function suMagasinRegle(string $shop, ?array $regles = null): array
{
    $regles = $regles ?? suRegles();
    $m = is_array($regles['magasins'][$shop] ?? null) ? $regles['magasins'][$shop] : [];
    $resp = ''; $respNom = '';
    foreach (function_exists('vcCadreDe') ? vcCadreDe($shop) : [] as $l) {
        if ($l['consultant'] !== '') { $resp = $l['consultant']; $respNom = $l['consultantNom'] ?: viConsultantNom($l['consultant']); break; }
    }
    $langue = in_array($m['langue'] ?? '', array_keys(SU_LANGUES), true) ? (string) $m['langue'] : '';
    return [
        'prepare' => (string) ($m['prepare'] ?? ''),
        'adapte' => (string) ($m['adapte'] ?? 'visite'),
        'envoie' => (string) ($m['envoie'] ?? 'visite'),
        'corrections' => (string) ($m['corrections'] ?? 'admin'),
        'copie' => array_values(array_filter(array_map('strval', is_array($m['copie'] ?? null) ? $m['copie'] : []))),
        'email' => (string) ($m['email'] ?? ''),
        'langue' => $langue,
        'responsable' => $resp, 'responsableNom' => $respNom,
    ];
}

function suPersonneNom(string $id): string
{
    if ($id === '') { return ''; }
    foreach (suRegles()['admins'] as $a) { if ((string) $a['id'] === $id) { return (string) ($a['nom'] ?? $id); } }
    return viConsultantNom($id);
}

function suPersonneEmail(string $id): ?string
{
    if ($id === '') { return null; }
    foreach (suRegles()['admins'] as $a) { if ((string) $a['id'] === $id) { return filter_var($a['email'] ?? '', FILTER_VALIDATE_EMAIL) ? (string) $a['email'] : null; } }
    foreach (viConsultants() as $c) { if ($c['id'] === $id) { return filter_var($c['email'] ?? '', FILTER_VALIDATE_EMAIL) ? (string) $c['email'] : null; } }
    return null;
}

/** Qui fait quoi pour une visite (ligne brute de con_visite). */
function suRoles(array $v): array
{
    $shop = (string) $v['shop_id']; $m = suMagasinRegle($shop);
    $cons = (string) $v['consultant_id'];
    $res = static fn (string $x): string => $x === 'visite' ? $cons : ($x === '' || $x === 'responsable' ? ($m['responsable'] ?: $cons) : $x);
    $prep = $m['prepare'] !== '' ? $m['prepare'] : ($m['responsable'] ?: $cons);
    $adapte = $res($m['adapte']); $envoie = $res($m['envoie']); $corr = $m['corrections'];
    return ['prepare' => $prep, 'prepareNom' => suPersonneNom($prep), 'adapte' => $adapte, 'adapteNom' => suPersonneNom($adapte),
        'envoie' => $envoie, 'envoieNom' => suPersonneNom($envoie), 'corrections' => $corr,
        'correctionsNom' => $corr === 'admin' ? 'l’admin réseau' : ($corr === 'responsable' ? ($m['responsableNom'] ?: 'le responsable') : suPersonneNom($corr)),
        'copie' => $m['copie'], 'copieNoms' => array_values(array_filter(array_map('suPersonneNom', $m['copie']))),
        'responsable' => $m['responsable'], 'responsableNom' => $m['responsableNom']];
}

function suDroitsDefaut(string $genre): array
{
    return $genre === 'admin'
        ? ['preparer' => true, 'visiter' => false, 'adapter' => false, 'envoyer' => true, 'valider' => true, 'regler' => true]
        : ['preparer' => true, 'visiter' => true, 'adapter' => true, 'envoyer' => true, 'valider' => true, 'regler' => false];
}

/** Les personnes du suivi : les consultants du panel, puis les admins réglés ici, avec leurs droits. */
function suPersonnes(?array $regles = null): array
{
    $regles = $regles ?? suRegles();
    $out = [];
    foreach (viConsultants() as $c) {
        $p = function_exists('vcProfilDe') ? vcProfilDe($c['id']) : '';
        $out[] = ['id' => $c['id'], 'nom' => $c['nom'], 'email' => $c['email'] ?? null, 'genre' => 'consultant', 'profilNom' => $p !== '' ? vcProfilNom($p) : 'Consultant',
            'droits' => array_merge(suDroitsDefaut('consultant'), array_intersect_key((array) ($regles['droits'][$c['id']] ?? []), array_flip(SU_DROITS)))];
    }
    foreach ($regles['admins'] as $a) {
        $id = (string) $a['id'];
        $out[] = ['id' => $id, 'nom' => (string) ($a['nom'] ?? $id), 'email' => $a['email'] ?? null, 'genre' => 'admin', 'profilNom' => (string) (($a['role'] ?? '') ?: 'Admin réseau'),
            'droits' => array_merge(suDroitsDefaut('admin'), array_intersect_key((array) ($regles['droits'][$id] ?? []), array_flip(SU_DROITS)))];
    }
    foreach ($out as &$p) { foreach ($p['droits'] as $k => $v) { $p['droits'][$k] = (bool) $v; } }
    unset($p);
    return $out;
}

/* --- langue et adresse du franchisé ------------------------------------------- */

/** La langue du franchisé si rien n'est réglé : la région du magasin (Flandre en néerlandais, Cantons de l'Est en allemand). */
function suLangueDefaut(string $shop): string
{
    $m = viMagasins()[$shop] ?? null;
    $txt = mb_strtolower(($m['ville'] ?? '') . ' ' . ($m['nom'] ?? ''));
    if (preg_match('/eupen|ostbelgien|sankt[- ]vith|kelmis|b[uü]tgenbach|cantons de l.est/u', $txt)) { return 'de'; }
    if (preg_match('/vlaa|flandre|flamand|antwerp|limburg|limbourg|\bgent\b|\bgand\b|brugge|leuven|mechelen|hasselt|\bhalle\b|aalst|kortrijk/u', $txt)) { return 'nl'; }
    return 'fr';
}

function suLangue(string $shop, ?array $regle = null): string
{
    $regle = $regle ?? suMagasinRegle($shop);
    return $regle['langue'] !== '' ? $regle['langue'] : suLangueDefaut($shop);
}

/** L'adresse du franchisé : celle de « Qui fait quoi », sinon celle du carnet du reporting. */
function suAdresse(string $shop, ?array $regle = null): array
{
    $regle = $regle ?? suMagasinRegle($shop);
    if (filter_var($regle['email'], FILTER_VALIDATE_EMAIL)) { return ['email' => $regle['email'], 'source' => 'Qui fait quoi']; }
    $nom = viMagasins()[$shop]['nom'] ?? null;
    if ($nom !== null) {
        try {
            foreach (Db::rows('SELECT nom, dest_par_magasin FROM ceo_rapport WHERE dest_par_magasin IS NOT NULL AND dest_par_magasin <> \'\' ORDER BY id') as $r) {
                $carnet = suJson($r['dest_par_magasin']);
                foreach ((array) ($carnet[$nom] ?? []) as $d) {
                    if (filter_var($d, FILTER_VALIDATE_EMAIL)) { return ['email' => (string) $d, 'source' => 'carnet du reporting (' . $r['nom'] . ')']; }
                }
            }
        } catch (Throwable $e) { /* pas de reporting ici */ }
    }
    return ['email' => null, 'source' => null];
}

/* --- la page du franchisé : un jeton par magasin ------------------------------- */

function suJeton(string $shop): string
{
    return substr(hash_hmac('sha256', 'suivi:' . $shop, (string) setting('visitesJeton', '')), 0, 24);
}

function suJetonOk(string $shop, string $jeton): bool
{
    return $shop !== '' && $jeton !== '' && isset(viMagasins()[$shop]) && hash_equals(suJeton($shop), $jeton);
}

function suLien(string $shop): string
{
    return rtrim(rapBaseUrl(), '/') . '/suivi/?shop=' . rawurlencode($shop) . '&jeton=' . suJeton($shop);
}

/* --- les mots des courriels et du rapport, en quatre langues -------------------- */

const SU_JOURS = ['fr' => ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'],
    'nl' => ['zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag'],
    'en' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
    'de' => ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag']];
const SU_MOIS_L = ['fr' => ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'],
    'nl' => ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'],
    'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
    'de' => ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember']];

function suDateLongue(string $date, string $l): string
{
    $t = strtotime(substr($date, 0, 10) . ' 12:00:00');
    if ($t === false) { return $date; }
    $l = isset(SU_JOURS[$l]) ? $l : 'fr';
    $j = SU_JOURS[$l][(int) date('w', $t)]; $m = SU_MOIS_L[$l][(int) date('n', $t) - 1]; $d = (int) date('j', $t);
    return $l === 'de' ? $j . ', ' . $d . '. ' . $m : $j . ' ' . $d . ' ' . $m;
}

/** Le nom d'un type dans la langue du franchisé : les types livrés sont traduits, un type créé ici garde son nom. */
function suTypeNom(string $code, string $l): string
{
    $dict = [
        'reguliere' => ['nl' => 'Regulier bezoek', 'en' => 'Regular visit', 'de' => 'Regulärer Besuch'],
        'production' => ['nl' => 'Productiebezoek', 'en' => 'Production visit', 'de' => 'Produktionsbesuch'],
        'hygiene' => ['nl' => 'Hygiëne en kwaliteit', 'en' => 'Hygiene and quality', 'de' => 'Hygiene und Qualität'],
        'msp' => ['nl' => 'Mystery shopper', 'en' => 'Mystery shopper', 'de' => 'Testkauf'],
        'bilan' => ['nl' => 'Kwartaalbespreking', 'en' => 'Quarterly review', 'de' => 'Quartalsbilanz'],
        'ouverture' => ['nl' => 'Opening', 'en' => 'Opening', 'de' => 'Eröffnung'],
        'suivi' => ['nl' => 'Opvolging actieplan', 'en' => 'Action plan follow-up', 'de' => 'Nachverfolgung des Aktionsplans'],
        'revisite' => ['nl' => 'Hercontrole', 'en' => 'Follow-up visit', 'de' => 'Nachkontrolle'],
    ];
    if ($l !== 'fr' && isset($dict[$code][$l])) { return $dict[$code][$l]; }
    $t = suTypesParCode()[$code] ?? null;
    return $t !== null ? (string) $t['nom'] : ($code === 'reguliere' ? 'Régulière' : $code);
}

function suL(string $k, string $l): string
{
    static $d = [
        'vide_sujets' => ['fr' => 'La visite fait le point sur l’ensemble du magasin.', 'nl' => 'Het bezoek overloopt de hele winkel.', 'en' => 'The visit covers the whole shop.', 'de' => 'Der Besuch betrachtet den ganzen Laden.'],
        'vide_points' => ['fr' => 'Aucun point à travailler.', 'nl' => 'Geen werkpunten.', 'en' => 'No points to work on.', 'de' => 'Keine Arbeitspunkte.'],
        'titre' => ['fr' => 'Rapport de visite', 'nl' => 'Bezoekverslag', 'en' => 'Visit report', 'de' => 'Besuchsbericht'],
        'du' => ['fr' => 'Visite du', 'nl' => 'Bezoek van', 'en' => 'Visit of', 'de' => 'Besuch vom'],
        'avec' => ['fr' => 'avec', 'nl' => 'met', 'en' => 'with', 'de' => 'mit'],
        'conformes' => ['fr' => 'points conformes', 'nl' => 'punten in orde', 'en' => 'points compliant', 'de' => 'Punkte in Ordnung'],
        'score' => ['fr' => 'Score de la visite', 'nl' => 'Score van het bezoek', 'en' => 'Visit score', 'de' => 'Besuchsbewertung'],
        'score_prec' => ['fr' => 'visite précédente', 'nl' => 'vorig bezoek', 'en' => 'previous visit', 'de' => 'letzter Besuch'],
        'score_premier' => ['fr' => 'première visite notée', 'nl' => 'eerste gescoorde bezoek', 'en' => 'first scored visit', 'de' => 'erster bewerteter Besuch'],
        'score_regle' => ['fr' => 'Chaque point conforme compte selon son poids. Ne comptent pas : les réponses N/A, le texte libre, les questions hors score.',
            'nl' => 'Elk punt in orde telt volgens zijn gewicht. Tellen niet mee: N.v.t.-antwoorden, vrije tekst, vragen buiten de score.',
            'en' => 'Each compliant point counts according to its weight. Not counted: N/A answers, free text, questions outside the score.',
            'de' => 'Jeder Punkt in Ordnung zählt nach seinem Gewicht. Nicht gezählt: N/A-Antworten, Freitext, Fragen ohne Bewertung.'],
        'positif' => ['fr' => 'Ce qui va bien', 'nl' => 'Wat goed gaat', 'en' => 'What is going well', 'de' => 'Was gut läuft'],
        'vu' => ['fr' => 'Vu sur place', 'nl' => 'Ter plaatse gezien', 'en' => 'Seen on site', 'de' => 'Vor Ort gesehen'],
        'probleme' => ['fr' => 'Le vrai problème', 'nl' => 'Het echte probleem', 'en' => 'The real issue', 'de' => 'Das eigentliche Problem'],
        'reco' => ['fr' => 'Recommandation', 'nl' => 'Aanbeveling', 'en' => 'Recommendation', 'de' => 'Empfehlung'],
        'points' => ['fr' => 'Points à travailler', 'nl' => 'Werkpunten', 'en' => 'Points to work on', 'de' => 'Arbeitspunkte'],
        'budget' => ['fr' => 'Le chiffre face au budget', 'nl' => 'De omzet tegenover het budget', 'en' => 'Sales against budget', 'de' => 'Umsatz gegenüber dem Budget'],
        'b_arrete' => ['fr' => 'arrêté au', 'nl' => 'stand op', 'en' => 'as of', 'de' => 'Stand'],
        'b_pourquoi' => ['fr' => 'Pourquoi', 'nl' => 'Waarom', 'en' => 'Why', 'de' => 'Warum'],
        'b_pistes' => ['fr' => 'Trois pistes de développement', 'nl' => 'Drie groeipistes', 'en' => 'Three growth leads', 'de' => 'Drei Entwicklungsansätze'],
        'b_dici' => ['fr' => 'd’ici le', 'nl' => 'tegen', 'en' => 'by', 'de' => 'bis'],
        'p_semaine' => ['fr' => 'Semaine', 'nl' => 'Week', 'en' => 'Week', 'de' => 'Woche'],
        'p_mois' => ['fr' => 'Mois', 'nl' => 'Maand', 'en' => 'Month', 'de' => 'Monat'],
        'p_trimestre' => ['fr' => 'Trimestre', 'nl' => 'Kwartaal', 'en' => 'Quarter', 'de' => 'Quartal'],
        'p_annee' => ['fr' => 'Année', 'nl' => 'Jaar', 'en' => 'Year', 'de' => 'Jahr'],
        'echeance' => ['fr' => 'échéance', 'nl' => 'deadline', 'en' => 'due', 'de' => 'Frist'],
        'photos' => ['fr' => 'Photos', 'nl' => 'Foto’s', 'en' => 'Photos', 'de' => 'Fotos'],
        'equipe' => ['fr' => 'Énergie de l’équipe', 'nl' => 'Energie van het team', 'en' => 'Team energy', 'de' => 'Energie des Teams'],
        'standards' => ['fr' => 'Standards appliqués', 'nl' => 'Normen toegepast', 'en' => 'Standards applied', 'de' => 'Standards eingehalten'],
        'raccourcis' => ['fr' => 'Quelques raccourcis', 'nl' => 'Enkele kortere wegen', 'en' => 'A few shortcuts', 'de' => 'Einige Abkürzungen'],
        'ecarts' => ['fr' => 'Écarts fréquents', 'nl' => 'Vaak afwijkingen', 'en' => 'Frequent deviations', 'de' => 'Häufige Abweichungen'],
        'satisfaits' => ['fr' => 'Clients satisfaits', 'nl' => 'Tevreden klanten', 'en' => 'Satisfied customers', 'de' => 'Zufriedene Kunden'],
        'mitiges' => ['fr' => 'Clients mitigés', 'nl' => 'Klanten met gemengde gevoelens', 'en' => 'Mixed customer reactions', 'de' => 'Gemischte Kundenreaktionen'],
        'insatisfaits' => ['fr' => 'Clients insatisfaits', 'nl' => 'Ontevreden klanten', 'en' => 'Dissatisfied customers', 'de' => 'Unzufriedene Kunden'],
        'c_production' => ['fr' => 'Production mal synchronisée', 'nl' => 'Productie niet afgestemd', 'en' => 'Production out of sync', 'de' => 'Produktion nicht abgestimmt'],
        'c_equipe' => ['fr' => 'Équipe démotivée', 'nl' => 'Gedemotiveerd team', 'en' => 'Demotivated team', 'de' => 'Demotiviertes Team'],
        'c_decor' => ['fr' => 'Décor, ambiance qui n’invite pas', 'nl' => 'Inrichting en sfeer nodigen niet uit', 'en' => 'Decor and atmosphere not inviting', 'de' => 'Einrichtung und Ambiente laden nicht ein'],
        'c_prix' => ['fr' => 'Prix mal positionnés', 'nl' => 'Prijzen slecht gepositioneerd', 'en' => 'Prices poorly positioned', 'de' => 'Preise schlecht positioniert'],
        'c_appro' => ['fr' => 'Approvisionnement, ruptures', 'nl' => 'Bevoorrading, tekorten', 'en' => 'Supply, stock-outs', 'de' => 'Nachschub, Fehlbestände'],
        'c_accueil' => ['fr' => 'Accueil, vente', 'nl' => 'Onthaal, verkoop', 'en' => 'Welcome, selling', 'de' => 'Empfang, Verkauf'],
        'c_hygiene' => ['fr' => 'Hygiène, propreté', 'nl' => 'Hygiëne, netheid', 'en' => 'Hygiene, cleanliness', 'de' => 'Hygiene, Sauberkeit'],
        'c_autre' => ['fr' => 'Autre', 'nl' => 'Andere', 'en' => 'Other', 'de' => 'Sonstiges'],
        'piece_pdf' => ['fr' => 'Le rapport est joint en PDF.', 'nl' => 'Het verslag zit als pdf in bijlage.', 'en' => 'The report is attached as a PDF.', 'de' => 'Der Bericht liegt als PDF bei.'],
        'signature' => ['fr' => 'Signature du franchisé', 'nl' => 'Handtekening van de franchisenemer', 'en' => 'Franchisee signature', 'de' => 'Unterschrift des Franchisenehmers'],
        'signatures' => ['fr' => 'Signatures', 'nl' => 'Handtekeningen', 'en' => 'Signatures', 'de' => 'Unterschriften'],
        'sig_consultant' => ['fr' => 'Le consultant', 'nl' => 'De consultant', 'en' => 'The consultant', 'de' => 'Der Berater'],
        'sig_franchise' => ['fr' => 'Le franchisé', 'nl' => 'De franchisenemer', 'en' => 'The franchisee', 'de' => 'Der Franchisenehmer'],
    ];
    return $d[$k][$l] ?? ($d[$k]['fr'] ?? $k);
}

/* --- les modèles de courriel ------------------------------------------------------ */

/** Les modèles livrés : code => nom, quand il part, variables, textes par langue. */
function suModelesDefaut(): array
{
    $sigFr = "L’équipe L’Atelier by"; $sigNl = "Het team van L’Atelier by"; $sigEn = "The L’Atelier by team"; $sigDe = "Ihr Team von L’Atelier by";
    return [
        'annonce' => ['nom' => 'Annonce de visite', 'quand' => 'à J−n, pour une visite annoncée (jamais pour une inopinée)',
            'vars' => ['magasin', 'date', 'heure', 'duree', 'type', 'consultant', 'sujets', 'lien'], 'textes' => [
            'fr' => ['sujet' => 'Visite du {date} à {heure} — {magasin}', 'corps' => "Bonjour,\n\n{consultant} passera à {magasin} le {date} à {heure} (environ {duree}).\nType de visite : {type}.\n\nCe que nous regarderons ensemble :\n\n{sujets}\n\nSi ce créneau ne vous convient pas, répondez à ce courriel.\n\nVos rapports de visite et vos points à travailler sont sur votre page : {lien}\n\nÀ bientôt,\n" . $sigFr],
            'nl' => ['sujet' => 'Bezoek op {date} om {heure} — {magasin}', 'corps' => "Beste,\n\n{consultant} komt op {date} om {heure} langs in {magasin} (ongeveer {duree}).\nSoort bezoek: {type}.\n\nWat we samen bekijken:\n\n{sujets}\n\nKomt dit moment niet gelegen? Beantwoord dan deze e-mail.\n\nUw bezoekverslagen en werkpunten vindt u op uw pagina: {lien}\n\nTot binnenkort,\n" . $sigNl],
            'en' => ['sujet' => 'Visit on {date} at {heure} — {magasin}', 'corps' => "Hello,\n\n{consultant} will visit {magasin} on {date} at {heure} (about {duree}).\nType of visit: {type}.\n\nWhat we will look at together:\n\n{sujets}\n\nIf this time does not suit you, please reply to this email.\n\nYour visit reports and the points to work on are on your page: {lien}\n\nSee you soon,\n" . $sigEn],
            'de' => ['sujet' => 'Besuch am {date} um {heure} — {magasin}', 'corps' => "Guten Tag,\n\n{consultant} besucht {magasin} am {date} um {heure} (etwa {duree}).\nArt des Besuchs: {type}.\n\nWas wir gemeinsam ansehen:\n\n{sujets}\n\nFalls der Termin nicht passt, antworten Sie bitte auf diese E-Mail.\n\nIhre Besuchsberichte und Arbeitspunkte finden Sie auf Ihrer Seite: {lien}\n\nBis bald,\n" . $sigDe]]],
        'deplacee' => ['nom' => 'Visite déplacée', 'quand' => 'quand une visite annoncée change de date ou d’heure après l’annonce',
            'vars' => ['magasin', 'date_avant', 'date', 'heure', 'type', 'consultant', 'lien'], 'textes' => [
            'fr' => ['sujet' => 'Visite déplacée au {date} à {heure} — {magasin}', 'corps' => "Bonjour,\n\nLa visite prévue le {date_avant} est déplacée au {date} à {heure}, avec {consultant}.\nType de visite : {type}.\n\nSi ce nouveau créneau ne vous convient pas, répondez à ce courriel.\n\n" . $sigFr],
            'nl' => ['sujet' => 'Bezoek verplaatst naar {date} om {heure} — {magasin}', 'corps' => "Beste,\n\nHet bezoek van {date_avant} is verplaatst naar {date} om {heure}, met {consultant}.\nSoort bezoek: {type}.\n\nKomt dit nieuwe moment niet gelegen? Beantwoord dan deze e-mail.\n\n" . $sigNl],
            'en' => ['sujet' => 'Visit moved to {date} at {heure} — {magasin}', 'corps' => "Hello,\n\nThe visit planned on {date_avant} has been moved to {date} at {heure}, with {consultant}.\nType of visit: {type}.\n\nIf the new time does not suit you, please reply to this email.\n\n" . $sigEn],
            'de' => ['sujet' => 'Besuch verschoben auf {date} um {heure} — {magasin}', 'corps' => "Guten Tag,\n\nder für {date_avant} geplante Besuch wird auf {date} um {heure} verschoben, mit {consultant}.\nArt des Besuchs: {type}.\n\nFalls der neue Termin nicht passt, antworten Sie bitte auf diese E-Mail.\n\n" . $sigDe]]],
        'annulee' => ['nom' => 'Visite annulée', 'quand' => 'quand une visite annoncée est annulée après l’annonce',
            'vars' => ['magasin', 'date', 'heure', 'type', 'consultant'], 'textes' => [
            'fr' => ['sujet' => 'Visite du {date} annulée — {magasin}', 'corps' => "Bonjour,\n\nLa visite prévue le {date} à {heure} avec {consultant} est annulée. Nous vous proposerons une nouvelle date.\n\n" . $sigFr],
            'nl' => ['sujet' => 'Bezoek van {date} geannuleerd — {magasin}', 'corps' => "Beste,\n\nHet bezoek van {date} om {heure} met {consultant} is geannuleerd. We stellen u een nieuwe datum voor.\n\n" . $sigNl],
            'en' => ['sujet' => 'Visit on {date} cancelled — {magasin}', 'corps' => "Hello,\n\nThe visit planned on {date} at {heure} with {consultant} is cancelled. We will suggest a new date.\n\n" . $sigEn],
            'de' => ['sujet' => 'Besuch am {date} abgesagt — {magasin}', 'corps' => "Guten Tag,\n\nder Besuch am {date} um {heure} mit {consultant} ist abgesagt. Wir schlagen Ihnen einen neuen Termin vor.\n\n" . $sigDe]]],
        'rapport' => ['nom' => 'Rapport de visite', 'quand' => 'à l’envoi du rapport, PDF joint',
            'vars' => ['magasin', 'date', 'type', 'consultant', 'phrase', 'points', 'piece', 'lien'], 'textes' => [
            'fr' => ['sujet' => 'Rapport de visite du {date} — {magasin}', 'corps' => "Bonjour,\n\nVoici le rapport de la visite du {date} avec {consultant} ({type}).\n\n{phrase}\n\nLes points à travailler :\n\n{points}\n\n{piece}\n\nLe rapport complet et vos points sont sur votre page, où vous pouvez envoyer la photo de chaque correction : {lien}\n\n" . $sigFr],
            'nl' => ['sujet' => 'Bezoekverslag van {date} — {magasin}', 'corps' => "Beste,\n\nHierbij het verslag van het bezoek van {date} met {consultant} ({type}).\n\n{phrase}\n\nDe werkpunten:\n\n{points}\n\n{piece}\n\nHet volledige verslag en uw werkpunten staan op uw pagina, waar u voor elke correctie een foto kunt sturen: {lien}\n\n" . $sigNl],
            'en' => ['sujet' => 'Visit report of {date} — {magasin}', 'corps' => "Hello,\n\nHere is the report of the visit on {date} with {consultant} ({type}).\n\n{phrase}\n\nThe points to work on:\n\n{points}\n\n{piece}\n\nThe full report and your points are on your page, where you can send a photo of each correction: {lien}\n\n" . $sigEn],
            'de' => ['sujet' => 'Besuchsbericht vom {date} — {magasin}', 'corps' => "Guten Tag,\n\nhier ist der Bericht zum Besuch am {date} mit {consultant} ({type}).\n\n{phrase}\n\nDie Arbeitspunkte:\n\n{points}\n\n{piece}\n\nDer vollständige Bericht und Ihre Arbeitspunkte stehen auf Ihrer Seite, wo Sie für jede Korrektur ein Foto senden können: {lien}\n\n" . $sigDe]]],
        'relance' => ['nom' => 'Relance : rapport pas encore lu', 'quand' => 'n jours après l’envoi, si le rapport n’a pas été ouvert',
            'vars' => ['magasin', 'date', 'type', 'lien'], 'textes' => [
            'fr' => ['sujet' => 'Votre rapport de visite du {date} vous attend — {magasin}', 'corps' => "Bonjour,\n\nLe rapport de la visite du {date} ({type}) n’a pas encore été ouvert. Il est sur votre page, avec les points à travailler : {lien}\n\n" . $sigFr],
            'nl' => ['sujet' => 'Uw bezoekverslag van {date} wacht op u — {magasin}', 'corps' => "Beste,\n\nHet verslag van het bezoek van {date} ({type}) is nog niet geopend. U vindt het op uw pagina, samen met de werkpunten: {lien}\n\n" . $sigNl],
            'en' => ['sujet' => 'Your visit report of {date} is waiting — {magasin}', 'corps' => "Hello,\n\nThe report of the visit on {date} ({type}) has not been opened yet. It is on your page, with the points to work on: {lien}\n\n" . $sigEn],
            'de' => ['sujet' => 'Ihr Besuchsbericht vom {date} wartet auf Sie — {magasin}', 'corps' => "Guten Tag,\n\nder Bericht zum Besuch am {date} ({type}) wurde noch nicht geöffnet. Sie finden ihn auf Ihrer Seite, mit den Arbeitspunkten: {lien}\n\n" . $sigDe]]],
        'lien' => ['nom' => 'Lien de la page du franchisé', 'quand' => 'quand le bureau envoie le lien depuis « Page du franchisé »',
            'vars' => ['magasin', 'lien'], 'textes' => [
            'fr' => ['sujet' => 'Votre page de suivi — {magasin}', 'corps' => "Bonjour,\n\nVoici le lien de votre page de suivi. Vous y trouvez vos rapports de visite et les points à travailler, et vous pouvez y envoyer la photo de chaque correction :\n\n{lien}\n\nGardez ce lien pour vous : il ouvre la page de {magasin} sans mot de passe.\n\n" . $sigFr],
            'nl' => ['sujet' => 'Uw opvolgingspagina — {magasin}', 'corps' => "Beste,\n\nHierbij de link naar uw opvolgingspagina. U vindt er uw bezoekverslagen en werkpunten, en u kunt er voor elke correctie een foto sturen:\n\n{lien}\n\nHoud deze link voor uzelf: hij opent de pagina van {magasin} zonder wachtwoord.\n\n" . $sigNl],
            'en' => ['sujet' => 'Your follow-up page — {magasin}', 'corps' => "Hello,\n\nHere is the link to your follow-up page. You will find your visit reports and the points to work on, and you can send a photo of each correction:\n\n{lien}\n\nKeep this link to yourself: it opens the {magasin} page without a password.\n\n" . $sigEn],
            'de' => ['sujet' => 'Ihre Nachverfolgungsseite — {magasin}', 'corps' => "Guten Tag,\n\nhier ist der Link zu Ihrer Nachverfolgungsseite. Dort finden Sie Ihre Besuchsberichte und Arbeitspunkte und können für jede Korrektur ein Foto senden:\n\n{lien}\n\nBehalten Sie diesen Link für sich: Er öffnet die Seite von {magasin} ohne Passwort.\n\n" . $sigDe]]],
    ];
}

/** Les modèles en vigueur : le texte réglé dans « Qui fait quoi », sinon le texte livré. */
function suModeles(): array
{
    $def = suModelesDefaut();
    $reg = setting('suiviModeles', []);
    $reg = is_array($reg) ? $reg : [];
    foreach ($def as $code => &$m) {
        $m['modifie'] = [];
        foreach (array_keys(SU_LANGUES) as $l) {
            $x = is_array($reg[$code][$l] ?? null) ? $reg[$code][$l] : null;
            $ok = $x !== null && trim((string) ($x['sujet'] ?? '')) !== '' && trim((string) ($x['corps'] ?? '')) !== '';
            if ($ok) { $m['textes'][$l] = ['sujet' => (string) $x['sujet'], 'corps' => (string) $x['corps']]; }
            $m['modifie'][$l] = $ok;
        }
    }
    unset($m);
    return $def;
}

function suModele(string $code, string $l): array
{
    $m = suModeles()[$code] ?? null;
    if ($m === null) { return ['sujet' => '', 'corps' => '']; }
    return $m['textes'][$l] ?? $m['textes']['fr'];
}

/**
 * Un modèle rendu : variables remplacées, paragraphes, listes. Une variable
 * liste ({sujets}, {points}) seule sur son paragraphe devient une liste à puces,
 * vide elle dit pourquoi ; au fil d'une phrase, ses éléments séparés par des
 * virgules. Pur : le banc le vérifie.
 */
function suRendre(array $modele, array $vars, string $l): array
{
    $scal = []; $listes = [];
    foreach ($vars as $k => $v) { if (is_array($v)) { $listes[$k] = array_values(array_map('strval', $v)); $scal[$k] = implode(', ', $listes[$k]); } else { $scal[$k] = (string) $v; } }
    $remplace = static function (string $s, bool $html) use ($scal): string {
        $base = $html ? htmlspecialchars($s, ENT_QUOTES, 'UTF-8') : $s;
        return (string) preg_replace_callback('/\{([a-z_]+)\}/', static function (array $m) use ($scal, $html): string {
            if (!array_key_exists($m[1], $scal)) { return $m[0]; }
            $v = $scal[$m[1]];
            if (!$html) { return $v; }
            if ($m[1] === 'lien' && $v !== '') { return '<a href="' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . '" style="color:#8D1D2C">' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . '</a>'; }
            return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        }, $base);
    };
    $paras = preg_split('/\n\s*\n/u', trim(str_replace("\r", '', (string) ($modele['corps'] ?? '')))) ?: [];
    $h = ''; $t = [];
    foreach ($paras as $p) {
        $p = trim($p);
        if ($p === '') { continue; }
        if (preg_match('/^\{([a-z_]+)\}$/', $p, $m) && array_key_exists($m[1], $listes)) {
            $items = $listes[$m[1]];
            if ($items === []) { $vide = suL('vide_' . $m[1], $l); $h .= '<p style="margin:0 0 12px;color:#666">' . htmlspecialchars($vide, ENT_QUOTES, 'UTF-8') . '</p>'; $t[] = $vide; continue; }
            $h .= '<ul style="margin:0 0 12px;padding-left:20px">' . implode('', array_map(static fn ($i) => '<li style="margin:0 0 4px">' . htmlspecialchars($i, ENT_QUOTES, 'UTF-8') . '</li>', $items)) . '</ul>';
            $t[] = implode("\n", array_map(static fn ($i) => '- ' . $i, $items));
            continue;
        }
        if (preg_match('/^\{([a-z_]+)\}$/', $p, $m) && array_key_exists($m[1], $scal) && trim($scal[$m[1]]) === '') { continue; }
        $h .= '<p style="margin:0 0 12px">' . nl2br($remplace($p, true), false) . '</p>';
        $t[] = $remplace($p, false);
    }
    $sujet = trim($remplace(str_replace(["\r", "\n"], ' ', (string) ($modele['sujet'] ?? '')), false));
    return ['sujet' => $sujet, 'html' => suCourrielHtml($h), 'texte' => implode("\n\n", $t)];
}

function suCourrielHtml(string $corps): string
{
    return '<div style="font-family:Helvetica,Arial,sans-serif;font-size:14px;color:#222;line-height:1.6;max-width:620px">'
        . '<div style="font-family:Georgia,serif;font-size:18px;letter-spacing:.12em;color:#8D1D2C;margin:0 0 18px">L’ATELIER <span style="font-size:12px;letter-spacing:.2em;color:#666">by</span></div>'
        . $corps . '</div>';
}

/** Des valeurs d'exemple pour l'aperçu et l'essai d'un modèle. */
function suVarsExemple(string $l, ?string $shop = null): array
{
    $shop = $shop ?? (string) (array_key_first(viMagasins()) ?? '');
    $j = date('Y-m-d', strtotime('+2 days'));
    $pts = ['fr' => ['P1 · Commande de pains spéciaux +15 % du mercredi au samedi — échéance 29/10', 'P2 · Objectif de la campagne affiché en arrière-boutique — échéance 02/11'],
        'nl' => ['P1 · Bestelling speciale broden +15 % van woensdag tot zaterdag — deadline 29/10', 'P2 · Campagnedoel opgehangen in de achterwinkel — deadline 02/11'],
        'en' => ['P1 · Order of speciality breads +15 % from Wednesday to Saturday — due 29/10', 'P2 · Campaign target posted in the back room — due 02/11'],
        'de' => ['P1 · Bestellung Spezialbrote +15 % von Mittwoch bis Samstag — Frist 29/10', 'P2 · Kampagnenziel im Hinterraum ausgehängt — Frist 02/11']];
    $suj = ['fr' => ['Le scoring du trimestre, poste par poste', 'La vitrine salée (température)'], 'nl' => ['De kwartaalscore, onderdeel per onderdeel', 'De hartige vitrine (temperatuur)'],
        'en' => ['The quarterly score, item by item', 'The savoury display (temperature)'], 'de' => ['Die Quartalswertung, Punkt für Punkt', 'Die herzhafte Vitrine (Temperatur)']];
    $phr = ['fr' => 'Un trimestre solide ; le pain manque en fin de journée.', 'nl' => 'Een sterk kwartaal; het brood raakt op aan het eind van de dag.',
        'en' => 'A solid quarter; bread runs out at the end of the day.', 'de' => 'Ein starkes Quartal; am Ende des Tages fehlt Brot.'];
    return ['magasin' => $shop !== '' ? suCourt($shop) : 'Halle', 'date' => suDateLongue($j, $l), 'date_avant' => suDateLongue(date('Y-m-d', strtotime('+1 day')), $l),
        'heure' => '10:00', 'duree' => '120 min', 'type' => suTypeNom('bilan', $l), 'consultant' => 'Sam V.', 'sujets' => $suj[$l] ?? $suj['fr'],
        'phrase' => $phr[$l] ?? $phr['fr'], 'points' => $pts[$l] ?? $pts['fr'], 'piece' => suL('piece_pdf', $l), 'lien' => $shop !== '' ? suLien($shop) : rtrim(rapBaseUrl(), '/') . '/suivi/'];
}

/** Un courriel au franchisé d'un magasin, dans sa langue, à son adresse, avec les copies données. */
function suEnvoyerFranchise(string $shop, string $code, array $vars, array $pieces = [], array $copies = []): array
{
    $regle = suMagasinRegle($shop); $ad = suAdresse($shop, $regle); $l = suLangue($shop, $regle);
    if ($ad['email'] === null) { return ['envoye' => false, 'motif' => 'adresse du franchisé inconnue (Qui fait quoi)', 'langue' => $l]; }
    if (!class_exists('Smtp') || !Smtp::configured()) { return ['envoye' => false, 'motif' => 'SMTP non configuré (Paramètres)', 'a' => $ad['email'], 'langue' => $l]; }
    $r = suRendre(suModele($code, $l), $vars + ['magasin' => suCourt($shop), 'lien' => suLien($shop)], $l);
    $cc = array_values(array_unique(array_filter($copies, static fn ($c) => is_string($c) && filter_var($c, FILTER_VALIDATE_EMAIL) && $c !== $ad['email'])));
    try { $ok = Smtp::envoyer($ad['email'], $r['sujet'], $r['html'], $pieces, '', $cc); }
    catch (Throwable $e) { return ['envoye' => false, 'motif' => $e->getMessage(), 'a' => $ad['email'], 'langue' => $l]; }
    return ['envoye' => $ok, 'motif' => $ok ? null : (Smtp::$lastError ?? 'envoi refusé'), 'a' => $ad['email'], 'copies' => $cc, 'langue' => $l, 'sujet' => $r['sujet']];
}

/* --- la préparation ------------------------------------------------------------- */

/** La préparation gardée sur la visite : blocs exclus, points du bureau, dossier figé à l'envoi sur la tablette. */
function suPrep(array $v): array
{
    $p = suJson($v['prep'] ?? null);
    $pts = [];
    foreach (is_array($p['points'] ?? null) ? $p['points'] : [] as $i => $x) {
        if (!is_array($x)) { continue; }
        $lib = mb_substr(trim((string) ($x['libelle'] ?? '')), 0, 190);
        if ($lib === '') { continue; }
        $ref = preg_replace('/[^\w-]/', '', (string) ($x['ref'] ?? '')) ?: ('bur' . ($i + 1));
        $pts[] = ['ref' => mb_substr($ref, 0, 40), 'libelle' => $lib, 'odj' => !isset($x['odj']) || (bool) $x['odj'], 'source' => (string) ($x['source'] ?? 'bureau')];
    }
    return ['blocs' => is_array($p['blocs'] ?? null) ? array_map('boolval', $p['blocs']) : [], 'points' => $pts,
        'dossier' => is_array($p['dossier'] ?? null) ? $p['dossier'] : [], 'note' => (string) ($p['note'] ?? ''), 'figeLe' => $p['figeLe'] ?? null];
}

/** Le dossier de visite d'un magasin, bloc par bloc, lu là où chaque chose vit. Une source muette dit pourquoi. */
function suDossier(string $shop): array
{
    $blocs = [];
    $bloc = static function (string $cle, array $lignes, bool $alerte = false, ?string $motif = null, bool $interne = false) use (&$blocs): void {
        $blocs[] = ['cle' => $cle, 'titre' => SU_BLOCS[$cle], 'lignes' => array_values(array_filter($lignes, static fn ($x) => $x !== '')), 'alerte' => $alerte, 'motif' => $motif, 'interne' => $interne];
    };
    $nf = static fn ($n, int $d = 0): string => number_format((float) $n, $d, ',', ' ');
    // 1. Santé : le mois face au budget, la part du CA pro, les invendus, Google, le rang au réseau.
    $l = []; $alerte = false; $motif = null;
    try {
        $mo = function_exists('frMois') ? frMois($shop) : ['lu' => false, 'motif' => 'module absent'];
        if (!empty($mo['lu'])) {
            if ($mo['atteinte'] !== null) { $l[] = 'CA du mois à ' . $nf($mo['atteinte']) . ' % du budget attendu à date'; $alerte = $alerte || $mo['atteinte'] < 90; }
            if (($mo['pro']['part'] ?? null) !== null) { $l[] = 'part B2B ' . $nf($mo['pro']['part']) . ' % (règle des ' . $nf(FR_PRO_MAX) . ' %)'; $alerte = $alerte || $mo['pro']['part'] > FR_PRO_MAX; }
        } else { $motif = (string) ($mo['motif'] ?? 'résultat du mois illisible'); }
    } catch (Throwable $e) { $motif = 'résultat du mois illisible'; }
    try {
        $inv = function_exists('frInvendus') ? frInvendus($shop) : ['lu' => false];
        if (!empty($inv['lu']) && $inv['part'] !== null) { $l[] = 'invendus ' . $nf($inv['part'], 1) . ' % du CA sur 7 jours (cible ' . $nf($inv['cible']) . ' %)'; $alerte = $alerte || $inv['part'] > $inv['cible']; }
    } catch (Throwable $e) { /* invendus muets */ }
    $g = viGoogle()[$shop] ?? null;
    if ($g && $g['note'] !== null) { $l[] = 'note Google ' . $nf($g['note'], 1) . ($g['avis'] !== null ? ' (' . $g['avis'] . ' avis)' : '') . ($g['faibles'] ? ' · ' . $g['faibles'] . ' avis ≤ 2/5 sur 30 jours' : ''); }
    $ca = viCa()[$shop] ?? null;
    if ($ca && $ca['pct'] !== null) { $l[] = 'CA de la semaine ' . ($ca['pct'] > 0 ? '+' : '') . $ca['pct'] . ' % face à l’objectif'; }
    $bloc('sante', $l, $alerte, $l === [] ? ($motif ?? 'aucune source ne répond') : null);
    // 2. Les points ouverts.
    $l = []; $alerte = false;
    foreach (viPlans($shop) as $p) {
        if (!in_array($p['statut'], ['ouvert', 'reprendre', 'escalade', 'attente'], true)) { continue; }
        $etat = ['ouvert' => 'ouvert', 'reprendre' => 'à reprendre', 'escalade' => 'escaladé', 'attente' => 'photo de correction à valider'][$p['statut']];
        $l[] = $p['priorite'] . ' · ' . $p['titre'] . ' — ' . $etat . ($p['retard'] ? ', en retard de ' . $p['retard'] . ' j' : ($p['echeance'] ? ', échéance ' . date('d/m', strtotime((string) $p['echeance'])) : ''));
        $alerte = $alerte || $p['priorite'] === 'P0' || $p['retard'] > 0;
    }
    $bloc('points', $l ?: ['aucun point ouvert'], $alerte);
    // 3. Les tâches du panel et leurs photos.
    $l = []; $motif = null;
    try {
        $t = function_exists('frTachesJours') ? frTachesJours($shop) : ['pct' => null, 'motif' => 'module absent'];
        if ($t['pct'] !== null) { $l[] = $t['pct'] . ' % des tâches rendues sur 30 jours' . ($t['joursObligManques'] ? ' · obligatoires manquées ' . $t['joursObligManques'] . ' jour' . ($t['joursObligManques'] > 1 ? 's' : '') : ''); }
        else { $motif = (string) ($t['motif'] ?? 'relevé absent'); }
        $rv = function_exists('frRevues') ? frRevues($shop) : null;
        if ($rv && !empty($rv['lu']) && $rv['notees']) { $l[] = $rv['notees'] . ' photo' . ($rv['notees'] > 1 ? 's' : '') . ' notée' . ($rv['notees'] > 1 ? 's' : '') . ', moyenne ' . $nf($rv['moyenne'], 1) . ' / 5' . ($rv['nc'] ? ' · ' . $rv['nc'] . ' infraction' . ($rv['nc'] > 1 ? 's' : '') : ''); }
    } catch (Throwable $e) { $motif = 'relevé des tâches illisible'; }
    $bloc('taches', $l, false, $l === [] ? $motif : null);
    // 4. Le scoring du trimestre.
    $l = [];
    try {
        $s = suScoring($shop);
        if ($s && $s['total'] !== null) {
            $l[] = 'Scoring ' . ($s['trimestre']['court'] ?? '') . ' : ' . $nf($s['total'], 1) . ' / ' . $s['sur'] . ($s['rang'] ? ' · ' . $s['rang'] . ($s['rang'] === 1 ? 'er' : 'e') . ' du réseau' : '');
            if (is_array($s['prec'] ?? null) && ($s['prec']['total'] ?? null) !== null) { $l[] = 'trimestre précédent : ' . $nf($s['prec']['total'], 1); }
        }
    } catch (Throwable $e) { /* scoring muet */ }
    $bloc('scoring', $l, false, $l === [] ? 'scoring pas encore calculé' : null);
    // 5. Le client mystère et le comptoir.
    $l = []; $alerte = false;
    $msp = suMspDernier($shop);
    if ($msp) {
        $l[] = 'Client mystère du ' . date('d/m/Y', strtotime($msp['passage'])) . ' : ' . $nf($msp['obtenu'], 0) . ' / ' . $nf($msp['maximum'], 0);
        foreach ($msp['rub'] as $nom => $v20) { if ($v20 !== null && $v20 < (float) viSeuils()['mspAlerte']) { $l[] = $nom . ' ' . $nf($v20, 0) . ' / 20 (sous le seuil)'; $alerte = true; } }
    }
    try {
        $cf = function_exists('frConformite') ? frConformite($shop) : null;
        if (is_array($cf) && empty($cf['motif'])) {
            $pl = $cf['planogramme'] ?? []; $as = $cf['assortiment'] ?? [];
            if (($pl['pct'] ?? null) !== null) { $l[] = 'planogramme tenu à ' . $pl['pct'] . ' %' . (!empty($pl['obligatoiresSansPlace']) ? ' · ' . $pl['obligatoiresSansPlace'] . ' obligatoire(s) sans place' : ''); }
            if (($as['pct'] ?? null) !== null) { $l[] = 'assortiment obligatoire vu en caisse à ' . $as['pct'] . ' %'; }
        }
    } catch (Throwable $e) { /* conformité muette */ }
    $bloc('msp', $l, $alerte, $l === [] ? 'pas de rapport ni de relevé du comptoir' : null);
    // 6. La dernière météo du franchisé — interne, jamais montrée au franchisé : le temps noté en fin de contrôle
    // (con_visite.meteo, 1 orage … 5 grand soleil), puis le détail de la tablette (con_franchise_meteo).
    $l = [];
    try {
        $vm = Db::row('SELECT prevu_le, meteo FROM con_visite WHERE shop_id = ? AND statut = \'terminee\' AND meteo IS NOT NULL ORDER BY prevu_le DESC, id DESC LIMIT 1', [$shop]);
        if ($vm) { $l[] = date('d/m', strtotime((string) $vm['prevu_le'])) . ' : ' . ([1 => 'orage', 2 => 'pluie', 3 => 'nuageux', 4 => 'éclaircies', 5 => 'grand soleil'][(int) $vm['meteo']] ?? '—') . ' (fin de contrôle)'; }
    } catch (Throwable $e) { /* colonne absente */ }
    try {
        if (function_exists('ensureFrMeteo')) {
            ensureFrMeteo();
            $m = Db::row('SELECT * FROM con_franchise_meteo WHERE shop_id = ? ORDER BY le DESC, id DESC LIMIT 1', [$shop]);
            if ($m) {
                $l[] = date('d/m', strtotime((string) $m['le'])) . ' : moral ' . ($m['moral'] ?? '—') . ' / 5, équipe ' . ($m['equipe'] ?? '—') . ' / 5'
                    . (isset($m['finances']) && $m['finances'] !== null ? ', finances ' . (int) $m['finances'] . ' / 5' : '');
                // Les finances (28/10/2026) : paiements à jour, ou ce qui est en retard et le détail donné par le franchisé.
                if (function_exists('frMeteoPaiements')) {
                    $pm = frMeteoPaiements($m);
                    if ($pm['payeATemps'] === true) { $l[] = 'paiements : tout est payé à temps'; }
                    if ($pm['payeATemps'] === false) { $l[] = 'paiements en retard' . ($pm['retardsLib'] ? ' (' . implode(', ', $pm['retardsLib']) . ')' : '') . ' : ' . mb_substr($pm['retardsInfo'], 0, 220); }
                }
                foreach (suJson($m['demandes']) as $d) { if (!empty($d['texte'])) { $l[] = 'demande : « ' . $d['texte'] . ' »'; } }
                if (!empty($m['inquietudes'])) { $l[] = 'inquiétude : ' . mb_substr((string) $m['inquietudes'], 0, 160); }
            }
        }
    } catch (Throwable $e) { /* météo muette */ }
    $bloc('meteo', $l, false, $l === [] ? 'pas encore relevée' : null, true);
    return $blocs;
}

/** GET /suivi/preparation?visite= — le dossier, ce que la tablette emportera, l'ordre du jour. */
function ep_suivi_preparation(): array
{
    ensureSuivi();
    $v = viVisiteParId((string) ($_GET['visite'] ?? ''));
    if ($v === null) { http_response_code(404); return ['error' => 'visite inconnue']; }
    @set_time_limit(120);
    return suPreparationVue($v);
}

function suPreparationVue(array $v): array
{
    $shop = (string) $v['shop_id']; $regles = suRegles(); $prep = suPrep($v);
    $dossier = suDossier($shop);
    foreach ($dossier as &$b) { $b['inclus'] = $prep['blocs'][$b['cle']] ?? ($b['cle'] !== 'msp' || $b['lignes'] !== []); }
    unset($b);
    $annoncee = suAnnoncee($v); $l = suLangue($shop); $ad = suAdresse($shop);
    $odjLe = date('Y-m-d', strtotime((string) $v['prevu_le'] . ' -' . (int) $regles['delais']['odjJours'] . ' days'));
    $apercu = suRendre(suModele('annonce', $l), suVarsVisite($v, $l, $prep) + ['magasin' => suCourt($shop), 'lien' => suLien($shop)], $l);
    $t = suTypesParCode()[(string) (($v['type_code'] ?? '') ?: 'reguliere')] ?? null;
    return ['visite' => viVisiteLigne($v), 'magasin' => viMagasins()[$shop] ?? ['id' => $shop, 'court' => $shop],
        'type' => $t ? ['code' => $t['code'], 'nom' => $t['nom'], 'duree' => $t['duree'], 'points' => $t['points'], 'dynamique' => $t['dynamique'], 'annoncee' => !array_key_exists('annoncee', $t) || (bool) $t['annoncee']] : null,
        'types' => function_exists('vcTypesCourts') ? vcTypesCourts() : [], 'dossier' => $dossier, 'prep' => $prep,
        'annoncee' => $annoncee, 'roles' => suRoles($v),
        'prepDu' => date('Y-m-d', strtotime((string) $v['prevu_le'] . ' -' . (int) $regles['delais']['prepJours'] . ' days')),
        'tablette' => ['envoyeeLe' => $v['prep_envoyee_le'] ?? null],
        'odj' => ['auto' => (bool) $regles['delais']['odjAuto'], 'prevuLe' => $odjLe, 'envoyeLe' => $v['odj_envoye_le'] ?? null, 'adresse' => $ad['email'], 'source' => $ad['source'],
            'langue' => $l, 'langueNom' => SU_LANGUES[$l], 'sujet' => $apercu['sujet'], 'texte' => $apercu['texte']]];
}

/** Les variables d'un courriel de visite (ligne brute). */
function suVarsVisite(array $v, string $l, ?array $prep = null): array
{
    $prep = $prep ?? suPrep($v);
    $sujets = array_values(array_map(static fn ($p) => (string) $p['libelle'], array_filter($prep['points'], static fn ($p) => $p['odj'])));
    return ['date' => suDateLongue((string) $v['prevu_le'], $l), 'heure' => (string) $v['debut_h'], 'duree' => (int) $v['duree_min'] . ' min',
        'type' => suTypeNom((string) (($v['type_code'] ?? '') ?: 'reguliere'), $l), 'consultant' => (string) $v['consultant_nom'], 'sujets' => $sujets];
}

/**
 * PUT /suivi/preparation/{id} — { blocs: {cle: bool}, points: [{libelle, odj}], annoncee, type, note,
 * envoyer: true (dossier figé et poussé sur la tablette), odj: true (ordre du jour envoyé maintenant) }.
 */
function wr_suivi_preparation(string $id): array
{
    ensureSuivi();
    $v = viVisiteParId($id);
    if ($v === null) { http_response_code(404); return ['error' => 'visite inconnue']; }
    if (!in_array($v['statut'], ['planifiee', 'confirmee', 'en_cours'], true)) { http_response_code(409); return ['error' => 'la visite est ' . $v['statut'] . ' : sa préparation est close']; }
    $b = body(); $now = date('Y-m-d H:i:s'); $qui = viQui($b + ['qui' => 'Bureau']);
    $prep = suPrep($v);
    if (isset($b['blocs']) && is_array($b['blocs'])) { foreach ($b['blocs'] as $k => $x) { if (isset(SU_BLOCS[$k])) { $prep['blocs'][$k] = (bool) $x; } } }
    if (isset($b['points']) && is_array($b['points'])) {
        $pts = [];
        foreach ($b['points'] as $i => $x) {
            if (!is_array($x)) { continue; }
            $lib = mb_substr(trim((string) ($x['libelle'] ?? '')), 0, 190);
            if ($lib === '') { continue; }
            $pts[] = ['ref' => 'bur' . (count($pts) + 1), 'libelle' => $lib, 'odj' => !isset($x['odj']) || (bool) $x['odj'], 'source' => in_array($x['source'] ?? '', ['bureau', 'msp', 'plan'], true) ? $x['source'] : 'bureau'];
        }
        $prep['points'] = array_slice($pts, 0, 20);
    }
    if (array_key_exists('note', $b)) { $prep['note'] = mb_substr(trim((string) $b['note']), 0, 2000); }
    $set = ['prep = ?', 'maj_le = ?']; $args = [null, $now];
    if (array_key_exists('annoncee', $b)) { $set[] = 'annoncee = ?'; $args[] = $b['annoncee'] === null ? null : ((bool) $b['annoncee'] ? 1 : 0); }
    $avant = $v;
    if (isset($b['type']) && function_exists('vcTypeCode')) {
        $type = vcTypeCode($b['type']);
        if ($type !== (string) ($v['type_code'] ?? 'reguliere')) {
            $td = vcType($type);
            $set[] = 'type_code = ?'; $args[] = $type;
            if ($td) { $set[] = 'duree_min = ?'; $args[] = (int) $td['duree']; }
            if (!array_key_exists('annoncee', $b)) { $set[] = 'annoncee = ?'; $args[] = null; }
        }
    }
    $envoyer = !empty($b['envoyer']);
    if ($envoyer) {
        $dossier = array_values(array_filter(suDossier((string) $v['shop_id']), static fn ($x) => $prep['blocs'][$x['cle']] ?? ($x['cle'] !== 'msp' || $x['lignes'] !== [])));
        $prep['dossier'] = array_map(static fn ($x) => ['cle' => $x['cle'], 'titre' => $x['titre'], 'lignes' => $x['lignes'], 'alerte' => $x['alerte'], 'interne' => $x['interne']], $dossier);
        $prep['figeLe'] = $now;
        $set[] = 'prep_envoyee_le = ?'; $args[] = $now;
    }
    $args[0] = json_encode($prep, JSON_UNESCAPED_UNICODE);
    $args[] = (int) $v['id'];
    Db::exec('UPDATE con_visite SET ' . implode(', ', $set) . ' WHERE id = ?', $args);
    $apres = Db::row('SELECT * FROM con_visite WHERE id = ?', [(int) $v['id']]);
    if (function_exists('vcApresChangement') && ($avant['type_code'] ?? '') !== ($apres['type_code'] ?? '')) { vcApresChangement($avant, $apres); }
    $court = suCourt((string) $v['shop_id']);
    $out = ['ok' => true];
    if ($envoyer) {
        viNotifier('c:' . $v['consultant_id'], 'Dossier prêt — ' . $court, 'Visite du ' . date('d/m', strtotime((string) $v['prevu_le'])) . ' : ' . count($prep['points']) . ' point(s) du bureau', 'controle/?id=' . rawurlencode((string) $v['consultant_id']) . '#fiche/' . $v['id'], 'prep-' . $v['id']);
        journalAdd($qui, 'Visite', null, 'Dossier de visite envoyé sur la tablette : ' . $court . ' le ' . $v['prevu_le']);
    }
    if (!empty($b['odj'])) {
        if (!suAnnoncee($apres)) { $out['odjEnvoi'] = ['envoye' => false, 'motif' => 'visite inopinée : rien ne part au franchisé']; }
        else { $out['odjEnvoi'] = suOdjEnvoyer($apres); }
    }
    return $out + suPreparationVue(Db::row('SELECT * FROM con_visite WHERE id = ?', [(int) $v['id']]));
}

/** L'ordre du jour d'une visite annoncée, au franchisé. */
function suOdjEnvoyer(array $v): array
{
    $shop = (string) $v['shop_id']; $l = suLangue($shop);
    $r = suEnvoyerFranchise($shop, 'annonce', suVarsVisite($v, $l));
    if ($r['envoye']) {
        Db::exec('UPDATE con_visite SET odj_envoye_le = ? WHERE id = ?', [date('Y-m-d H:i:s'), (int) $v['id']]);
        journalAdd('Cockpit', 'Visite', null, 'Ordre du jour envoyé à ' . suCourt($shop) . ' (' . $r['a'] . ', ' . $l . ') pour la visite du ' . $v['prevu_le']);
    }
    return $r;
}

/* --- le rapport ----------------------------------------------------------------- */

/** Le contenu du rapport : ce que la tablette a écrit, ce que le bureau a adapté. */
function suRapportContenu(array $v): array
{
    $r = suJson($v['rapport'] ?? null);
    $causes = !empty($v['causes']) ? (json_decode((string) $v['causes'], true) ?: []) : [];
    return ['phrase' => (string) ($r['phrase'] ?? ''), 'positif' => (string) ($v['positif'] ?? ''), 'sentiment' => $v['sentiment'] !== null ? (int) $v['sentiment'] : null,
        'execution' => (string) ($v['execution'] ?? ''), 'clients' => (string) ($v['clients'] ?? ''), 'causes' => array_values($causes),
        'diagnostic' => (string) ($v['diagnostic'] ?? ''), 'reco' => (string) ($v['reco'] ?? '')];
}

/** Les points de la checklist d'une visite : conformes, non conformes, écarts. */
function suChecklistBilan(array $v): array
{
    $seuil = (int) viSeuils()['planoOrange'];
    $pts = array_map('viPointLigne', Db::rows('SELECT * FROM con_visite_point WHERE visite_id = ?', [(int) $v['id']]));
    // Les questions de la visite (son type, sinon la liste standard) : la réponse d'un choix se lit par son libellé.
    $defs = [];
    $code = (string) (($v['type_code'] ?? '') ?: 'reguliere');
    foreach (function_exists('vcTypes') ? vcTypes() : [] as $t) {
        if (($t['code'] ?? '') !== $code) { continue; }
        foreach ((array) ($t['checklist'] ?? []) as $m) { foreach ((array) ($m['points'] ?? []) as $pt) { if (isset($pt['ref'])) { $defs[$pt['ref']] = $pt; } } }
    }
    // Les photos du jour ont leur question, quel que soit le type (src/visites_questions.php, viPhotosNormalise).
    if (function_exists('viPhotos')) { foreach (viPhotos()['points'] as $pt) { $defs[$pt['ref']] = $pt; } }
    // La charte de communication aussi : un support par question, Oui / Non avec sa photo.
    if (function_exists('viCharte')) { foreach (viCharte()['points'] as $pt) { $defs[$pt['ref']] = $pt; } }
    $ok = 0; $ko = 0; $na = 0; $ecarts = [];
    foreach ($pts as $p) {
        $fait = $p['etat'] !== '' || $p['note'] !== null || $p['valeur'] !== null;
        if ($p['etat'] === 'na') { $na++; continue; }
        $nc = $p['etat'] === 'ko' || ($p['note'] !== null && $p['note'] <= 2) || ($p['valeur'] !== null && $p['valeur'] < $seuil);
        if ($nc) { $ko++; $ecarts[] = ['ref' => $p['ref'], 'module' => $p['module'], 'libelle' => $p['libelle'], 'note' => $p['note'], 'valeur' => $p['valeur'], 'commentaire' => $p['commentaire'],
            'reponse' => function_exists('viReponseTexte') ? viReponseTexte($defs[$p['ref']] ?? [], $p['reponse'] ?? null) : '']; }
        elseif ($fait) { $ok++; }
    }
    return ['total' => $ok + $ko, 'ok' => $ok, 'ko' => $ko, 'na' => $na, 'ecarts' => $ecarts];
}

function suRapportDelai(array $v): ?array
{
    if (empty($v['termine_a'])) { return null; }
    $h = (int) suRegles()['delais']['rapportHeures'];
    $limite = strtotime((string) $v['termine_a']) + $h * 3600;
    $reste = $limite - time();
    return ['limite' => date('Y-m-d H:i', $limite), 'heures' => $h, 'resteH' => round($reste / 3600, 1), 'depasse' => $reste < 0];
}

/** Les points proposés par la tablette et ceux déjà ouverts pour cette visite. */
function suRapportPlans(array $v, array $r): array
{
    $incl = is_array($r['plans'] ?? null) ? $r['plans'] : [];
    $out = [];
    foreach (Db::rows('SELECT * FROM con_visite_action WHERE visite_id = ? ORDER BY FIELD(priorite, \'P0\', \'P1\', \'P2\'), id', [(int) $v['id']]) as $p) {
        $l = viPlanLigne($p);
        $l['inclus'] = $p['statut'] === 'interne' ? false : ($p['statut'] === 'propose' ? (bool) ($incl[(string) $p['id']] ?? true) : true);
        $l['modifiable'] = $p['statut'] === 'propose';
        $out[] = $l;
    }
    return $out;
}

function suRapportPhotos(array $v, array $r): array
{
    $incl = is_array($r['photos'] ?? null) ? $r['photos'] : [];
    $out = []; $n = 0;
    foreach (Db::rows('SELECT * FROM con_visite_photo WHERE visite_id = ? AND genre NOT IN (\'signature\', \'sig_consultant\') ORDER BY prise_a', [(int) $v['id']]) as $p) {
        $l = viPhotoLigne($p);
        $l['inclus'] = array_key_exists((string) $p['id'], $incl) ? (bool) $incl[(string) $p['id']] : $n < 6;
        $n++;
        $out[] = $l;
    }
    return $out;
}

/** GET /suivi/rapport?visite= — le brouillon, ses points et ses photos, l'envoi. */
function ep_suivi_rapport(): array
{
    ensureSuivi();
    $v = viVisiteParId((string) ($_GET['visite'] ?? ''));
    if ($v === null) { http_response_code(404); return ['error' => 'visite inconnue']; }
    return suRapportVue($v);
}

function suRapportVue(array $v): array
{
    $shop = (string) $v['shop_id']; $r = suJson($v['rapport'] ?? null);
    $roles = suRoles($v); $l = suLangue($shop); $ad = suAdresse($shop);
    $contenu = suRapportContenu($v); $ck = suChecklistBilan($v);
    $plans = suRapportPlans($v, $r);
    if ($contenu['phrase'] === '') {
        $n = count(array_filter($plans, static fn ($p) => $p['inclus']));
        $contenu['phrase'] = suTypeNom((string) (($v['type_code'] ?? '') ?: 'reguliere'), 'fr') . ' : ' . $ck['ok'] . ' / ' . $ck['total'] . ' points conformes'
            . ($n ? ', ' . $n . ' point' . ($n > 1 ? 's' : '') . ' à travailler.' : '.');
    }
    $copies = [];
    foreach (array_unique(array_filter(array_merge($roles['copie'], [$roles['responsable']]))) as $id) {
        if ($id === $roles['envoie']) { continue; }
        $copies[] = ['id' => $id, 'nom' => suPersonneNom($id), 'email' => suPersonneEmail($id)];
    }
    $vars = suVarsRapport($v, $contenu, $plans, $l);
    $apercu = suRendre(suModele('rapport', $l), $vars + ['magasin' => suCourt($shop), 'lien' => suLien($shop)], $l);
    return ['visite' => viVisiteLigne($v), 'magasin' => viMagasins()[$shop] ?? ['id' => $shop, 'court' => $shop],
        'typeNom' => suTypeNom((string) (($v['type_code'] ?? '') ?: 'reguliere'), 'fr'), 'statut' => $v['rapport_statut'] ?? null,
        'delai' => suRapportDelai($v), 'contenu' => $contenu, 'checklist' => $ck, 'plans' => $plans, 'photos' => suRapportPhotos($v, $r),
        // Le score (26/10/2026) : la visite et la précédente comparable. Visible ici et dans le rapport, pas sur la tablette (sauf avant de signer).
        'score' => suScore($v),
        'causesDiag' => viCausesDiag(), 'roles' => $roles,
        'envoi' => ['a' => $ad['email'], 'source' => $ad['source'], 'langue' => $l, 'langueNom' => SU_LANGUES[$l], 'copies' => $copies,
            'envoyeLe' => $v['rapport_envoye_le'] ?? null, 'envoyePar' => $v['rapport_envoye_par'] ?? null, 'luLe' => $v['rapport_lu_le'] ?? null,
            'smtp' => class_exists('Smtp') && Smtp::configured(), 'dernier' => $r['envoi'] ?? null],
        'apercu' => ['sujet' => $apercu['sujet'], 'texte' => $apercu['texte']], 'adapteLe' => $r['adapte_le'] ?? null, 'adaptePar' => $r['adapte_par'] ?? null];
}

function suVarsRapport(array $v, array $contenu, array $plans, string $l): array
{
    $pts = [];
    foreach ($plans as $p) {
        if (!$p['inclus']) { continue; }
        $pts[] = $p['priorite'] . ' · ' . $p['titre'] . ($p['echeance'] ? ' — ' . suL('echeance', $l) . ' ' . date('d/m', strtotime((string) $p['echeance'])) : '');
    }
    return ['date' => suDateLongue((string) $v['prevu_le'], $l), 'type' => suTypeNom((string) (($v['type_code'] ?? '') ?: 'reguliere'), $l),
        'consultant' => (string) $v['consultant_nom'], 'phrase' => $contenu['phrase'], 'points' => $pts, 'piece' => suL('piece_pdf', $l)];
}

/**
 * PUT /suivi/rapport/{id} — le bureau adapte : { phrase, positif, sentiment, execution, clients, causes, diagnostic,
 * reco, plans: [{id, inclus, titre, detail, priorite, echeance, assigne}], photos: {id: bool}, qui }.
 */
function wr_suivi_rapport(string $id): array
{
    ensureSuivi();
    $v = viVisiteParId($id);
    if ($v === null) { http_response_code(404); return ['error' => 'visite inconnue']; }
    if ($v['statut'] !== 'terminee') { http_response_code(409); return ['error' => 'la visite n’est pas terminée']; }
    $b = body(); $now = date('Y-m-d H:i:s');
    $r = suJson($v['rapport'] ?? null);
    if (array_key_exists('phrase', $b)) { $r['phrase'] = mb_substr(trim((string) $b['phrase']), 0, 600); }
    if (isset($b['photos']) && is_array($b['photos'])) { foreach ($b['photos'] as $pid => $x) { $r['photos'][(string) (int) $pid] = (bool) $x; } }
    if (isset($b['plans']) && is_array($b['plans'])) {
        foreach ($b['plans'] as $x) {
            if (!is_array($x) || empty($x['id'])) { continue; }
            $p = Db::row('SELECT * FROM con_visite_action WHERE id = ? AND visite_id = ?', [(int) $x['id'], (int) $v['id']]);
            if ($p === null || $p['statut'] !== 'propose') { continue; }
            $r['plans'][(string) $p['id']] = !isset($x['inclus']) || (bool) $x['inclus'];
            $set = []; $args = [];
            if (isset($x['titre']) && trim((string) $x['titre']) !== '') { $set[] = 'titre = ?'; $args[] = mb_substr(trim((string) $x['titre']), 0, 190); }
            if (array_key_exists('detail', $x)) { $set[] = 'detail = ?'; $args[] = mb_substr((string) $x['detail'], 0, 2000) ?: null; }
            if (in_array($x['priorite'] ?? '', VI_PRIORITES, true)) { $set[] = 'priorite = ?'; $args[] = $x['priorite']; }
            if (in_array($x['assigne'] ?? '', VI_ASSIGNES, true)) { $set[] = 'assigne = ?'; $args[] = $x['assigne']; }
            if (array_key_exists('echeance', $x)) { $set[] = 'echeance = ?'; $args[] = viDate($x['echeance'] ?? null); }
            if ($set) { $set[] = 'maj_le = ?'; $args[] = $now; $args[] = (int) $p['id']; Db::exec('UPDATE con_visite_action SET ' . implode(', ', $set) . ' WHERE id = ?', $args); }
        }
    }
    $set = []; $args = [];
    if (array_key_exists('positif', $b)) { $set[] = 'positif = ?'; $args[] = mb_substr((string) $b['positif'], 0, 2000); }
    if (array_key_exists('sentiment', $b)) { $s = (int) $b['sentiment']; $set[] = 'sentiment = ?'; $args[] = $s >= 1 && $s <= 5 ? $s : null; }
    if (array_key_exists('execution', $b)) { $set[] = 'execution = ?'; $args[] = in_array($b['execution'], VI_EXECUTION, true) && $b['execution'] !== '' ? $b['execution'] : null; }
    if (array_key_exists('clients', $b)) { $set[] = 'clients = ?'; $args[] = in_array($b['clients'], VI_CLIENTS, true) && $b['clients'] !== '' ? $b['clients'] : null; }
    if (array_key_exists('causes', $b)) { $c = is_array($b['causes']) ? array_values(array_intersect(array_map('strval', $b['causes']), array_keys(viCausesDiag()))) : []; $set[] = 'causes = ?'; $args[] = $c ? json_encode($c) : null; }
    if (array_key_exists('diagnostic', $b)) { $set[] = 'diagnostic = ?'; $args[] = mb_substr((string) $b['diagnostic'], 0, 4000) ?: null; }
    if (array_key_exists('reco', $b)) { $set[] = 'reco = ?'; $args[] = mb_substr((string) $b['reco'], 0, 4000) ?: null; }
    $r['adapte_le'] = $now; $r['adapte_par'] = viQui($b + ['qui' => 'Bureau']);
    $set[] = 'rapport = ?'; $args[] = json_encode($r, JSON_UNESCAPED_UNICODE);
    if (($v['rapport_statut'] ?? null) === null) { $set[] = 'rapport_statut = ?'; $args[] = 'brouillon'; }
    $set[] = 'maj_le = ?'; $args[] = $now; $args[] = (int) $v['id'];
    Db::exec('UPDATE con_visite SET ' . implode(', ', $set) . ' WHERE id = ?', $args);
    return ['ok' => true] + suRapportVue(Db::row('SELECT * FROM con_visite WHERE id = ?', [(int) $v['id']]));
}

/** POST /suivi/rapport/{id}/envoyer — les points cochés s'ouvrent chez le franchisé, les autres restent internes, le rapport part. */
function wr_suivi_rapport_envoyer(string $id): array
{
    ensureSuivi();
    $v = viVisiteParId($id);
    if ($v === null) { http_response_code(404); return ['error' => 'visite inconnue']; }
    if ($v['statut'] !== 'terminee') { http_response_code(409); return ['error' => 'la visite n’est pas terminée']; }
    $b = body();
    return suRapportEnvoyer($v, viQui($b + ['qui' => 'Bureau']), !empty($b['renvoyer']));
}

function suRapportEnvoyer(array $v, string $qui, bool $renvoyer = false): array
{
    $shop = (string) $v['shop_id']; $court = suCourt($shop); $now = date('Y-m-d H:i:s');
    $deja = ($v['rapport_statut'] ?? null) === 'envoye';
    if ($deja && !$renvoyer) { http_response_code(409); return ['error' => 'rapport déjà envoyé le ' . $v['rapport_envoye_le']]; }
    $r = suJson($v['rapport'] ?? null);
    $ouverts = 0; $internes = 0;
    if (!$deja) {
        foreach (Db::rows('SELECT * FROM con_visite_action WHERE visite_id = ? AND statut = \'propose\'', [(int) $v['id']]) as $p) {
            $inclus = (bool) ($r['plans'][(string) $p['id']] ?? true);
            $vers = $inclus ? 'ouvert' : 'interne';
            Db::exec('UPDATE con_visite_action SET statut = ?, cree_le = ?, maj_le = ? WHERE id = ?', [$vers, $inclus ? $now : $p['cree_le'], $now, (int) $p['id']]);
            Db::exec('INSERT INTO con_visite_action_evt (plan_id, quand, qui, de_statut, vers_statut, commentaire) VALUES (?,?,?,?,?,?)',
                [(int) $p['id'], $now, $qui, 'propose', $vers, $inclus ? 'envoyé au franchisé avec le rapport' : 'gardé en interne']);
            $inclus ? $ouverts++ : $internes++;
        }
        Db::exec('UPDATE con_visite SET rapport_statut = \'envoye\', rapport_envoye_le = ?, rapport_envoye_par = ?, maj_le = ? WHERE id = ?', [$now, mb_substr($qui, 0, 120), $now, (int) $v['id']]);
    }
    $v = Db::row('SELECT * FROM con_visite WHERE id = ?', [(int) $v['id']]);
    // Le PDF, dans la langue du franchisé ; le courriel, avec les copies.
    $l = suLangue($shop); $plans = suRapportPlans($v, $r); $contenu = suRapportContenu($v);
    if ($contenu['phrase'] === '') { $contenu['phrase'] = suRapportVue($v)['contenu']['phrase']; }
    $pdf = function_exists('rapPdfRendu') ? rapPdfRendu(suRapportHtml($v, $l), ['rapport' => suL('titre', $l), 'magasin' => viMagasins()[$shop]['nom'] ?? $court, 'genere' => date('d/m/Y à H:i'), 'envoye' => date('d/m/Y')]) : null;
    $pieces = $pdf !== null ? [['nom' => 'rapport-visite-' . preg_replace('/[^\w-]/', '', $court) . '-' . $v['prevu_le'] . '.pdf', 'type' => 'application/pdf', 'contenu' => $pdf]] : [];
    $roles = suRoles($v);
    $cc = array_values(array_filter(array_map('suPersonneEmail', array_unique(array_filter(array_merge($roles['copie'], [$roles['responsable']]))))));
    // La phrase sur la pièce jointe ne part que si le PDF a bien été rendu.
    $envoi = suEnvoyerFranchise($shop, 'rapport', ['piece' => $pdf !== null ? suL('piece_pdf', $l) : ''] + suVarsRapport($v, $contenu, $plans, $l), $pieces, $cc);
    $envoi['pdf'] = $pdf !== null; $envoi['le'] = $now;
    $r['envoi'] = $envoi;
    Db::exec('UPDATE con_visite SET rapport = ? WHERE id = ?', [json_encode($r, JSON_UNESCAPED_UNICODE), (int) $v['id']]);
    viNotifier($shop, 'Rapport de visite — ' . $court, 'Visite du ' . date('d/m', strtotime((string) $v['prevu_le'])) . ($ouverts ? ' · ' . $ouverts . ' point(s) à travailler' : ''), 'suivi/?shop=' . rawurlencode($shop) . '&jeton=' . suJeton($shop), 'rapport-' . $v['id']);
    journalAdd($qui, 'Visite', null, 'Rapport de visite ' . ($deja ? 'renvoyé' : 'envoyé') . ' : ' . $court . ' (visite du ' . $v['prevu_le'] . ')'
        . ($deja ? '' : ' · ' . $ouverts . ' point(s) ouvert(s), ' . $internes . ' gardé(s) en interne')
        . ($envoi['envoye'] ? ' · courriel à ' . $envoi['a'] : ' · pas de courriel : ' . $envoi['motif']));
    return ['ok' => true, 'ouverts' => $ouverts, 'internes' => $internes, 'envoi' => $envoi] + suRapportVue($v);
}

/** GET /suivi/rapport/apercu?visite=&langue= — le rapport tel que le PDF le rend. */
function ep_suivi_rapport_apercu(): array
{
    ensureSuivi();
    $v = viVisiteParId((string) ($_GET['visite'] ?? ''));
    if ($v === null) { http_response_code(404); return ['error' => 'visite inconnue']; }
    $l = isset(SU_LANGUES[$_GET['langue'] ?? '']) ? (string) $_GET['langue'] : suLangue((string) $v['shop_id']);
    return ['html' => suRapportHtml($v, $l), 'langue' => $l];
}

/** Le rapport en HTML (PDF et aperçu) : ce que le franchisé reçoit, sans rien d'interne. */
/** Le score d'une visite et celui de la précédente comparable (viScoreVisite) ; null pour un suivi ou une revisite. */
function suScore(array $v): ?array
{
    if (!function_exists('viScoreVisite')) { return null; }
    $a = viScoreVisite($v);
    if ($a === null) { return null; }
    return ['actuel' => $a, 'precedent' => viScorePrecedent($v)];
}

/** Le bloc du score dans le rapport : le chiffre sur 100, sa barre, l'écart à la visite précédente, chaque module. */
function suScoreHtml(?array $sc, string $l, callable $e): string
{
    if ($sc === null || ($sc['actuel']['score'] ?? null) === null) { return ''; }
    $a = $sc['actuel']; $p = $sc['precedent']['score'] ?? null;
    $coul = static fn (int $x): string => $x >= 85 ? '#2d7a3e' : ($x >= 70 ? '#D97706' : '#C0182B');
    $ecart = static function (?int $d): string {
        if ($d === null) { return ''; }
        $c = $d > 0 ? '#2d7a3e' : ($d < 0 ? '#C0182B' : '#888');
        return '<span style="color:' . $c . ';font-weight:700">' . ($d > 0 ? '+' : ($d < 0 ? '−' : '±')) . abs($d) . '</span>';
    };
    $barre = static fn (int $x, string $c, int $h): string => '<div style="background:#EFE9E1;border-radius:4px;height:' . $h . 'px;overflow:hidden"><div style="background:' . $c . ';width:' . max(0, min(100, $x)) . '%;height:' . $h . 'px"></div></div>';
    $avant = [];
    foreach ((array) ($p['modules'] ?? []) as $m) { $avant[$m['id']] = (int) $m['score']; }
    $prec = $p !== null
        ? $e(suL('score_prec', $l)) . ' (' . date('d/m', strtotime((string) $sc['precedent']['le'])) . ') : ' . (int) $p['score'] . ' · ' . $ecart((int) $a['score'] - (int) $p['score'])
        : $e(suL('score_premier', $l));
    $lignes = '';
    foreach ($a['modules'] as $m) {
        $nom = $l === 'nl' && ($m['nom_nl'] ?? '') !== '' ? $m['nom_nl'] : $m['nom'];
        $d = isset($avant[$m['id']]) ? (int) $m['score'] - $avant[$m['id']] : null;
        $lignes .= '<tr><td style="padding:3px 10px 3px 0;white-space:nowrap">' . $e($nom) . '</td><td style="width:100%;padding:3px 0">' . $barre((int) $m['score'], $coul((int) $m['score']), 8) . '</td>'
            . '<td style="padding:3px 0 3px 10px;text-align:right;font-weight:700">' . (int) $m['score'] . '</td><td style="padding:3px 0 3px 8px;text-align:right;font-size:11px;white-space:nowrap">' . $ecart($d) . '</td></tr>';
    }
    return '<div style="margin:0 0 14px;padding:12px 14px;border:1px solid #EAE3DA;border-radius:10px">'
        . '<div style="font-weight:700;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#666;margin:0 0 4px">' . $e(suL('score', $l)) . '</div>'
        . '<div style="margin:0 0 6px"><span style="font-family:Georgia,serif;font-size:34px;color:#8D1D2C">' . (int) $a['score'] . '</span><span style="color:#888"> / 100</span>'
        . '<span style="color:#666;font-size:12px;margin-left:12px">' . $prec . '</span></div>'
        . $barre((int) $a['score'], '#8D1D2C', 8)
        . '<table style="border-collapse:collapse;width:100%;margin-top:10px;font-size:12px">' . $lignes . '</table>'
        . '<div style="color:#888;font-size:10.5px;margin-top:6px">' . $e(suL('score_regle', $l)) . '</div></div>';
}

function suRapportHtml(array $v, string $l): string
{
    $e = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $shop = (string) $v['shop_id']; $r = suJson($v['rapport'] ?? null);
    $c = suRapportContenu($v); $ck = suChecklistBilan($v);
    $plans = array_values(array_filter(suRapportPlans($v, $r), static fn ($p) => $p['inclus'] && $p['statut'] !== 'interne'));
    $photos = array_values(array_filter(suRapportPhotos($v, $r), static fn ($p) => $p['inclus']));
    if ($c['phrase'] === '') { $c['phrase'] = $ck['ok'] . ' / ' . $ck['total'] . ' ' . suL('conformes', $l) . '.'; }
    $racine = realpath(__DIR__ . '/../public') ?: (__DIR__ . '/../public');
    $h = '<div style="font-family:Helvetica,Arial,sans-serif;color:#222;font-size:12.5px;line-height:1.55">'
        . '<div style="font-family:Georgia,serif;font-size:22px;color:#8D1D2C;margin:0 0 2px">' . $e(suL('titre', $l)) . ' — ' . $e(suCourt($shop)) . '</div>'
        . '<div style="color:#666;margin:0 0 14px">' . $e(suL('du', $l) . ' ' . suDateLongue((string) $v['prevu_le'], $l) . ' · ' . suTypeNom((string) (($v['type_code'] ?? '') ?: 'reguliere'), $l) . ' · ' . suL('avec', $l) . ' ' . $v['consultant_nom'])
        . ' · ' . $ck['ok'] . ' / ' . $ck['total'] . ' ' . $e(suL('conformes', $l)) . '</div>'
        . '<p style="font-size:14px;margin:0 0 14px"><b>' . $e($c['phrase']) . '</b></p>';
    $h .= suScoreHtml(suScore($v), $l, $e);
    $sec = static fn (string $t, string $corps): string => '<div style="margin:0 0 12px"><div style="font-weight:700;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#666;margin:0 0 3px">' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '</div>' . $corps . '</div>';
    if ($c['positif'] !== '') { $h .= $sec(suL('positif', $l), '<div>' . nl2br($e($c['positif'])) . '</div>'); }
    $vu = [];
    if ($c['sentiment']) { $vu[] = suL('equipe', $l) . ' ' . $c['sentiment'] . ' / 5'; }
    if ($c['execution'] !== '') { $vu[] = suL($c['execution'], $l); }
    if ($c['clients'] !== '') { $vu[] = suL($c['clients'], $l); }
    if ($vu) { $h .= $sec(suL('vu', $l), '<div>' . $e(implode(' · ', $vu)) . '</div>'); }
    if ($c['causes'] || $c['diagnostic'] !== '') {
        $h .= $sec(suL('probleme', $l), ($c['causes'] ? '<div><b>' . $e(implode(', ', array_map(static fn ($k) => suL('c_' . $k, $l), $c['causes']))) . '</b></div>' : '') . ($c['diagnostic'] !== '' ? '<div>' . nl2br($e($c['diagnostic'])) . '</div>' : ''));
    }
    if ($c['reco'] !== '') { $h .= $sec(suL('reco', $l), '<div>' . nl2br($e($c['reco'])) . '</div>'); }
    // Le CA sous le budget, noté avec le franchisé : les écarts, pourquoi, ses trois pistes et leur échéance.
    $bc = !empty($v['budget_ca']) ? suJson($v['budget_ca']) : [];
    if (($bc['pourquoi'] ?? '') !== '' || array_filter((array) ($bc['pistes'] ?? []))) {
        $ecs = [];
        foreach ((array) ($bc['ecarts'] ?? []) as $k => $x) { $ecs[] = suL('p_' . $k, $l) . ' ' . ($x > 0 ? '+' : ($x < 0 ? '−' : '')) . str_replace('.', ',', (string) abs((float) $x)) . ' %'; }
        $corps = $ecs ? '<div><b style="color:#C0182B">' . $e(implode(' · ', $ecs)) . '</b>' . (!empty($bc['arrete']) ? ' <span style="color:#666">· ' . $e(suL('b_arrete', $l)) . ' ' . date('d/m', strtotime((string) $bc['arrete'])) . '</span>' : '') . '</div>' : '';
        if (($bc['pourquoi'] ?? '') !== '') { $corps .= '<div style="margin-top:4px"><b>' . $e(suL('b_pourquoi', $l)) . '</b> — ' . nl2br($e($bc['pourquoi'])) . '</div>'; }
        $pi = array_values(array_filter(array_map('strval', (array) ($bc['pistes'] ?? [])), static fn (string $p): bool => trim($p) !== ''));
        if ($pi) {
            $corps .= '<div style="margin-top:4px"><b>' . $e(suL('b_pistes', $l)) . '</b>' . (!empty($bc['echeance']) ? ' (' . $e(suL('b_dici', $l)) . ' ' . date('d/m', strtotime((string) $bc['echeance'])) . ')' : '') . '</div><ol style="margin:2px 0 0 18px;padding:0">'
                . implode('', array_map(static fn (string $p): string => '<li>' . htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . '</li>', $pi)) . '</ol>';
        }
        $h .= $sec(suL('budget', $l), $corps);
    }
    $lp = '';
    foreach ($plans as $p) {
        $lp .= '<tr><td style="padding:4px 8px 4px 0;vertical-align:top"><b style="color:' . ($p['priorite'] === 'P0' ? '#C0182B' : '#222') . '">' . $e($p['priorite']) . '</b></td><td style="padding:4px 8px 4px 0">' . $e($p['titre'])
            . ($p['detail'] ? '<div style="color:#666">' . $e($p['detail']) . '</div>' : '') . '</td><td style="padding:4px 0;white-space:nowrap;color:#666">' . ($p['echeance'] ? $e(suL('echeance', $l)) . ' ' . date('d/m', strtotime((string) $p['echeance'])) : '') . '</td></tr>';
    }
    $h .= $sec(suL('points', $l), $lp !== '' ? '<table style="border-collapse:collapse;width:100%">' . $lp . '</table>' : '<div style="color:#666">' . $e(suL('vide_points', $l)) . '</div>');
    if ($photos) {
        $imgs = '';
        foreach (array_slice($photos, 0, 9) as $p) {
            $f = $racine . '/' . $p['chemin'];
            if (!is_file($f)) { continue; }
            $mime = (string) (mime_content_type($f) ?: 'image/jpeg');
            $imgs .= '<img src="data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($f)) . '" style="width:31%;margin:0 1.5% 8px 0;border-radius:6px;vertical-align:top" alt="">';
        }
        if ($imgs !== '') { $h .= $sec(suL('photos', $l), '<div>' . $imgs . '</div>'); }
    }
    // Les deux signatures de fin de visite, côte à côte : le consultant, le franchisé (une visite d'avant le 26/10/2026 n'a que la seconde).
    $sigs = '';
    foreach (['sig_consultant' => 'sig_consultant', 'signature' => 'sig_franchise'] as $genre => $lib) {
        $sig = Db::row('SELECT chemin FROM con_visite_photo WHERE visite_id = ? AND genre = ? ORDER BY id DESC LIMIT 1', [(int) $v['id'], $genre]);
        if (!$sig || !is_file($racine . '/' . $sig['chemin'])) { continue; }
        $qui = $genre === 'sig_consultant' ? (string) $v['consultant_nom'] : suCourt($shop);
        $sigs .= '<td style="padding:0 18px 0 0;vertical-align:top"><div style="color:#666;font-size:11px">' . $e(suL($lib, $l)) . ($qui !== '' ? ' · ' . $e($qui) : '') . '</div>'
            . '<img src="data:image/png;base64,' . base64_encode((string) file_get_contents($racine . '/' . $sig['chemin'])) . '" style="height:70px" alt=""></td>';
    }
    if ($sigs !== '') { $h .= $sec(suL('signatures', $l), '<table style="border-collapse:collapse"><tr>' . $sigs . '</tr></table>'); }
    return $h . '</div>';
}

/** Après PUT /visites/{id} statut terminee : le brouillon du rapport attend au bureau. */
function suApresTerminee(array $v): void
{
    ensureSuivi();
    $v = Db::row('SELECT * FROM con_visite WHERE id = ?', [(int) $v['id']]) ?? $v;
    if (($v['rapport_statut'] ?? null) !== null) { return; }
    Db::exec('UPDATE con_visite SET rapport_statut = \'brouillon\' WHERE id = ?', [(int) $v['id']]);
    $roles = suRoles($v); $court = suCourt((string) $v['shop_id']);
    $d = suRapportDelai(['termine_a' => $v['termine_a'] ?? date('Y-m-d H:i:s')] + $v);
    if ($roles['adapte'] !== '') {
        viNotifier('c:' . $roles['adapte'], 'Rapport à adapter — ' . $court, 'Brouillon de la visite du ' . date('d/m', strtotime((string) $v['prevu_le'])) . ($d ? ' · à envoyer avant le ' . date('d/m H:i', strtotime($d['limite'])) : ''), '#/franchises', 'rapport-' . $v['id']);
    }
}

/** Après un changement de visite : le franchisé prévenu d'une visite annoncée déplacée ou annulée, s'il a reçu l'ordre du jour. */
function suApresChangement(array $avant, array $apres): ?array
{
    if (empty($avant['odj_envoye_le']) || !suAnnoncee($apres)) { return null; }
    $shop = (string) $apres['shop_id']; $l = suLangue($shop);
    if ($apres['statut'] === 'annulee' && $avant['statut'] !== 'annulee') { return suEnvoyerFranchise($shop, 'annulee', suVarsVisite($avant, $l)); }
    if (in_array($apres['statut'], ['planifiee', 'confirmee'], true) && ($avant['prevu_le'] !== $apres['prevu_le'] || $avant['debut_h'] !== $apres['debut_h'])) {
        return suEnvoyerFranchise($shop, 'deplacee', suVarsVisite($apres, $l) + ['date_avant' => suDateLongue((string) $avant['prevu_le'], $l)]);
    }
    return null;
}

/* --- le tableau de bord ---------------------------------------------------------- */

/** L'étape d'une visite dans le circuit : preparer, tablette, adapter, envoye — null si elle n'y est plus. */
function suEtape(array $v): ?string
{
    if ($v['statut'] === 'annulee') { return null; }
    if ($v['statut'] === 'en_cours') { return 'tablette'; }
    if (in_array($v['statut'], ['planifiee', 'confirmee'], true)) { return !empty($v['prep_envoyee_le']) || $v['prevu_le'] < date('Y-m-d') ? 'tablette' : 'preparer'; }
    if ($v['statut'] === 'terminee') {
        $s = $v['rapport_statut'] ?? null;
        if ($s === 'brouillon') { return 'adapter'; }
        if ($s === 'envoye') { return 'envoye'; }
    }
    return null;
}

/** GET /suivi/tableau?consultant=&semaine=0 — les visites dans le circuit, les franchisés, les points et les corrections. */
/**
 * Le scoring du trimestre, gardé : son calcul relit le panel pour tous les magasins (≈ 20 s en production,
 * 24/10/2026), et le tableau de bord, la préparation et la page du franchisé l'ouvraient à chaque lecture.
 * L'horloge des visites le recalcule toutes les 25 minutes (suHorloge) ; une lecture ne paie le calcul que
 * si le cache est vide ou a plus de 2 heures ; périmé, il se relance par /suivi/tableau, la route qui le lit.
 */
function suScoringTous(bool $forcer = false): ?array
{
    if (!function_exists('frScoringTous')) { return null; }
    if (!function_exists('rcServi')) { return frScoringTous(); }
    $r = rcServi('suScoring', static fn (): array => frScoringTous() ?? [], static fn (array $x): int => $x ? 7200 : 0, $forcer, '/suivi/tableau', 10800);
    unset($r['cache']);
    return $r ?: null;
}

/** Le scoring d'un magasin, lu dans le cache (même forme que frScoring). */
function suScoring(string $shop): ?array
{
    $d = suScoringTous();
    foreach ($d['magasins'] ?? [] as $l) {
        if ((string) $l['id'] !== $shop) { continue; }
        return ['trimestre' => $d['trimestre'], 'postes' => $l['postes'], 'total' => $l['total'], 'n' => $l['n'], 'sur' => $l['sur'], 'etoiles' => $l['etoiles'],
            'prec' => $l['prec'], 'delta' => $l['delta'], 'rang' => $l['rang'], 'magasins' => count($d['magasins']), 'noms' => array_map(static fn ($p) => $p['nom'], $d['postes'] ?? [])];
    }
    return null;
}

function ep_suivi_tableau(): array
{
    ensureSuivi();
    @set_time_limit(120);
    $regles = suRegles(); $auj = date('Y-m-d'); $now = time();
    $cons = trim((string) ($_GET['consultant'] ?? ''));
    $sem = max(-8, min(8, (int) ($_GET['semaine'] ?? 0)));
    $lundi = date('Y-m-d', strtotime('monday this week ' . ($sem >= 0 ? '+' : '') . $sem . ' weeks'));
    $dimanche = date('Y-m-d', strtotime($lundi . ' +6 days'));
    $du = date('Y-m-d', strtotime('-45 days')); $au = date('Y-m-d', strtotime('+35 days'));
    $etat = viBoutiquesEtat(null, $du, $au);
    $feux = []; foreach ($etat['boutiques'] as $b) { $feux[(string) $b['id']] = $b; }
    $rows = Db::rows('SELECT * FROM con_visite WHERE prevu_le BETWEEN ? AND ? AND statut <> \'annulee\'' . ($cons !== '' ? ' AND consultant_id = ?' : '') . ' ORDER BY prevu_le, debut_h', $cons !== '' ? [$du, $au, $cons] : [$du, $au]);
    $plansParVisite = [];
    foreach (Db::rows('SELECT visite_id, statut, COUNT(*) n FROM con_visite_action WHERE visite_id IS NOT NULL GROUP BY visite_id, statut') as $x) { $plansParVisite[(int) $x['visite_id']][$x['statut']] = (int) $x['n']; }
    $cartes = ['preparer' => [], 'tablette' => [], 'adapter' => [], 'envoye' => []];
    $debutMois = date('Y-m-01');
    foreach ($rows as $v) {
        $et = suEtape($v);
        if ($et === null) { continue; }
        if ($et === 'envoye' && (string) $v['rapport_envoye_le'] < date('Y-m-d', strtotime('-31 days'))) { continue; }
        if ($et === 'preparer' && $v['prevu_le'] > date('Y-m-d', strtotime('+21 days'))) { continue; }
        $shop = (string) $v['shop_id']; $b = $feux[$shop] ?? null; $prep = suPrep($v);
        $prepDu = date('Y-m-d', strtotime((string) $v['prevu_le'] . ' -' . (int) $regles['delais']['prepJours'] . ' days'));
        $np = $plansParVisite[(int) $v['id']] ?? [];
        $c = ['id' => (int) $v['id'], 'shop' => $shop, 'court' => suCourt($shop), 'feu' => $b['feu'] ?? 'vert', 'type' => (string) (($v['type_code'] ?? '') ?: 'reguliere'),
            'typeNom' => suTypeNom((string) (($v['type_code'] ?? '') ?: 'reguliere'), 'fr'), 'annoncee' => suAnnoncee($v), 'le' => $v['prevu_le'], 'h' => $v['debut_h'],
            'consultant' => (string) $v['consultant_id'], 'consultantNom' => (string) $v['consultant_nom'], 'statut' => $v['statut'],
            'dossier' => ['points' => count($prep['points']), 'blocs' => count(array_filter($prep['blocs'], static fn ($x) => $x === false))], 'prepDu' => $prepDu,
            'prepRetard' => $et === 'preparer' && $prepDu < $auj, 'prepEnvoyeeLe' => $v['prep_envoyee_le'] ?? null, 'odjEnvoyeLe' => $v['odj_envoye_le'] ?? null,
            'nonFaite' => in_array($v['statut'], ['planifiee', 'confirmee'], true) && $v['prevu_le'] < $auj,
            'proposes' => (int) ($np['propose'] ?? 0), 'ouverts' => (int) (($np['ouvert'] ?? 0) + ($np['reprendre'] ?? 0) + ($np['escalade'] ?? 0) + ($np['attente'] ?? 0)),
            'termineLe' => $v['termine_a'], 'delai' => $et === 'adapter' ? suRapportDelai($v) : null,
            'envoyeLe' => $v['rapport_envoye_le'] ?? null, 'luLe' => $v['rapport_lu_le'] ?? null, 'relanceLe' => $v['rapport_relance_le'] ?? null];
        $cartes[$et][] = $c;
    }
    usort($cartes['adapter'], static fn ($a, $b) => strcmp((string) ($a['delai']['limite'] ?? ''), (string) ($b['delai']['limite'] ?? '')));
    usort($cartes['envoye'], static fn ($a, $b) => strcmp((string) $b['envoyeLe'], (string) $a['envoyeLe']));
    // Les points ouverts du réseau, les plus urgents d'abord ; les corrections à valider.
    $plans = array_values(array_filter($etat['plans'], static fn ($p) => in_array($p['statut'], ['ouvert', 'reprendre', 'escalade', 'attente'], true)));
    usort($plans, static fn ($a, $b) => [$a['statut'] === 'escalade' ? 0 : 1, $a['priorite'], -$a['retard'], (string) $a['echeance']] <=> [$b['statut'] === 'escalade' ? 0 : 1, $b['priorite'], -$b['retard'], (string) $b['echeance']]);
    $photos = [];
    $ids = array_values(array_unique(array_merge(array_filter(array_map(static fn ($p) => $p['photo_id'], $plans)))));
    foreach ($ids ? Db::rows('SELECT * FROM con_visite_photo WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) : [] as $ph) { $photos[(int) $ph['id']] = viPhotoLigne($ph); }
    $corrections = [];
    foreach ($plans as &$p) {
        $p['court'] = suCourt($p['shop']);
        $p['visiteLe'] = null;
        if ($p['visite_id']) { $vv = Db::row('SELECT prevu_le, type_code FROM con_visite WHERE id = ?', [$p['visite_id']]); if ($vv) { $p['visiteLe'] = $vv['prevu_le']; $p['visiteType'] = suTypeNom((string) (($vv['type_code'] ?? '') ?: 'reguliere'), 'fr'); } }
        if ($p['statut'] === 'attente') {
            $avant = $p['visite_id'] && $p['ref'] ? Db::row('SELECT * FROM con_visite_photo WHERE visite_id = ? AND point_ref = ? AND genre <> \'correction\' ORDER BY id LIMIT 1', [$p['visite_id'], $p['ref']]) : null;
            $apres = $p['photo_id'] ? ($photos[$p['photo_id']] ?? null) : null;
            if ($apres === null) { $x = Db::row('SELECT * FROM con_visite_photo WHERE plan_id = ? ORDER BY id DESC LIMIT 1', [$p['id']]); $apres = $x ? viPhotoLigne($x) : null; }
            $com = Db::row('SELECT commentaire, quand FROM con_visite_action_evt WHERE plan_id = ? AND vers_statut = \'attente\' ORDER BY id DESC LIMIT 1', [$p['id']]);
            $attenteH = $com ? round(($now - strtotime((string) $com['quand'])) / 3600, 1) : null;
            $corrections[] = $p + ['avant' => $avant ? viPhotoLigne($avant) : null, 'apres' => $apres, 'commentaire' => $com['commentaire'] ?? null, 'recuLe' => $com['quand'] ?? $p['maj_le'],
                'attenteH' => $attenteH, 'horsDelai' => $attenteH !== null && $attenteH > (int) $regles['delais']['validationHeures'], 'valide' => suRoles(['shop_id' => $p['shop'], 'consultant_id' => ''])['correctionsNom']];
        }
    }
    unset($p);
    // Les franchisés : le feu, la dernière visite, le dernier rapport, les points, la prochaine visite, le cadre du mois, le scoring.
    $attendu = function_exists('vcAttendu') ? vcAttendu(date('Y-m')) : [];
    $sc = suScoringTous(); $scPar = [];
    foreach ($sc['magasins'] ?? [] as $l) { $scPar[(string) $l['id']] = $l; }
    $franchises = [];
    foreach ($etat['boutiques'] as $b) {
        $sid = (string) $b['id'];
        $der = Db::row('SELECT * FROM con_visite WHERE shop_id = ? AND statut = \'terminee\' ORDER BY prevu_le DESC, id DESC LIMIT 1', [$sid]);
        $derRap = Db::row('SELECT id, prevu_le, rapport_statut, rapport_envoye_le, rapport_lu_le FROM con_visite WHERE shop_id = ? AND statut = \'terminee\' AND rapport_statut IS NOT NULL ORDER BY prevu_le DESC, id DESC LIMIT 1', [$sid]);
        $pp = array_values(array_filter($plans, static fn ($p) => $p['shop'] === $sid));
        $cad = array_values(array_filter($attendu, static fn ($l) => $l['shop'] === $sid && $l['par'] === 'mois'));
        $s = $scPar[$sid] ?? null; $roles = suMagasinRegle($sid, $regles);
        // Le consultant responsable et la langue des courriels : l'assistant de « Visites » les propose d'office (26/10/2026).
        $lg = suLangue($sid, $roles);
        $franchises[] = ['id' => $sid, 'court' => $b['court'], 'feu' => $b['feu'], 'motifs' => array_slice($b['motifs'], 0, 3), 'due' => $b['due'], 'responsable' => $roles['responsableNom'],
            'responsableId' => $roles['responsable'], 'langue' => $lg, 'langueNom' => SU_LANGUES[$lg] ?? $lg,
            'derniere' => $der ? ['id' => (int) $der['id'], 'le' => $der['prevu_le'], 'typeNom' => suTypeNom((string) (($der['type_code'] ?? '') ?: 'reguliere'), 'fr'), 'checklist' => suChecklistBilan($der)] : null,
            'rapport' => $derRap ? ['id' => (int) $derRap['id'], 'le' => $derRap['prevu_le'], 'statut' => $derRap['rapport_statut'], 'envoyeLe' => $derRap['rapport_envoye_le'], 'luLe' => $derRap['rapport_lu_le']] : null,
            'points' => ['n' => count($pp), 'p0' => count(array_filter($pp, static fn ($p) => $p['priorite'] === 'P0')), 'attente' => count(array_filter($pp, static fn ($p) => $p['statut'] === 'attente')),
                'retard' => count(array_filter($pp, static fn ($p) => $p['retard'] > 0)), 'escalade' => count(array_filter($pp, static fn ($p) => $p['statut'] === 'escalade'))],
            'prochaine' => $b['prochaineVisite'], 'cadre' => ['faites' => array_sum(array_column($cad, 'faites')), 'attendu' => array_sum(array_column($cad, 'attendu')), 'planifiees' => array_sum(array_column($cad, 'planifiees'))],
            'scoring' => $s ? ['total' => $s['total'], 'sur' => $s['sur'], 'rang' => $s['rang']] : null];
    }
    $semaine = array_filter($rows, static fn ($v) => $v['prevu_le'] >= $lundi && $v['prevu_le'] <= $dimanche);
    $envMois = array_filter($rows, static fn ($v) => ($v['rapport_statut'] ?? null) === 'envoye' && (string) $v['rapport_envoye_le'] >= $debutMois);
    $urgent = array_filter($cartes['adapter'], static fn ($c) => $c['delai'] && $c['delai']['resteH'] < 12);
    return ['lu' => date('Y-m-d H:i'), 'aujourdhui' => $auj, 'semaine' => ['du' => $lundi, 'au' => $dimanche, 'decalage' => $sem], 'consultant' => $cons,
        'consultants' => viConsultants(), 'types' => function_exists('vcTypesCourts') ? vcTypesCourts() : [], 'delais' => $regles['delais'], 'cartes' => $cartes,
        'kpis' => ['visitesSemaine' => count($semaine), 'aPreparer' => count($cartes['preparer']), 'surTablette' => count($cartes['tablette']),
            'aAdapter' => count($cartes['adapter']), 'urgent' => count($urgent), 'urgentTxt' => $urgent ? array_values($urgent)[0]['court'] . ' avant ' . date('H:i', strtotime(array_values($urgent)[0]['delai']['limite'])) : null,
            'envoyesMois' => count($envMois), 'lusMois' => count(array_filter($envMois, static fn ($v) => !empty($v['rapport_lu_le']))),
            'points' => count($plans), 'p0' => count(array_filter($plans, static fn ($p) => $p['priorite'] === 'P0')), 'retard' => count(array_filter($plans, static fn ($p) => $p['retard'] > 0)),
            'corrections' => count($corrections)],
        'franchises' => $franchises, 'points' => $plans, 'corrections' => $corrections];
}

/* --- le client mystère : un encodage, par passage ------------------------------- */

/** Une note de rubrique lue « 17 / 20 », « 8,5/10 » ou 17 : ramenée sur 20. */
function suNote20(mixed $v): ?float
{
    if (is_int($v) || is_float($v)) { return (float) $v; }
    $s = str_replace(',', '.', trim((string) $v));
    if (preg_match('/^(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)$/', $s, $m)) { return (float) $m[2] > 0 ? round((float) $m[1] / (float) $m[2] * 20, 1) : null; }
    return is_numeric($s) ? (float) $s : null;
}

function suMspLigne(array $r): array
{
    $l = function_exists('sqMspLigne') ? sqMspLigne($r) : $r;
    $passage = (string) (($r['passage_le'] ?? '') ?: substr((string) ($r['le'] ?? date('Y-m-d')), 0, 10));
    $rub = [];
    foreach (suJson($r['rubriques'] ?? null) as $k => $v) { $rub[(string) $k] = suNote20($v); }
    return $l + ['passage' => $passage, 'rub' => $rub, 'montrer' => !isset($r['montrer']) || (int) $r['montrer'] === 1, 'suites' => suJson($r['suites'] ?? null),
        'sur20' => (float) $r['maximum'] > 0 ? round((float) $r['obtenu'] / (float) $r['maximum'] * 20, 1) : null];
}

function suMspDernier(string $shop): ?array
{
    try {
        ensureSuivi();
        $r = Db::row('SELECT * FROM con_scoring_msp WHERE shop_id = ? ORDER BY COALESCE(passage_le, DATE(le)) DESC, id DESC LIMIT 1', [$shop]);
    } catch (Throwable $e) { return null; }
    return $r ? suMspLigne($r) : null;
}

/** Les rapports client mystère au format de l'application (un par passage, le mois du passage, rubriques sur 20). */
function suMspPourVisites(?string $shop, int $n): array
{
    try { ensureSuivi(); $rows = Db::rows('SELECT * FROM con_scoring_msp' . ($shop !== null ? ' WHERE shop_id = ?' : '') . ' ORDER BY COALESCE(passage_le, DATE(le)) DESC, id DESC', $shop !== null ? [$shop] : []); }
    catch (Throwable $e) { return []; }
    $out = [];
    foreach ($rows as $r) {
        $m = suMspLigne($r); $s = (string) $r['shop_id'];
        if (count($out[$s] ?? []) >= $n) { continue; }
        $out[$s][] = ['id' => (int) $r['id'], 'shop' => $s, 'mois' => substr($m['passage'], 0, 7), 'passage' => $m['passage'], 'total' => $m['sur20'],
            'obtenu' => (float) $r['obtenu'], 'maximum' => (float) $r['maximum'], 'rubriques' => array_filter($m['rub'], static fn ($x) => $x !== null),
            'commentaires' => $r['commentaire'], 'fichier' => $r['fichier'], 'par' => $r['par'], 'le' => $r['le'], 'montrer' => $m['montrer'], 'source' => 'scoring'];
    }
    return $out;
}

/** GET /suivi/msp — les rapports magasin par magasin, ce qui a suivi, ce qu'il faut pour encoder. */
function ep_suivi_msp(): array
{
    ensureSuivi();
    $mags = viMagasins(); $seuil = (float) viSeuils()['mspAlerte']; $regles = suRegles();
    $rows = Db::rows('SELECT * FROM con_scoring_msp ORDER BY COALESCE(passage_le, DATE(le)) DESC, id DESC');
    $parShop = [];
    foreach ($rows as $r) { $parShop[(string) $r['shop_id']][] = suMspLigne($r); }
    $magasins = []; $notes = []; $notesPrec = []; $alertes = []; $maxima = [];
    $corriges = 0; $releves = 0;
    foreach ($mags as $sid => $m) {
        $sid = (string) $sid;
        $liste = $parShop[$sid] ?? [];
        $der = $liste[0] ?? null; $prec = $liste[1] ?? null;
        $plans = []; $suiteVisite = null;
        if ($der) {
            $notes[] = $der['sur20']; $maxima[] = (float) $der['maximum'];
            if ($prec) { $notesPrec[] = $prec['sur20']; }
            foreach ($der['rub'] as $nom => $v20) { if ($v20 !== null && $v20 < $seuil) { $alertes[] = ['shop' => $sid, 'court' => $m['court'], 'rubrique' => $nom, 'note' => $v20]; } }
            foreach (Db::rows('SELECT * FROM con_visite_action WHERE shop_id = ? AND point_ref = ? ORDER BY id', [$sid, 'msp' . $der['id']]) as $p) { $plans[] = viPlanLigne($p); }
            // La visite qui a suivi le passage : ses points du module client mystère.
            $vs = Db::row('SELECT * FROM con_visite WHERE shop_id = ? AND statut = \'terminee\' AND prevu_le > ? ORDER BY prevu_le LIMIT 1', [$sid, $der['passage']]);
            if ($vs) {
                $pts = Db::rows('SELECT etat, note FROM con_visite_point WHERE visite_id = ? AND (module = \'msp\' OR point_ref LIKE \'msp%\')', [(int) $vs['id']]);
                $ok = count(array_filter($pts, static fn ($p) => $p['etat'] === 'ok' || ($p['note'] !== null && (int) $p['note'] >= 4)));
                $suiteVisite = ['id' => (int) $vs['id'], 'le' => $vs['prevu_le'], 'points' => count($pts), 'corriges' => $ok];
                $releves += count($pts); $corriges += $ok;
            }
        }
        $proch = Db::row('SELECT id, prevu_le, debut_h, consultant_nom, type_code FROM con_visite WHERE shop_id = ? AND statut IN (\'planifiee\', \'confirmee\') AND prevu_le >= ? ORDER BY prevu_le LIMIT 1', [$sid, date('Y-m-d')]);
        $magasins[] = ['id' => $sid, 'court' => $m['court'], 'responsable' => suMagasinRegle($sid, $regles)['responsableNom'], 'dernier' => $der, 'precedent' => $prec,
            'historique' => array_slice($liste, 0, 8), 'plans' => $plans, 'suiteVisite' => $suiteVisite,
            'prochaine' => $proch ? ['id' => (int) $proch['id'], 'le' => $proch['prevu_le'], 'h' => $proch['debut_h'], 'consultant' => $proch['consultant_nom'], 'typeNom' => suTypeNom((string) (($proch['type_code'] ?? '') ?: 'reguliere'), 'fr')] : null];
    }
    usort($magasins, static fn ($a, $b) => strcmp((string) ($b['dernier']['passage'] ?? ''), (string) ($a['dernier']['passage'] ?? '')));
    $memeMax = $maxima && count(array_unique($maxima)) === 1 ? $maxima[0] : null;
    $moy = $notes ? array_sum($notes) / count($notes) : null;
    $plansOuverts = Db::rows('SELECT priorite FROM con_visite_action WHERE point_ref LIKE \'msp%\' AND statut IN (\'ouvert\', \'reprendre\', \'escalade\', \'attente\')');
    $rubriques = SU_RUBRIQUES_MSP;
    foreach ($rows as $r) { foreach (array_keys(suJson($r['rubriques'])) as $k) { if (!in_array($k, $rubriques, true)) { $rubriques[] = (string) $k; } } }
    return ['lu' => date('Y-m-d H:i'), 'seuil' => $seuil, 'delais' => $regles['delais'], 'magasins' => $magasins, 'rubriques' => array_slice($rubriques, 0, 8),
        'trimestres' => function_exists('sqTrimestres') ? sqTrimestres() : [],
        'kpis' => ['moyenne' => $moy !== null ? ($memeMax ? round($moy / 20 * $memeMax, 1) : round($moy, 1)) : null, 'sur' => $memeMax ?? 20,
            'moyennePrec' => $notesPrec ? ($memeMax ? round(array_sum($notesPrec) / count($notesPrec) / 20 * $memeMax, 1) : round(array_sum($notesPrec) / count($notesPrec), 1)) : null,
            'sur5' => $moy !== null ? round($moy / 4, 1) : null, 'alertes' => $alertes, 'points' => count($plansOuverts),
            'p0' => count(array_filter($plansOuverts, static fn ($p) => $p['priorite'] === 'P0')), 'releves' => $releves, 'corriges' => $corriges]];
}

/**
 * POST /suivi/msp — un passage : { shop, passage_le, obtenu, maximum, rubriques: {nom: note sur 20}, commentaire,
 * fichier (data:application/pdf…), montrer, suites: { points: [{titre, priorite}], controle: bool, tache: bool }, par }.
 * Le trimestre vient de la date du passage ; réencoder le même trimestre remplace.
 */
function wr_suivi_msp(): array
{
    ensureSuivi();
    $b = body();
    $shop = trim((string) ($b['shop'] ?? ''));
    if (!isset(viMagasins()[$shop])) { http_response_code(422); return ['error' => 'magasin inconnu']; }
    $passage = viDate($b['passage_le'] ?? null) ?? date('Y-m-d');
    $r = suMspEnregistrer($shop, $passage, $b['obtenu'] ?? null, $b['maximum'] ?? null, is_array($b['rubriques'] ?? null) ? $b['rubriques'] : [],
        (string) ($b['commentaire'] ?? ''), $b['fichier'] ?? null, !isset($b['montrer']) || (bool) $b['montrer'], viQui($b + ['qui' => 'Bureau']));
    if (!empty($r['error'])) { return $r; }
    $msp = $r['msp'];
    $s = is_array($b['suites'] ?? null) ? $b['suites'] : [];
    $suites = ['points' => [], 'controle' => null, 'tache' => null];
    $delais = suRegles()['delais'];
    foreach (is_array($s['points'] ?? null) ? $s['points'] : [] as $p) {
        if (!is_array($p) || trim((string) ($p['titre'] ?? '')) === '') { continue; }
        $prio = in_array($p['priorite'] ?? '', VI_PRIORITES, true) ? $p['priorite'] : 'P2';
        $j = (int) ($delais[strtolower($prio)] ?? 7);
        $suites['points'][] = suPlanCreer($shop, (string) $p['titre'], 'Client mystère du ' . date('d/m/Y', strtotime($passage)), $prio, date('Y-m-d', strtotime('+' . $j . ' days')), 'msp' . $msp['id'], viQui($b + ['qui' => 'Bureau']));
    }
    if ($suites['points']) {
        $p0 = count(array_filter($suites['points'], static fn ($p) => $p['priorite'] === 'P0'));
        viNotifier($shop, 'Plan d’action — ' . suCourt($shop), count($suites['points']) . ' point(s) après le client mystère' . ($p0 ? ' dont ' . $p0 . ' P0' : ''), 'suivi/?shop=' . rawurlencode($shop) . '&jeton=' . suJeton($shop), 'plan-' . $shop);
    }
    if (!empty($s['controle'])) {
        $v = Db::row('SELECT * FROM con_visite WHERE shop_id = ? AND statut IN (\'planifiee\', \'confirmee\') AND prevu_le >= ? ORDER BY prevu_le LIMIT 1', [$shop, date('Y-m-d')]);
        if ($v) {
            $prep = suPrep($v);
            $bas = array_filter($msp['rub'], static fn ($x) => $x !== null && $x < 16);
            $prep['points'][] = ['ref' => 'bur' . (count($prep['points']) + 1), 'libelle' => mb_substr('Client mystère du ' . date('d/m', strtotime($passage)) . ' : contrôler ' . ($bas ? implode(', ', array_map(static fn ($k, $x) => mb_strtolower((string) $k) . ' (' . $x . '/20)', array_keys($bas), $bas)) : 'les relevés du rapport'), 0, 190), 'odj' => false, 'source' => 'msp'];
            Db::exec('UPDATE con_visite SET prep = ?, maj_le = ? WHERE id = ?', [json_encode($prep, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), (int) $v['id']]);
            $suites['controle'] = ['visite' => (int) $v['id'], 'le' => $v['prevu_le'], 'consultant' => $v['consultant_nom']];
        } else { $suites['controle'] = ['visite' => null, 'motif' => 'aucune visite planifiée pour ce magasin']; }
    }
    if (!empty($s['tache']) && function_exists('ensureVisitesCadre')) {
        $resp = suMagasinRegle($shop)['responsable'];
        if ($resp !== '') {
            $now = date('Y-m-d H:i:s');
            Db::exec('INSERT INTO con_consultant_tache (client_id, consultant_id, consultant_nom, shop_id, visite_id, source, quand, delai, titre, detail, echeance, statut, cree_par, cree_le, maj_le) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                ['msp' . $msp['id'] . ':visite', $resp, viConsultantNom($resp), $shop, null, 'perso', null, null, 'Planifier une visite « Client mystère » à ' . suCourt($shop), 'Après le passage du ' . date('d/m/Y', strtotime($passage)), date('Y-m-d', strtotime('+7 days')), 'a_faire', viQui($b + ['qui' => 'Bureau']), $now, $now]);
            $suites['tache'] = ['consultant' => viConsultantNom($resp)];
        } else { $suites['tache'] = ['motif' => 'pas de consultant responsable au cadre']; }
    }
    Db::exec('UPDATE con_scoring_msp SET suites = ? WHERE id = ?', [json_encode(['points' => count($suites['points']), 'controle' => $suites['controle'], 'tache' => $suites['tache'], 'le' => date('Y-m-d H:i')], JSON_UNESCAPED_UNICODE), (int) $msp['id']]);
    return ['ok' => true, 'msp' => $msp, 'suites' => $suites];
}

/** Le passage enregistré dans la seule table du client mystère (celle du scoring). */
function suMspEnregistrer(string $shop, string $passage, mixed $obtenu, mixed $max, array $rubriques, string $commentaire, mixed $fichier, bool $montrer, string $par): array
{
    $tri = sqTrimestre(date('Y', strtotime($passage)) . '-T' . (int) ceil((int) date('n', strtotime($passage)) / 3));
    $rub = [];
    foreach ($rubriques as $k => $v) {
        $k = mb_substr(trim((string) $k), 0, 60); $n = suNote20($v);
        if ($k !== '' && $n !== null) { $rub[$k] = rtrim(rtrim(number_format(max(0, min(20, $n)), 1, '.', ''), '0'), '.') . ' / 20'; }
    }
    $o = is_numeric($obtenu) ? round((float) $obtenu, 2) : null;
    $m = is_numeric($max) ? round((float) $max, 2) : null;
    if ($o === null && $rub) { $o = round(array_sum(array_map('suNote20', $rub)), 2); $m = 20.0 * count($rub); }
    if ($o === null || $m === null || $m <= 0 || $o < 0 || $o > $m) { http_response_code(422); return ['error' => 'note attendue : obtenu et maximum, 0 ≤ obtenu ≤ maximum']; }
    $chemin = null;
    if (is_string($fichier) && preg_match('#^data:application/pdf;base64,(.+)$#s', $fichier, $mm)) {
        $bin = base64_decode($mm[1], true);
        if ($bin === false || !str_starts_with($bin, '%PDF')) { http_response_code(422); return ['error' => 'le fichier n’est pas un PDF']; }
        if (strlen($bin) > 8 * 1024 * 1024) { http_response_code(422); return ['error' => 'PDF de plus de 8 Mo']; }
        $dossier = __DIR__ . '/../public/uploads/scoring/msp';
        if (!is_dir($dossier) && !@mkdir($dossier, 0775, true) && !is_dir($dossier)) { http_response_code(500); return ['error' => 'dossier uploads/scoring inaccessible']; }
        $nom = preg_replace('/[^\w-]/', '', $shop) . '-' . $tri['cle'] . '.pdf';
        if (@file_put_contents($dossier . '/' . $nom, $bin) === false) { http_response_code(500); return ['error' => 'PDF non enregistré (droits du dossier uploads)']; }
        $chemin = 'uploads/scoring/msp/' . $nom;
    }
    Db::exec('INSERT INTO con_scoring_msp (shop_id, trimestre, obtenu, maximum, rubriques, commentaire, fichier, par, le, passage_le, montrer) VALUES (?,?,?,?,?,?,?,?,?,?,?)
              ON DUPLICATE KEY UPDATE obtenu = VALUES(obtenu), maximum = VALUES(maximum), rubriques = VALUES(rubriques), commentaire = VALUES(commentaire),
              fichier = COALESCE(VALUES(fichier), fichier), par = VALUES(par), le = VALUES(le), passage_le = VALUES(passage_le), montrer = VALUES(montrer)',
        [$shop, $tri['cle'], $o, $m, json_encode($rub, JSON_UNESCAPED_UNICODE), mb_substr(trim($commentaire), 0, 2000) ?: null, $chemin, mb_substr($par, 0, 120) ?: null, date('Y-m-d H:i:s'), $passage, $montrer ? 1 : 0]);
    $r = Db::row('SELECT * FROM con_scoring_msp WHERE shop_id = ? AND trimestre = ?', [$shop, $tri['cle']]);
    journalAdd($par ?: 'Bureau', 'Scoring', null, 'Client mystère du ' . date('d/m/Y', strtotime($passage)) . ' — ' . suCourt($shop) . ' : ' . $o . ' / ' . $m);
    return ['ok' => true, 'msp' => suMspLigne($r)];
}

/** Un point à travailler ouvert par le bureau (client mystère, suites) — l'événement de création avec. */
function suPlanCreer(string $shop, string $titre, string $detail, string $prio, ?string $echeance, ?string $ref, string $qui): array
{
    ensureVisites();
    $now = date('Y-m-d H:i:s');
    Db::exec('INSERT INTO con_visite_action (client_id, shop_id, visite_id, point_ref, titre, detail, priorite, assigne, echeance, statut, cree_par, cree_le, maj_le) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [null, $shop, null, $ref !== null ? mb_substr($ref, 0, 60) : null, mb_substr(trim($titre), 0, 190), mb_substr($detail, 0, 2000) ?: null, in_array($prio, VI_PRIORITES, true) ? $prio : 'P1', 'franchise', $echeance, 'ouvert', mb_substr($qui, 0, 120), $now, $now]);
    $id = (int) Db::pdo()->lastInsertId();
    Db::exec('INSERT INTO con_visite_action_evt (plan_id, quand, qui, de_statut, vers_statut, commentaire) VALUES (?,?,?,?,?,?)', [$id, $now, $qui, null, 'ouvert', 'créé']);
    return viPlanLigne(Db::row('SELECT * FROM con_visite_action WHERE id = ?', [$id]));
}

/* --- la page du franchisé (publique, par jeton) ------------------------------------ */

/**
 * Les routes que le franchisé appelle depuis sa page, sans session du cockpit :
 * le jeton du magasin est leur clé. Appelée par le contrôleur frontal avant
 * l'authentification ; null pour toute autre adresse.
 */
function suRoutePublique(string $method, string $path): ?array
{
    if ($method === 'GET' && $path === '/suivi/franchise') { return ep_suivi_franchise(); }
    if ($method === 'POST' && $path === '/suivi/franchise/correction') { return wr_suivi_franchise_correction(); }
    if ($method === 'POST' && $path === '/suivi/franchise/lu') { return wr_suivi_franchise_lu(); }
    return null;
}

function suFranchiseShop(array $src): ?string
{
    $shop = trim((string) ($src['shop'] ?? ''));
    return suJetonOk($shop, trim((string) ($src['jeton'] ?? ''))) ? $shop : null;
}

/** GET /suivi/franchise?shop=&jeton= — ce que voit le franchisé : ses points, ses rapports, sa prochaine visite annoncée. */
function ep_suivi_franchise(): array
{
    ensureSuivi();
    $shop = suFranchiseShop($_GET);
    if ($shop === null) { http_response_code(403); return ['error' => 'lien', 'message' => 'Ce lien n’est pas (ou plus) valable. Demandez le lien de votre page à votre consultant.']; }
    $regles = suRegles(); $voit = $regles['voit']; $mag = viMagasins()[$shop]; $l = suLangue($shop);
    $out = ['magasin' => ['id' => $shop, 'court' => $mag['court'], 'nom' => $mag['nom']], 'langue' => $l, 'lu' => date('Y-m-d H:i'), 'voit' => $voit, 'aujourdhui' => date('Y-m-d')];
    if ($voit['points']) {
        $pts = []; $valides = [];
        foreach (Db::rows('SELECT a.*, v.prevu_le AS v_le, v.consultant_nom AS v_cons FROM con_visite_action a LEFT JOIN con_visite v ON v.id = a.visite_id WHERE a.shop_id = ? AND a.statut NOT IN (\'propose\', \'interne\') AND (a.statut <> \'ferme\' OR a.maj_le >= ?) ORDER BY FIELD(a.priorite, \'P0\', \'P1\', \'P2\'), a.echeance, a.id', [$shop, date('Y-m-01 00:00:00')]) as $p) {
            $x = viPlanLigne($p);
            if (in_array($p['statut'], ['valide', 'ferme'], true)) { $valides[] = ['titre' => $x['titre'], 'le' => substr((string) $p['maj_le'], 0, 10)]; continue; }
            $ph = $p['photo_id'] ? Db::row('SELECT * FROM con_visite_photo WHERE id = ?', [(int) $p['photo_id']]) : Db::row('SELECT * FROM con_visite_photo WHERE plan_id = ? ORDER BY id DESC LIMIT 1', [(int) $p['id']]);
            $pts[] = ['id' => $x['id'], 'titre' => $x['titre'], 'detail' => $x['detail'], 'priorite' => $x['priorite'], 'echeance' => $x['echeance'], 'retard' => $x['retard'], 'statut' => $x['statut'],
                'retour' => $p['statut'] === 'reprendre' ? $x['retour'] : null, 'visiteLe' => $p['v_le'], 'consultant' => $p['v_cons'], 'source' => str_starts_with((string) $p['point_ref'], 'msp') ? 'msp' : 'visite',
                'photo' => $ph ? ['chemin' => $ph['chemin'], 'le' => $ph['prise_a']] : null];
        }
        $out['points'] = $pts; $out['valides'] = $valides;
    }
    if ($voit['rapports']) {
        $raps = [];
        foreach (Db::rows('SELECT * FROM con_visite WHERE shop_id = ? AND statut = \'terminee\' AND (rapport_statut = \'envoye\' OR (rapport_statut IS NULL AND prevu_le >= ?)) ORDER BY prevu_le DESC, id DESC LIMIT 12', [$shop, date('Y-m-d', strtotime('-180 days'))]) as $v) {
            $ck = suChecklistBilan($v); $ancien = ($v['rapport_statut'] ?? null) === null;
            $r = suJson($v['rapport'] ?? null); $c = suRapportContenu($v);
            $plans = $ancien ? [] : array_values(array_filter(suRapportPlans($v, $r), static fn ($p) => $p['inclus'] && $p['statut'] !== 'interne'));
            $photos = $ancien ? [] : array_map(static fn ($p) => ['chemin' => $p['chemin'], 'genre' => $p['genre']], array_values(array_filter(suRapportPhotos($v, $r), static fn ($p) => $p['inclus'])));
            $raps[] = ['id' => (int) $v['id'], 'le' => $v['prevu_le'], 'type' => suTypeNom((string) (($v['type_code'] ?? '') ?: 'reguliere'), $l), 'consultant' => $v['consultant_nom'],
                'checklist' => ['ok' => $ck['ok'], 'total' => $ck['total']], 'ancien' => $ancien, 'phrase' => $ancien ? '' : ($c['phrase'] ?: suRapportVue($v)['contenu']['phrase']),
                'positif' => $ancien ? '' : $c['positif'], 'reco' => $c['reco'], 'causes' => $ancien ? [] : array_map(static fn ($k) => suL('c_' . $k, $l), $c['causes']),
                'diagnostic' => $ancien ? '' : $c['diagnostic'], 'points' => array_map(static fn ($p) => ['titre' => $p['titre'], 'priorite' => $p['priorite'], 'echeance' => $p['echeance']], $plans),
                'photos' => $photos, 'envoyeLe' => $v['rapport_envoye_le'] ?? null, 'luLe' => $v['rapport_lu_le'] ?? null];
        }
        $out['rapports'] = $raps;
    }
    if ($voit['prochaine']) {
        $out['prochaine'] = null;
        foreach (Db::rows('SELECT * FROM con_visite WHERE shop_id = ? AND statut IN (\'planifiee\', \'confirmee\') AND prevu_le >= ? ORDER BY prevu_le, debut_h LIMIT 6', [$shop, date('Y-m-d')]) as $v) {
            if (!suAnnoncee($v)) { continue; }
            $out['prochaine'] = ['le' => $v['prevu_le'], 'h' => $v['debut_h'], 'duree' => (int) $v['duree_min'], 'type' => suTypeNom((string) (($v['type_code'] ?? '') ?: 'reguliere'), $l), 'consultant' => $v['consultant_nom'],
                'sujets' => array_values(array_map(static fn ($p) => $p['libelle'], array_filter(suPrep($v)['points'], static fn ($p) => $p['odj'])))];
            break;
        }
    }
    if ($voit['msp']) {
        $out['msp'] = array_map(static fn ($m) => ['passage' => $m['passage'], 'obtenu' => (float) $m['obtenu'], 'maximum' => (float) $m['maximum'], 'rubriques' => array_filter($m['rub'], static fn ($x) => $x !== null), 'fichier' => $m['fichier']],
            array_values(array_filter(array_map('suMspLigne', Db::rows('SELECT * FROM con_scoring_msp WHERE shop_id = ? ORDER BY COALESCE(passage_le, DATE(le)) DESC LIMIT 4', [$shop])), static fn ($m) => $m['montrer'])));
    }
    if ($voit['scoring']) {
        $s = suScoring($shop);
        $out['scoring'] = $s ? ['trimestre' => $s['trimestre']['court'] ?? '', 'total' => $s['total'], 'sur' => $s['sur'], 'etoiles' => $s['etoiles'] ?? null,
            'rang' => $s['rang'] ?? null, 'magasins' => $s['magasins'] ?? null, 'prec' => isset($s['prec']['total']) ? $s['prec']['total'] : null,
            'postes' => array_map(static fn ($p) => ['v' => $p['v'] ?? null], (array) $s['postes'])] : null;
    }
    // Ce que l'ancienne page « Mon suivi » (dashboard/suivi.html) montrait, repris ici : le volet de chaque jour et le journal.
    if ($voit['journalier']) { $out['journalier'] = suJournalier($shop); }
    if ($voit['journal']) { $out['journal'] = suJournalFranchise($shop, $voit); }
    return $out;
}

/**
 * Le volet « chaque jour » de la page du franchisé : ce que les données disent sans aller sur place — les tâches
 * sur 30 jours et les notes du consultant, les infractions, les invendus, les réclamations fournisseur, les
 * objectifs, les remarques à l'équipe, la note Google, le CA de la semaine, le comptoir. Les mêmes lectures que la
 * fiche franchisé (src/franchises.php), gardées cinq minutes : la page est publique, chaque ouverture ne relit pas
 * le panel.
 */
function suJournalier(string $shop): array
{
    if (!function_exists('frTachesJours')) { return ['indispo' => 'module des franchisés absent']; }
    $calc = static fn (): array => ['lu' => date('Y-m-d H:i'), 'taches' => frTachesJours($shop), 'tachesJour' => frTachesJour($shop), 'revues' => frRevues($shop),
        'invendus' => frInvendus($shop), 'reclamations' => frReclamations($shop), 'objectifs' => frObjectifs($shop), 'remarques' => frRemarques($shop),
        'google' => function_exists('viGoogle') ? (viGoogle()[$shop] ?? null) : null, 'ca' => function_exists('viCa') ? (viCa()[$shop] ?? null) : null,
        'comptoir' => frConformite($shop)];
    if (!function_exists('rcServi')) { return $calc(); }
    $r = rcServi('suFrJ:' . $shop, $calc, static fn (array $x): int => 300, false, '/suivi/franchise', 900);
    unset($r['cache']);
    return $r;
}

/**
 * Le journal des 60 jours (frJournal), sans ce que le franchisé ne doit pas voir : les points proposés ou gardés
 * en interne (et le passage d'un point par ces états), les visites dont le rapport est encore un brouillon, les
 * passages client mystère non montrés.
 */
function suJournalFranchise(string $shop, array $voit): array
{
    if (!function_exists('frJournal')) { return []; }
    $plans = []; $visites = []; $msp = [];
    foreach (Db::rows('SELECT id FROM con_visite_action WHERE shop_id = ? AND statut IN (\'propose\', \'interne\')', [$shop]) as $r) { $plans[(int) $r['id']] = true; }
    foreach (Db::rows('SELECT id FROM con_visite WHERE shop_id = ? AND rapport_statut = \'brouillon\'', [$shop]) as $r) { $visites[(int) $r['id']] = true; }
    foreach (Db::rows('SELECT id, montrer FROM con_scoring_msp WHERE shop_id = ?', [$shop]) as $r) { if (!$voit['msp'] || (isset($r['montrer']) && (int) $r['montrer'] !== 1)) { $msp[(int) $r['id']] = true; } }
    $out = [];
    foreach (frJournal($shop) as $e) {
        $id = (int) ($e['id'] ?? 0);
        if (($e['genre'] === 'plan' && (isset($plans[$id]) || in_array($e['vers'] ?? null, ['propose', 'interne'], true))) || ($e['genre'] === 'visite' && isset($visites[$id])) || ($e['genre'] === 'msp' && isset($msp[$id]))) { continue; }
        $out[] = ['le' => $e['le'], 'volet' => $e['volet'], 'genre' => $e['genre'], 'texte' => $e['texte']];
    }
    return $out;
}

/** POST /suivi/franchise/correction — { shop, jeton, plan, data (photo), commentaire } : la photo part, le point attend la validation. */
function wr_suivi_franchise_correction(): array
{
    ensureSuivi();
    $b = body();
    $shop = suFranchiseShop($b);
    if ($shop === null) { http_response_code(403); return ['error' => 'lien']; }
    $p = Db::row('SELECT * FROM con_visite_action WHERE id = ? AND shop_id = ?', [(int) ($b['plan'] ?? 0), $shop]);
    if ($p === null) { http_response_code(404); return ['error' => 'point inconnu']; }
    if (!in_array($p['statut'], ['ouvert', 'reprendre', 'escalade'], true)) { http_response_code(409); return ['error' => 'ce point n’attend pas de correction (' . $p['statut'] . ')']; }
    $com = mb_substr(trim((string) ($b['commentaire'] ?? '')), 0, 1000);
    $photoId = null;
    if (!empty($b['data'])) {
        $r = viPhotoEnregistrer(['client_id' => mb_substr((string) ($b['client_id'] ?? ''), 0, 40) ?: null, 'shop' => $shop, 'plan_id' => (int) $p['id'], 'ref' => $p['point_ref'], 'genre' => 'correction', 'data' => (string) $b['data'], 'par' => 'franchise']);
        if (empty($r['ok'])) { return $r; }
        $photoId = (int) $r['photo']['id'];
    } elseif ($com === '') { http_response_code(422); return ['error' => 'une photo ou un mot']; }
    $now = date('Y-m-d H:i:s'); $qui = 'Franchisé ' . suCourt($shop);
    Db::exec('UPDATE con_visite_action SET statut = \'attente\', photo_id = COALESCE(?, photo_id), maj_le = ? WHERE id = ?', [$photoId, $now, (int) $p['id']]);
    Db::exec('INSERT INTO con_visite_action_evt (plan_id, quand, qui, de_statut, vers_statut, commentaire, photo_id) VALUES (?,?,?,?,?,?,?)', [(int) $p['id'], $now, $qui, $p['statut'], 'attente', $com !== '' ? $com : null, $photoId]);
    viNotifier('admin', 'Correction reçue — ' . suCourt($shop), $p['titre'] . ' : photo à valider', '#/franchises', 'valider-' . $p['id']);
    journalAdd($qui, 'Plan d’action', null, suCourt($shop) . ' : « ' . $p['titre'] . ' » ' . $p['statut'] . ' → attente (correction envoyée depuis sa page)');
    return ['ok' => true, 'plan' => viPlanLigne(Db::row('SELECT * FROM con_visite_action WHERE id = ?', [(int) $p['id']]))];
}

/** POST /suivi/franchise/lu — { shop, jeton, visite } : le rapport est ouvert. */
function wr_suivi_franchise_lu(): array
{
    ensureSuivi();
    $b = body();
    $shop = suFranchiseShop($b);
    if ($shop === null) { http_response_code(403); return ['error' => 'lien']; }
    $v = Db::row('SELECT id, rapport_lu_le FROM con_visite WHERE id = ? AND shop_id = ? AND rapport_statut = \'envoye\'', [(int) ($b['visite'] ?? 0), $shop]);
    if ($v === null) { return ['ok' => true, 'deja' => true]; }
    if ($v['rapport_lu_le'] === null) { Db::exec('UPDATE con_visite SET rapport_lu_le = ? WHERE id = ?', [date('Y-m-d H:i:s'), (int) $v['id']]); }
    return ['ok' => true];
}

/* --- le lien de la page, et l'aperçu dans le cockpit ------------------------------ */

/** GET /suivi/lien?shop= — le lien personnel du franchisé, son adresse, sa langue, le courriel qui l'envoie. */
function ep_suivi_lien(): array
{
    ensureSuivi();
    $shop = trim((string) ($_GET['shop'] ?? ''));
    if (!isset(viMagasins()[$shop])) { http_response_code(404); return ['error' => 'magasin inconnu']; }
    $l = suLangue($shop); $ad = suAdresse($shop);
    $r = suRendre(suModele('lien', $l), ['magasin' => suCourt($shop), 'lien' => suLien($shop)], $l);
    return ['shop' => $shop, 'url' => suLien($shop), 'chemin' => 'suivi/?shop=' . rawurlencode($shop) . '&jeton=' . suJeton($shop), 'adresse' => $ad['email'], 'source' => $ad['source'],
        'langue' => $l, 'langueNom' => SU_LANGUES[$l], 'sujet' => $r['sujet'], 'texte' => $r['texte'], 'voit' => suRegles()['voit'], 'smtp' => class_exists('Smtp') && Smtp::configured()];
}

/** POST /suivi/lien/envoyer — { shop } : le lien part au franchisé, dans sa langue. */
function wr_suivi_lien_envoyer(): array
{
    ensureSuivi();
    $b = body(); $shop = trim((string) ($b['shop'] ?? ''));
    if (!isset(viMagasins()[$shop])) { http_response_code(404); return ['error' => 'magasin inconnu']; }
    $r = suEnvoyerFranchise($shop, 'lien', []);
    if ($r['envoye']) { journalAdd(viQui($b + ['qui' => 'Bureau']), 'Suivi', null, 'Lien de la page de suivi envoyé à ' . suCourt($shop) . ' (' . $r['a'] . ')'); }
    return ['ok' => $r['envoye'], 'envoi' => $r];
}

/* --- qui fait quoi : lecture et écriture --------------------------------------------- */

/** GET /suivi/regles — les règles, les magasins, les personnes, les modèles, et ce que les délais donnent ce mois-ci. */
function ep_suivi_regles(): array
{
    ensureSuivi();
    $regles = suRegles(); $mags = viMagasins();
    $types = suTypesParCode();
    $attendu = function_exists('vcAttendu') ? vcAttendu(date('Y-m')) : [];
    $magasins = [];
    foreach ($mags as $sid => $m) {
        $sid = (string) $sid; $mr = suMagasinRegle($sid, $regles); $ad = suAdresse($sid, $mr);
        $cad = [];
        foreach (function_exists('vcCadreDe') ? vcCadreDe($sid) : [] as $l) { $cad[] = ($types[$l['type']]['nom'] ?? $l['type']) . ' ' . $l['nb'] . ' / ' . ($l['par'] === 'mois' ? 'mois' : 'trim.') . ($l['auto'] ? ' (fréquence)' : ''); }
        $magasins[] = ['id' => $sid, 'court' => $m['court'], 'nom' => $m['nom'], 'regle' => $mr, 'adresse' => $ad, 'langueDefaut' => suLangueDefaut($sid), 'langue' => suLangue($sid, $mr), 'cadre' => $cad];
    }
    // Ce que les délais donnent : l'envoi des rapports et la validation des corrections sur 60 jours.
    $env = Db::rows('SELECT termine_a, rapport_envoye_le FROM con_visite WHERE rapport_statut = \'envoye\' AND rapport_envoye_le >= ? AND termine_a IS NOT NULL', [date('Y-m-d', strtotime('-60 days'))]);
    $hs = array_map(static fn ($r) => (strtotime((string) $r['rapport_envoye_le']) - strtotime((string) $r['termine_a'])) / 3600, $env);
    $horsDelai = count(array_filter($hs, static fn ($h) => $h > (int) $regles['delais']['rapportHeures']));
    $val = Db::rows('SELECT e1.quand recu, MIN(e2.quand) traite FROM con_visite_action_evt e1 JOIN con_visite_action_evt e2 ON e2.plan_id = e1.plan_id AND e2.quand >= e1.quand AND e2.vers_statut IN (\'valide\', \'reprendre\')
                     WHERE e1.vers_statut = \'attente\' AND e1.quand >= ? GROUP BY e1.id, e1.quand', [date('Y-m-d', strtotime('-60 days'))]);
    $hv = array_map(static fn ($r) => (strtotime((string) $r['traite']) - strtotime((string) $r['recu'])) / 3600, $val);
    $faites = array_sum(array_map(static fn ($l) => $l['par'] === 'mois' ? $l['faites'] : 0, $attendu));
    $att = array_sum(array_map(static fn ($l) => $l['par'] === 'mois' ? $l['attendu'] : 0, $attendu));
    $pla = array_sum(array_map(static fn ($l) => $l['par'] === 'mois' ? $l['planifiees'] : 0, $attendu));
    $couverts = count(array_filter($magasins, static fn ($m) => $m['regle']['responsable'] !== ''));
    return ['regles' => $regles, 'magasins' => $magasins, 'personnes' => suPersonnes($regles), 'consultants' => viConsultants(), 'droits' => SU_DROITS,
        'langues' => SU_LANGUES, 'modeles' => suModeles(), 'smtp' => class_exists('Smtp') && Smtp::configured(),
        'types' => array_values(array_map(static fn ($t) => ['code' => $t['code'], 'nom' => $t['nom'], 'annoncee' => !array_key_exists('annoncee', $t) || (bool) $t['annoncee'], 'actif' => $t['actif']], $types)),
        'kpis' => ['magasins' => count($magasins), 'couverts' => $couverts, 'cadre' => ['faites' => $faites, 'attendu' => $att, 'planifiees' => $pla],
            'envoiMoyenH' => $hs ? round(array_sum($hs) / count($hs), 1) : null, 'envois' => count($hs), 'horsDelai' => $horsDelai,
            'validationMoyenneH' => $hv ? round(array_sum($hv) / count($hv), 1) : null, 'validations' => count($hv)]];
}

/** PUT /suivi/regles — { magasins, droits, admins, delais, voit, modeles: {code: {langue: {sujet, corps}}}, types: {code: annoncee} }. */
function wr_suivi_regles(): array
{
    ensureSuivi();
    $b = body(); $cur = suRegles(); $mags = viMagasins();
    $ids = array_merge(array_map(static fn ($c) => $c['id'], viConsultants()));
    $s = setting('suiviRegles', []); $s = is_array($s) ? $s : [];
    if (isset($b['admins']) && is_array($b['admins'])) {
        $ad = [];
        foreach ($b['admins'] as $a) {
            if (!is_array($a) || trim((string) ($a['nom'] ?? '')) === '') { continue; }
            $id = preg_replace('/[^\w-]/', '', (string) ($a['id'] ?? '')) ?: ('a' . substr(md5((string) $a['nom'] . microtime(true)), 0, 6));
            $ad[] = ['id' => mb_substr($id, 0, 20), 'nom' => mb_substr(trim((string) $a['nom']), 0, 120), 'email' => filter_var($a['email'] ?? '', FILTER_VALIDATE_EMAIL) ? (string) $a['email'] : null, 'role' => mb_substr(trim((string) ($a['role'] ?? '')), 0, 60)];
        }
        $s['admins'] = $ad;
    }
    $ids = array_merge($ids, array_map(static fn ($a) => $a['id'], $s['admins'] ?? $cur['admins']));
    $okPers = static fn (string $x): bool => in_array($x, $ids, true);
    if (isset($b['magasins']) && is_array($b['magasins'])) {
        $out = is_array($s['magasins'] ?? null) ? $s['magasins'] : [];
        foreach ($b['magasins'] as $sid => $m) {
            $sid = (string) $sid;
            if (!isset($mags[$sid]) || !is_array($m)) { continue; }
            $prep = (string) ($m['prepare'] ?? ''); $ada = (string) ($m['adapte'] ?? 'visite'); $env = (string) ($m['envoie'] ?? 'visite'); $cor = (string) ($m['corrections'] ?? 'admin');
            $email = trim((string) ($m['email'] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(422); return ['error' => 'adresse invalide pour ' . $mags[$sid]['court'] . ' : ' . $email]; }
            $out[$sid] = ['prepare' => $prep === '' || $okPers($prep) ? $prep : '', 'adapte' => $ada === 'visite' || $ada === 'responsable' || $okPers($ada) ? $ada : 'visite',
                'envoie' => $env === 'visite' || $env === 'responsable' || $okPers($env) ? $env : 'visite', 'corrections' => in_array($cor, ['admin', 'responsable'], true) || $okPers($cor) ? $cor : 'admin',
                'copie' => array_values(array_filter(array_map('strval', is_array($m['copie'] ?? null) ? $m['copie'] : []), $okPers)), 'email' => $email,
                'langue' => in_array($m['langue'] ?? '', array_keys(SU_LANGUES), true) ? (string) $m['langue'] : ''];
        }
        $s['magasins'] = $out;
    }
    if (isset($b['droits']) && is_array($b['droits'])) {
        $out = [];
        foreach ($b['droits'] as $pid => $d) {
            if (!$okPers((string) $pid) || !is_array($d)) { continue; }
            foreach (SU_DROITS as $k) { if (array_key_exists($k, $d)) { $out[(string) $pid][$k] = (bool) $d[$k]; } }
        }
        $s['droits'] = $out;
    }
    if (isset($b['delais']) && is_array($b['delais'])) {
        $d = suReglesDefaut()['delais']; $out = array_merge($d, is_array($s['delais'] ?? null) ? $s['delais'] : []);
        foreach ($d as $k => $v) {
            if (!array_key_exists($k, $b['delais'])) { continue; }
            $x = $b['delais'][$k];
            $out[$k] = is_bool($v) ? (bool) $x : (is_int($v) ? max(0, min($k === 'rapportHeures' || $k === 'validationHeures' ? 720 : 90, (int) $x)) : (in_array($x, ['rappel', 'tel_quel'], true) ? $x : $v));
        }
        $s['delais'] = $out;
        if (array_key_exists('escaladeJours', $b['delais'])) {
            $se = viSeuils(); $se['escaladeJours'] = max(0, min(30, (int) $b['delais']['escaladeJours']));
            viSettingSet('visitesSeuils', $se);
        }
    }
    if (isset($b['voit']) && is_array($b['voit'])) {
        $out = suReglesDefaut()['voit'];
        foreach ($out as $k => $v) { $out[$k] = array_key_exists($k, $b['voit']) ? (bool) $b['voit'][$k] : ($cur['voit'][$k] ?? $v); }
        $s['voit'] = $out;
    }
    viSettingSet('suiviRegles', $s);
    if (isset($b['modeles']) && is_array($b['modeles'])) {
        $def = suModelesDefaut(); $reg = setting('suiviModeles', []); $reg = is_array($reg) ? $reg : [];
        foreach ($b['modeles'] as $code => $parL) {
            if (!isset($def[$code]) || !is_array($parL)) { continue; }
            foreach ($parL as $l => $x) {
                if (!isset(SU_LANGUES[$l]) || !is_array($x)) { continue; }
                $sujet = mb_substr(trim((string) ($x['sujet'] ?? '')), 0, 190); $corps = mb_substr(trim(str_replace("\r", '', (string) ($x['corps'] ?? ''))), 0, 6000);
                $livre = $def[$code]['textes'][$l];
                if ($sujet === '' || $corps === '' || ($sujet === $livre['sujet'] && $corps === $livre['corps']) || !empty($x['reset'])) { unset($reg[$code][$l]); }
                else { $reg[$code][$l] = ['sujet' => $sujet, 'corps' => $corps]; }
            }
            if (($reg[$code] ?? []) === []) { unset($reg[$code]); }
        }
        viSettingSet('suiviModeles', $reg);
    }
    if (isset($b['types']) && is_array($b['types']) && function_exists('vcTypes')) {
        $regT = setting('visitesTypes', null);
        $liste = is_array($regT) && $regT ? $regT : array_map(static fn ($t) => array_intersect_key($t, array_flip(['code', 'nom', 'duree', 'profil', 'checklist', 'taches', 'actif', 'dynamique', 'annoncee'])), array_values(vcTypesDefaut()));
        foreach ($liste as &$t) { if (is_array($t) && array_key_exists((string) ($t['code'] ?? ''), $b['types'])) { $t['annoncee'] = (bool) $b['types'][$t['code']]; } }
        unset($t);
        $codes = array_map(static fn ($t) => (string) ($t['code'] ?? ''), $liste);
        foreach ($b['types'] as $code => $an) {
            if (in_array((string) $code, $codes, true)) { continue; }
            $d = vcTypesDefaut()[(string) $code] ?? null;
            if ($d !== null) { $d['annoncee'] = (bool) $an; if ($d['code'] === 'reguliere') { $d['checklist'] = null; } $liste[] = $d; }
        }
        viSettingSet('visitesTypes', $liste);
        suTypesParCode(true);
    }
    journalAdd(viQui($b + ['qui' => 'Admin']), 'Suivi', null, 'Qui fait quoi : règles du suivi des franchisés enregistrées');
    return ['ok' => true] + ep_suivi_regles();
}

/** POST /suivi/modeles/apercu — { code, langue, sujet?, corps?, shop? } : le modèle rendu avec des valeurs d'exemple. */
function wr_suivi_modeles_apercu(): array
{
    ensureSuivi();
    $b = body(); $code = (string) ($b['code'] ?? ''); $l = isset(SU_LANGUES[$b['langue'] ?? '']) ? (string) $b['langue'] : 'fr';
    if (!in_array($code, SU_MODELES, true)) { http_response_code(422); return ['error' => 'modèle inconnu']; }
    $m = suModele($code, $l);
    if (isset($b['sujet'])) { $m['sujet'] = (string) $b['sujet']; }
    if (isset($b['corps'])) { $m['corps'] = (string) $b['corps']; }
    $shop = isset(viMagasins()[(string) ($b['shop'] ?? '')]) ? (string) $b['shop'] : null;
    return suRendre($m, suVarsExemple($l, $shop), $l) + ['langue' => $l];
}

/** POST /suivi/modeles/essai — { code, langue, a } : le modèle en vigueur, avec des valeurs d'exemple, à une adresse d'essai. */
function wr_suivi_modeles_essai(): array
{
    ensureSuivi();
    $b = body(); $code = (string) ($b['code'] ?? ''); $l = isset(SU_LANGUES[$b['langue'] ?? '']) ? (string) $b['langue'] : 'fr'; $a = trim((string) ($b['a'] ?? ''));
    if (!in_array($code, SU_MODELES, true)) { http_response_code(422); return ['error' => 'modèle inconnu']; }
    if (!filter_var($a, FILTER_VALIDATE_EMAIL)) { http_response_code(422); return ['error' => 'adresse d’essai invalide']; }
    if (!class_exists('Smtp') || !Smtp::configured()) { return ['ok' => false, 'error' => 'SMTP non configuré (Paramètres)']; }
    $r = suRendre(suModele($code, $l), suVarsExemple($l), $l);
    $ok = Smtp::envoyer($a, '[Essai] ' . $r['sujet'], $r['html']);
    return ['ok' => $ok, 'a' => $a, 'motif' => $ok ? null : (Smtp::$lastError ?? 'envoi refusé')];
}

/* --- l'horloge ---------------------------------------------------------------------- */

/**
 * Appelée par l'horloge des visites (toutes les 5 minutes) : l'ordre du jour des
 * visites annoncées à J−n, le rappel d'un rapport pas adapté dans le délai (ou son
 * envoi tel quel, selon la règle), la relance d'un rapport envoyé mais pas lu.
 */
function suHorloge(array &$fait): void
{
    ensureSuivi();
    $regles = suRegles(); $d = $regles['delais']; $auj = date('Y-m-d'); $now = time();
    $fait += ['odj' => 0, 'rapportsRappel' => 0, 'rapportsAuto' => 0, 'relances' => 0, 'scoring' => 0];
    // Le scoring gardé est recalculé ici, hors de toute lecture d'écran, dès qu'il a plus de 25 minutes.
    $sc = setting('suScoring');
    if (!is_array($sc) || !isset($sc['le']) || $now - (int) $sc['le'] > 1500) {
        try { suScoringTous(true); $fait['scoring'] = 1; } catch (Throwable $e) { /* le prochain passage réessaiera */ }
    }
    if ($d['odjAuto']) {
        $jusque = date('Y-m-d', strtotime('+' . (int) $d['odjJours'] . ' days'));
        foreach (Db::rows('SELECT * FROM con_visite WHERE statut IN (\'planifiee\', \'confirmee\') AND odj_envoye_le IS NULL AND prevu_le BETWEEN ? AND ?', [$auj, $jusque]) as $v) {
            if (!suAnnoncee($v)) { continue; }
            $r = suOdjEnvoyer($v);
            if ($r['envoye']) { $fait['odj']++; }
        }
    }
    foreach (Db::rows('SELECT * FROM con_visite WHERE statut = \'terminee\' AND rapport_statut = \'brouillon\' AND termine_a IS NOT NULL') as $v) {
        $limite = strtotime((string) $v['termine_a']) + (int) $d['rapportHeures'] * 3600;
        if ($now < $limite) { continue; }
        if ($d['rapportTard'] === 'tel_quel') { suRapportEnvoyer($v, 'horloge (délai dépassé, envoyé tel quel)'); $fait['rapportsAuto']++; continue; }
        $r = suJson($v['rapport'] ?? null); $roles = suRoles($v); $court = suCourt((string) $v['shop_id']);
        if (empty($r['rappel1'])) {
            viNotifier('c:' . $roles['adapte'], 'Rapport en retard — ' . $court, 'Le délai de ' . $d['rapportHeures'] . ' h est dépassé : le franchisé attend son rapport.', '#/franchises', 'rapport-' . $v['id']);
            $r['rappel1'] = date('Y-m-d H:i');
        } elseif (empty($r['rappel2']) && $now > $limite + 24 * 3600) {
            viNotifier($roles['responsable'] !== '' && $roles['responsable'] !== $roles['adapte'] ? 'c:' . $roles['responsable'] : 'admin', 'Rapport en retard de 24 h — ' . $court, 'Visite du ' . date('d/m', strtotime((string) $v['prevu_le'])) . ' par ' . $v['consultant_nom'], '#/franchises', 'rapport2-' . $v['id']);
            $r['rappel2'] = date('Y-m-d H:i');
        } else { continue; }
        Db::exec('UPDATE con_visite SET rapport = ? WHERE id = ?', [json_encode($r, JSON_UNESCAPED_UNICODE), (int) $v['id']]);
        $fait['rapportsRappel']++;
    }
    if ((int) $d['relanceLuJours'] > 0) {
        $avant = date('Y-m-d H:i:s', $now - (int) $d['relanceLuJours'] * 86400);
        foreach (Db::rows('SELECT * FROM con_visite WHERE rapport_statut = \'envoye\' AND rapport_lu_le IS NULL AND rapport_relance_le IS NULL AND rapport_envoye_le < ?', [$avant]) as $v) {
            $shop = (string) $v['shop_id']; $l = suLangue($shop);
            $r = suEnvoyerFranchise($shop, 'relance', ['date' => suDateLongue((string) $v['prevu_le'], $l), 'type' => suTypeNom((string) (($v['type_code'] ?? '') ?: 'reguliere'), $l)]);
            Db::exec('UPDATE con_visite SET rapport_relance_le = ? WHERE id = ?', [date('Y-m-d H:i:s'), (int) $v['id']]);
            if ($r['envoye']) { $fait['relances']++; }
        }
    }
}
