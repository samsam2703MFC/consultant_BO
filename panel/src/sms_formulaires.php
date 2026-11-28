<?php
declare(strict_types=1);

/**
 * Les SMS des formulaires bureaux (28/11/2026). Sam : « rajouter un SMS envoyé lors d'un formulaire envoyé ? au GSM du
 * franchisé et au mien », « paramètre dans consultant BO, rail dashboard », puis « A » : les deux formulaires bureaux.
 *
 * Chaque nouvelle inscription par le formulaire en six étapes (bureaux_dev.php, bdvPublic) et chaque nouvelle demande hors
 * zone (bdvHorsZoneEnregistrer, à l'insertion) envoie un SMS court au GSM du franchisé du magasin et au numéro du siège,
 * chacun allumé ou éteint dans son réglage. Le texte est en ASCII (un seul SMS, 160 caractères) et ne porte du prospect
 * que son nom de société, son code postal, sa commune et son effectif — jamais son e-mail ni son téléphone. Au plus
 * SMSF_MAX_JOUR formulaires notifiés par jour et par magasin (la demande hors zone est publique). L'envoi part après la
 * réponse au visiteur ; il passe par shared/src/sms.php (Twilio, réglé dans config.php : sans réglage, rien ne part).
 *
 *   GET  /dashboard/sms-formulaires?shop=          {fournisseur, magasin: {actif, numero}, siege: {actif, numero|null},
 *                                                   siegeModifiable, aujourdhui, max}
 *   POST /dashboard/sms-formulaires {shop, actif, numero, siege?: {actif, numero}}   le numéro du siège : le siège seul
 *   POST /dashboard/sms-formulaires/essai {shop}   un SMS d'essai, tout de suite → {envoyes, echecs, plafond}
 *
 * Tables : panel_sms_formulaire (un réglage par magasin ; « reseau » : celui du siège), panel_sms_formulaire_envoi (un
 * formulaire notifié : envoyés, échecs ; sert au plafond du jour). Le réglage se fait dans le dashboard, Paramètres (la
 * roue du rail) › Alertes SMS. Tests : php panel/bin/tests_sms_formulaires.php
 */

const SMSF_MAX_JOUR = 10;
const SMSF_RESEAU = 'reseau';

