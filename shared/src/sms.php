<?php
declare(strict_types=1);

/**
 * Cockpit CEO — l'envoi d'un SMS (14/10/2026). Pour commencer, un usage : la vendeuse reçoit un SMS
 * quand elle gagne une prime (src/primes_app.php, « SMS à la réussite », réglable dans Équipe & ventes
 * › Paramètres). Un fournisseur, Twilio, réglé dans config/config.php (hors Git) :
 *
 *   'sms' => ['fournisseur' => 'twilio', 'sid' => 'AC…', 'token' => '…', 'expediteur' => '+32… ou AtelierBy'],
 *
 * ou par les variables d'environnement TWILIO_SID, TWILIO_TOKEN, SMS_EXPEDITEUR. Sans réglage, rien ne
 * part : l'appelant le dit (journal, écran).
 */

/** Le réglage du fournisseur, la config d'abord, l'environnement ensuite. */
function smsConfig(): array
{
    $c = method_exists('Db', 'config') ? (array) (Db::config()['sms'] ?? []) : [];
    return ['fournisseur' => (string) ($c['fournisseur'] ?? 'twilio'),
        'sid' => (string) ($c['sid'] ?? (getenv('TWILIO_SID') ?: '')),
        'token' => (string) ($c['token'] ?? (getenv('TWILIO_TOKEN') ?: '')),
        'expediteur' => (string) ($c['expediteur'] ?? (getenv('SMS_EXPEDITEUR') ?: ''))];
}

/** Un fournisseur est-il réglé ? */
function smsConfigure(): bool
{
    $c = smsConfig();
    return $c['fournisseur'] === 'twilio' && $c['sid'] !== '' && $c['token'] !== '' && $c['expediteur'] !== '';
}

/**
 * Un numéro au format international (+32…), ou null s'il n'en est pas un. Un numéro national belge
 * (0470…) prend l'indicatif du pays ; « 0032 » devient « +32 ».
 */
function smsNumero(?string $brut, string $indicatif = '32'): ?string
{
    $n = preg_replace('/[^\d+]/', '', (string) $brut) ?? '';
    if ($n === '') { return null; }
    if (str_starts_with($n, '00')) { $n = '+' . substr($n, 2); }
    elseif (str_starts_with($n, '0')) { $n = '+' . $indicatif . substr($n, 1); }
    elseif (!str_starts_with($n, '+')) { $n = '+' . $n; }
    return preg_match('/^\+\d{8,15}$/', $n) === 1 ? $n : null;
}

/**
 * Envoie un SMS. Rend {ok, erreur} — jamais une exception.
 *
 * @return array{ok: bool, erreur: ?string}
 */
function smsEnvoyer(string $numero, string $texte): array
{
    if (!smsConfigure()) { return ['ok' => false, 'erreur' => 'aucun fournisseur SMS réglé (config.php › sms)']; }
    $c = smsConfig();
    $ch = curl_init('https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($c['sid']) . '/Messages.json');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERPWD => $c['sid'] . ':' . $c['token'],
        CURLOPT_POSTFIELDS => http_build_query(['To' => $numero, 'From' => $c['expediteur'], 'Body' => mb_substr($texte, 0, 600)])]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw !== false && $code >= 200 && $code < 300) { return ['ok' => true, 'erreur' => null]; }
    $d = $raw !== false ? json_decode((string) $raw, true) : null;
    return ['ok' => false, 'erreur' => ($code > 0 ? 'HTTP ' . $code : 'injoignable') . (is_array($d) && isset($d['message']) ? ' : ' . $d['message'] : ($err !== '' ? ' : ' . $err : ''))];
}

/**
 * Le fournisseur en place (11/11/2026) : SMSAPI s'il est réglé (MétaDonnée › Alertes SMS, src/metadonnee.php, celui des
 * alertes de leads), sinon Twilio (config.php › sms). null : aucun, rien ne part.
 */
function smsFournisseur(): ?string
{
    if (class_exists('MdSms') && MdSms::configured()) { return 'smsapi'; }
    return smsConfigure() ? 'twilio' : null;
}

/** Un numéro tapé à la main, au format international (+32…), ou null. La lecture des leads d'abord, elle connaît « +32 (0)476 ». */
function smsNorme(?string $brut): ?string
{
    $brut = trim((string) $brut);
    if ($brut === '') { return null; }
    return function_exists('mdTelE164') ? mdTelE164($brut) : smsNumero($brut);
}

/** +32476123456 → « 0476 12 34 56 », ce que lit un gérant. */
function smsLisible(?string $e164): string
{
    return function_exists('mdTelLisible') ? mdTelLisible($e164) : (string) $e164;
}

/**
 * Un SMS par le fournisseur en place. Rend {ok, a (numéro international), erreur, via} — jamais une exception.
 *
 * @return array{ok: bool, a: ?string, erreur: ?string, via: ?string}
 */
function smsPartir(string $brut, string $texte): array
{
    $num = smsNorme($brut);
    if ($num === null) { return ['ok' => false, 'a' => null, 'erreur' => 'numéro illisible : ' . $brut, 'via' => null]; }
    $via = smsFournisseur();
    if ($via === null) { return ['ok' => false, 'a' => $num, 'erreur' => 'aucun fournisseur SMS réglé (MétaDonnée › Alertes SMS)', 'via' => null]; }
    try {
        if ($via === 'smsapi') {
            $from = function_exists('mdReglages') ? (string) (mdReglages()['sms']['from'] ?? '') : '';
            [$ok, $info] = MdSms::envoyer($num, $texte, $from);
            return ['ok' => (bool) $ok, 'a' => $num, 'erreur' => $ok ? null : (string) $info, 'via' => $via];
        }
        $r = smsEnvoyer($num, $texte);
        return ['ok' => $r['ok'], 'a' => $num, 'erreur' => $r['erreur'], 'via' => $via];
    } catch (Throwable $e) {
        return ['ok' => false, 'a' => $num, 'erreur' => $e->getMessage(), 'via' => $via];
    }
}
