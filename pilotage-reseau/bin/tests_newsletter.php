#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests du lien du bouton d'une campagne newsletter (29/10/2026) — sans base, sans réseau, sans SMTP.
 *
 *   php bin/tests_newsletter.php
 *
 * Une campagne porte son lien (`mar_nl_campagne.lien`) : le clic suivi (/c/{token}) y mène, {{code}} remplacé par le
 * voucher du destinataire — par exemple le webshop ouvert sur le magasin, le code du bon déjà saisi
 * (https://webshop.atelierby.be/?shop=2&mode=collect&voucher=TUBIZE2). On vérifie la validation de l'adresse, le code
 * mis à sa place, le bouton du mail (test et vrai envoi), et l'enregistrement d'une campagne (refus, lien gardé).
 */

// --- doublures : pas de base, pas de SMTP, pas de journal ------------------------------------
final class Db
{
    /** Les lignes rendues par Db::row, par début de requête. */
    public static array $lignes = [];
    public static array $ecrit = [];
    public static function rows(string $sql, array $a = []): array { return []; }
    public static function row(string $sql, array $a = []): ?array
    {
        foreach (self::$lignes as $debut => $ligne) { if (str_starts_with($sql, $debut)) { return $ligne; } }
        return null;
    }
    public static function exec(string $sql, array $a = []): int { self::$ecrit[] = [$sql, $a]; return 0; }
    public static function pdo(): object { return new class { public function lastInsertId(): string { return '41'; } }; }
}
final class Smtp
{
    public static ?string $lastError = null;
    public static function configured(): bool { return false; }
}
function setting(string $k, mixed $d = null): mixed { return $GLOBALS['reglages'][$k] ?? $d; }
function body(): array { return $GLOBALS['corps']; }
function journalAdd(string $actor, string $kind, ?string $project, string $message): void {}
function rapBaseUrl(): string { return 'https://cockpit.exemple'; }
function ep_stores(): array { return []; }

require __DIR__ . '/../src/newsletter.php';
require __DIR__ . '/../src/newsletter_envoi.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, $detail = null): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== null ? ' — ' . (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE)) : '') . "\n"; }
}
$GLOBALS['reglages'] = [];
$WS = 'https://webshop.atelierby.be/?shop=2&mode=collect&voucher=';

echo "Validation du lien\n";
verifie('vide : pas de lien (le lien commun)', nlLienValide('') === '' && nlLienValide(null) === '' && nlLienValide('   ') === '');
verifie('le webshop avec le code du bon : gardé tel quel', nlLienValide($WS . 'TUBIZE2') === $WS . 'TUBIZE2');
verifie('les espaces autour sont retirés', nlLienValide('  ' . $WS . 'TUBIZE2 ') === $WS . 'TUBIZE2');
verifie('{{code}} et {{code_promo}} acceptés', nlLienValide($WS . '{{code}}') === $WS . '{{code}}' && nlLienValide($WS . '{{code_promo}}') === $WS . '{{code_promo}}');
verifie('http:// accepté', nlLienValide('http://webshop.atelierby.be/') === 'http://webshop.atelierby.be/');
verifie('sans https:// : refusé', nlLienValide('webshop.atelierby.be/?shop=2') === null);
verifie('javascript: refusé', nlLienValide('javascript:alert(1)') === null);
verifie('une autre balise que {{code}} : refusée', nlLienValide($WS . '{{prénom}}') === null);
verifie('un espace dans l’adresse : refusé', nlLienValide('https://webshop.atelierby.be/?voucher=TU BIZE') === null);
verifie('plus de 500 signes : refusé', nlLienValide('https://webshop.atelierby.be/?q=' . str_repeat('a', 480)) === null);

echo "Le code à sa place\n";
verifie('{{code}} devient le voucher', nlLienCible($WS . '{{code}}', 'K7M2DIAM') === $WS . 'K7M2DIAM');
verifie('{{code_promo}} aussi', nlLienCible($WS . '{{code_promo}}', 'K7M2DIAM') === $WS . 'K7M2DIAM');
verifie('sans voucher : le paramètre reste vide (le webshop l’ignore)', nlLienCible($WS . '{{code}}', null) === $WS);
verifie('le code est encodé pour l’adresse', nlLienCible($WS . '{{code}}', 'A&B') === $WS . 'A%26B');
verifie('sans balise : rien ne change', nlLienCible($WS . 'TUBIZE2', 'K7M2DIAM') === $WS . 'TUBIZE2');

echo "Le bouton du mail\n";
$camp = ['id' => 7, 'sender_shop_id' => '2', 'template_id' => 'blank', 'image' => '', 'subjects_json' => '{"fr":"Cougnou"}', 'bodies_json' => '{"fr":"Bonjour {{prénom}},\n\nVotre code : {{code}}"}',
    'headlines_json' => '{"fr":"Titre"}', 'ctas_json' => '{"fr":"Commander"}', 'lien' => $WS . '{{code}}'];
