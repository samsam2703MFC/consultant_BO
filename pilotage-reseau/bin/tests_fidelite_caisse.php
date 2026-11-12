#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de la carte de fidélité à la caisse (12/11/2026, src/fidelite_caisse.php) — base SQLite en mémoire, panel
 * simulé, sans réseau.
 *
 *   php bin/tests_fidelite_caisse.php
 *
 * Ce qui se vérifie : la clé de caisse (inconnue, lien d'écran), l'état que la caisse lit avant la question ; la
 * remise refusée avant le lancement, la remise d'essai ; « oui » (la carte), « non » (la carte et un bon papier, puis un
 * code imprimé) ; la même `ref` ou un double appui qui ne doublent rien ; le bon du magasin avant celui du réseau ; pas
 * de bon (règle coupée, plafond du jour) ; la création du code au panel (réussie, refusée, début refusé, relance) ;
 * l'annulation à la caisse (code désactivé ou repris au battement, trop tard, autre magasin) ; la mise en route (cartes,
 * campagne vérifiée, dates) ; le suivi (étapes, comptes, stock, cartes pour 100 tickets, membres, alertes, vendeuses) ;
 * le déclencheur « carte » dans le Programme.
 */

ob_start();
date_default_timezone_set('Europe/Brussels');

// --- doublures ---------------------------------------------------------------------------
final class Db
{
    public static ?PDO $p = null;
    public static function pdo(): PDO
    {
        if (self::$p === null) {
            self::$p = new PDO('sqlite::memory:');
            self::$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$p->exec('CREATE TABLE ceo_fid_regle (id INTEGER PRIMARY KEY AUTOINCREMENT, bloc TEXT, type TEXT, params_json TEXT, shop_id TEXT DEFAULT "",
                actif INT DEFAULT 1, sort_order INT DEFAULT 0, updated_at TEXT NULL, updated_by TEXT NULL)');
            self::$p->exec('CREATE TABLE ceo_fid_visite (id INTEGER PRIMARY KEY AUTOINCREMENT, client_ref TEXT, shop_id TEXT, visited_at TEXT, montant REAL, mission TEXT NULL)');
            self::$p->exec('CREATE TABLE ceo_fid_carte (id INTEGER PRIMARY KEY AUTOINCREMENT, shop_id INT, remise_le TEXT, premiere INT, essai INT DEFAULT 0, ref TEXT NULL,
                carte TEXT NULL, emp INT NULL, borne_id INT NULL, regle_id INT NULL, bon_mode TEXT NULL, bon_libelle TEXT NULL, bon_valeur REAL NULL, bon_code TEXT NULL,
                bon_des TEXT NULL, bon_fin TEXT NULL, bon_campagne INT NULL, bon_etat TEXT NULL, bon_code_id INT NULL, bon_essais INT DEFAULT 0, bon_erreur TEXT NULL,
                motif TEXT NULL, annule_le TEXT NULL, UNIQUE (shop_id, ref), UNIQUE (bon_code))');
            self::$p->exec('CREATE TABLE ceo_fid_lancement (shop_id INT PRIMARY KEY, cartes_recues INT DEFAULT 0, campagne INT NULL, campagne_nom TEXT NULL,
                equipe_le TEXT NULL, lance_le TEXT NULL, note TEXT NULL, maj_le TEXT NULL, maj_par TEXT NULL)');
            self::$p->exec('CREATE TABLE ceo_app_setting (key TEXT PRIMARY KEY, value TEXT)');
            self::$p->exec("CREATE TABLE mot_borne (id INTEGER PRIMARY KEY AUTOINCREMENT, shop INT, nom TEXT, jeton_hash TEXT UNIQUE, sorte TEXT DEFAULT 'ecran',
                actif INT DEFAULT 1, cree TEXT DEFAULT CURRENT_TIMESTAMP, vue_le TEXT NULL)");
            self::$p->exec('CREATE TABLE ceo_pwa_jour (shop_id INT, jour TEXT, tickets INT, identifies INT DEFAULT 0, lu_le TEXT NULL, essais INT DEFAULT 0)');
            self::$p->exec('CREATE TABLE ceo_pwa_visite (shop_id INT, jour TEXT, client_id INT, tickets INT DEFAULT 1)');
            self::$p->exec('CREATE TABLE ceo_pwa_client (client_id INT PRIMARY KEY, pwa INT NULL, fidelite INT NULL, membre_depuis TEXT NULL, lu_le TEXT)');
        }
        return self::$p;
    }
    private static function sql(string $sql): string
    {
        $sql = str_replace('NOW()', "datetime('now')", $sql);
        return str_replace('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', 'INSERT OR REPLACE INTO ceo_app_setting VALUES (?,?)', $sql);
    }
    public static function rows(string $sql, array $a = []): array { $s = self::pdo()->prepare(self::sql($sql)); $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC); }
    public static function row(string $sql, array $a = []): ?array { return self::rows($sql, $a)[0] ?? null; }
    public static function exec(string $sql, array $a = []): int
    {
        if (str_starts_with(ltrim($sql), 'CREATE TABLE IF NOT EXISTS')) { return 0; }
        $s = self::pdo()->prepare(self::sql($sql)); $s->execute($a); return $s->rowCount();
    }
}
/** Le panel simulé : les réponses à rendre, dans l'ordre, et les appels reçus. */
final class ErpApi
{
    public static bool $dispo = true;
    public static array $reponses = [];
    public static array $appels = [];
    public static function disponible(): bool { return self::$dispo; }
    public static function ecrire(string $m, string $p, ?array $b = null): array
    {
        self::$appels[] = [$m, $p, $b];
        return array_shift(self::$reponses) ?? ['code' => 201, 'corps' => ['data' => ['id' => 9000 + count(self::$appels)]], 'erreur' => null];
    }
}
$GLOBALS['FICHES'] = [];
function pbFiche(int $id): ?array { return $GLOBALS['FICHES'][$id] ?? null; }
$GLOBALS['JOURNAL'] = [];
function journalAdd(string $actor, string $kind, ?string $project, string $message, ?string $ts = null): void { $GLOBALS['JOURNAL'][] = $message; }
$GLOBALS['CORPS'] = [];
function body(): array { return $GLOBALS['CORPS']; }
function setting(string $key, mixed $default = null): mixed
{
    $r = Db::row('SELECT value FROM ceo_app_setting WHERE key = ?', [$key]);
    return $r === null ? $default : json_decode((string) $r['value'], true);
}
function ep_stores(): array
{
    return [['id' => '2', 'nom' => 'Corbais', 'status' => 'Ouvert'], ['id' => '4', 'nom' => 'Halle', 'status' => 'Ouvert'],
        ['id' => '5', 'nom' => 'Sombreffe', 'status' => 'Ouvert'], ['id' => '9', 'nom' => 'Fermé', 'status' => 'Fermé']];
}
function planNomsMagasins(): array { return [2 => 'Corbais', 4 => 'Halle', 5 => 'Sombreffe']; }
function venteEmployes(): array { return [12 => ['nom' => 'Bram Claes', 'shop' => '4'], 11 => ['nom' => 'Anna Peeters', 'shop' => '4']]; }
/** La clé de caisse (src/pointage.php) : la borne active du jeton. */
function ptgBorneDuJeton(string $jeton): ?array
{
    return Db::row('SELECT * FROM mot_borne WHERE jeton_hash = ? AND actif = 1', [hash('sha256', $jeton)]);
}

