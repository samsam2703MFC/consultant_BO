#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests des photos de contrôle lues par les coachs de l'assistant (18/11/2026, Sam : « tous les coachs IA doivent
 * reprendre les résultats des contrôles qualité photos ») — base SQLite en mémoire, panel en doublure, sans clé.
 *
 *   php bin/tests_controles_photos.php
 *
 * La fiche porte les notes des photos (aujourd'hui, sept jours, mois), chaque non-conformité avec le commentaire du
 * consultant, les contrôles sans photo ; rien de nominatif ; la journée lue par la fiche n'est pas relue ; une lecture
 * reste dans son budget et dit ce qui manque ; les trois coachs reçoivent la règle, « n'en parle pas » compris ; l'écran
 * Contrôles qualité du cockpit rend toujours ses lignes.
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
    static function fx(string $q): string { return str_replace('ON DUPLICATE KEY UPDATE value = VALUES(value)', 'ON CONFLICT(key) DO UPDATE SET value = excluded.value', str_replace('`key`', 'key', $q)); }
    static function exec(string $q, array $a = []): int { $s = self::pdo()->prepare(self::fx($q)); $s->execute($a); return $s->rowCount(); }
    static function rows(string $q, array $a = []): array { $s = self::pdo()->prepare($q); $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC); }
    static function row(string $q, array $a = []): ?array { $r = self::rows($q, $a); return $r[0] ?? null; }
}
function setting(string $k, $d = null) { $r = Db::row('SELECT value FROM ceo_app_setting WHERE key = ?', [$k]); return $r ? json_decode($r['value'], true) : $d; }
function analyseListe($r): array { return is_array($r) && array_is_list($r) ? $r : (is_array($r['data'] ?? null) ? $r['data'] : []); }

/** Le panel : les tâches d'un magasin par jour ([sid][date] => liste), et le compte des chemins lus. */
final class PanelApi
{
    public static array $taches = [];
    public static array $lus = [];
    public static ?string $lastError = null;
    public static function configured(): bool { return true; }
    public static function consultantShops(): array { return [['id' => 7, 'name' => 'SRL Untel'], ['id' => 9, 'name' => 'SPRL Autre']]; }
    public static function getParallele(array $paths, int $front = 4, int $timeout = 25): array
    {
        $out = [];
        foreach ($paths as $k => $p) {
            self::$lus[] = $p;
            preg_match('#/shops/(\d+)/tasks\?date=([\d-]+)#', $p, $m);
            $out[$k] = ['tasks' => self::$taches[(int) $m[1]][$m[2]] ?? [], 'summary' => []];
        }
        return $out;
    }
    public static function liste(array $d): array
    {
        if (array_is_list($d)) { return array_values(array_filter($d, 'is_array')); }
        foreach (['data', 'items', 'tasks'] as $k) { if (isset($d[$k]) && is_array($d[$k])) { $s = self::liste($d[$k]); if ($s !== []) { return $s; } } }
        return [];
    }
}

require __DIR__ . '/../src/controles_qualite.php';
require __DIR__ . '/../src/assistant_magasin.php';

Db::pdo()->exec('CREATE TABLE ceo_app_setting (key TEXT PRIMARY KEY, value TEXT)');
Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?)', ['signalement', json_encode(['seuil' => 4, 'niveaux' => [
    ['n' => 5, 'nom' => 'Exemplaire'], ['n' => 4, 'nom' => 'Conforme'], ['n' => 3, 'nom' => 'Non conforme — mineur'],
    ['n' => 2, 'nom' => 'Non conforme — majeur'], ['n' => 1, 'nom' => 'Non conforme — critique']]])]);

$auj = date('Y-m-d');
$j = static fn (int $n) => date('Y-m-d', strtotime($auj . ' -' . $n . ' days'));
$t = static fn (int $id, string $nom, array $x = []) => $x + ['task_id' => $id, 'task_name' => $nom, 'checklist_name' => 'CQ — Vitrines', 'execution_time' => '07:00:00',
    'status' => 'TODO', 'requires_photo' => true, 'attachment_id' => 0, 'completed_by_name' => 'Julie Dupont', 'reviewed_by_name' => 'Marc Consultant'];