$href = static fn (string $html) => preg_match('/<a href="([^"]+)" style="display:inline-block;background:#8D1D2C/', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES) : null;
$h = nlRendre($camp, 'fr', ['prenom' => 'Marie'], 'K7M2DIAM', null);
verifie('un test (sans jeton) : le bouton mène tout droit au lien, code compris', $href($h) === $WS . 'K7M2DIAM', $href($h));
$h = nlRendre($camp, 'fr', ['prenom' => 'Marie'], 'K7M2DIAM', str_repeat('a', 32));
verifie('un vrai envoi : le bouton passe par le clic suivi', $href($h) === 'https://cockpit.exemple/api/cockpit/marketing/newsletter/c/' . str_repeat('a', 32), $href($h));
$h = nlRendre(array_merge($camp, ['lien' => '']), 'fr', ['prenom' => 'Marie'], null, null);
verifie('sans lien, un test mène au cockpit comme avant', $href($h) === 'https://cockpit.exemple', $href($h));
$h = nlRendre(array_merge($camp, ['lien' => 'javascript:alert(1)']), 'fr', ['prenom' => 'Marie'], null, null);
verifie('un lien gardé invalide n’est jamais servi', $href($h) === 'https://cockpit.exemple', $href($h));

echo "Enregistrer une campagne\n";
Db::$lignes = [
    'SELECT COUNT(*) AS n FROM mar_nl_source' => ['n' => 4],   // jeu d'essai déjà semé : nlSemer() s'arrête aux magasins
    'SELECT * FROM mar_nl_magasin WHERE shop_id = ?' => ['shop_id' => 'brand', 'can_send' => 1],
    'SELECT * FROM mar_nl_segment WHERE id = ?' => ['id' => 'tous-2', 'source_id' => 'indiv', 'shop_id' => '2'],
    'SELECT 1 FROM mar_nl_magasin WHERE shop_id = ?' => ['1' => 1],
];
$campagne = ['role' => 'brand', 'name' => 'CMR - Newsletter Tubize Formule 2', 'segment' => 'tous-2', 'langs' => ['fr'], 'templateId' => 'blank', 'channel' => 'email',
    'subjects' => ['fr' => 'Cougnou'], 'bodies' => ['fr' => 'Bonjour'], 'date' => '2026-11-02', 'time' => '08:30', 'sender' => 'brand'];
$GLOBALS['corps'] = $campagne + ['lien' => 'webshop.atelierby.be/?shop=2'];
$r = @wr_newsletter_campagne_post();
verifie('un lien sans https:// : 422, rien n’est écrit', str_contains((string) ($r['error'] ?? ''), 'Lien du bouton') && !array_filter(Db::$ecrit, fn ($e) => str_starts_with($e[0], 'INSERT INTO mar_nl_campagne')), $r);
Db::$ecrit = [];
$GLOBALS['corps'] = $campagne + ['lien' => ' ' . $WS . 'TUBIZE2 '];
$r = @wr_newsletter_campagne_post();
$ins = array_values(array_filter(Db::$ecrit, fn ($e) => str_starts_with($e[0], 'INSERT INTO mar_nl_campagne')));
verifie('un bon lien : gardé, nettoyé, avec la campagne', !isset($r['error']) && $ins && str_contains($ins[0][0], ', lien)') && end($ins[0][1]) === $WS . 'TUBIZE2', $ins[0][1] ?? $r);
Db::$ecrit = [];
$GLOBALS['corps'] = $campagne;
@wr_newsletter_campagne_post();
$ins = array_values(array_filter(Db::$ecrit, fn ($e) => str_starts_with($e[0], 'INSERT INTO mar_nl_campagne')));
verifie('sans lien : NULL (le lien commun)', $ins && end($ins[0][1]) === null, $ins[0][1] ?? null);
verifie('la campagne relue rend son lien', nlCampagneRow(['id' => 3, 'nom' => 'x', 'source_id' => 'indiv', 'segment_id' => 's', 'langues' => 'fr', 'template_id' => 'blank', 'channel' => 'email',
    'subjects_json' => null, 'bodies_json' => null, 'sms_json' => null, 'social_json' => null, 'send_mode' => 'manual', 'trigger_rule' => null, 'send_at' => null, 'max_vouchers' => null,
    'sender_shop_id' => 'brand', 'statut' => 'sched', 'test_sent' => 0, 'stats_json' => null, 'exemple' => 0, 'created_by' => 'brand', 'created_at' => null, 'lien' => $WS . 'TUBIZE2'])['lien'] === $WS . 'TUBIZE2');

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