require __DIR__ . '/../src/fidelite.php';
require __DIR__ . '/../src/fidelite_caisse.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
/** Un appel de route : le corps posé, le code HTTP rendu. */
function appel(callable $f, array $corps = [], array $get = []): array
{
    http_response_code(200);
    $GLOBALS['CORPS'] = $corps; $_GET = $get;
    $r = $f();
    return [http_response_code(), $r];
}
$t = static fn (string $quand) => strtotime($quand);

// Deux clés de caisse (Halle, Corbais) et le lien d'un écran (Halle).
foreach ([[4, 'Caisse', 'CLE-HALLE-0123456789abcdef', 'caisse'], [2, 'Caisse', 'CLE-CORBAIS-0123456789abcd', 'caisse'], [4, 'Tablette', 'LIEN-ECRAN-0123456789abcde', 'ecran']] as [$s, $n, $j, $so]) {
    Db::exec('INSERT INTO mot_borne (shop, nom, jeton_hash, sorte) VALUES (?,?,?,?)', [$s, $n, hash('sha256', $j), $so]);
}
const HALLE = 'CLE-HALLE-0123456789abcdef';
const CORBAIS = 'CLE-CORBAIS-0123456789abcd';
$T0 = $t('2026-11-14 09:12:00');

echo "La clé de caisse, l'état avant la question\n";
[$c] = appel(fn () => ep_fidelite_caisse_etat($T0), [], ['b' => 'CLE-INCONNUE-0123456789']);
verifie('clé inconnue : 404', $c === 404);
[$c] = appel(fn () => ep_fidelite_caisse_etat($T0), [], ['b' => 'LIEN-ECRAN-0123456789abcde']);
verifie('le lien d’un écran : 403 (il se voit sur la tablette)', $c === 403);
[$c, $e] = appel(fn () => ep_fidelite_caisse_etat($T0), [], ['b' => HALLE]);
verifie('état : Halle, pas encore lancé', $c === 200 && $e['shop'] === 4 && $e['magasin'] === 'Halle' && $e['lance'] === false, json_encode($e));
verifie('la question et ses deux réponses, en français et en néerlandais', $e['question']['fr'] === 'C’est la première fois que vous venez ?'
    && $e['question']['nl'] !== '' && str_contains($e['oui']['fr'], 'carte QR') && str_contains($e['non']['fr'], 'bon de 2 €') && str_contains($e['non']['nl'], 'bon van 2 €'), json_encode($e));
