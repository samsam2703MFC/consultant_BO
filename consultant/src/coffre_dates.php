<?php
declare(strict_types=1);

/*
 * LES GRANDES DATES DU COFFRE-FORT (22/11/2026, Sam : « rajouter dans le coffre-fort un bouton avec les grandes dates
 * (triennats des contrats de bail et autres) ; faire un assistant lorsque j'importe un document »).
 *
 * Une grande date oblige la boutique à agir ou change ses droits : la fin d'un triennat du bail commercial et le dernier
 * jour pour donner congé, la fenêtre de la demande de renouvellement, la fin du bail, la fin du contrat de franchise,
 * l'échéance d'une assurance, d'une autorisation… Elles se rangent par boutique (un coffre par boutique), avec le
 * document d'où elles viennent.
 *
 *   con_coffre_date   boutique, document (facultatif), sorte, titre, le, fin (une fenêtre : du … au …), note, source
 *                     (assistant | calcul | manuel), qui, rôle, quand
 * Les échéances posées sur les documents du coffre (con_doc.echeance) s'y ajoutent d'office, en lecture seule.
 *
 * L'ASSISTANT : à l'import d'un document (ou à la demande, sur un document du coffre), le modèle le lit (PDF, photo,
 * Word) et PROPOSE ses grandes dates, chacune avec l'extrait qui la justifie ; rien ne s'enregistre sans la confirmation
 * de la personne. Pour un bail commercial, le modèle relève la prise de cours, la durée et le préavis ; les triennats, le
 * dernier jour du congé et la fenêtre du renouvellement se CALCULENT ici (cofBailDates), pas par le modèle.
 * Règle générale des baux commerciaux : 9 ans au moins ; le preneur peut mettre fin au bail à l'expiration de chaque
 * triennat, moyennant un préavis de 6 mois (recommandé ou exploit d'huissier) ; il demande le renouvellement entre le
 * 18e et le 15e mois avant la fin. Le bail et sa région peuvent prévoir autre chose : la note de chaque date le rappelle.
 *
 * Routes (préfixe /coffre/magasin : écran de magasin, ACCES_FRANCHISE ; le siège y passe avec ?shop=) :
 *   GET    /coffre/magasin/dates?shop=                    les grandes dates (rangées, et les échéances des documents)
 *   POST   /coffre/magasin/dates {shop, dates:[{sorte, titre, le, fin?, note?, docId?, source?}],
 *                                 echeanceDoc?:{docId, le}, titreDoc?:{docId, titre}, pieceDoc?:{docId, piece}, depuis?:'cockpit'}   ranger (après
 *                                 l'assistant, ou à la main) ; `depuis` ne compte que sans session (accès ouvert)
 *   DELETE /coffre/magasin/dates?shop=&id=                retirer une date rangée
 *   POST   /coffre/magasin/assistant {shop, id}           l'assistant lit le document n° id et propose ses grandes dates
 */

const COF_DATE_SORTES = [
    'bail_preavis' => 'Dernier jour pour donner congé (bail)',
    'bail_triennat' => 'Fin d’un triennat du bail',
    'bail_renouvellement' => 'Demande de renouvellement du bail',
    'bail_fin' => 'Fin du bail',
    'bail_debut' => 'Prise de cours du bail',
    'bail_indexation' => 'Indexation ou révision du loyer',
    'contrat_fin' => 'Fin du contrat de franchise',
    'contrat_preavis' => 'Préavis du contrat',
    'assurance' => 'Échéance d’assurance',
    'autorisation' => 'Autorisation, permis, contrôle',
    'echeance' => 'Échéance d’un document',
    'autre' => 'Autre grande date',
];
/** Les sortes que cofBailDates calcule : celles du modèle sont remplacées par le calcul quand le bail est lu. */
const COF_DATE_BAIL_CALCUL = ['bail_preavis', 'bail_triennat', 'bail_renouvellement', 'bail_fin', 'bail_debut'];
const COF_DATE_BIENTOT_J = 120;              // « bientôt » : dans les quatre mois
const COF_ASSISTANT_MAX_OCTETS = 20 * 1048576; // au-delà, le document ne part pas au modèle (32 Mo par requête, base64 compris)
const COF_GENRES = ['bail_commercial' => 'Bail commercial', 'contrat_franchise' => 'Contrat de franchise', 'avenant' => 'Avenant',
    'assurance' => 'Police d’assurance', 'autorisation' => 'Autorisation ou permis', 'fourniture' => 'Contrat de fourniture ou de service',
    'autre' => 'Autre document'];

