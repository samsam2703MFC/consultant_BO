<?php
declare(strict_types=1);
/**
 * LA NOTE DU JOUR D'UN MAGASIN.
 *
 * Le dashboard journalier dit ce qui s'est passé en chiffres ; la note dit
 * pourquoi — la pluie, la kermesse, le four en panne, l'extra qui n'est pas
 * venu. Elle sert le jour même à l'équipe et au consultant, et surtout un an
 * plus tard : la même semaine N-1 s'affiche sous la note du jour, pour qu'un
 * lundi étrange trouve son explication avant qu'on la cherche dans les
 * chiffres.
 *
 * Une note par magasin et par jour, signée d'un prénom si on veut. Un texte
 * vide efface la ligne : un jour sans note n'a pas de ligne, et c'est ce qui
 * rend le vide lisible — « rien à signaler » ne s'écrit pas.
 */

function ensureNotesJour(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_shop_day_note ('
        . 'shop_id VARCHAR(8) NOT NULL,'
        . 'jour DATE NOT NULL,'
        . 'texte TEXT NULL,'
        . 'par VARCHAR(120) NULL,'
        . 'maj_le DATETIME NULL,'
        . 'PRIMARY KEY (shop_id, jour)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** Une date AAAA-MM-JJ qui existe, rien d'autre. */
function njDateValide(string $d): bool
{
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)
        && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
}

/** Le lundi et le dimanche de la semaine d'une date — semaine du lundi au dimanche, comme partout. */
function njSemaine(string $date): array
{
    $t = new DateTimeImmutable($date . ' 12:00:00');
    $lundi = $t->modify('-' . ((int) $t->format('N') - 1) . ' days');
    return [$lundi->format('Y-m-d'), $lundi->modify('+6 days')->format('Y-m-d')];
}

/** Les notes d'un magasin entre deux dates, dans l'ordre des jours. Une note vidée n'existe pas. */
function njLignes(string $shop, string $du, string $au): array
{
    $out = [];
    foreach (Db::rows('SELECT jour, texte, par, maj_le FROM ceo_shop_day_note
                        WHERE shop_id = ? AND jour BETWEEN ? AND ? ORDER BY jour', [$shop, $du, $au]) as $r) {
        $texte = trim((string) ($r['texte'] ?? ''));
        if ($texte === '') { continue; }
        $par = trim((string) ($r['par'] ?? ''));
        $out[] = [
            'jour'  => substr((string) $r['jour'], 0, 10),
            'texte' => $texte,
            'par'   => $par !== '' ? $par : null,
            'le'    => $r['maj_le'] !== null ? (string) $r['maj_le'] : null,
        ];
    }
    return $out;
}

/**
 * GET /exploitation/notes?shop=&date= — la note du jour, celles de sa semaine,
 * et la même semaine un an plus tôt.
 *
 * Le N-1 est la semaine décalée de 364 jours : le même jour de semaine, comme
 * les ventes face au N-1 partout ailleurs dans le cockpit. Un mardi se relit
 * face à un mardi, pas face à la date du calendrier.
 */
function ep_notes_jour(): array
{
    ensureNotesJour();
    $shop = trim((string) ($_GET['shop'] ?? ''));
    $date = (string) ($_GET['date'] ?? '');
    if ($shop === '' || !njDateValide($date)) {
        http_response_code(400);
        return ['error' => 'magasin ou date manquant'];
    }
    [$du, $au] = njSemaine($date);
    $l1 = (new DateTimeImmutable($du . ' 12:00:00'))->modify('-364 days');
    $du1 = $l1->format('Y-m-d');
    $au1 = $l1->modify('+6 days')->format('Y-m-d');

    $semaine = njLignes($shop, $du, $au);
    $note = null;
    foreach ($semaine as $l) { if ($l['jour'] === $date) { $note = $l; break; } }

    return ['shop' => $shop, 'jour' => $date, 'note' => $note,
        'semaine' => ['du' => $du, 'au' => $au, 'notes' => $semaine],
        'n1'      => ['du' => $du1, 'au' => $au1, 'notes' => njLignes($shop, $du1, $au1)]];
}

/**
 * POST /exploitation/note — { shop, jour, texte, par }.
 *
 * Un refus se dit par le STATUT HTTP : le dashboard lit le statut, pas le
 * corps. Un texte vide SUPPRIME la note plutôt que d'en garder une blanche.
 */
function wr_note_jour(): array
{
    ensureNotesJour();
    $b = body();
    $shop  = trim((string) ($b['shop'] ?? ''));
    $jour  = (string) ($b['jour'] ?? '');
    $texte = trim((string) ($b['texte'] ?? ''));
    $par   = trim((string) ($b['par'] ?? ''));
    if ($shop === '' || !njDateValide($jour)) {
        http_response_code(400);
        return ['error' => 'magasin ou jour manquant'];
    }
    if ($texte === '') {
        Db::exec('DELETE FROM ceo_shop_day_note WHERE shop_id = ? AND jour = ?', [$shop, $jour]);
        return ['ok' => true, 'vide' => true];
    }
    // 2 000 caractères : de quoi raconter une journée, pas d'y coller un rapport.
    // La borne est dite ici plutôt que laissée à MySQL, qui tronquerait sans
    // prévenir et rendrait une note amputée à la relecture.
    if (mb_strlen($texte) > 2000) {
        http_response_code(422);
        return ['error' => 'note trop longue (' . mb_strlen($texte) . ' caractères, maximum 2 000)'];
    }
    $par = $par !== '' ? mb_substr($par, 0, 120) : null;
    Db::exec('INSERT INTO ceo_shop_day_note (shop_id, jour, texte, par, maj_le)
              VALUES (?,?,?,?,NOW())
              ON DUPLICATE KEY UPDATE texte = VALUES(texte), par = VALUES(par), maj_le = NOW()',
        [$shop, $jour, $texte, $par]);
    return ['ok' => true, 'note' => ['jour' => $jour, 'texte' => $texte, 'par' => $par, 'le' => date('Y-m-d H:i:s')]];
}