verifie('le bon des déjà-venus (règle du réseau posée d’office) : 2 €, papier, 30 jours', $e['bon'] === ['mode' => 'papier', 'libelle' => 'Bon de 2 €', 'valeur' => 2.0, 'jours' => 30], json_encode($e['bon']));
verifie('la règle « carte » posée une fois (réglage fidCarteSemee)', setting('fidCarteSemee') !== null
    && count(array_filter(fidRegles(), fn ($r) => $r['type'] === 'declencheur' && $r['params']['evenement'] === 'carte')) === 1);

echo "\nAvant le lancement\n";
[$c, $r] = appel(fn () => wr_fidelite_caisse_carte($T0), ['b' => HALLE, 'premiere' => true]);
verifie('une remise avant le lancement : 409', $c === 409 && ($r['lance'] ?? null) === false);
[$c, $r] = appel(fn () => wr_fidelite_caisse_carte($T0), ['b' => HALLE, 'premiere' => false, 'essai' => true, 'ref' => 'essai-szymon-1']);
verifie('une remise d’essai passe : marquée ESSAI, bon papier, rien au panel', $c === 200 && $r['essai'] === true && $r['bon']['mode'] === 'papier'
    && str_starts_with($r['ecran']['fr'], 'ESSAI — ') && ErpApi::$appels === [], json_encode($r));
[$c, $r] = appel(fn () => wr_fidelite_caisse_carte($T0), ['b' => HALLE]);
verifie('premiere absent : 400', $c === 400);
[$c, $r] = appel(fn () => wr_fidelite_caisse_carte($T0), ['b' => HALLE, 'premiere' => 'oui']);
verifie('premiere « oui » (texte) : 400', $c === 400);

echo "\nLa mise en route\n";
[$c, $r] = appel(fn () => wr_fidelite_caisse_lancement(), ['shop' => 4, 'cartesRecues' => 500, 'equipeLe' => '2026-11-13', 'lanceLe' => '2026-11-14', 'qui' => 'Sam']);
verifie('Halle : 500 cartes, équipe briefée le 13, lancé le 14', $c === 200 && $r['lancement']['cartesRecues'] === 500 && $r['lancement']['lanceLe'] === '2026-11-14'
    && $r['lancement']['equipeLe'] === '2026-11-13' && $r['lancement']['campagne'] === null, json_encode($r));
verifie('journalisé', str_contains(end($GLOBALS['JOURNAL']), 'Halle : 500 cartes reçues, équipe briefée le 13/11/2026, lancement le 14/11/2026'), end($GLOBALS['JOURNAL']));
[$c, $r] = appel(fn () => wr_fidelite_caisse_lancement(), ['shop' => 4, 'cartesAjout' => 250]);
verifie('une livraison de 250 s’ajoute : 750', $r['lancement']['cartesRecues'] === 750);
[$c] = appel(fn () => wr_fidelite_caisse_lancement(), ['shop' => 4, 'lanceLe' => '14/11/2026']);
verifie('une date mal écrite : 422', $c === 422);
[$c] = appel(fn () => wr_fidelite_caisse_lancement(), ['cartesRecues' => 10]);
verifie('sans magasin : 400', $c === 400);
[$c, $e] = appel(fn () => ep_fidelite_caisse_etat($T0), [], ['b' => HALLE]);
verifie('l’état : lancé', $e['lance'] === true);

echo "\n« Oui » : la carte\n";
[$c, $r] = appel(fn () => wr_fidelite_caisse_carte($T0), ['b' => HALLE, 'premiere' => true, 'ref' => 'pos-halle-0001', 'emp' => 12, 'carte' => " AB-12\n34 "]);
verifie('la carte seule, la consigne en deux langues', $c === 200 && $r['remise'] === 'carte' && $r['bon'] === null
    && $r['ecran'] === ['fr' => 'Donnez la carte QR au client.', 'nl' => 'Geef de klant de QR-kaart.'] && $r['ticket'] === null, json_encode($r));