function cofDatesTable(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    cofTable();
    Db::exec('CREATE TABLE IF NOT EXISTS con_coffre_date ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY,'
        . 'shop INT NOT NULL,'
        . 'doc_id INT NULL,'
        . "sorte VARCHAR(30) NOT NULL DEFAULT 'autre',"
        . "titre VARCHAR(190) NOT NULL DEFAULT '',"
        . 'le DATE NOT NULL,'
        . 'fin DATE NULL,'
        . 'note VARCHAR(500) NULL,'
        . "source VARCHAR(12) NOT NULL DEFAULT 'manuel',"  // assistant | calcul | manuel
        . "qui VARCHAR(120) NOT NULL DEFAULT '',"
        . "role VARCHAR(12) NOT NULL DEFAULT '',"          // franchise | siege
        . 'cree_le DATETIME NOT NULL,'
        . 'KEY idx_cofd_shop (shop, le),'
        . 'KEY idx_cofd_doc (doc_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/* --- les dates -------------------------------------------------------------------------------------------------- */

/** Une date AAAA-MM-JJ valide (1990-2100), sinon null. */
function cofDateOk(mixed $d): ?string
{
    $d = trim((string) $d);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) { return null; }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) $m[1] >= 1990 && (int) $m[1] <= 2100 ? $d : null;
}

/** $n mois plus tard (ou plus tôt) ; un 31 devient le dernier jour d'un mois plus court (pas de débordement). */
function cofMois(string $d, int $n): string
{
    [$y, $m, $j] = array_map('intval', explode('-', $d));
    $t = $y * 12 + ($m - 1) + $n;
    $y2 = intdiv($t, 12); $m2 = $t % 12 + 1;
    return sprintf('%04d-%02d-%02d', $y2, $m2, min($j, (int) date('t', mktime(0, 0, 0, $m2, 1, $y2))));
}

function cofJour(string $d, int $n): string
{
    return (new DateTimeImmutable($d))->modify(($n >= 0 ? '+' : '') . $n . ' day')->format('Y-m-d');
}

function cofDateFr(string $d): string
{
    return substr($d, 8, 2) . '/' . substr($d, 5, 2) . '/' . substr($d, 0, 4);
}

/**
 * Les grandes dates d'un bail commercial, calculées depuis sa prise de cours : pour chaque triennat, le dernier jour
 * pour donner congé (préavis de $preavisMois mois) et sa fin ; la fenêtre de la demande de renouvellement (du 18e au
 * 15e mois avant la fin) ; la fin du bail. Rend [{sorte, titre, le, fin, note}], par date.
 */
function cofBailDates(string $debut, int $ans = 9, int $preavisMois = 6): array
{
    $ans = max(1, min(99, $ans));
    $preavisMois = max(1, min(24, $preavisMois));
    $base = 'Calculé depuis la prise de cours du ' . cofDateFr($debut) . ' (' . $ans . ' ans) ; règle générale des baux commerciaux, à vérifier dans le bail.';
    $apres = cofMois($debut, 12 * $ans);         // le lendemain de la fin du bail
    $out = [['sorte' => 'bail_debut', 'titre' => 'Prise de cours du bail', 'le' => $debut, 'fin' => null, 'note' => 'Durée : ' . $ans . ' ans.']];
    for ($k = 3, $n = 1; $k < $ans; $k += 3, $n++) {
        $exp = cofMois($debut, 12 * $k);           // le lendemain de la fin du triennat
        $finTri = cofJour($exp, -1);
        $rang = $n === 1 ? '1er' : $n . 'e';
        $out[] = ['sorte' => 'bail_preavis', 'titre' => 'Dernier jour pour donner congé — ' . $rang . ' triennat', 'le' => cofJour(cofMois($exp, -$preavisMois), -1), 'fin' => null,
            'note' => 'Un congé de ' . $preavisMois . ' mois (recommandé ou exploit d’huissier), donné au plus tard ce jour, met fin au bail le ' . cofDateFr($finTri) . '. ' . $base];
        $out[] = ['sorte' => 'bail_triennat', 'titre' => 'Fin du ' . $rang . ' triennat', 'le' => $finTri, 'fin' => null, 'note' => $base];
    }
    if ($ans >= 3) {
        $out[] = ['sorte' => 'bail_renouvellement', 'titre' => 'Demande de renouvellement du bail', 'le' => cofMois($apres, -18), 'fin' => cofMois($apres, -15),
            'note' => 'Le preneur demande le renouvellement entre le 18e et le 15e mois avant la fin du bail (recommandé ou exploit d’huissier) ; sans demande, le bail prend fin à son terme. ' . $base];
    }
    $out[] = ['sorte' => 'bail_fin', 'titre' => 'Fin du bail', 'le' => cofJour($apres, -1), 'fin' => null, 'note' => $base];
    usort($out, static fn ($a, $b) => strcmp($a['le'], $b['le']));
    return $out;
}

