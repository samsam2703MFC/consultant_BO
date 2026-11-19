#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests des photos 5/5 d'un type de tâche, toutes périodes (19/11/2026, Sam : « dans le suivi des tâches, dans
 * l'entraînement de l'IA, je dois pouvoir récupérer dans les anciennes photos tous les 5/5 par type de tâche
 * demandé ») — GET /ia/parfaites, base SQLite en mémoire, la liste des tâches du panel en doublure.
 *
 *   php bin/tests_ia_parfaites.php
 *
 * Les types : les rayons (les « Photo du comptoir - … » de plusieurs moments réunis), puis les autres tâches, chacun
 * avec son nombre de 5/5, toutes périodes ; une tâche sans photo ne compte pas. Les photos d'un type : les plus récentes
 * d'abord, par pages, avec qui les a notées ; une note sous 5 n'y est pas ; la liste du panel n'est lue qu'une fois.
 */
$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

final class Db {
    static ?PDO $p = null;
    static function pdo(): PDO { if (!self::$p) { self::$p = new PDO('sqlite::memory:'); self::$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); } return self::$p; }
    // Les CREATE et ALTER des modules sont en MySQL : le test crée ses tables lui-même.
    static function exec(string $q, array $a = []): int
    {
        if (preg_match('/^\s*(CREATE|ALTER)\b/i', $q)) { return 0; }
        $q = str_replace(['INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)'], ['INSERT INTO ceo_app_setting VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET value = excluded.value'], $q);
        $s = self::pdo()->prepare($q); $s->execute($a); return $s->rowCount();
    }
    static function rows(string $q, array $a = []): array { $s = self::pdo()->prepare($q); $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC); }
    static function row(string $q, array $a = []): ?array { $r = self::rows($q, $a); return $r[0] ?? null; }
}
function setting(string $k, $d = null) { $r = Db::row('SELECT value FROM ceo_app_setting WHERE k = ?', [$k]); return $r ? json_decode($r['value'], true) : $d; }

// La liste des tâches du panel (task_cadre.php), en doublure : le compte des lectures.
$LECTURES = 0;
$TACHES = [
    ['id' => 11, 'name' => 'Photo du comptoir - Viennoiseries', 'requires_photo' => 1],
    ['id' => 12, 'name' => 'Photo du comptoir après-midi - Viennoiseries', 'requires_photo' => 1],
    ['id' => 21, 'name' => 'Photo du comptoir - Pâtisseries', 'requires_photo' => 1],
    ['id' => 31, 'name' => 'Propreté de la salle', 'requires_photo' => 1],
    ['id' => 41, 'name' => 'Caisse comptée', 'requires_photo' => 0],
];
function tkLireListe(string $p): array { global $LECTURES, $TACHES; $LECTURES++; return [$TACHES, null]; }
function tkNormTache(array $t): array { return ['id' => (int) $t['id'], 'nom' => (string) $t['name'], 'photo' => (bool) $t['requires_photo']]; }

require __DIR__ . '/../src/rayons_photos.php';
require __DIR__ . '/../src/ia_assistant.php';

$P = Db::pdo();
$P->exec('CREATE TABLE ceo_app_setting (k TEXT PRIMARY KEY, value TEXT)');
$P->exec('CREATE TABLE mac_task_review (id INTEGER PRIMARY KEY AUTOINCREMENT, id_shop INT, id_task INT, review_date TEXT, rating INT, is_accepted INT, comment TEXT, consultant_name TEXT, owner_name TEXT)');
$P->exec('CREATE TABLE con_cq_ia (shop_id INT, task_id INT, jour TEXT, tache TEXT, rayon TEXT, niveau INT, note_finale INT)');
Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
    ['signalement', json_encode(['seuil' => 4, 'niveaux' => [['n' => 1, 'nom' => 'Critique'], ['n' => 3, 'nom' => 'Moyen'], ['n' => 5, 'nom' => 'Exemplaire']]])]);