$id1 = $r['id'];
$g = Db::row('SELECT * FROM ceo_fid_carte WHERE id = ?', [$id1]);
verifie('gardée : Halle, la vendeuse, le numéro de carte nettoyé, la caisse vue', (int) $g['shop_id'] === 4 && (int) $g['emp'] === 12 && $g['carte'] === 'AB-1234'
    && Db::row('SELECT vue_le FROM mot_borne WHERE id = 1')['vue_le'] === '2026-11-14 09:12:00', json_encode($g));
[$c, $r] = appel(fn () => wr_fidelite_caisse_carte($T0 + 30), ['b' => HALLE, 'premiere' => true, 'ref' => 'pos-halle-0001']);
verifie('la même ref renvoyée : la même remise, rien de neuf', $r['id'] === $id1 && !empty($r['repris']) && Db::row('SELECT COUNT(*) n FROM ceo_fid_carte WHERE essai = 0')['n'] == 1);
[$c, $r] = appel(fn () => wr_fidelite_caisse_carte($T0 + 60), ['b' => HALLE, 'premiere' => true]);
$id2 = $r['id'];
[$c, $r] = appel(fn () => wr_fidelite_caisse_carte($T0 + 62), ['b' => HALLE, 'premiere' => true]);
verifie('sans ref, deux appuis en 2 s : une seule remise', $r['id'] === $id2 && !empty($r['repris']));
[$c, $r] = appel(fn () => wr_fidelite_caisse_carte($T0 + 70), ['b' => HALLE, 'premiere' => true]);
verifie('10 s plus tard : un autre client', $r['id'] !== $id2 && empty($r['repris']));

echo "\n« Non » : la carte et un bon papier\n";
[$c, $r] = appel(fn () => wr_fidelite_caisse_carte($T0 + 100), ['b' => HALLE, 'premiere' => false, 'ref' => 'pos-halle-0002', 'emp' => 11]);
verifie('la carte et un bon papier de 2 €, valable du 15/11 au 14/12', $r['remise'] === 'carte_bon' && $r['bon']['mode'] === 'papier' && $r['bon']['code'] === null
    && $r['bon']['libelle'] === 'Bon de 2 €' && $r['bon']['des'] === '2026-11-15' && $r['bon']['jusqu_au'] === '2026-12-14', json_encode($r));
verifie('la consigne : « un bon papier »', str_contains($r['ecran']['fr'], 'un bon papier : Bon de 2 €, valable jusqu’au 14/12/2026') && str_contains($r['ecran']['nl'], 'papieren bon: Bon van 2 €'), json_encode($r['ecran']));
verifie('rien au panel pour un bon papier', ErpApi::$appels === [] && Db::row('SELECT bon_etat FROM ceo_fid_carte WHERE id = ?', [$r['id']])['bon_etat'] === 'papier');

echo "\nLa campagne du magasin : le code imprimé\n";
$GLOBALS['FICHES'] = [
    77 => ['campaign' => ['id' => 77, 'name' => 'Bienvenue fidélité Halle', 'code_type' => 'UNIQUE', 'requires_customer' => false, 'status' => 'ACTIVE']],
    78 => ['campaign' => ['id' => 78, 'name' => 'Code pour tous', 'code_type' => 'SHARED', 'status' => 'ACTIVE']],
    79 => ['campaign' => ['id' => 79, 'name' => 'Clients B2B', 'code_type' => 'UNIQUE', 'requires_customer' => true, 'status' => 'ACTIVE']],
    80 => ['campaign' => ['id' => 80, 'name' => 'Brouillon', 'code_type' => 'UNIQUE', 'requires_customer' => false, 'status' => 'DRAFT']],
];
[$c] = appel(fn () => wr_fidelite_caisse_lancement(), ['shop' => 4, 'campagne' => 78]);
verifie('une campagne à code pour tous : 422', $c === 422);
[$c] = appel(fn () => wr_fidelite_caisse_lancement(), ['shop' => 4, 'campagne' => 79]);
verifie('une campagne qui impose un client : 422', $c === 422);
[$c] = appel(fn () => wr_fidelite_caisse_lancement(), ['shop' => 4, 'campagne' => 12345]);
verifie('une campagne introuvable : 404', $c === 404);
[$c, $r] = appel(fn () => wr_fidelite_caisse_lancement(), ['shop' => 5, 'campagne' => 80]);
verifie('une campagne pas active : acceptée, avec un avertissement', $c === 200 && $r['lancement']['campagne'] === 80 && count($r['avis']) === 1);
ErpApi::$dispo = false;
[$c] = appel(fn () => wr_fidelite_caisse_lancement(), ['shop' => 4, 'campagne' => 77]);
verifie('panel injoignable : 503, rien de posé', $c === 503 && fcaLancement(4)['campagne'] === null);
ErpApi::$dispo = true;
[$c, $r] = appel(fn () => wr_fidelite_caisse_lancement(), ['shop' => 4, 'campagne' => 77]);
verifie('la campagne 77 posée, son nom gardé', $c === 200 && $r['lancement']['campagne'] === 77 && $r['lancement']['campagneNom'] === 'Bienvenue fidélité Halle' && $r['avis'] === []);
[$c, $r] = appel(fn () => wr_fidelite_caisse_carte($T0 + 200), ['b' => HALLE, 'premiere' => false, 'ref' => 'pos-halle-0003']);
$code = $r['bon']['code'] ?? '';
verifie('un code « FID » + 8 signes lisibles, rendu tout de suite', $r['bon']['mode'] === 'code' && (bool) preg_match('/^FID[A-HJ-NP-Z2-9]{8}$/', $code), $code);
verifie('le ticket à imprimer : le code, les dates, en deux langues', in_array('Code : ' . $code, $r['ticket'], true) && in_array('Valable du 15/11/2026 au 14/12/2026', $r['ticket'], true)
    && in_array('Geldig van 15/11/2026 tot 14/12/2026', $r['ticket'], true) && in_array('Bon de 2 € sur votre prochain achat', $r['ticket'], true), json_encode($r['ticket']));