PanelApi::$taches[7] = [
    $auj => [
        $t(1, 'Vitrine des viennoiseries', ['attachment_id' => 11, 'status' => 'DONE', 'review_rating' => 4, 'reviewed_at' => $auj . ' 08:00:00']),
        $t(2, 'Comptoir des pâtisseries', ['attachment_id' => 12, 'status' => 'DONE', 'execution_time' => '09:00:00']),
        $t(3, 'Présentoir à pains', ['execution_time' => '16:00:00']),
        $t(4, 'Sortir les poubelles', ['requires_photo' => false]),   // sans photo : pas un contrôle photo
    ],
    $j(1) => [
        $t(5, 'Vitrine des viennoiseries', ['attachment_id' => 13, 'review_rating' => 2, 'review_comment' => 'Vitrine à moitié vide à 7 h, croissants manquants.']),
        $t(6, 'Comptoir des pâtisseries', ['attachment_id' => 14, 'review_rating' => 5]),
    ],
    $j(3) => [$t(7, 'Présentoir à pains'), $t(8, 'Vitrine des viennoiseries', ['attachment_id' => 15, 'review_rating' => 1, 'review_comment' => 'Produits sans étiquette.'])],
    $j(4) => [$t(9, 'Présentoir à pains')],
];

// --- la fiche : les photos de contrôle -------------------------------------------------
echo "Les photos de contrôle dans la fiche\n";
$cp = amControlesPhotos(7);
$s7 = $cp['septDerniersJours'] ?? [];
verifie('le barème et le seuil sont dits', ($cp['conformeAPartirDe'] ?? '') === '4 · Conforme' && count($cp['bareme'] ?? []) === 5);
verifie('aujourd’hui : trois contrôles photo, la tâche sans photo écartée', count($cp['aujourdhui'] ?? []) === 3, json_encode($cp['aujourdhui'] ?? null, JSON_UNESCAPED_UNICODE));
verifie('aujourd’hui, rangé à l’heure, avec son état',
    array_column($cp['aujourdhui'], 'etat') === ['noté 4 · Conforme (conforme)', 'photo rendue, pas encore notée', 'pas encore rendue'], json_encode(array_column($cp['aujourdhui'], 'etat'), JSON_UNESCAPED_UNICODE));
verifie('sept jours : 8 contrôles, 4 notés, 2 conformes, 2 non conformes', $s7['controles'] === 8 && $s7['notees'] === 4 && $s7['conformes'] === 2 && $s7['nonConformes'] === 2, json_encode($s7, JSON_UNESCAPED_UNICODE));
verifie('sept jours : 1 à noter, 2 pas rendues, 1 en attente', $s7['aNoter'] === 1 && $s7['pasRendues'] === 2 && $s7['enAttenteAujourdhui'] === 1);
verifie('sept jours : la note moyenne', $s7['noteMoyenne'] === 3.0);
verifie('sept jours : la répartition, de la meilleure note à la pire', array_keys($s7['parNote']) === ['5 · Exemplaire', '4 · Conforme', '2 · Non conforme — majeur', '1 · Non conforme — critique'], json_encode($s7['parNote'], JSON_UNESCAPED_UNICODE));
$nc = $cp['nonConformesSeptDerniersJours'] ?? [];
verifie('les non-conformités, la plus récente d’abord', count($nc) === 2 && $nc[0]['date'] === $j(1) && $nc[1]['note'] === '1 · Non conforme — critique');
verifie('chaque non-conformité porte ce que le consultant a relevé', ($nc[0]['releveDuConsultant'] ?? '') === 'Vitrine à moitié vide à 7 h, croissants manquants.');
verifie('l’exemplaire de la semaine est nommé', ($cp['exemplairesSeptDerniersJours'][0]['controle'] ?? '') === 'Comptoir des pâtisseries (CQ — Vitrines)');
verifie('le contrôle qui revient sans photo est compté', ($cp['sansPhotoSeptDerniersJours'][0] ?? null) === ['controle' => 'Présentoir à pains (CQ — Vitrines)', 'fois' => 2]);
$txt = json_encode($cp, JSON_UNESCAPED_UNICODE);
verifie('rien de nominatif : ni qui a fait, ni qui a noté', !str_contains($txt, 'Julie') && !str_contains($txt, 'Marc'));