/** Siège, ou accès ouvert : tout document de la boutique, toute date. Sinon (un magasin) : son coffre, ses dates. */
function cofPeutTout(): bool
{
    if (function_exists('accesMode') && accesMode() === 'ouvert') { return true; }
    $id = function_exists('accesIdentite') ? accesIdentite() : null;
    return is_array($id) && ($id['role'] ?? '') === 'siege';
}

/** Un document que cet appel peut lire : le siège, tout document de la boutique ; un magasin, ceux de son coffre. */
function cofDocLisible(int $shop, int $id): ?array
{
    if ($shop <= 0 || $id <= 0) { return null; }
    cofTable();
    return cofPeutTout() ? Db::row('SELECT * FROM con_doc WHERE id = ? AND shop = ?', [$id, $shop]) : cofDocDuCoffre($shop, $id);
}

/** L'état d'une date : passee, encours (dans sa fenêtre), bientot (≤ COF_DATE_BIENTOT_J jours), avenir. */
function cofDateEtat(string $le, ?string $fin, string $auj): array
{
    $j = (int) floor((strtotime($le) - strtotime($auj)) / 86400);
    if (($fin ?? $le) < $auj) { return ['cle' => 'passee', 'jours' => $j]; }
    if ($fin !== null && $le <= $auj) { return ['cle' => 'encours', 'jours' => (int) floor((strtotime($fin) - strtotime($auj)) / 86400)]; }
    return ['cle' => $j <= COF_DATE_BIENTOT_J ? 'bientot' : 'avenir', 'jours' => $j];
}

/**
 * Les grandes dates d'une boutique, par date : celles rangées (con_coffre_date) et, d'office, les échéances des documents
 * du coffre qu'aucune date rangée ne reprend. Chaque ligne : {id|null, sorte, sorteLib, titre, le, fin, note, source,
 * docId, docTitre, docCoffre, par, role, etat, modifiable}.
 */
function cofDatesListe(int $shop): array
{
    cofDatesTable();
    $auj = date('Y-m-d');
    $tout = cofPeutTout();
    $docs = [];
    $lignes = [];
    foreach (Db::rows('SELECT * FROM con_doc WHERE shop = ?', [$shop]) as $d) {
        $docs[(int) $d['id']] = $d;
        if (function_exists('docLigne')) { $l = docLigne($d); $lignes[] = ['id' => (int) $d['id'], 'piece' => $l['piece'] ?? null, 'date' => $l['date'] ?? substr((string) ($l['deposeLe'] ?? ''), 0, 10)]; }
    }
    // L'échéance d'un document remplacé par un plus récent de la même pièce ne compte plus (26/11/2026 : l'ancienne
    // attestation restait la « prochaine grande date » après un renouvellement).
    $remplaces = function_exists('cofRemplaces') ? cofRemplaces($lignes) : [];
    $out = []; $reprises = [];
    foreach (Db::rows('SELECT * FROM con_coffre_date WHERE shop = ? ORDER BY le, id', [$shop]) as $r) {
        $doc = $r['doc_id'] !== null ? ($docs[(int) $r['doc_id']] ?? null) : null;
        $fin = $r['fin'] !== null ? (string) $r['fin'] : null;
        if ($doc !== null) { $reprises[(int) $r['doc_id']][] = (string) $r['le']; if ($fin !== null) { $reprises[(int) $r['doc_id']][] = $fin; } }
        $out[] = ['id' => (int) $r['id'], 'sorte' => (string) $r['sorte'], 'sorteLib' => COF_DATE_SORTES[(string) $r['sorte']] ?? COF_DATE_SORTES['autre'],
            'titre' => (string) $r['titre'], 'le' => (string) $r['le'], 'fin' => $fin, 'note' => $r['note'] !== null ? (string) $r['note'] : null,
            'source' => (string) $r['source'], 'docId' => $doc !== null ? (int) $r['doc_id'] : null, 'docTitre' => $doc !== null ? (string) $doc['titre'] : null,
            'docCoffre' => $doc !== null && !empty($doc['visible_franchise']), 'par' => (string) $r['qui'], 'role' => (string) $r['role'],
            'etat' => cofDateEtat((string) $r['le'], $fin, $auj), 'modifiable' => $tout || (string) $r['role'] === 'franchise'];
    }
    foreach ($docs as $id => $d) {
        $ech = cofDateOk($d['echeance'] ?? null);
        if ($ech === null || empty($d['visible_franchise']) || in_array($ech, $reprises[$id] ?? [], true) || isset($remplaces[$id])) { continue; }
        $out[] = ['id' => null, 'sorte' => $d['sorte'] === 'contrat_franchise' ? 'contrat_fin' : 'echeance', 'sorteLib' => $d['sorte'] === 'contrat_franchise' ? COF_DATE_SORTES['contrat_fin'] : COF_DATE_SORTES['echeance'],
            'titre' => 'Échéance — ' . (string) $d['titre'], 'le' => $ech, 'fin' => null, 'note' => 'L’échéance posée sur le document.', 'source' => 'document',
            'docId' => $id, 'docTitre' => (string) $d['titre'], 'docCoffre' => true, 'par' => '', 'role' => '', 'etat' => cofDateEtat($ech, null, $auj), 'modifiable' => false];
    }
    usort($out, static fn ($a, $b) => strcmp($a['le'], $b['le']) ?: strcmp((string) $a['fin'], (string) $b['fin']));
    return $out;
}