verifie('la consigne : « le bon imprimé »', str_contains($r['ecran']['fr'], 'le bon imprimé (Bon de 2 €)'));
$id3 = $r['id'];
verifie('en attente de création au panel', Db::row('SELECT bon_etat FROM ceo_fid_carte WHERE id = ?', [$id3])['bon_etat'] === 'a_creer' && ErpApi::$appels === []);
$res = fcaPousser(1, $id3, $T0 + 201);
$a = ErpApi::$appels[0] ?? null;
verifie('créé au panel : POST …/77/codes, une utilisation, dès demain', $a !== null && $a[0] === 'POST' && $a[1] === '/admin/voucher-campaigns/77/codes'
    && $a[2] === ['code' => $code, 'status' => 'ACTIVE', 'usage_limit' => 1, 'valid_from' => '2026-11-15 00:00:00', 'valid_to' => '2026-12-14 23:59:59'], json_encode($a));
$g = Db::row('SELECT * FROM ceo_fid_carte WHERE id = ?', [$id3]);
verifie('état « créé », le numéro du code gardé', $g['bon_etat'] === 'cree' && (int) $g['bon_code_id'] === 9001 && $res === '1 code(s) créé(s)', $res . ' ' . json_encode($g));

echo "\nLe panel qui refuse\n";
ErpApi::$appels = [];
[$c, $r] = appel(fn () => wr_fidelite_caisse_carte($T0 + 300), ['b' => HALLE, 'premiere' => false, 'ref' => 'pos-halle-0004']);
$id4 = $r['id'];
ErpApi::$reponses = [['code' => 422, 'corps' => null, 'erreur' => 'HTTP 422 — valid_from : must not be in the future'], ['code' => 201, 'corps' => ['data' => ['code' => ['id' => 555]]], 'erreur' => null]];
fcaPousser(1, $id4, $T0 + 301);
verifie('un début refusé : recréé sans début', count(ErpApi::$appels) === 2 && ErpApi::$appels[1][2]['valid_from'] === null
    && Db::row('SELECT bon_etat, bon_code_id FROM ceo_fid_carte WHERE id = ?', [$id4]) == ['bon_etat' => 'cree', 'bon_code_id' => 555]);
