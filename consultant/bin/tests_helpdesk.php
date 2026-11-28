#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Le helpdesk des franchisés (20/11/2026) : le SMS au bureau à chaque nouvelle demande, et la date limite que le
 * consultant fixe à la réception. Sur une base SQLite en mémoire, sans réseau : le panel et SMSAPI sont des doublures.
 *
 *   php consultant/bin/tests_helpdesk.php
 *
 * Ce qui doit rester vrai : une demande envoyée du dashboard fait partir un SMS (magasin, sujet, titre, début de la
 * demande, référence ; sans lien) à chaque numéro du réglage, une seule fois par numéro, en alphabet SMS ; un
 * destinataire sans numéro ou un SMS refusé n'empêche jamais la demande ; SMS coupés, rien ne part. La date limite
 * n'accepte que 2, 5, 10, 30 ou 90 jours, se retire avec 0, fait passer « En cours » une demande « Nouvelle », et une
 * demande ouverte au-delà est en retard ; le franchisé voit la date, pas qui l'a fixée.
 */

set_error_handler(static fn (int $n, string $m): bool => str_contains($m, 'http_response_code'), E_WARNING);

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

// --- doublures : la base, les réglages, le journal, le panel, SMSAPI ------------------------------------------
final class Db
{
    public static ?PDO $p = null;
    public static function pdo(): PDO
    {
        if (!self::$p) { self::$p = new PDO('sqlite::memory:'); self::$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
        return self::$p;
    }
    public static function fx(string $q): string
    {
        $q = str_replace('INT AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $q);
        $q = (string) preg_replace('/,\s*(UNIQUE )?KEY \w+ \([^)]*\)/', '', $q);
        $q = (string) preg_replace('/ON DUPLICATE KEY UPDATE (\w+) = VALUES\(\w+\)/', 'ON CONFLICT DO UPDATE SET $1 = excluded.$1', $q);
        return $q;
    }
    public static function exec(string $q, array $a = []): int { $s = self::pdo()->prepare(self::fx($q)); $s->execute($a); return $s->rowCount(); }
    public static function rows(string $q, array $a = []): array { $s = self::pdo()->prepare($q); $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC); }
    public static function row(string $q, array $a = []): ?array { $r = self::rows($q, $a); return $r[0] ?? null; }
}
function body(): array { return $GLOBALS['corps'] ?? []; }
$REGLAGES = [];
function setting(string $k, mixed $d = null): mixed { return $GLOBALS['REGLAGES'][$k] ?? $d; }
function settingEcrire(string $k, mixed $v): void { $GLOBALS['REGLAGES'][$k] = json_decode((string) json_encode($v), true); }
$JOURNAL = [];
function journalAdd(string $actor, string $kind, ?string $project, string $message): void { $GLOBALS['JOURNAL'][] = $message; }
function rapBaseUrl(): string { return 'https://cockpit.exemple/consulant_bo'; }
function viMagasins(): array { return ['4' => ['id' => '4', 'nom' => 'Atelier by Harmonie - Sombreffe', 'court' => 'Sombreffe']]; }
function vrcDeballer(mixed $d, array $cles = []): ?array
{
    if (!is_array($d) || $d === []) { return null; }
    foreach ($cles as $k) { if (isset($d[$k]) && is_array($d[$k]) && !array_is_list($d[$k])) { $d = $d[$k]; } }
    return array_is_list($d) ? null : $d;
}

/** Le panel : POST /cases rend la demande créée ; les PATCH sont notés. */
final class PanelApi
{
    public static ?string $lastError = null;
    public static ?string $jetonImpose = 'jeton-de-test';
    public static array $envois = [];
    public static array $cases = [];
    public static function configured(): bool { return true; }
    public static function liste(mixed $r): array { return is_array($r) && isset($r['data']) && is_array($r['data']) ? $r['data'] : []; }
    public static function get(string $path): mixed { return ['data' => self::$cases]; }
    public static function envoi(string $m, string $path, array $corps): array
    {
        self::$envois[] = [$m, $path, $corps];
        if ($m === 'POST' && $path === '/cases') {
            return [true, ['data' => ['id' => 501, 'ref' => 'CASE-20261120-AB12C', 'shop_id' => $corps['shop_id'], 'shop_name' => 'Atelier by Harmonie - Sombreffe',
                'requester_name' => 'Marie', 'title' => $corps['title'], 'area_key' => $corps['area_key'], 'status' => 'NEW']]];
        }
        return [true, ['ok' => true]];
    }
}

/** SMSAPI : chaque SMS noté ; un numéro listé dans $refuse est refusé. */
final class MdSms
{
    public static array $partis = [];
    public static array $refuse = [];
    public static bool $regle = true;
    public static function configured(): bool { return self::$regle; }
    public static function envoyer(string $tel, string $texte, string $from = ''): array
    {
        if (in_array($tel, self::$refuse, true)) { return [false, '(13) numéro refusé']; }
        self::$partis[] = ['a' => $tel, 'texte' => $texte];
        return [true, 'id' . count(self::$partis)];
    }
}

// Le GSM et la langue du franchisé (consultant/src/suivi.php › suTel, suLangue) : Sombreffe (4) en français, Halle (7)
// en néerlandais, Corbais (2) sans GSM.
function suTel(string $shop, ?array $regle = null): array { return ['4' => ['tel' => '+32470999888', 'source' => 'Qui fait quoi'], '7' => ['tel' => '+32470777666', 'source' => 'Qui fait quoi']][$shop] ?? ['tel' => null, 'source' => null]; }
function suLangue(string $shop, ?array $regle = null): string { return $shop === '7' ? 'nl' : 'fr'; }
require __DIR__ . '/../../shared/src/sms.php';
require __DIR__ . '/../src/helpdesk.php';

function nouvelle(array $corps = []): array
{
    $GLOBALS['corps'] = $corps + ['shop' => 4, 'domaine' => 'sales', 'titre' => 'Démarchage sur Nivelles Sud',
        'description' => "En livraison chez GUEST Nivelles (sur Nivelles Sud), je souhaite développer les livraisons (et services traiteurs et boulangerie) sur ce secteur : l’hôpital, les écoles, les bureaux du zoning. Peut-on en parler à la prochaine visite ? Merci « d’avance » 🙂",
        'passage' => true, 'photos' => []];
    return wr_helpdesk_magasin();
}

echo "Le texte du SMS\n";
verifie('alphabet SMS : apostrophes et guillemets droits, accents hors alphabet retirés (ê, û, ç), émojis enlevés, é et è gardés',
    hdkGsm("L’été « fête » — crème brûlée 🙂 ça") === "L'été \"fete\" - crème brulée ca",
    hdkGsm("L’été « fête » — crème brûlée 🙂 ça"));
verifie('le résumé coupe entre deux mots, avec « ... »', hdkResume(str_repeat('mot ', 100), 30) === 'mot mot mot mot mot mot mot...', hdkResume(str_repeat('mot ', 100), 30));
verifie('une demande courte reste entière, sur une ligne', hdkResume("Une ligne\n\net  une autre") === 'Une ligne et une autre');
$t = hdkSmsTexte(['magasin' => 'Sombreffe', 'domaine' => 'Ventes', 'titre' => 'Titre', 'description' => 'Texte', 'passage' => true, 'photos' => 2, 'ref' => 'CASE-20261120-AB12C', 'demandeur' => 'Marie']);
verifie('le SMS : magasin, sujet, demandeur, titre, demande, passage, photos, référence sans CASE-',
    $t === "Demande au bureau - Sombreffe (Ventes), de Marie\nTitre\nTexte\nPassage du consultant souhaité. 2 photos. Réf. 20261120-AB12C", $t);

echo "Le réglage\n";
$R = hdkSmsReglage();
verifie('par défaut : Sam (+48725038049) et Pierre Boseret, numéro à compléter', $R['actif'] === true && $R['destinataires'] === [['nom' => 'Sam', 'tel' => '+48725038049', 'consultant' => ''], ['nom' => 'Pierre Boseret', 'tel' => '', 'consultant' => '']], json_encode($R));
$GLOBALS['corps'] = ['actif' => true, 'destinataires' => [['nom' => 'Sam', 'tel' => '+48725038049'], ['nom' => 'Pierre', 'tel' => '12']]];
$r = wr_helpdesk_sms();
verifie('un numéro illisible est refusé, rien n’est enregistré', $r['ok'] === false && !isset($REGLAGES['helpdeskSms']), json_encode($r));
$GLOBALS['corps'] = ['actif' => true, 'destinataires' => [['nom' => ' Sam ', 'tel' => '+48 725 038 049'], ['nom' => '', 'tel' => ''], ['nom' => 'Pierre Boseret', 'tel' => '0470 12 34 56']]];
$r = wr_helpdesk_sms();
verifie('enregistré : lignes vides retirées, noms nettoyés', $r['ok'] === true && $REGLAGES['helpdeskSms']['destinataires'] === [['nom' => 'Sam', 'tel' => '+48 725 038 049', 'consultant' => ''], ['nom' => 'Pierre Boseret', 'tel' => '0470 12 34 56', 'consultant' => '']], json_encode($REGLAGES['helpdeskSms'] ?? null));
verifie('la lecture rend le fournisseur et le numéro lisible', $r['fournisseur'] === 'smsapi' && $r['destinataires'][1]['lisible'] === '+32470123456', json_encode($r['destinataires']));

echo "La demande et ses SMS\n";
unset($REGLAGES['helpdeskSms']);
$r = nouvelle();
verifie('la demande part au panel', $r['ok'] === true && $r['id'] === 501 && $r['ref'] === 'CASE-20261120-AB12C', json_encode($r));
verifie('réglage par défaut : un SMS, à Sam seulement (Pierre n’a pas de numéro)', count(MdSms::$partis) === 1 && MdSms::$partis[0]['a'] === '+48725038049', json_encode(MdSms::$partis));
$sms = MdSms::$partis[0]['texte'] ?? '';
verifie('le SMS dit le magasin, le sujet, qui, le titre', str_starts_with($sms, "Demande au bureau - Sombreffe (Ventes), de Marie\nDémarchage sur Nivelles Sud\n"), $sms);
verifie('le SMS reprend le début de la demande, coupé', str_contains($sms, 'En livraison chez GUEST Nivelles') && str_contains($sms, '...') && !str_contains($sms, 'Merci'), $sms);
verifie('le SMS dit le passage souhaité et la référence, et finit par elle', str_ends_with($sms, 'Passage du consultant souhaité. Réf. 20261120-AB12C'), $sms);
verifie('le SMS ne porte aucun lien', !preg_match('~https?://|/#/~', $sms), $sms);
verifie('le SMS est en alphabet SMS (aucun ’, «, émoji)', !preg_match('/[’«»🙂]/u', $sms), $sms);
verifie('le SMS tient en trois SMS', mb_strlen($sms) <= 459, (string) mb_strlen($sms));
$der = $REGLAGES['helpdeskSmsDernier'] ?? null;
verifie('le dernier envoi est gardé : Sam parti, Pierre sans numéro', is_array($der) && $der['destinataires'] === [['nom' => 'Sam', 'ok' => true, 'motif' => null], ['nom' => 'Pierre Boseret', 'ok' => false, 'motif' => 'pas de numéro']], json_encode($der));
verifie('le journal le dit', str_contains(end($JOURNAL), 'SMS à Sam') && str_contains(end($JOURNAL), 'Pierre Boseret (pas de numéro)'), end($JOURNAL));

MdSms::$partis = [];
$REGLAGES['helpdeskSms'] = ['actif' => true, 'destinataires' => [['nom' => 'Sam', 'tel' => '+48725038049'], ['nom' => 'Pierre Boseret', 'tel' => '0470 12 34 56'], ['nom' => 'Sam (bis)', 'tel' => '0048 725 038 049']]];
nouvelle();
verifie('deux numéros : deux SMS ; le même numéro écrit autrement ne reçoit qu’un SMS', array_column(MdSms::$partis, 'a') === ['+48725038049', '+32470123456'], json_encode(array_column(MdSms::$partis, 'a')));

MdSms::$partis = []; MdSms::$refuse = ['+32470123456'];
$r = nouvelle(['passage' => false]);
verifie('un SMS refusé n’empêche pas la demande', $r['ok'] === true && count(MdSms::$partis) === 1, json_encode($r));
verifie('le refus est gardé et journalisé', ($REGLAGES['helpdeskSmsDernier']['destinataires'][1]['ok'] ?? null) === false && str_contains(end($JOURNAL), 'SMS non parti : Pierre Boseret ((13) numéro refusé)'), end($JOURNAL));
verifie('sans passage demandé, le SMS n’en parle pas', !str_contains(MdSms::$partis[0]['texte'], 'Passage'));
MdSms::$refuse = [];

MdSms::$partis = [];
$REGLAGES['helpdeskSms']['actif'] = false;
$r = nouvelle();
verifie('SMS coupés : la demande part, aucun SMS', $r['ok'] === true && MdSms::$partis === [] && str_contains(end($JOURNAL), 'SMS au bureau coupés'), end($JOURNAL));

echo "La date limite\n";
PanelApi::$envois = [];
$GLOBALS['corps'] = ['jours' => 7];
$r = wr_helpdesk_delai(501);
verifie('un délai hors liste est refusé', $r['ok'] === false && str_contains($r['error'], '2, 5, 10, 30, 90'), json_encode($r));
$GLOBALS['corps'] = ['jours' => 5, 'statut' => 'NEW', 'shop' => '4', 'ref' => 'CASE-20261120-AB12C'];
$r = wr_helpdesk_delai(501);
$attendu = date('Y-m-d', strtotime('+5 days'));
verifie('5 jours : la date limite est dans 5 jours', $r['ok'] === true && $r['echeance'] === $attendu && $r['delai'] === 5, json_encode($r));
verifie('une demande « Nouvelle » passe « En cours » à la réception', PanelApi::$envois === [['PATCH', '/cases/501/status', ['status' => 'IN_PROGRESS']]], json_encode(PanelApi::$envois));
$e = hdkEcheances([501, 502]);
verifie('elle est gardée, avec qui l’a fixée', ($e[501]['echeance'] ?? null) === $attendu && $e[501]['delai'] === 5 && $e[501]['par'] === 'Cockpit' && !isset($e[502]), json_encode($e));
PanelApi::$envois = [];
$GLOBALS['corps'] = ['jours' => 30, 'statut' => 'IN_PROGRESS', 'shop' => '9'];
$r = wr_helpdesk_delai(777);
verifie('une demande venue d’ailleurs (sans ligne au cockpit) reçoit sa date ; une demande « En cours » ne change pas de statut',
    $r['ok'] === true && (hdkEcheances([777])[777]['delai'] ?? null) === 30 && PanelApi::$envois === [], json_encode(PanelApi::$envois));
$GLOBALS['corps'] = ['jours' => 0];
$r = wr_helpdesk_delai(777);
verifie('0 retire la date limite', $r['ok'] === true && hdkEcheances([777]) === []);

$v = hdkEcheanceVue(['echeance' => '2026-11-18', 'delai' => 2, 'par' => 'Sam', 'le' => '2026-11-16 10:00:00'], true, '2026-11-20');
verifie('ouverte au-delà de sa date : en retard de 2 jours', $v['enRetard'] === true && $v['joursRestants'] === -2 && $v['echeancePar'] === 'Sam', json_encode($v));
verifie('fermée au-delà de sa date : pas en retard', hdkEcheanceVue(['echeance' => '2026-11-18'], false, '2026-11-20')['enRetard'] === false);
verifie('le jour même : 0 jour restant, pas en retard', hdkEcheanceVue(['echeance' => '2026-11-20'], true, '2026-11-20') === ['echeance' => '2026-11-20', 'delai' => null, 'echeancePar' => null, 'echeanceLe' => null, 'joursRestants' => 0, 'enRetard' => false]);
verifie('sans date : rien', hdkEcheanceVue(null, true)['echeance'] === null && hdkEcheanceVue(null, true)['enRetard'] === false);

PanelApi::$cases = [['id' => 501, 'ref' => 'CASE-20261120-AB12C', 'area_key' => 'sales', 'status' => 'IN_PROGRESS', 'title' => 'T', 'description' => 'D', 'shop_id' => 4, 'requester_name' => 'Marie', 'created_at' => '2026-11-20 10:00:00']];
$_GET['shop'] = '4';
$m = ep_helpdesk_magasin();
verifie('le franchisé voit la date limite, ni qui l’a fixée ni le demandeur', ($m['demandes'][0]['echeance'] ?? null) === $attendu && !isset($m['demandes'][0]['echeancePar']) && !isset($m['demandes'][0]['demandeur']), json_encode($m['demandes'][0] ?? null));
$c = ep_helpdesk_demandes();
verifie('le cockpit voit la date limite, les jours restants et les délais possibles', ($c['demandes'][0]['echeance'] ?? null) === $attendu && $c['demandes'][0]['joursRestants'] === 5 && $c['demandes'][0]['enRetard'] === false && $c['delais'] === [2, 5, 10, 30, 90], json_encode($c['demandes'][0] ?? null));

echo "Les relances\n";
$rel = hdkRelancesReglage([]);
verifie('par défaut : 7 j, 08:30, jours ouvrés, tant que pas en cours (21/11), au consultant, regroupées, sans plafond',
    $rel === ['actif' => true, 'jours' => 7, 'heure' => '08:30', 'ouvres' => true, 'quelles' => 'nouvelles', 'aQui' => 'consultant', 'regrouper' => true, 'plafond' => 0, 'v' => 2], json_encode($rel));
verifie('un réglage d’avant le 21/11 (« sans réponse », sans version) prend « pas encore en cours » ; un choix enregistré depuis reste',
    hdkRelancesReglage(['quelles' => 'sans_reponse'])['quelles'] === 'nouvelles' && hdkRelancesReglage(['quelles' => 'sans_reponse', 'v' => 2])['quelles'] === 'sans_reponse');
verifie('un réglage hors des choix revient aux choix validés', hdkRelancesReglage(['jours' => 5, 'heure' => '25:00', 'quelles' => 'x', 'plafond' => 3]) === $rel);
verifie('consultant par le nom : « Sam » ~ « Sam V. », « Pierre Boseret » ~ « Pierre B. »', hdkEstConsultant(['nom' => 'Sam'], 'Sam V.') && hdkEstConsultant(['nom' => 'Pierre Boseret'], 'Pierre B.'));
verifie('… mais pas « Pierre M. » ni « Loïc V. »', !hdkEstConsultant(['nom' => 'Pierre Boseret'], 'Pierre M.') && !hdkEstConsultant(['nom' => 'Sam'], 'Loïc V.'));
verifie('consultant réglé : il prime, accents et points ignorés', hdkEstConsultant(['nom' => 'Loïc', 'consultant' => 'Loïc V.'], 'Loic V') && !hdkEstConsultant(['nom' => 'Sam', 'consultant' => 'Pierre B.'], 'Sam V.'));
verifie('jours ouvrés : samedi et dimanche passent au lundi', hdkOuvre('2026-11-28') === '2026-11-30' && hdkOuvre('2026-11-29') === '2026-11-30' && hdkOuvre('2026-11-27') === '2026-11-27');
verifie('le premier lundi', hdkLundi('2026-11-20') === '2026-11-23' && hdkLundi('2026-11-23') === '2026-11-23');
$dem = static fn (array $c): array => hdkDemande($c + ['area_key' => 'strategy', 'status' => 'IN_PROGRESS', 'description' => '', 'shop_id' => 4, 'shop_name' => 'Atelier by Harmonie - Sombreffe', 'admin_reply' => '', 'consultant_name' => '']);
verifie('en attente avant la mise en route (20/11) : le premier lundi, 23/11', hdkProchaineRelance($dem(['id' => 1, 'created_at' => '2026-10-07 09:00:00']), $rel, null, '2026-11-20') === '2026-11-23');
verifie('7 jours après l’envoi : envoyée le 18/11, relancée le 25/11', hdkProchaineRelance($dem(['id' => 2, 'created_at' => '2026-11-18 09:00:00']), $rel, null, '2026-11-20') === '2026-11-25');
verifie('envoyée un samedi : 7 jours après tombe un samedi, la relance part le lundi', hdkProchaineRelance($dem(['id' => 3, 'created_at' => '2026-11-21 09:00:00']), $rel, null, '2026-11-20') === '2026-11-30');
verifie('puis 7 jours après la dernière relance', hdkProchaineRelance($dem(['id' => 1, 'created_at' => '2026-10-07 09:00:00']), $rel, '2026-11-23 08:30:00', '2026-11-20') === '2026-11-30');
verifie('l’heure : pas avant 08:30, pas après 20:00, pas le week-end ; un retard part le jour même',
    !hdkRelanceDue('2026-11-23', $rel, '2026-11-23 08:29') && hdkRelanceDue('2026-11-23', $rel, '2026-11-23 08:30') && !hdkRelanceDue('2026-11-23', $rel, '2026-11-23 20:00')
    && !hdkRelanceDue('2026-11-23', $rel, '2026-11-28 09:00') && hdkRelanceDue('2026-11-23', $rel, '2026-11-24 09:00') && !hdkRelanceDue('2026-11-25', $rel, '2026-11-24 09:00'));
$relSR = ['quelles' => 'sans_reponse'] + $rel;
verifie('fin : fermée, répondue, suspendue, plafond',
    hdkRelanceArret($dem(['id' => 1, 'status' => 'RESOLVED']), $rel, 0, null) === 'Résolue' && hdkRelanceArret($dem(['id' => 1, 'admin_reply' => 'ok', 'admin_reply_at' => '2026-12-01 10:00:00']), $relSR, 2, null) === 'réponse le 01/12'
    && hdkRelanceArret($dem(['id' => 1, 'status' => 'NEW']), $rel, 0, ['le' => 'x', 'par' => 'Sam']) === 'suspendue' && hdkRelanceArret($dem(['id' => 1, 'status' => 'NEW']), ['plafond' => 6] + $rel, 6, null) === '6 relances faites' && hdkRelanceArret($dem(['id' => 1, 'status' => 'NEW']), $rel, 9, null) === null);
verifie('tant que pas en cours : « Nouvelle » se relance, même répondue ; « En cours » s’arrête',
    hdkRelanceArret($dem(['id' => 1, 'status' => 'NEW', 'admin_reply' => 'ok']), $rel, 3, null) === null && hdkRelanceArret($dem(['id' => 1, 'status' => 'IN_PROGRESS']), $rel, 0, null) === 'passée en cours');

// La maquette, rejouée : Sombreffe (trois demandes réelles), Gosselies (Pierre B.), mise en route le vendredi 20/11.
$CASES = [
    ['id' => 11, 'ref' => 'CASE-20261007-9E319', 'area_key' => 'strategy', 'status' => 'NEW', 'title' => 'Démarchage sur Nivelles Sud', 'created_at' => '2026-10-07 09:00:00'],
    ['id' => 12, 'ref' => 'CASE-20260915-23CC8', 'area_key' => 'social_media', 'status' => 'IN_PROGRESS', 'title' => 'Campagne de démarchage pour les lunchs en entreprise', 'consultant_name' => 'Sam V.', 'created_at' => '2026-09-15 09:00:00'],
    ['id' => 13, 'ref' => 'CASE-20260519-8B90A', 'area_key' => 'operational_activities', 'status' => 'IN_PROGRESS', 'title' => 'Sombreffe : verrière cuisine, réagencement étalement & cadrage affichage', 'consultant_name' => 'Loic V.', 'created_at' => '2026-05-19 09:00:00'],
    ['id' => 14, 'ref' => 'CASE-20261118-4F2D1', 'area_key' => 'product', 'status' => 'IN_PROGRESS', 'title' => 'Nouvelle tarte du mois : la pâte se rétracte', 'consultant_name' => 'Pierre B.', 'shop_id' => 6, 'shop_name' => 'Atelier by - Gosselies', 'created_at' => '2026-11-18 09:00:00'],
];
$D = static fn (): array => array_map($dem, $GLOBALS['CASES']);
// La maquette a été validée avec la règle « sans réponse du bureau » : son scénario la garde (choix enregistré, v2).
$REGLAGES['helpdeskSms'] = ['actif' => true, 'destinataires' => [['nom' => 'Sam', 'tel' => '+48725038049'], ['nom' => 'Pierre Boseret', 'tel' => '0470 12 34 56']], 'relances' => ['quelles' => 'sans_reponse', 'v' => 2]];
$REGLAGES['helpdeskRelancesDepuis'] = '2026-11-20';
MdSms::$partis = [];
$r = hdkRelancesHorloge('2026-11-23 08:20', $D());
verifie('lundi 23/11 à 08:20 : trop tôt, rien n’est lu', ($r['rien'] ?? '') === 'hors des heures des relances' && MdSms::$partis === [], json_encode($r));
$r = hdkRelancesHorloge('2026-11-23 08:30', $D());
$a = array_column(MdSms::$partis, 'texte', 'a');
verifie('lundi 23/11 à 08:30 : un SMS à Sam, un à Pierre', count(MdSms::$partis) === 2 && isset($a['+48725038049'], $a['+32470123456']) && $r['demandes'] === 3, json_encode($r));
verifie('Sam : ses 3 demandes de Sombreffe en une liste (sa demande sans son nom, Loïc nommé, celle sans consultant dite)',
    str_starts_with($a['+48725038049'], "Relance - 3 demandes sans réponse\n1. Sombreffe (Stratégie) : Démarchage sur Nivelles Sud - 47 j, pas de consultant\n")
    && str_contains($a['+48725038049'], "2. Sombreffe (Réseaux sociaux) : Campagne de démarchage pour les lunchs en entreprise - 69 j\n")
    && str_contains($a['+48725038049'], ' - 188 j, Loic V.'), $a['+48725038049']);
verifie('Pierre : la demande sans consultant et celle de Loïc (sans numéro), pas celle de Sam', str_starts_with($a['+32470123456'], 'Relance - 2 demandes sans réponse') && !str_contains($a['+32470123456'], 'lunchs'), $a['+32470123456']);
verifie('ni lien ni caractère hors alphabet SMS', !preg_match('~https?://|/#/|[’«»]~u', implode(' ', $a)));
verifie('le journal : une ligne par demande et par personne, à la même heure', (int) Db::row('SELECT COUNT(*) AS n FROM con_hdk_relance')['n'] === 5 && (int) Db::row('SELECT COUNT(DISTINCT le) AS n FROM con_hdk_relance')['n'] === 1);
MdSms::$partis = [];
verifie('08:40 le même jour : rien ne repart', (hdkRelancesHorloge('2026-11-23 08:40', $D())['rien'] ?? '') === 'aucune relance due' && MdSms::$partis === []);

$GLOBALS['corps'] = ['action' => 'suspendre', 'shop' => '4', 'ref' => 'CASE-20260519-8B90A'];
verifie('la verrière : relances suspendues', wr_helpdesk_relances(13)['ok'] === true && isset(hdkRelancesSuspendues([13])[13]));
$r = hdkRelancesHorloge('2026-11-25 08:30', $D());
verifie('mercredi 25/11 : la tarte de Gosselies, 7 jours après son envoi, à Pierre seul, en un SMS',
    count(MdSms::$partis) === 1 && MdSms::$partis[0]['a'] === '+32470123456' && str_starts_with(MdSms::$partis[0]['texte'], "Relance - 1 demande sans réponse\nGosselies (Produit) : Nouvelle tarte du mois : la pate se rétracte - 7 j, pas de date limite. Réf. 20261118-4F2D1"), json_encode(MdSms::$partis));
MdSms::$partis = [];
verifie('samedi 28/11 : rien', (hdkRelancesHorloge('2026-11-28 09:00', $D())['rien'] ?? '') === 'hors des heures des relances' && MdSms::$partis === []);
$CASES[3]['admin_reply'] = 'Baisser le four de 10 degrés.'; $CASES[3]['admin_reply_at'] = '2026-11-26 10:00:00';
hdkRelancesHorloge('2026-11-30 08:30', $D());
$a = array_column(MdSms::$partis, 'texte', 'a');
verifie('lundi 30/11 : Sam, la demande sans consultant et la sienne ; Pierre, la demande sans consultant ; ni la verrière (suspendue) ni la tarte (répondue)',
    count(MdSms::$partis) === 2 && str_starts_with($a['+48725038049'], 'Relance - 2 demandes') && str_contains($a['+48725038049'], 'lunchs') && !str_contains(implode(' ', $a), 'verri') && !str_contains(implode(' ', $a), 'tarte')
    && str_starts_with($a['+32470123456'], "Relance (2e) - 1 demande sans réponse\nSombreffe (Stratégie) : Démarchage sur Nivelles Sud - 54 j, pas de consultant, pas de date limite."), json_encode($a));
MdSms::$partis = [];
$CASES[1]['admin_reply'] = 'Oui : un photographe passe le 10/12.'; $CASES[1]['admin_reply_at'] = '2026-12-01 10:00:00';
hdkRelancesHorloge('2026-12-07 08:30', $D());
verifie('lundi 07/12 : la 3e relance de la seule demande qui attend, à Sam et à Pierre',
    count(MdSms::$partis) === 2 && MdSms::$partis[0]['texte'] === MdSms::$partis[1]['texte'] && str_starts_with(MdSms::$partis[0]['texte'], 'Relance (3e) - 1 demande sans réponse'), json_encode(MdSms::$partis));
$J = hdkRelancesJournal([11, 12, 13, 14]);
verifie('le journal par demande : 3, 2, 1 et 1 relances', $J[11]['n'] === 3 && $J[12]['n'] === 2 && $J[13]['n'] === 1 && $J[14]['n'] === 1 && $J[11]['envois'][0]['a'] === [['nom' => 'Sam', 'ok' => true, 'motif' => null], ['nom' => 'Pierre Boseret', 'ok' => true, 'motif' => null]], json_encode($J[11]['envois'][0] ?? null));
$Rg = hdkSmsReglage();
$v = hdkRelanceVue(hdkDemande($CASES[0] + ['shop_id' => 4]) + hdkEcheanceVue(null, true), $J[11], null, $Rg, '2026-11-20');
verifie('la fiche : 3 envoyées, prochaine le lundi 14/12, à Sam et Pierre Boseret', $v['n'] === 3 && $v['prochaine'] === '2026-12-14' && $v['a'] === ['Sam', 'Pierre Boseret'] && $v['arret'] === null, json_encode($v));
$v = hdkRelanceVue(hdkDemande($CASES[1] + ['shop_id' => 4]) + hdkEcheanceVue(null, true), $J[12], null, $Rg, '2026-11-20');
verifie('la fiche d’une demande répondue : arrêtée, réponse le 01/12', $v['arret'] === 'réponse le 01/12' && $v['prochaine'] === null, json_encode($v));
$GLOBALS['corps'] = ['action' => 'reprendre'];
wr_helpdesk_relances(13);
verifie('reprendre la verrière', !isset(hdkRelancesSuspendues([13])[13]));
$REGLAGES['helpdeskSms']['relances'] = ['regrouper' => false, 'quelles' => 'sans_reponse', 'v' => 2];
MdSms::$partis = [];
$CASES[1]['admin_reply'] = ''; $CASES[2]['consultant_name'] = 'Sam V.';
hdkRelancesHorloge('2026-12-14 08:30', $D());
verifie('« un SMS par demande » : chacune son SMS', count(MdSms::$partis) === 4 && count(array_filter(MdSms::$partis, static fn ($x) => str_contains($x['texte'], '1 demande'))) === 4, json_encode(array_column(MdSms::$partis, 'a')));
$REGLAGES['helpdeskSms']['relances'] = ['actif' => false];
verifie('relances coupées : rien', (hdkRelancesHorloge('2026-12-21 08:30', $D())['rien'] ?? '') === 'relances coupées');
$REGLAGES['helpdeskSms']['relances'] = [];
MdSms::$regle = false;
verifie('sans fournisseur SMS : rien n’est lu', (hdkRelancesHorloge('2026-12-21 08:30', $D())['rien'] ?? '') === 'aucun fournisseur SMS réglé');
MdSms::$regle = true;

echo "Le réglage, l'essai, la relance tout de suite\n";
$REGLAGES['helpdeskSms'] = ['actif' => true, 'destinataires' => [['nom' => 'Sam', 'tel' => '+48725038049']], 'relances' => ['actif' => false]];
$GLOBALS['corps'] = ['actif' => true, 'destinataires' => [['nom' => 'Sam', 'tel' => '+48725038049', 'consultant' => 'Sam V.']], 'relances' => ['actif' => true, 'jours' => 14, 'heure' => '9h']];
verifie('une heure illisible est refusée', wr_helpdesk_sms()['ok'] === false);
$REGLAGES['helpdeskRelancesDepuis'] = '2026-11-20';
$GLOBALS['corps']['relances']['heure'] = '09:15';
$r = wr_helpdesk_sms();
verifie('enregistré : 14 j à 09:15, le consultant du numéro gardé', $r['ok'] === true && $r['relances']['jours'] === 14 && $r['relances']['heure'] === '09:15' && $r['destinataires'][0]['consultant'] === 'Sam V.', json_encode($r));
verifie('des relances rallumées repartent d’aujourd’hui', $REGLAGES['helpdeskRelancesDepuis'] === date('Y-m-d'));
MdSms::$partis = [];
$GLOBALS['corps'] = ['tel' => '+32 470 99 99 99'];
verifie('l’essai à un numéro hors du réglage est refusé', wr_helpdesk_sms_essai()['ok'] === false && MdSms::$partis === []);
$GLOBALS['corps'] = ['tel' => '0048 725 038 049'];
$r = wr_helpdesk_sms_essai();
verifie('l’essai part au numéro du réglage, sans lien', $r['ok'] === true && count(MdSms::$partis) === 1 && str_starts_with(MdSms::$partis[0]['texte'], 'Essai du cockpit (SMS au bureau)') && !str_contains(MdSms::$partis[0]['texte'], 'http'), json_encode($r));
MdSms::$partis = [];
PanelApi::$cases = $CASES;
$GLOBALS['corps'] = ['action' => 'maintenant'];
$n0 = (int) Db::row('SELECT COUNT(*) AS n FROM con_hdk_relance WHERE case_id = 11')['n'];
$r = wr_helpdesk_relances(11);
verifie('« Relancer maintenant » : le SMS part, noté comme relance manuelle', $r['ok'] === true && count(MdSms::$partis) === 1 && (int) Db::row('SELECT COUNT(*) AS n FROM con_hdk_relance WHERE case_id = 11 AND manuel = 1')['n'] === 1 && (int) Db::row('SELECT COUNT(*) AS n FROM con_hdk_relance WHERE case_id = 11')['n'] === $n0 + 1, json_encode($r));
// La règle du 21/11 : tant que pas en cours. Seule la demande « Nouvelle » (Démarchage) se relance.
$REGLAGES['helpdeskSms']['relances'] = [];
$J0 = (int) Db::row('SELECT COUNT(*) AS n FROM con_hdk_relance')['n'];
MdSms::$partis = [];
$CASES[1]['admin_reply'] = ''; $CASES[3]['admin_reply'] = '';
hdkRelancesHorloge('2026-12-28 08:30', $D());
$cases = array_map('intval', array_column(Db::rows('SELECT case_id FROM con_hdk_relance WHERE id > ?', [$J0]), 'case_id'));
verifie('tant que pas en cours : seule la demande « Nouvelle » est relancée (les « En cours » ne le sont plus, même sans réponse)',
    $cases !== [] && array_values(array_unique($cases)) === [11] && str_contains(MdSms::$partis[0]['texte'] ?? '', 'pas encore en cours'), json_encode([$cases, MdSms::$partis[0]['texte'] ?? null]));

echo "L'accueil : les demandes à traiter\n";
$avecMagasin = static fn (array $l): array => array_map(static fn ($c) => $c + ['shop_id' => 4, 'shop_name' => 'Atelier by Harmonie - Sombreffe'], $l);
$CASES2 = $avecMagasin($CASES); $CASES2[] = ['id' => 15, 'ref' => 'CASE-20261203-7C3E0', 'area_key' => 'sales', 'status' => 'NEW', 'title' => 'Commande de fin d’année', 'shop_id' => 7, 'shop_name' => 'Atelier by - Halle', 'created_at' => date('Y-m-d', strtotime('-2 days')) . ' 09:00:00'];
PanelApi::$cases = $CASES2;
$a = ep_helpdesk_a_traiter();
verifie('les demandes pas encore en cours, la plus ancienne d’abord, avec leurs relances', $a['n'] === 2 && $a['demandes'][0]['id'] === 11 && $a['demandes'][0]['magasin'] === 'Sombreffe'
    && $a['demandes'][0]['relances'] >= 4 && $a['demandes'][1]['id'] === 15 && $a['demandes'][1]['magasin'] === 'Halle' && $a['demandes'][1]['age'] === 2 && $a['demandes'][1]['relances'] === 0, json_encode($a));
PanelApi::$cases = $CASES;

echo "La demande urgente\n";
$REGLAGES['helpdeskSms'] = ['actif' => true, 'destinataires' => [['nom' => 'Sam', 'tel' => '+48725038049'], ['nom' => 'Pierre Boseret', 'tel' => '0470 12 34 56']]];
MdSms::$partis = []; PanelApi::$envois = [];
$r = nouvelle(['urgent' => true, 'passage' => false]);
verifie('urgente : le SMS commence par « URGENT », à tout le monde', $r['ok'] === true && count(MdSms::$partis) === 2 && str_starts_with(MdSms::$partis[0]['texte'], 'URGENT - Demande au bureau - Sombreffe (Ventes)')
    && str_starts_with(MdSms::$partis[1]['texte'], 'URGENT - '), json_encode(array_column(MdSms::$partis, 'texte')));
$post = array_values(array_filter(PanelApi::$envois, static fn ($e) => $e[1] === '/cases'))[0] ?? null;
verifie('… le panel le lit dans la demande, le cockpit la garde urgente', $post !== null && str_contains($post[2]['description'], 'URGENT') && isset(hdkUrgentes([501])[501]), json_encode($post[2]['description'] ?? null));
MdSms::$partis = [];
nouvelle(['passage' => false]);
verifie('une demande normale garde son SMS, sans « URGENT »', count(MdSms::$partis) === 2 && str_starts_with(MdSms::$partis[0]['texte'], 'Demande au bureau - '), json_encode(array_column(MdSms::$partis, 'texte')));
Db::exec('UPDATE con_hdk_demande SET urgent = 0 WHERE case_id = 501');
Db::exec('INSERT INTO con_hdk_demande (case_id, shop_id, passage, photos, cree_le, urgent) VALUES (15, \'7\', 0, NULL, \'2026-11-21 08:00:00\', 1)');
$CASES3 = $avecMagasin($CASES); $CASES3[] = ['id' => 15, 'ref' => 'CASE-20261121-URG01', 'area_key' => 'operational_activities', 'status' => 'NEW', 'title' => 'Four en panne', 'shop_id' => 7, 'shop_name' => 'Atelier by - Halle', 'created_at' => date('Y-m-d') . ' 07:00:00'];
PanelApi::$cases = $CASES3;
$a = ep_helpdesk_a_traiter();
verifie('l’accueil : l’urgente en tête, même la plus récente', $a['demandes'][0]['id'] === 15 && $a['demandes'][0]['urgent'] === true && $a['urgentes'] === 1 && $a['demandes'][1]['id'] === 11, json_encode(array_map(static fn ($d) => [$d['id'], $d['urgent']], $a['demandes'])));
$c = ep_helpdesk_demandes();
verifie('le cockpit : l’urgente ouverte en tête de la liste', $c['demandes'][0]['id'] === 15 && $c['demandes'][0]['urgent'] === true, json_encode(array_column($c['demandes'], 'id')));
PanelApi::$cases = $CASES;

echo "Le SMS au franchisé à la réponse\n";
MdSms::$partis = []; PanelApi::$envois = [];
$GLOBALS['corps'] = ['reponse' => "Oui, on passe mardi à 10 h avec le devis des livraisons. Préparez la liste des bureaux du zoning.", 'statut' => 'IN_PROGRESS', 'shop' => '4', 'titre' => 'Démarchage sur Nivelles Sud', 'ref' => 'CASE-20261007-9E319'];
$r = wr_helpdesk_repondre(11);
verifie('la réponse part au panel, puis un SMS au GSM du franchisé', $r['ok'] === true && count(MdSms::$partis) === 1 && MdSms::$partis[0]['a'] === '+32470999888' && $r['sms']['envoye'] === true, json_encode($r));
verifie('le SMS : le titre, le début de la réponse, où lire la suite ; sans lien', str_starts_with(MdSms::$partis[0]['texte'], "L'Atelier By - Le bureau a répondu à votre demande \"Démarchage sur Nivelles Sud\" : Oui, on passe mardi")
    && str_contains(MdSms::$partis[0]['texte'], 'dans votre dashboard, Demande au bureau') && !str_contains(MdSms::$partis[0]['texte'], 'http'), MdSms::$partis[0]['texte'] ?? '');
verifie('le cockpit le dit', str_contains((string) $r['avertissement'], 'SMS au franchisé (+32470999888)'), (string) $r['avertissement']);
verifie('en néerlandais pour Halle', str_starts_with(hdkSmsFranchiseTexte('Oven kapot', 'We komen morgen.', 'nl'), "L'Atelier By - Het kantoor heeft uw aanvraag \"Oven kapot\" beantwoord: We komen morgen."));
MdSms::$partis = [];
$GLOBALS['corps'] = ['reponse' => 'Vu.', 'shop' => '2', 'titre' => 'Nouvelle caisse'];
$r = wr_helpdesk_repondre(12);
verifie('un magasin sans GSM : la réponse part, pas de SMS, le cockpit le dit', $r['ok'] === true && MdSms::$partis === [] && str_contains((string) $r['avertissement'], 'aucun GSM'), (string) $r['avertissement']);
$REGLAGES['helpdeskSms']['franchise'] = false;
$GLOBALS['corps'] = ['reponse' => 'Vu.', 'shop' => '4', 'titre' => 'X'];
$r = wr_helpdesk_repondre(11);
verifie('coupé dans « SMS au bureau » : pas de SMS', $r['ok'] === true && MdSms::$partis === [] && str_contains((string) $r['avertissement'], 'coupé'), (string) $r['avertissement']);
unset($REGLAGES['helpdeskSms']['franchise']);

$c = ep_helpdesk_demandes();
verifie('la liste du cockpit porte les relances de chaque demande et celles à venir', isset($c['demandes'][0]['relances']['n'], $c['relances']['aVenir'], $c['relances']['sms']) && $c['relances']['sms'] >= 8, json_encode($c['relances'] ?? null));

echo "Le statut posé, gardé un quart d'heure (la liste du panel en retard)\n";
$CASE9 = ['id' => 909, 'ref' => 'CASE-20261123-90909', 'area_key' => 'sales', 'status' => 'IN_PROGRESS', 'title' => 'Cost biscuits', 'description' => 'D', 'shop_id' => 4, 'requester_name' => 'Marie', 'created_at' => '2026-11-23 09:00:00'];
PanelApi::$cases = [$CASE9]; PanelApi::$envois = []; $JOURNAL = [];
$corps = ['statut' => 'CANCELLED'];
$r = wr_helpdesk_statut(909);
verifie('le statut part au panel et revient dans la réponse', ($r['ok'] ?? false) && ($r['ouverte'] ?? true) === false && PanelApi::$envois[0] === ['PATCH', '/cases/909/status', ['status' => 'CANCELLED']], json_encode([$r, PanelApi::$envois]));
$c = ep_helpdesk_demandes(); $x = array_values(array_filter($c['demandes'], static fn ($d) => $d['id'] === 909))[0] ?? [];
verifie('la liste du panel encore « En cours » : le cockpit montre « Annulée », fermée, en attente du panel', ($x['statut'] ?? '') === 'CANCELLED' && ($x['ouverte'] ?? true) === false && !empty($x['statutAttente']), json_encode($x));
$m = ep_helpdesk_magasin(); $y = array_values(array_filter($m['demandes'] ?? [], static fn ($d) => $d['id'] === 909))[0] ?? [];
verifie('le franchisé la voit aussi « Annulée »', ($y['statut'] ?? '') === 'CANCELLED', json_encode($y));
PanelApi::$cases = [['status' => 'CANCELLED'] + $CASE9];
$c = ep_helpdesk_demandes(); $x = array_values(array_filter($c['demandes'], static fn ($d) => $d['id'] === 909))[0] ?? [];
verifie('la liste a rattrapé : plus d’attente, la garde est levée', ($x['statut'] ?? '') === 'CANCELLED' && empty($x['statutAttente']) && Db::rows('SELECT * FROM con_hdk_statut WHERE case_id = 909') === []);
PanelApi::$cases = [$CASE9];
$c = ep_helpdesk_demandes(); $x = array_values(array_filter($c['demandes'], static fn ($d) => $d['id'] === 909))[0] ?? [];
verifie('sans garde, la liste du panel fait foi', ($x['statut'] ?? '') === 'IN_PROGRESS');
$corps = ['statut' => 'RESOLVED']; wr_helpdesk_statut(909);
Db::exec('UPDATE con_hdk_statut SET pose_le = ? WHERE case_id = 909', [date('Y-m-d H:i:s', time() - 1000)]);
$JOURNAL = [];
$c = ep_helpdesk_demandes(); $x = array_values(array_filter($c['demandes'], static fn ($d) => $d['id'] === 909))[0] ?? [];
verifie('au-delà d’un quart d’heure, la liste du panel reprend et le journal le dit', ($x['statut'] ?? '') === 'IN_PROGRESS' && (bool) array_filter($JOURNAL, static fn ($l) => str_contains($l, 'le panel rend toujours « En cours »')), json_encode($JOURNAL));
$corps = ['reponse' => 'Réglé.', 'statut' => 'RESOLVED', 'shop' => '4', 'titre' => 'Cost biscuits', 'ref' => 'CASE-20261123-90909'];
wr_helpdesk_repondre(909);
$c = ep_helpdesk_demandes(); $x = array_values(array_filter($c['demandes'], static fn ($d) => $d['id'] === 909))[0] ?? [];
verifie('une réponse avec « Résolue » est gardée de même', ($x['statut'] ?? '') === 'RESOLVED' && !empty($x['statutAttente']));

// Les demandes que le panel écrit en polonais, remises en français (27/11/2026).
$pl = hdkDemande(['id' => 77, 'area_key' => 'webshop_billing_vat_conflict', 'status' => 'NEW', 'title' => 'Konflikt NIP Webshop: BE0838388420',
    'description' => 'Kilka rekordów firmowych ma ten sam numer VAT BE0838388420. Sprawdź rekordy klientów B2B: 62, 9135. Próba dodania firmy do faktur na koncie klienta #13178.']);
verifie('le titre polonais du panel passe en français', $pl['titre'] === 'Conflit de n° de TVA sur le webshop : BE0838388420');
verifie('la description polonaise passe en français, avec ses valeurs', $pl['description'] === 'Plusieurs fiches société ont le même n° de TVA BE0838388420. Vérifiez les fiches des clients B2B 62 et 9135. Le problème est apparu en ajoutant la société aux factures du compte client n° 13178.');
verifie('le domaine du panel a un nom français', $pl['domaineNom'] === 'Webshop : n° de TVA en double');
$fr = hdkDemande(['id' => 78, 'area_key' => 'strategy', 'title' => 'Vitrine', 'description' => 'Texte libre du magasin.']);
verifie('un texte hors modèle reste tel quel', $fr['titre'] === 'Vitrine' && $fr['description'] === 'Texte libre du magasin.');

echo "\n" . $ok . ' ok, ' . $ko . " KO\n";
exit($ko > 0 ? 1 : 0);