/** Le résumé des grandes dates pour les repères du coffre : la prochaine, et combien tombent bientôt. */
function cofDatesResume(int $shop): array
{
    try {
        $l = array_values(array_filter(cofDatesListe($shop), static fn ($d) => $d['etat']['cle'] !== 'passee'));
        $p = $l[0] ?? null;
        return ['n' => count($l), 'bientot' => count(array_filter($l, static fn ($d) => in_array($d['etat']['cle'], ['bientot', 'encours'], true))),
            'prochaine' => $p !== null ? ['titre' => $p['titre'], 'le' => $p['le'], 'fin' => $p['fin'], 'etat' => $p['etat'], 'sorteLib' => $p['sorteLib']] : null];
    } catch (Throwable $e) { return ['n' => 0, 'bientot' => 0, 'prochaine' => null]; }
}

/** GET /coffre/magasin/dates?shop= */
function ep_coffre_dates(): array
{
    $shop = (int) ($_GET['shop'] ?? 0);
    if (!cofShopOk($shop)) { http_response_code($shop > 0 ? 403 : 400); return ['error' => $shop > 0 ? 'ce magasin n’est pas sur votre session' : 'shop requis']; }
    return ['shop' => (string) $shop, 'aujourdhui' => date('Y-m-d'), 'bientotJours' => COF_DATE_BIENTOT_J,
        'sortes' => array_map(static fn ($k, $v) => ['v' => $k, 'nom' => $v], array_keys(COF_DATE_SORTES), COF_DATE_SORTES),
        'dates' => cofDatesListe($shop)];
}

