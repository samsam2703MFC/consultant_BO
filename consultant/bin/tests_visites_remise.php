#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * La remise à zéro des visites (19/11/2026) — sur une vraie base SQLite en mémoire, sans réseau.
 *
 *   php consultant/bin/tests_visites_remise.php
 *
 * Ce qui doit rester vrai : sans le mot EFFACER ni le bon nombre, rien ne part ; avec, toutes les visites partent avec
 * leurs réponses, leurs photos (fichiers compris, et les photos de correction de leurs points), leurs points et leur
 * historique, leurs tâches, leurs missions du helpdesk ; restent les points nés hors visite (détachés d'une photo
 * effacée), les tâches sans visite, les fichiers hors uploads/visites/ ; personne n'est prévenu.
 */

// En ligne de commande, le code HTTP d'un refus ne peut pas se poser une fois la sortie commencée : sans effet ici.
set_error_handler(static fn (int $n, string $m): bool => str_contains($m, 'http_response_code'), E_WARNING);

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

// --- doublures : une base SQLite, le corps de la requête, le journal, aucun envoi -------------------------
final class Db
{
    public static ?PDO $p = null;
    public static function pdo(): PDO
    {
        if (!self::$p) { self::$p = new PDO('sqlite::memory:'); self::$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
        return self::$p;
    }
    // Le SQL MySQL du schéma, ramené à SQLite (comme tests_fil.php).
    public static function fx(string $q): string
    {
        $q = str_replace('INT AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $q);
        $q = (string) preg_replace('/,\s*(UNIQUE )?KEY \w+ \([^)]*\)/', '', $q);
        $q = (string) preg_replace('/ AFTER \w+/', '', $q);
        if (str_contains($q, ' ON DUPLICATE KEY UPDATE value = value')) { $q = str_replace(['INSERT INTO', ' ON DUPLICATE KEY UPDATE value = value'], ['INSERT OR IGNORE INTO', ''], $q); }
        return (string) preg_replace('/\)\s*ENGINE=.*$/s', ')', $q);
    }
    public static function exec(string $q, array $a = []): int { $s = self::pdo()->prepare(self::fx($q)); $s->execute($a); return $s->rowCount(); }
    public static function rows(string $q, array $a = []): array { $s = self::pdo()->prepare($q); $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC); }
    public static function row(string $q, array $a = []): ?array { $r = self::rows($q, $a); return $r[0] ?? null; }
}
function body(): array { return $GLOBALS['corps'] ?? []; }
function setting(string $k, mixed $d = null): mixed { return $d; }
$JOURNAL = [];
function journalAdd(string $actor, string $kind, ?string $project, string $message): void { $GLOBALS['JOURNAL'][] = $message; }
// Rien ne doit partir : une annulation d'agenda ou un courriel ferait échouer le banc.
$ENVOIS = 0;
function vcApresChangement(array $avant, array $apres): array { $GLOBALS['ENVOIS']++; return []; }
// Les tâches du cadre et les missions du helpdesk : leurs tables, comme leurs modules les créent.
function ensureVisitesCadre(): void { Db::exec('CREATE TABLE IF NOT EXISTS con_consultant_tache (id INTEGER PRIMARY KEY AUTOINCREMENT, visite_id INT NULL, titre TEXT)'); }
function hdkTable(): void { Db::exec('CREATE TABLE IF NOT EXISTS con_hdk_mission (id INTEGER PRIMARY KEY AUTOINCREMENT, case_id INT NOT NULL, visite_id INT NOT NULL, titre TEXT)'); }

require __DIR__ . '/../src/visites.php';
Db::exec('CREATE TABLE ceo_app_setting (k TEXT PRIMARY KEY, value TEXT)');
ensureVisites(); ensureVisitesCadre(); hdkTable();
// Les colonnes du rapport, ajoutées par le suivi (suivi.php, ensureSuivi) que ce banc ne charge pas.
Db::exec('ALTER TABLE con_visite ADD COLUMN rapport_envoye_le TEXT NULL');

// --- le réseau de test : trois visites, une quatrième visite plus récente, des points hors visite -----------
$racine = dirname(__DIR__, 2) . '/public/uploads/visites/banc-remise-' . getmypid();
@mkdir($racine, 0777, true);
// Le dossier du banc disparaît, même si un test casse en route.
register_shutdown_function(static function () use ($racine): void { foreach (glob($racine . '/*') ?: [] as $f) { @unlink($f); } @rmdir($racine); });
$f1 = 'uploads/visites/banc-remise-' . getmypid() . '/a.jpg'; file_put_contents(dirname(__DIR__, 2) . '/public/' . $f1, 'x');
$f2 = 'uploads/visites/banc-remise-' . getmypid() . '/b.jpg'; file_put_contents(dirname(__DIR__, 2) . '/public/' . $f2, 'x');
$visite = static function (string $shop, string $le, string $statut, ?string $rap = null): int {
    Db::exec('INSERT INTO con_visite (client_id, shop_id, consultant_id, consultant_nom, prevu_le, debut_h, duree_min, statut, motif, rapport_envoye_le, cree_le, maj_le) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        ['c' . uniqid(), $shop, 'c1', 'Pierre B.', $le, '09:30', 90, $statut, 'reguliere', $rap, '2026-11-01 10:00:00', '2026-11-01 10:00:00']);
    return (int) Db::pdo()->lastInsertId();
};
$v1 = $visite('4', '2026-11-12', 'terminee', '2026-11-13 10:00:00');
$v2 = $visite('2', '2026-11-19', 'en_cours');
$v3 = $visite('9', '2026-11-26', 'planifiee');
foreach ([[$v1, 'h1'], [$v1, 'h2'], [$v2, 'h1']] as [$vid, $ref]) {
    Db::exec('INSERT INTO con_visite_point (visite_id, module, point_ref, libelle, etat, maj_le) VALUES (?,?,?,?,?,?)', [$vid, 'hygiene', $ref, 'Sol', 'ok', '2026-11-12 10:00:00']);
}
$plan = static function (?int $vid, string $titre): int {
    Db::exec('INSERT INTO con_visite_action (client_id, shop_id, visite_id, titre, priorite, assigne, statut, cree_par, cree_le, maj_le) VALUES (?,?,?,?,?,?,?,?,?,?)',
        ['p' . uniqid(), '4', $vid, $titre, 'P1', 'franchise', 'ouvert', 'Sam', '2026-11-13 10:00:00', '2026-11-13 10:00:00']);
    return (int) Db::pdo()->lastInsertId();
};
$p1 = $plan($v1, 'Vitrine salée');
$pHors = $plan(null, 'Point du client mystère');
$photo = static function (?int $vid, ?int $pid, string $chemin, string $genre): int {
    Db::exec('INSERT INTO con_visite_photo (client_id, shop_id, visite_id, plan_id, genre, chemin, prise_a) VALUES (?,?,?,?,?,?,?)',
        ['f' . uniqid(), '4', $vid, $pid, $genre, $chemin, '2026-11-12 10:00:00']);
    return (int) Db::pdo()->lastInsertId();
};
$ph1 = $photo($v1, null, $f1, 'point');
$phCorr = $photo(null, $p1, $f2, 'correction');               // la correction du franchisé : sans visite, mais du point de la visite
$phAutre = $photo(null, $pHors, 'uploads/autre/c.jpg', 'correction');
Db::exec('UPDATE con_visite_action SET photo_id = ? WHERE id = ?', [$ph1, $pHors]);  // une photo de la visite, preuve d'un point hors visite
Db::exec('INSERT INTO con_visite_action_evt (plan_id, quand, qui, vers_statut) VALUES (?,?,?,?), (?,?,?,?)', [$p1, '2026-11-13 10:00:00', 'Sam', 'ouvert', $pHors, '2026-11-13 10:00:00', 'Sam', 'ouvert']);
Db::exec('INSERT INTO con_consultant_tache (visite_id, titre) VALUES (?, ?), (?, ?), (NULL, ?)', [$v2, 'Préparer', $v3, 'Préparer', 'Tâche sans visite']);
Db::exec('INSERT INTO con_hdk_mission (case_id, visite_id, titre) VALUES (7, ?, ?)', [$v3, 'Voir la vitrine']);
$n = static fn (string $t, string $w = '1=1'): int => (int) Db::row("SELECT COUNT(*) n FROM $t WHERE $w")['n'];

echo "Le compte, sans rien toucher\n";
$c = ep_visites_remise()['compte'];
verifie('trois visites, trois magasins, du 12/11 au 26/11', $c['visites'] === 3 && $c['magasins'] === 3 && $c['du'] === '2026-11-12' && $c['au'] === '2026-11-26', json_encode($c));
verifie('par statut', $c['parStatut'] === ['en_cours' => 1, 'planifiee' => 1, 'terminee' => 1], json_encode($c['parStatut']));
verifie('3 réponses, 2 photos (dont la correction), 1 point, 2 tâches, 1 mission, 1 rapport envoyé',
    $c['reponses'] === 3 && $c['photos'] === 2 && $c['points'] === 1 && $c['taches'] === 2 && $c['missions'] === 1 && $c['rapports'] === 1, json_encode($c));
verifie('compter n’efface rien', $n('con_visite') === 3 && $n('con_visite_photo') === 3);

echo "Les garde-fous\n";
$GLOBALS['corps'] = ['attendu' => 3];
verifie('sans le mot EFFACER : refus, rien ne part', isset(wr_visites_remise()['error']) && $n('con_visite') === 3);
$GLOBALS['corps'] = ['confirmer' => 'effacer', 'attendu' => 3];
verifie('le mot en minuscules ne suffit pas', isset(wr_visites_remise()['error']) && $n('con_visite') === 3);
$GLOBALS['corps'] = ['confirmer' => 'EFFACER', 'attendu' => 2];
$r = wr_visites_remise();
verifie('un nombre qui n’est plus le bon : refus, le compte à jour revient', str_contains((string) ($r['error'] ?? ''), 'recomptez') && ($r['compte']['visites'] ?? 0) === 3 && $n('con_visite') === 3);
$GLOBALS['corps'] = ['confirmer' => 'EFFACER'];
verifie('sans nombre : refus', isset(wr_visites_remise()['error']) && $n('con_visite') === 3);

echo "La remise à zéro\n";
$GLOBALS['corps'] = ['confirmer' => 'EFFACER', 'attendu' => 3, 'qui' => 'Sam V.'];
$r = wr_visites_remise();
verifie('elle répond ce qui est parti', ($r['ok'] ?? false) && $r['efface']['visites'] === 3 && $r['efface']['fichiers'] === 2, json_encode($r));
verifie('plus aucune visite, aucune réponse', $n('con_visite') === 0 && $n('con_visite_point') === 0 && $r['reste']['visites'] === 0);
verifie('les photos de la visite et la correction de son point sont parties ; la photo d’un point hors visite reste', $n('con_visite_photo') === 1 && Db::row('SELECT id FROM con_visite_photo')['id'] == $phAutre);
verifie('les fichiers sous uploads/visites/ sont effacés', !is_file(dirname(__DIR__, 2) . '/public/' . $f1) && !is_file(dirname(__DIR__, 2) . '/public/' . $f2));
verifie('le point de la visite et son historique sont partis', $n('con_visite_action', 'id = ' . $p1) === 0 && $n('con_visite_action_evt', 'plan_id = ' . $p1) === 0);
verifie('le point hors visite reste, détaché de la photo effacée, avec son historique', $n('con_visite_action', 'id = ' . $pHors) === 1
    && Db::row('SELECT photo_id FROM con_visite_action WHERE id = ?', [$pHors])['photo_id'] === null && $n('con_visite_action_evt', 'plan_id = ' . $pHors) === 1);
verifie('les tâches des visites partent, la tâche sans visite reste', $n('con_consultant_tache') === 1 && Db::row('SELECT titre FROM con_consultant_tache')['titre'] === 'Tâche sans visite');
verifie('les missions du helpdesk confiées aux visites partent', $n('con_hdk_mission') === 0);
verifie('personne n’est prévenu', $ENVOIS === 0);
verifie('le journal le garde, avec les chiffres', count($JOURNAL) === 1 && str_contains($JOURNAL[0], '3 visite(s) de 3 magasin(s)') && str_contains($JOURNAL[0], 'sans prévenir personne'), $JOURNAL[0] ?? '');
$v4 = $visite('4', '2026-12-01', 'planifiee');
verifie('le numéro des visites continue : une nouvelle visite ne reprend pas un ancien numéro', $v4 > $v3, $v4 . ' / ' . $v3);
$GLOBALS['corps'] = ['confirmer' => 'EFFACER', 'attendu' => 1];
verifie('une base déjà vide d’anciennes visites : la nouvelle se compte et s’efface aussi', (wr_visites_remise()['efface']['visites'] ?? -1) === 1 && $n('con_visite') === 0);

echo "\n$ok réussi(s), $ko en échec\n";
exit($ko === 0 ? 0 : 1);