$note = static function (int $shop, int $tache, string $jour, int $n, ?string $par = 'Nathalie', ?string $com = null, ?string $owner = null): void {
    Db::exec('INSERT INTO mac_task_review (id_shop, id_task, review_date, rating, is_accepted, comment, consultant_name, owner_name) VALUES (?, ?, ?, ?, 1, ?, ?, ?)', [$shop, $tache, $jour, $n, $com, $par, $owner]);
};
// Viennoiseries : 30 photos 5/5 sur deux tâches (matin et après-midi), d'un an à hier ; une 4/5 qui ne compte pas.
for ($i = 0; $i < 30; $i++) { $note(4 + $i % 2, $i % 3 ? 11 : 12, date('Y-m-d', strtotime('-' . (1 + 12 * $i) . ' days')), 5, $i === 0 ? null : 'Nathalie', $i === 0 ? 'Comptoir plein' : null, $i === 0 ? 'Sam' : null); }
$note(4, 11, date('Y-m-d'), 4);
$note(4, 21, '2025-12-02', 5);                 // Pâtisseries : une
$note(7, 31, '2026-02-10', 5); $note(7, 31, '2026-03-11', 5);   // Propreté : deux
$note(4, 41, '2026-03-01', 5);                 // Caisse : sans photo, ne compte pas
$note(9, 99, '2026-04-01', 5);                 // une tâche retirée du panel, connue de con_cq_ia
Db::exec('INSERT INTO con_cq_ia (shop_id, task_id, jour, tache) VALUES (9, 99, ?, ?)', ['2026-04-01', 'Vitrine traiteur']);

echo "Les types\n";
$_GET = [];
$R = ep_ia_parfaites();
$parCle = array_column($R['types'], null, 'cle');
verifie('les rayons d’abord, puis les autres tâches par nom', array_column($R['types'], 'type') === ['rayon', 'rayon', 'tache', 'tache'] && $R['types'][2]['nom'] === 'Propreté de la salle', json_encode(array_column($R['types'], 'nom')));
verifie('Viennoiseries : les 30 photos 5/5, matin et après-midi réunis ; la 4/5 ne compte pas', ($parCle['viennoiseries']['n'] ?? 0) === 30, json_encode($parCle['viennoiseries'] ?? null));
verifie('Pâtisseries 1, Propreté 2, la tâche retirée du panel nommée par con_cq_ia', ($parCle['patisseries']['n'] ?? 0) === 1 && ($parCle['tache:Propreté de la salle']['n'] ?? 0) === 2 && isset($parCle['tache:Vitrine traiteur']), json_encode(array_keys($parCle)));
verifie('une tâche sans photo n’est pas un type', !isset($parCle['tache:Caisse comptée']));
verifie('le niveau modèle et son nom', $R['niveau'] === 5 && $R['nomNiveau'] === 'Exemplaire' && !isset($R['photos']));

echo "Les photos d'un type\n";
$_GET = ['sujet' => 'viennoiseries'];
$V = ep_ia_parfaites();
verifie('une page de 24, les plus récentes d’abord, la suite au rang 24', count($V['photos']) === 24 && $V['suite'] === 24 && $V['total'] === 30
    && $V['photos'][0]['date'] === date('Y-m-d', strtotime('-1 days')) && $V['photos'][0]['date'] > $V['photos'][23]['date'], json_encode([count($V['photos']), $V['suite'], $V['photos'][0]['date'] ?? null]));
verifie('chaque photo : magasin, tâche, jour, qui l’a notée (la direction quand le consultant manque), le commentaire',
    $V['photos'][0]['shopId'] === '4' && $V['photos'][0]['tache'] === 'Photo du comptoir après-midi - Viennoiseries' && $V['photos'][0]['par'] === 'Sam' && $V['photos'][0]['commentaire'] === 'Comptoir plein'
    && $V['photos'][1]['par'] === 'Nathalie', json_encode($V['photos'][0]));
verifie('aucune 4/5 parmi elles', !in_array(date('Y-m-d'), array_column($V['photos'], 'date'), true));
$_GET = ['sujet' => 'viennoiseries', 'de' => '24'];
$V2 = ep_ia_parfaites();
verifie('la page suivante : les 6 plus anciennes, sans suite', count($V2['photos']) === 6 && $V2['suite'] === null && $V2['photos'][5]['date'] === date('Y-m-d', strtotime('-' . (1 + 12 * 29) . ' days')), json_encode(array_column($V2['photos'], 'date')));
$_GET = ['sujet' => 'tache:Propreté de la salle'];
$T = ep_ia_parfaites();
verifie('une autre tâche : ses deux photos, type « tache »', $T['type'] === 'tache' && count($T['photos']) === 2 && $T['photos'][0]['date'] === '2026-03-11', json_encode($T['photos']));
$_GET = ['sujet' => 'inconnu'];
$I = ep_ia_parfaites();
verifie('un type inconnu : rien, sans erreur', $I['photos'] === [] && $I['total'] === 0 && $I['suite'] === null);
verifie('la liste des tâches du panel n’est lue qu’une fois (gardée une heure)', $LECTURES === 1, (string) $LECTURES);

echo "\n" . ($ko === 0 ? "Tout est vert : $ok vérifications." : "$ko échec(s), $ok réussite(s).") . "\n";
exit($ko === 0 ? 0 : 1);