/** POST /coffre/magasin/dates — ranger des grandes dates (celles que l'assistant a proposées et que l'on a gardées, ou à la main). */
function wr_coffre_dates(): array
{
    $b = body();
    $shop = (int) ($b['shop'] ?? 0);
    if (!cofShopOk($shop)) { http_response_code($shop > 0 ? 403 : 400); return ['error' => $shop > 0 ? 'ce magasin n’est pas sur votre session' : 'shop requis']; }
    cofDatesTable();
    // Qui range : la session d'abord ; sans session (accès ouvert), le cockpit le dit (`depuis: cockpit`), sinon le magasin.
    $qui = cofQui(($b['depuis'] ?? '') === 'cockpit' ? 'siege' : 'franchise', $shop);
    $n = 0; $refus = [];
    foreach (array_slice((array) ($b['dates'] ?? []), 0, 30) as $i => $d) {
        if (!is_array($d)) { continue; }
        $le = cofDateOk($d['le'] ?? null);
        $titre = trim(mb_substr((string) ($d['titre'] ?? ''), 0, 190));
        if ($le === null || $titre === '') { $refus[] = ($titre ?: 'date ' . ($i + 1)) . ' (' . ($le === null ? 'date invalide' : 'titre vide') . ')'; continue; }
        $fin = cofDateOk($d['fin'] ?? null);
        if ($fin !== null && $fin < $le) { $fin = null; }
        $sorte = isset(COF_DATE_SORTES[(string) ($d['sorte'] ?? '')]) ? (string) $d['sorte'] : 'autre';
        $docId = (int) ($d['docId'] ?? 0);
        if ($docId > 0 && cofDocLisible($shop, $docId) === null) { $docId = 0; }
        $source = in_array($d['source'] ?? '', ['assistant', 'calcul'], true) ? (string) $d['source'] : 'manuel';
        $note = trim(mb_substr((string) ($d['note'] ?? ''), 0, 500)) ?: null;
        // La même date (même document, même sorte, même jour) ne se range pas deux fois.
        if (Db::row('SELECT id FROM con_coffre_date WHERE shop = ? AND sorte = ? AND le = ? AND ' . ($docId > 0 ? 'doc_id = ?' : 'doc_id IS NULL'),
            array_merge([$shop, $sorte, $le], $docId > 0 ? [$docId] : [])) !== null) { continue; }
        Db::exec('INSERT INTO con_coffre_date (shop, doc_id, sorte, titre, le, fin, note, source, qui, role, cree_le) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$shop, $docId > 0 ? $docId : null, $sorte, $titre, $le, $fin, $note, $source, $qui['nom'], $qui['role'], date('Y-m-d H:i:s')]);
        $n++;
    }
    // L'assistant propose aussi l'échéance du document lui-même, et un titre lisible quand il porte le nom du fichier.
    $maj = [];
    $e = is_array($b['echeanceDoc'] ?? null) ? $b['echeanceDoc'] : null;
    if ($e !== null && ($le = cofDateOk($e['le'] ?? null)) !== null && cofDocLisible($shop, (int) ($e['docId'] ?? 0)) !== null) {
        Db::exec('UPDATE con_doc SET echeance = ? WHERE id = ? AND shop = ?', [$le, (int) $e['docId'], $shop]); $maj[] = 'échéance';
    }
    // La pièce attendue du document (coffre v2) : un magasin ne rattache qu'à une pièce de l'onglet Légal.
    $pd = is_array($b['pieceDoc'] ?? null) ? $b['pieceDoc'] : null;
    if ($pd !== null && cofDocLisible($shop, (int) ($pd['docId'] ?? 0)) !== null) {
        $pc = (string) ($pd['piece'] ?? '');
        if ($pc === '' || (isset(COF_PIECES[$pc]) && (cofPeutTout() || COF_PIECES[$pc]['onglet'] === 'legal'))) {
            Db::exec('UPDATE con_doc SET piece = ? WHERE id = ? AND shop = ?', [$pc !== '' ? $pc : null, (int) $pd['docId'], $shop]); $maj[] = 'pièce';
        }
    }
    $t = is_array($b['titreDoc'] ?? null) ? $b['titreDoc'] : null;
    if ($t !== null && ($tt = trim(mb_substr((string) ($t['titre'] ?? ''), 0, 190))) !== '' && cofDocLisible($shop, (int) ($t['docId'] ?? 0)) !== null) {
        Db::exec('UPDATE con_doc SET titre = ? WHERE id = ? AND shop = ?', [$tt, (int) $t['docId'], $shop]); $maj[] = 'titre';
    }
    if (($n > 0 || $maj !== []) && function_exists('journalAdd')) {
        journalAdd($qui['nom'], $qui['role'] === 'siege' ? 'Siège' : 'Franchisé', 'Coffre-fort', $n . ' grande(s) date(s) rangée(s) pour le magasin ' . $shop . ($maj !== [] ? ' ; document mis à jour (' . implode(', ', $maj) . ')' : ''));
    }
    return ['ok' => true, 'ajoutees' => $n, 'refusees' => $refus, 'document' => $maj, 'dates' => cofDatesListe($shop)];
}

/** DELETE /coffre/magasin/dates?shop=&id= — un magasin retire les dates qu'il a rangées ; le siège, toutes. */
function wr_coffre_date_suppr(): array
{
    $shop = (int) ($_GET['shop'] ?? 0);
    $id = (int) ($_GET['id'] ?? 0);
    if (!cofShopOk($shop)) { http_response_code($shop > 0 ? 403 : 400); return ['error' => $shop > 0 ? 'ce magasin n’est pas sur votre session' : 'shop requis']; }
    cofDatesTable();
    $r = Db::row('SELECT * FROM con_coffre_date WHERE id = ? AND shop = ?', [$id, $shop]);
    if ($r === null) { http_response_code(404); return ['error' => 'date introuvable']; }
    if (!cofPeutTout() && (string) $r['role'] !== 'franchise') { http_response_code(403); return ['error' => 'cette date a été rangée par le siège : votre consultant la retire']; }
    Db::exec('DELETE FROM con_coffre_date WHERE id = ? AND shop = ?', [$id, $shop]);
    return ['ok' => true, 'dates' => cofDatesListe($shop)];
}

/* --- l'assistant ------------------------------------------------------------------------------------------------ */

const COF_ASSISTANT_CONSIGNE = "Tu lis un document rangé dans le coffre-fort d'une boutique franchisée L'Atelier By (boulangerie-pâtisserie, Belgique)"
    . " et tu relèves ses GRANDES DATES : celles qui obligent la boutique à agir ou qui changent ses droits — prise de cours et fin"
    . " d'un bail ou d'un contrat, derniers jours pour un préavis, fenêtre de renouvellement, échéance d'une police d'assurance,"
    . " d'une autorisation ou d'un contrôle, indexation ou révision du loyer."
    . "\nTu recopies ce qui est écrit et tu n'inventes rien : chaque date vient avec l'extrait du document qui la donne ; une date qui se"
    . " déduit directement du texte (« neuf années prenant cours le 1er mars 2022 ») se donne avec cet extrait ; une date introuvable"
    . " ne se donne pas. Les dates passées se donnent aussi."
    . "\nPour un bail commercial, remplis `bail` (prise de cours, durée en années, préavis du preneur en mois s'il est écrit) : les fins de"
    . " triennat, les derniers jours du congé, la fenêtre du renouvellement et la fin du bail se calculent ensuite à partir de ces données,"
    . " ne les mets pas dans `dates` (une indexation, oui). Pour tout autre document, `bail` vaut null."
    . "\n`piece` dit quelle pièce attendue du coffre ce document remplit (le bail commercial, l'attestation d'assurance RC…), autre si aucune."
    . "\nDans `remarques`, dis en une ou deux phrases ce qui n'a pas pu être lu ou ce qui demande une vérification (une clause qui"
    . " déroge à la règle générale, une page illisible) ; vide sinon.";

function cofAssistantSchema(): array
{
    $txt = ['type' => 'string'];
    $ent = ['anyOf' => [['type' => 'integer'], ['type' => 'null']]];
    $date = $txt + ['description' => 'AAAA-MM-JJ'];
    return ['type' => 'object', 'additionalProperties' => false,
        'properties' => [
            'genre' => ['type' => 'string', 'enum' => array_keys(COF_GENRES)],
            'piece' => ['type' => 'string', 'enum' => array_merge(array_keys(COF_PIECES), ['autre']),
                'description' => 'La pièce attendue du coffre que ce document remplit : ' . implode(' ; ', array_map(static fn ($k, $p) => $k . ' = ' . $p['nom'], array_keys(COF_PIECES), COF_PIECES)) . ' ; autre sinon'],
            'titre' => $txt + ['description' => 'Un titre court pour le coffre (« Bail commercial — rue de la Station 12, Gosselies »)'],
            'resume' => $txt + ['description' => 'Ce qu’est le document et ce qu’il engage, en une ou deux phrases'],
            'bail' => ['anyOf' => [['type' => 'null'], ['type' => 'object', 'additionalProperties' => false,
                'properties' => [
                    'prise_de_cours' => $txt + ['description' => 'AAAA-MM-JJ ; vide si le bail ne la donne pas'],
                    'duree_ans' => $ent + ['description' => 'Durée du bail en années, null si non écrite'],
                    'preavis_mois' => $ent + ['description' => 'Préavis du preneur, en mois, pour un congé à l’expiration d’un triennat ; null si non écrit'],
                    'extrait' => $txt + ['description' => 'La phrase du bail qui donne la prise de cours et la durée, recopiée'],
                ],
                'required' => ['prise_de_cours', 'duree_ans', 'preavis_mois', 'extrait']]]],
            'echeance' => $txt + ['description' => 'L’échéance principale du document (fin du contrat, de la police, de l’autorisation), AAAA-MM-JJ ; vide sinon'],
            'dates' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false,
                'properties' => [
                    'sorte' => ['type' => 'string', 'enum' => array_keys(COF_DATE_SORTES)],
                    'titre' => $txt + ['description' => 'Ce qui se passe ce jour-là, en quelques mots'],
                    'le' => $date,
                    'fin' => $txt + ['description' => 'La fin d’une fenêtre (AAAA-MM-JJ), vide pour une date seule'],
                    'extrait' => $txt + ['description' => 'La phrase du document qui donne ou permet de calculer la date, recopiée'],
                    'page' => $ent + ['description' => 'La page du document, null si inconnue'],
                ],
                'required' => ['sorte', 'titre', 'le', 'fin', 'extrait', 'page']]],
            'remarques' => $txt,
        ],
        'required' => ['genre', 'piece', 'titre', 'resume', 'bail', 'echeance', 'dates', 'remarques']];
}