// --- le budget, la garde -----------------------------------------------------------------
echo "La lecture du panel\n";
$attendus = (strtotime($auj) - strtotime(min(date('Y-m-01'), $j(6)))) / 86400 + 1;
verifie('une lecture reste dans son budget', count(PanelApi::$lus) === min(AM_CQ_BUDGET, (int) $attendus), count(PanelApi::$lus) . ' lus');
verifie('les jours les plus récents sont lus d’abord', str_ends_with(PanelApi::$lus[0], $auj));
verifie('la couverture est dite tant qu’il manque des jours', $attendus > AM_CQ_BUDGET ? isset($cp['couverture']) : !isset($cp['couverture']));
$n = count(PanelApi::$lus);
amControlesPhotos(7);
$n2 = count(PanelApi::$lus) - $n;
verifie('la deuxième lecture ne relit que les jours manquants', $n2 === max(0, (int) $attendus - AM_CQ_BUDGET), $n2 . ' relus');
// La journée lue par la fiche sert telle quelle : cqmGarder la garde, cqmJournees ne la relit pas.
Db::exec('DELETE FROM ceo_app_setting WHERE key = ?', [cqmCle(9, $auj)]);
cqmGarder(9, $auj, ['tasks' => [$t(20, 'Vitrine', ['attachment_id' => 3, 'review_rating' => 3, 'review_comment' => 'Trop peu de choix'])], 'summary' => []]);
$n = count(PanelApi::$lus);
$L = cqmJournees([[9, $auj]]);
verifie('la journée lue par la fiche n’est pas relue au panel', count(PanelApi::$lus) === $n && ($L['jours'][9][$auj][0]['note'] ?? null) === 3);

// --- les coachs -----------------------------------------------------------------------------
echo "Les trois coachs\n";
verifie('la règle commune est dans le message système', str_contains(AM_SYSTEME, 'controlesPhotos') && str_contains(AM_SYSTEME, 'Tous les personnages les reprennent'));
foreach (array_keys(AM_COACHS) as $k) {
    verifie(AM_COACHS[$k]['nom'] . ' : le personnage parle des photos de contrôle', str_contains(amPersona($k), 'photo'));
}
verifie('« n’en parle pas » garde l’exception des photos (Coach Vente)', str_contains(amPersona('vente'), "sauf les résultats des contrôles en photo"));
verifie('l’exception ne touche que les contrôles', !str_contains(amPersona('vente'), "Budget : n'en parle"), amPersona('vente'));
Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?)', ['assistantCoachs', json_encode(['pierre' => ['accents' => ['controles' => ['niveau' => 'non', 'verifie' => 'Rien.']]]])]);
verifie('un coach réglé sur « n’en parle pas » au cockpit garde l’exception', str_contains(amPersona('pierre'), 'sauf les résultats des contrôles en photo'));
verifie('une question toute prête porte sur les photos', in_array('Mes photos de contrôle sont-elles conformes ?', array_merge(...array_map(static fn ($g) => $g[1], AM_QUESTIONS['groupes'])), true));

// --- l'écran Contrôles qualité du cockpit, après la mise en commun --------------------------
echo "L'écran Contrôles qualité\n";
$_GET = ['m' => substr($auj, 0, 7), 'shop' => '7'];
$e = ep_controles_qualite();
$aujL = array_values(array_filter($e['lignes'], static fn ($l) => $l['date'] === $auj));
verifie('les lignes du jour, avec leur état', array_column($aujL, 'etat') === [4, 'a_noter', 'en_attente'], json_encode(array_column($aujL, 'etat')));
verifie('le magasin garde son nom court, jamais la société', ($e['magasins'][0]['nom'] ?? '') === 'Magasin 7');
verifie('le barème vient du réglage', $e['seuil'] === 4 && count($e['niveaux']) === 5);

echo $ko ? "\n$ko vérification(s) en échec sur " . ($ok + $ko) . ".\n" : "\nTout est vert : $ok vérifications.\n";
exit($ko ? 1 : 0);