[$c, $r] = appel(fn () => wr_fidelite_caisse_carte($T0 + 400), ['b' => HALLE, 'premiere' => false, 'ref' => 'pos-halle-0005']);
$id5 = $r['id'];
for ($i = 0; $i < FCA_ESSAIS; $i++) { ErpApi::$reponses[] = ['code' => 500, 'corps' => null, 'erreur' => 'HTTP 500']; fcaPousser(1, $id5, $T0 + 401 + $i); }
$g = Db::row('SELECT * FROM ceo_fid_carte WHERE id = ?', [$id5]);
verifie('refusé ' . FCA_ESSAIS . ' fois : « échec », l’erreur gardée', $g['bon_etat'] === 'echec' && (int) $g['bon_essais'] === FCA_ESSAIS && $g['bon_erreur'] === 'HTTP 500');
ErpApi::$dispo = false;
verifie('panel injoignable au battement : rien ne bouge', str_contains(fcaPousser(10, null, $T0), 'indisponible') || fcaPousser(10, null, $T0) === 'rien en attente');
ErpApi::$dispo = true;
[$c, $r] = appel(fn () => wr_fidelite_caisse_pousser(), ['id' => $id5]);
verifie('relancé depuis le cockpit : créé', $r['relances'] === 1 && Db::row('SELECT bon_etat FROM ceo_fid_carte WHERE id = ?', [$id5])['bon_etat'] === 'cree', json_encode($r));
Db::exec("INSERT INTO ceo_fid_carte (shop_id, remise_le, premiere, bon_mode, bon_code, bon_des, bon_fin, bon_campagne, bon_etat) VALUES (4, '2026-10-01 10:00:00', 0, 'code', 'FIDVIEUX2345', '2026-10-02', '2026-10-31', 77, 'a_creer')");
$idV = (int) Db::pdo()->lastInsertId();
fcaPousser(5, $idV, $T0);
verifie('un code périmé avant d’être créé : « échec », sans appel', Db::row('SELECT bon_etat FROM ceo_fid_carte WHERE id = ?', [$idV])['bon_etat'] === 'echec');
Db::exec('DELETE FROM ceo_fid_carte WHERE id = ?', [$idV]);

echo "\nAnnuler depuis la caisse\n";
ErpApi::$appels = [];
[$c, $r] = appel(fn () => wr_fidelite_caisse_annuler($T0 + 600), ['b' => HALLE, 'id' => $id3]);
verifie('le code créé se désactive au panel', $c === 200 && $r['bon'] === 'annule' && (ErpApi::$appels[0] ?? null) === ['PATCH', '/admin/voucher-codes/9001/status', ['status' => 'DISABLED']], json_encode([$r, ErpApi::$appels]));
[$c, $r] = appel(fn () => wr_fidelite_caisse_annuler($T0 + 601), ['b' => HALLE, 'id' => $id3]);
verifie('annulé deux fois : rien de plus', $c === 200 && !empty($r['deja']) && count(ErpApi::$appels) === 1);
ErpApi::$reponses = [['code' => 500, 'corps' => null, 'erreur' => 'HTTP 500']];
[$c, $r] = appel(fn () => wr_fidelite_caisse_annuler($T0 + 602), ['b' => HALLE, 'id' => $id4]);
verifie('le panel résiste : « à retirer »', $r['bon'] === 'a_retirer');
fcaPousser(10, null, $T0 + 3600);
verifie('repris au battement : désactivé', Db::row('SELECT bon_etat FROM ceo_fid_carte WHERE id = ?', [$id4])['bon_etat'] === 'annule');
[$c] = appel(fn () => wr_fidelite_caisse_annuler($T0 + 2000), ['b' => HALLE, 'id' => $id5]);
verifie('plus de 15 minutes après : 422', $c === 422);
[$c] = appel(fn () => wr_fidelite_caisse_annuler($T0 + 450), ['b' => CORBAIS, 'id' => $id5]);
verifie('la remise d’un autre magasin : 404', $c === 404);

echo "\nLe bon du magasin, pas de bon\n";
$GLOBALS['CORPS'] = ['regles' => [['type' => 'declencheur', 'shop' => '4', 'actif' => true, 'params' => ['evenement' => 'carte', 'recompense' => ['type' => 'bon', 'valeur' => 5], 'validite' => 14]]]];
wr_fidelite_regles_post();
[$c, $r] = appel(fn () => wr_fidelite_caisse_carte($T0 + 700), ['b' => HALLE, 'premiere' => false, 'essai' => true]);
verifie('le bon de Halle (5 €, 14 jours) avant celui du réseau ; un essai : code ESSAI, jamais créé', $r['bon']['libelle'] === 'Bon de 5 €' && $r['bon']['jusqu_au'] === '2026-11-28'
    && str_starts_with((string) $r['bon']['code'], 'ESSAI') && in_array('*** ESSAI — NON VALABLE ***', $r['ticket'], true)
    && Db::row('SELECT bon_etat FROM ceo_fid_carte WHERE id = ?', [$r['id']])['bon_etat'] === 'essai', json_encode($r));