/** Le contenu d'un document tel que le modèle le lit : [octets ou texte, mime] ; [null, motif] s'il ne se lit pas. */
function cofAssistantContenu(array $r): array
{
    $chemin = docDossier((int) $r['shop']) . '/' . basename((string) $r['fichier']);
    if (!is_file($chemin)) { return [null, 'le fichier n’est plus sur le serveur']; }
    if ((int) filesize($chemin) > COF_ASSISTANT_MAX_OCTETS) { return [null, 'le document est trop lourd pour l’assistant (' . (COF_ASSISTANT_MAX_OCTETS / 1048576) . ' Mo au plus)']; }
    $ext = strtolower((string) pathinfo((string) $r['nom_origine'], PATHINFO_EXTENSION));
    $mime = (string) $r['mime'];
    if ($mime === 'application/pdf' || $ext === 'pdf') { return [(string) file_get_contents($chemin), 'application/pdf']; }
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) { return [(string) file_get_contents($chemin), $ext === 'jpg' ? 'image/jpeg' : 'image/' . $ext]; }
    if (in_array($ext, ['txt', 'csv', 'eml'], true)) { return [mb_substr((string) file_get_contents($chemin), 0, 400000), 'text/plain']; }
    if (in_array($ext, ['docx', 'odt'], true) && class_exists('ZipArchive')) {
        // Un fichier Word ou OpenDocument : son texte, paragraphe par paragraphe.
        $z = new ZipArchive();
        if ($z->open($chemin) === true) {
            $xml = (string) $z->getFromName($ext === 'docx' ? 'word/document.xml' : 'content.xml');
            $z->close();
            $t = trim(html_entity_decode(strip_tags((string) preg_replace('#</(w:p|text:p|text:h)>#', "\n", $xml)), ENT_QUOTES | ENT_XML1, 'UTF-8'));
            if ($t !== '') { return [mb_substr($t, 0, 400000), 'text/plain']; }
        }
        return [null, 'le texte du fichier ne se lit pas'];
    }
    return [null, 'l’assistant lit les PDF, les photos (JPG, PNG, WebP) et les fichiers Word ; pas ce fichier (' . ($ext ?: 'sans extension') . ')'];
}