function ensureSmsFormulairesTables(): void
{
    static $fait = false;
    if ($fait || !class_exists('Db')) { return; }
    Db::exec('CREATE TABLE IF NOT EXISTS panel_sms_formulaire ('
        . 'shop_id VARCHAR(16) NOT NULL PRIMARY KEY, actif TINYINT NOT NULL DEFAULT 0, numero VARCHAR(20) NOT NULL DEFAULT "", maj_le DATETIME NOT NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS panel_sms_formulaire_envoi ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY, shop_id VARCHAR(16) NOT NULL, quoi VARCHAR(20) NOT NULL, quand DATETIME NOT NULL,'
        . 'envoyes INT NOT NULL DEFAULT 0, echecs INT NOT NULL DEFAULT 0, erreur VARCHAR(255) NULL, INDEX (shop_id, quand)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $fait = true;
}

/* ── Ce qui ne dépend de rien (testé) ─────────────────────────────────────────────────────────────────────────── */

/** Un texte en ASCII imprimable : accents retirés, guillemets et tirets typographiques simplifiés, le reste ôté. */
function smsfAscii(string $t): string
{
    // Les guillemets français et leurs espaces intérieures (« Les Lutins ») deviennent "Les Lutins".
    $t = (string) preg_replace(['/«[\s\x{00A0}\x{202F}]*/u', '/[\s\x{00A0}\x{202F}]*»/u'], '"', $t);
    $t = strtr($t, ["\u{00A0}" => ' ', "\u{202F}" => ' ', '“' => '"', '”' => '"', '’' => "'", '‘' => "'", '–' => '-', '—' => '-', '…' => '...',
        'œ' => 'oe', 'Œ' => 'OE', 'æ' => 'ae', 'Æ' => 'AE', 'ß' => 'ss']);
    // Les lettres accentuées courantes, sans l'extension intl (le serveur peut ne pas l'avoir) ; Normalizer fait le reste.
    $t = strtr($t, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i',
        'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ÿ' => 'y', 'ñ' => 'n',
        'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ç' => 'C', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Î' => 'I', 'Ï' => 'I', 'Ô' => 'O',
        'Ö' => 'O', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U']);
    if (class_exists('Normalizer')) { $t = (string) Normalizer::normalize($t, Normalizer::FORM_D); }
    $t = (string) preg_replace('/\p{Mn}+/u', '', $t);
    $t = (string) preg_replace('/[^\x20-\x7E]+/', '', $t);
    return trim((string) preg_replace('/\s+/', ' ', $t));
}

/**
 * Le SMS d'un formulaire : « {magasin} : nouvelle inscription bureau - {société}, {cp commune}, {n} pers. Dashboard >
 * Clients > Bureaux » ; « demande hors zone » pour l'autre ; un essai pour vérifier les numéros. Un SMS : 160 caractères au
 * plus, la société raccourcie d'abord.
 */
function smsfTexte(string $magasin, string $quoi, array $d): string
{
    $mag = smsfAscii($magasin) ?: 'L\'Atelier By';
    if ($quoi === 'essai') { return mb_substr($mag . ' : SMS d\'essai des formulaires bureaux. Dashboard > Parametres', 0, 160); }
    $quoiTxt = $quoi === 'hors_zone' ? 'demande hors zone' : 'nouvelle inscription bureau';
    $lieu = trim(smsfAscii((string) ($d['cp'] ?? '')) . ' ' . smsfAscii((string) ($d['ville'] ?? '')));
    $n = (int) ($d['personnes'] ?? 0);
    $fin = ($lieu !== '' ? ', ' . $lieu : '') . ($n > 0 ? ', ' . $n . ' pers' : '') . '. Dashboard > Clients > Bureaux';
    $tete = $mag . ' : ' . $quoiTxt . ' - ';
    $nom = smsfAscii((string) ($d['nom'] ?? '')) ?: 'sans nom';
    $place = 160 - strlen($tete) - strlen($fin);
    if (strlen($nom) > $place) { $nom = $place > 3 ? rtrim(substr($nom, 0, $place - 3)) . '...' : ''; }
    return substr($tete . $nom . $fin, 0, 160);
}

/** Les numéros des réglages allumés, au format international, sans doublon ; un numéro invalide ne reçoit rien. */
function smsfDestinataires(array $magasin, array $siege): array
{
    $l = [];
    foreach ([$magasin, $siege] as $r) {
        if (empty($r['actif'])) { continue; }
        $n = smsNumero((string) ($r['numero'] ?? ''));
        if ($n !== null && !in_array($n, $l, true)) { $l[] = $n; }
    }
    return $l;
}

/* ── Les réglages ─────────────────────────────────────────────────────────────────────────────────────────────── */

function smsfReglage(string $shop): array
{
    ensureSmsFormulairesTables();
    $r = Db::row('SELECT actif, numero FROM panel_sms_formulaire WHERE shop_id = ?', [$shop]);
    return ['actif' => (bool) (int) ($r['actif'] ?? 0), 'numero' => (string) ($r['numero'] ?? '')];
}

function smsfReglageEcrire(string $shop, bool $actif, string $numero): void
{
    ensureSmsFormulairesTables();
    Db::exec('INSERT INTO panel_sms_formulaire (shop_id, actif, numero, maj_le) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE actif = VALUES(actif), numero = VALUES(numero), maj_le = NOW()',
        [$shop, $actif ? 1 : 0, $numero]);
}

/** Le siège (ou l'accès ouvert du banc) lit et règle le numéro du siège ; un franchisé, non. */
function smsfSiege(): bool
{
    if (function_exists('accesMode') && accesMode() === 'ouvert') { return true; }
    return function_exists('accesIdentite') && (accesIdentite()['role'] ?? '') === 'siege';
}

function smsfAujourdhui(string $shop): int
{
    ensureSmsFormulairesTables();
    return (int) (Db::row('SELECT COUNT(*) n FROM panel_sms_formulaire_envoi WHERE shop_id = ? AND quand >= CURDATE()', [$shop])['n'] ?? 0);
}

function smsfNomMagasin(string $shop): string
{
    return function_exists('accesNomMagasin') ? (string) accesNomMagasin($shop) : 'Magasin ' . $shop;
}

/* ── L'envoi ──────────────────────────────────────────────────────────────────────────────────────────────────── */

/**
 * Le SMS d'un formulaire, envoyé maintenant aux numéros allumés : rien sans destinataire ; rien au-delà du plafond du jour.
 * `$envoyer(numero, texte)` rend {ok, erreur} (smsEnvoyer par défaut). Un formulaire notifié laisse une trace (sans les
 * numéros) et une ligne au journal (sans les numéros). → {envoyes, echecs, plafond}
 */
function smsfEnvoyerMaintenant(string $shop, string $quoi, array $d, ?callable $envoyer = null): array
{
    $dest = smsfDestinataires(smsfReglage($shop), smsfReglage(SMSF_RESEAU));
    if ($dest === []) { return ['envoyes' => 0, 'echecs' => 0, 'plafond' => false]; }
    if (smsfAujourdhui($shop) >= SMSF_MAX_JOUR) { return ['envoyes' => 0, 'echecs' => 0, 'plafond' => true]; }
    $texte = smsfTexte(smsfNomMagasin($shop), $quoi, $d);
    $envoyer = $envoyer ?? static fn (string $n, string $t): array => smsEnvoyer($n, $t);
    $ok = 0; $ko = 0; $err = null;
    foreach ($dest as $n) {
        $r = $envoyer($n, $texte);
        if (!empty($r['ok'])) { $ok++; } else { $ko++; $err = $err ?? (string) ($r['erreur'] ?? 'refusé'); }
    }
    Db::exec('INSERT INTO panel_sms_formulaire_envoi (shop_id, quoi, quand, envoyes, echecs, erreur) VALUES (?, ?, NOW(), ?, ?, ?)',
        [$shop, $quoi, $ok, $ko, $err !== null ? mb_substr($err, 0, 255) : null]);
    if (function_exists('journalAdd')) {
        $lib = ['inscription' => 'inscription bureau', 'hors_zone' => 'demande hors zone', 'essai' => 'SMS d’essai'][$quoi] ?? $quoi;
        journalAdd('dashboard', 'bureaux', null, 'Magasin ' . $shop . ' : SMS du formulaire (' . $lib . ') — ' . $ok . ' envoyé(s)' . ($ko ? ', ' . $ko . ' en échec (' . $err . ')' : ''));
    }
    return ['envoyes' => $ok, 'echecs' => $ko, 'plafond' => false];
}

/** Le SMS d'un formulaire, envoyé après la réponse au visiteur (le formulaire ne l'attend pas). Jamais une exception. */
function smsfNotifier(string $shop, string $quoi, array $d): void
{
    register_shutdown_function(static function () use ($shop, $quoi, $d) {
        if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
        try { smsfEnvoyerMaintenant($shop, $quoi, $d); } catch (Throwable $e) { /* un SMS manqué n'arrête rien */ }
    });
}

/* ── Les routes du dashboard ──────────────────────────────────────────────────────────────────────────────────── */

function smsfShop(?array $b = null): string
{
    $s = (string) ($b !== null ? ($b['shop'] ?? '') : ($_GET['shop'] ?? ''));
    return preg_match('/^\d{1,6}$/', $s) ? $s : '';
}

function ep_sms_formulaires(string $shop): array
{
    $m = smsfReglage($shop); $s = smsfReglage(SMSF_RESEAU); $siege = smsfSiege();
    return ['fournisseur' => function_exists('smsConfigure') && smsConfigure(), 'magasin' => $m,
        'siege' => ['actif' => $s['actif'], 'numero' => $siege ? $s['numero'] : null], 'siegeModifiable' => $siege,
        'aujourdhui' => smsfAujourdhui($shop), 'max' => SMSF_MAX_JOUR];
}

/** Un numéro du réglage : vide s'il est éteint et vide ; sinon un numéro valide, au format international. [numero, erreur] */
function smsfNumeroReglage($brut, bool $actif, string $qui): array
{
    $t = trim((string) $brut);
    if ($t === '' && !$actif) { return ['', null]; }
    $n = smsNumero($t);
    return $n === null ? [null, 'Le ' . $qui . ' : un numéro de GSM valide (0470 12 34 56 ou +32 470 12 34 56).'] : [$n, null];
}

function wr_sms_formulaires(string $shop): array
{
    $b = function_exists('body') ? body() : [];
    $actif = !empty($b['actif']);
    [$num, $e] = smsfNumeroReglage($b['numero'] ?? '', $actif, 'GSM du franchisé');
    if ($e !== null) { http_response_code(422); return ['error' => $e]; }
    $sg = isset($b['siege']) && is_array($b['siege']) ? $b['siege'] : null;
    $numS = null;
    if ($sg !== null) {
        if (!smsfSiege()) { http_response_code(403); return ['error' => 'Le numéro du siège se règle depuis un compte du siège.']; }
        [$numS, $e] = smsfNumeroReglage($sg['numero'] ?? '', !empty($sg['actif']), 'numéro du siège');
        if ($e !== null) { http_response_code(422); return ['error' => $e]; }
    }
    smsfReglageEcrire($shop, $actif, (string) $num);
    if ($sg !== null) { smsfReglageEcrire(SMSF_RESEAU, !empty($sg['actif']), (string) $numS); }
    if (function_exists('journalAdd')) {
        journalAdd('dashboard', 'Paramètre', 'SMS des formulaires', 'Magasin ' . $shop . ' : SMS au franchisé ' . ($actif ? 'allumés' : 'éteints')
            . ($sg !== null ? ' ; SMS au siège ' . (!empty($sg['actif']) ? 'allumés' : 'éteints') : ''));
    }
    return ['ok' => true] + ep_sms_formulaires($shop);
}

function wr_sms_formulaires_essai(string $shop): array
{
    if (!function_exists('smsConfigure') || !smsConfigure()) { http_response_code(422); return ['error' => 'Aucun fournisseur SMS n’est réglé sur le serveur : rien ne part.']; }
    if (smsfDestinataires(smsfReglage($shop), smsfReglage(SMSF_RESEAU)) === []) { http_response_code(422); return ['error' => 'Aucun numéro allumé : allumez et enregistrez un GSM d’abord.']; }
    $r = smsfEnvoyerMaintenant($shop, 'essai', []);
    if ($r['plafond']) { http_response_code(429); return ['error' => 'Le plafond de ' . SMSF_MAX_JOUR . ' SMS par jour est atteint pour ce magasin : réessayez demain.']; }
    return ['ok' => $r['echecs'] === 0] + $r;
}

function smsfRoute(string $method, string $path): ?array
{
    if ($path !== '/dashboard/sms-formulaires' && $path !== '/dashboard/sms-formulaires/essai') { return null; }
    $shop = smsfShop($method === 'GET' ? null : (function_exists('body') ? body() : []));
    if ($shop === '') { http_response_code(400); return ['error' => 'shop manquant']; }
    if ($method === 'GET' && $path === '/dashboard/sms-formulaires') { return ep_sms_formulaires($shop); }
    if ($method === 'POST' && $path === '/dashboard/sms-formulaires') { return wr_sms_formulaires($shop); }
    if ($method === 'POST') { return wr_sms_formulaires_essai($shop); }
    http_response_code(404);
    return ['error' => 'route inconnue'];
}