$idHalle = array_values(array_filter(fidRegles(), fn ($x) => ($x['params']['evenement'] ?? '') === 'carte' && $x['shop'] === '4'))[0]['id'];
$GLOBALS['CORPS'] = ['regles' => [['id' => $idHalle, 'shop' => '4', 'actif' => true, 'params' => ['evenement' => 'carte', 'recompense' => ['type' => 'produit', 'libelle' => 'un café offert'], 'validite' => 7]]]];
wr_fidelite_regles_post();
[$c, $r] = appel(fn () => wr_fidelite_caisse_carte($T0 + 710), ['b' => HALLE, 'premiere' => false, 'essai' => true]);
verifie('un produit offert : le libellé, sans valeur', $r['bon']['libelle'] === 'un café offert' && $r['bon']['valeur'] === null && in_array('Un café offert à votre prochaine visite', $r['ticket'], true), json_encode($r['ticket']));
$GLOBALS['CORPS'] = ['regles' => [['type' => 'declencheur', 'actif' => true, 'params' => ['evenement' => 'carte', 'recompense' => ['type' => 'points', 'valeur' => 50]]]]];
wr_fidelite_regles_post();
$der = array_values(array_filter(fidRegles(), fn ($x) => ($x['params']['evenement'] ?? '') === 'carte'));
verifie('des points à la caisse (pas de compte) : ramenés à un bon', end($der)['params']['recompense']['type'] === 'bon');
foreach (fidRegles() as $x) { if (($x['params']['evenement'] ?? '') === 'carte' && $x['shop'] === '4') { Db::exec('DELETE FROM ceo_fid_regle WHERE id = ?', [$x['id']]); } }
foreach (array_slice($der, 1) as $x) { Db::exec('DELETE FROM ceo_fid_regle WHERE id = ?', [$x['id']]); }
$reseau = $der[0]['id'];
Db::exec('UPDATE ceo_fid_regle SET actif = 0 WHERE id = ?', [$reseau]);
[$c, $r] = appel(fn () => wr_fidelite_caisse_carte($T0 + 800), ['b' => HALLE, 'premiere' => false, 'ref' => 'pos-halle-0006']);
verifie('la règle coupée : la carte seule, « aucun bon prévu »', $r['remise'] === 'carte' && $r['bon'] === null && $r['motif'] === 'aucun bon prévu'
    && str_contains($r['ecran']['nl'], 'geen bon voorzien'), json_encode($r));
Db::exec('UPDATE ceo_fid_regle SET actif = 1 WHERE id = ?', [$reseau]);
$auj = date('Y-m-d', $T0);
for ($i = 0; $i < FCA_BONS_JOUR; $i++) {
    Db::exec("INSERT INTO ceo_fid_carte (shop_id, remise_le, premiere, bon_mode, bon_etat) VALUES (2, ?, 0, 'papier', 'papier')", [$auj . ' 08:00:00']);
}
fcaTables();
Db::exec('INSERT INTO ceo_fid_lancement (shop_id, cartes_recues, lance_le) VALUES (2, 300, ?)', ['2026-11-01']);
[$c, $r] = appel(fn () => wr_fidelite_caisse_carte($T0 + 900), ['b' => CORBAIS, 'premiere' => false]);
verifie('Corbais au plafond du jour (' . FCA_BONS_JOUR . ' bons) : la carte seule', $r['bon'] === null && $r['motif'] === 'plafond du jour' && str_contains($r['ecran']['nl'], 'daglimiet'), json_encode($r));

echo "\nLe suivi\n";
Db::exec("INSERT INTO ceo_pwa_jour (shop_id, jour, tickets, lu_le) VALUES (4, '2026-11-14', 400, '2026-11-14 10:00:00'), (4, '2026-11-13', 380, '2026-11-13 22:00:00')");
Db::exec("INSERT INTO ceo_pwa_client (client_id, pwa, membre_depuis, lu_le) VALUES (501, 1, '2026-11-14', 'x'), (502, 1, '2026-09-01', 'x'), (503, 0, '2026-11-14', 'x'), (504, 1, '2026-11-14', 'x')");
Db::exec("INSERT INTO ceo_pwa_visite (shop_id, jour, client_id) VALUES (4, '2026-11-14', 501), (4, '2026-11-14', 502), (4, '2026-11-14', 503), (2, '2026-11-14', 504)");
[$c, $S] = appel(fn () => ep_fidelite_caisse($T0 + 1000), [], ['jours' => 30]);
$H = array_values(array_filter($S['magasins'], fn ($m) => $m['id'] === 4))[0] ?? null;
verifie('les magasins ouverts seulement', count($S['magasins']) === 3);
// Halle : 3 « oui » (une ref, deux sans ref), 3 « non » comptés (papier, code, code refusé puis relancé) ; le 2e code annulé, les essais hors compte.
verifie('Halle : 6 cartes sur la période, 3 premières, 3 déjà venus, 2 bons (deux annulés), 1 sans bon',
    $H['periode']['cartes'] === 6 && $H['periode']['premieres'] === 3 && $H['periode']['dejaVenus'] === 3 && $H['periode']['bons'] === 2 && $H['periode']['sansBon'] === 1, json_encode($H['periode']));