/**
 * Ce que l'assistant propose pour un document lu (la réponse du modèle, $j) : les dates du modèle, celles du bail
 * calculées, l'échéance et le titre du document. Une date déjà rangée pour ce document est marquée « déjà » et
 * décochée ; une date passée est décochée. Fonction pure (sauf la lecture des dates rangées), testée seule.
 */
function cofAssistantPropositions(array $j, array $doc, array $deja, string $auj): array
{
    $props = [];
    $ajouter = static function (array $p) use (&$props, $deja, $auj): void {
        $k = $p['sorte'] . '|' . $p['le'];
        if (isset($props[$k])) { return; }
        $p['sorteLib'] = COF_DATE_SORTES[$p['sorte']];
        $p['deja'] = in_array($k, $deja, true);
        $p['coche'] = !$p['deja'] && ($p['fin'] ?? $p['le']) >= $auj;
        $p['cle'] = substr(md5($k), 0, 10);
        $p['etat'] = cofDateEtat($p['le'], $p['fin'] ?? null, $auj);
        $props[$k] = $p;
    };
    $bail = is_array($j['bail'] ?? null) ? $j['bail'] : null;
    $debut = $bail !== null ? cofDateOk($bail['prise_de_cours'] ?? null) : null;
    $calcule = ($j['genre'] ?? '') === 'bail_commercial' && $debut !== null;
    if ($calcule) {
        $ans = (int) ($bail['duree_ans'] ?? 0) ?: 9;
        $preavis = (int) ($bail['preavis_mois'] ?? 0) ?: 6;
        $plus = (empty($bail['duree_ans']) ? ' Durée non lue dans le bail : 9 ans par défaut.' : '') . (empty($bail['preavis_mois']) ? ' Préavis non lu : 6 mois par défaut.' : '');
        foreach (cofBailDates($debut, $ans, $preavis) as $d) {
            $ajouter(array_merge($d, ['source' => 'calcul', 'extrait' => mb_substr((string) ($bail['extrait'] ?? ''), 0, 300), 'page' => null, 'note' => trim($d['note'] . $plus)]));
        }
    }
    foreach ((array) ($j['dates'] ?? []) as $d) {
        if (!is_array($d) || ($le = cofDateOk($d['le'] ?? null)) === null) { continue; }
        $sorte = isset(COF_DATE_SORTES[(string) ($d['sorte'] ?? '')]) ? (string) $d['sorte'] : 'autre';
        if ($calcule && in_array($sorte, COF_DATE_BAIL_CALCUL, true)) { continue; }   // le calcul fait foi
        $fin = cofDateOk($d['fin'] ?? null);
        $ajouter(['sorte' => $sorte, 'titre' => trim(mb_substr((string) ($d['titre'] ?? ''), 0, 190)) ?: COF_DATE_SORTES[$sorte], 'le' => $le,
            'fin' => $fin !== null && $fin >= $le ? $fin : null, 'note' => null, 'source' => 'assistant',
            'extrait' => trim(mb_substr((string) ($d['extrait'] ?? ''), 0, 300)), 'page' => isset($d['page']) && is_int($d['page']) ? $d['page'] : null]);
    }
    $props = array_values($props);
    usort($props, static fn ($a, $b) => strcmp($a['le'], $b['le']));
    $ech = cofDateOk($j['echeance'] ?? null);
    $titreDoc = trim(mb_substr((string) ($j['titre'] ?? ''), 0, 190));
    $nomFichier = (string) pathinfo((string) ($doc['nom_origine'] ?? ''), PATHINFO_FILENAME);
    $genre = isset(COF_GENRES[(string) ($j['genre'] ?? '')]) ? (string) $j['genre'] : 'autre';
    $piece = (string) ($j['piece'] ?? '');
    return ['genre' => $genre, 'genreLib' => COF_GENRES[$genre], 'titre' => $titreDoc, 'resume' => trim((string) ($j['resume'] ?? '')),
        // La pièce attendue que l'assistant y reconnaît (coffre v2) ; null si ce n'en est pas une.
        'pieceSuggeree' => isset(COF_PIECES[$piece]) ? ['cle' => $piece, 'nom' => COF_PIECES[$piece]['nom'], 'onglet' => COF_PIECES[$piece]['onglet']] : null,
        'remarques' => trim((string) ($j['remarques'] ?? '')), 'propositions' => array_slice($props, 0, 30),
        // L'échéance du document : proposée quand il n'en a pas (ou une autre) ; le titre : quand il porte le nom du fichier.
        'echeanceDoc' => $ech !== null && $ech !== (string) ($doc['echeance'] ?? '') ? ['actuelle' => !empty($doc['echeance']) ? (string) $doc['echeance'] : null, 'proposee' => $ech] : null,
        'titreDoc' => $titreDoc !== '' && $titreDoc !== (string) ($doc['titre'] ?? '') && in_array((string) ($doc['titre'] ?? ''), [$nomFichier, ''], true)
            ? ['actuel' => (string) ($doc['titre'] ?? ''), 'propose' => $titreDoc] : null];
}

/** POST /coffre/magasin/assistant {shop, id} — l'assistant lit le document et propose ses grandes dates (rien n'est rangé). */
function wr_coffre_assistant(): array
{
    $b = body();
    $shop = (int) ($b['shop'] ?? 0);
    if (!cofShopOk($shop)) { http_response_code($shop > 0 ? 403 : 400); return ['error' => $shop > 0 ? 'ce magasin n’est pas sur votre session' : 'shop requis']; }
    $r = cofDocLisible($shop, (int) ($b['id'] ?? 0));
    if ($r === null) { http_response_code(404); return ['error' => 'document introuvable dans ce coffre']; }
    [$contenu, $mime] = cofAssistantContenu($r);
    if ($contenu === null) { http_response_code(422); return ['error' => $mime, 'docId' => (int) $r['id']]; }
    cofDatesTable();
    $auj = date('Y-m-d');
    $texte = 'Document : « ' . $r['titre'] . ' » (' . $r['nom_origine'] . '), rangé comme « ' . (DOC_SORTES[(string) $r['sorte']] ?? 'Autre document') . ' »'
        . (!empty($r['date_doc']) ? ', daté du ' . cofDateFr((string) $r['date_doc']) : '') . '. Nous sommes le ' . cofDateFr($auj) . '. Relève ses grandes dates.';
    $rep = Anthropic::documentJson(COF_ASSISTANT_CONSIGNE, $texte, $contenu, $mime, cofAssistantSchema(), 8000, 150);
    if ($rep['json'] === null) { http_response_code(502); return ['error' => 'l’assistant n’a pas pu lire le document : ' . $rep['erreur'], 'docId' => (int) $r['id']]; }
    $deja = array_map(static fn ($x) => $x['sorte'] . '|' . $x['le'], Db::rows('SELECT sorte, le FROM con_coffre_date WHERE shop = ? AND doc_id = ?', [$shop, (int) $r['id']]));
    return ['ok' => true, 'docId' => (int) $r['id'], 'nom' => (string) $r['nom_origine'], 'docTitre' => (string) $r['titre']]
        + cofAssistantPropositions($rep['json'], $r, $deja, $auj) + ['modele' => $rep['modele']];
}