verifie('le stock : 750 reçues, 6 données, 744 restent', $H['stock'] === ['recues' => 750, 'donnees' => 6, 'reste' => 744, 'jours' => 1736], json_encode($H['stock']));
verifie('cartes pour 100 tickets : 6 sur les 400 tickets du jour relevé (le 13 : aucune carte)', $H['pour100'] === round(600 / 780, 1) && $H['ticketsLus'] === 780, json_encode([$H['pour100'], $H['ticketsLus']]));
verifie('nouveaux membres de l’app vus à Halle depuis le lancement : 1', $H['membres'] === 1);
$et = array_column($H['etapes'], 'fait', 'k');
verifie('les six étapes de Halle faites', $et === ['cle' => true, 'cartes' => true, 'bon' => true, 'equipe' => true, 'lance' => true, 'premiere' => true] && $H['pret'] === 6, json_encode($et));
$So = array_values(array_filter($S['magasins'], fn ($m) => $m['id'] === 5))[0];
verifie('Sombreffe : pas de clé, pas de cartes, pas lancé', array_column($So['etapes'], 'fait', 'k')['cle'] === false && $So['lancement']['lance'] === false && $So['pret'] === 1, json_encode($So['etapes']));
$Co = array_values(array_filter($S['magasins'], fn ($m) => $m['id'] === 2))[0];
verifie('Corbais : 101 « déjà venus » sur 101 → alerte ; stock 199, ≈ 27 jours : pas d’alerte de stock',
    count(array_filter($Co['alertes'], fn ($a) => str_contains($a, 'déjà venus'))) === 1 && $Co['stock']['reste'] === 199 && !array_filter($Co['alertes'], fn ($a) => str_contains($a, 'stock')), json_encode($Co['alertes']));
verifie('la série : 30 jours, aujourd’hui 107 cartes au réseau', count($S['serie']) === 30 && end($S['serie'])['jour'] === '2026-11-14'
    && end($S['serie'])['premieres'] + end($S['serie'])['dejaVenus'] === 107, json_encode(end($S['serie'])));
verifie('les vendeuses, nommées', ($S['vendeuses'][0]['nom'] ?? '') !== '' && in_array('Bram Claes', array_column($S['vendeuses'], 'nom'), true), json_encode($S['vendeuses']));
verifie('le bon du réseau : 2 €, 30 jours', $S['regleReseau']['libelle'] === 'Bon de 2 €' && $S['regleReseau']['jours'] === 30);
[$c, $S2] = appel(fn () => ep_fidelite_caisse($T0 + 1000), [], ['jours' => 30, 'shop' => 4]);
verifie('la série de Halle seule : 6 aujourd’hui', end($S2['serie'])['premieres'] + end($S2['serie'])['dejaVenus'] === 6);
verifie('les dernières remises de Halle : essais et annulées visibles, marquées', count(array_filter($S2['dernieres'], fn ($d) => $d['essai'])) >= 1
    && count(array_filter($S2['dernieres'], fn ($d) => $d['annule'])) >= 1 && !array_filter($S2['dernieres'], fn ($d) => $d['shop'] !== 4));
// Plus de stock, plus de carte depuis trois jours.
Db::exec('UPDATE ceo_fid_lancement SET cartes_recues = 6 WHERE shop_id = 4');
[$c, $S3] = appel(fn () => ep_fidelite_caisse($t('2026-11-18 12:00')), [], []);
$H3 = array_values(array_filter($S3['magasins'], fn ($m) => $m['id'] === 4))[0];
verifie('Halle, le 18 : plus de cartes, aucune depuis trois jours', in_array('plus de cartes en stock', $H3['alertes'], true)
    && in_array('aucune carte donnée depuis 3 jours', $H3['alertes'], true), json_encode($H3['alertes']));

echo "\nLe Programme\n";
$regles = fidRegles();
$st = fidStats($regles, ['clients' => []], fidJour('2026-11-14'), '');
$idC = array_values(array_filter($regles, fn ($x) => ($x['params']['evenement'] ?? '') === 'carte'))[0]['id'];
verifie('le déclencheur « carte » : les bons remis en caisse sur 30 jours (réseau : 2 Halle + 100 Corbais)', $st['regles'][$idC]['txt'] === '102 bons remis en caisse sur 30 jours', $st['regles'][$idC]['txt']);
verifie('son coût dans le budget', abs(($st['budget']['cout'] ?? 0) - round(102 * 2 * FID_TAUX_BON + 0)) < 2, json_encode($st['budget']));
verifie('le battement horaire : une phrase', is_string(fcaCron()));

ob_end_flush();
echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
