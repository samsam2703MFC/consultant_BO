<?php
declare(strict_types=1);

/**
 * Client SMTP minimal — l'envoi des rapports.
 *
 * Pas de bibliothèque : le serveur n'a pas Composer, et le besoin est un seul
 * geste (un mail HTML à quelques adresses). Le protocole est parlé à la main :
 * EHLO, STARTTLS ou SSL direct, AUTH LOGIN, MAIL FROM, RCPT TO, DATA.
 *
 * MÊME RÈGLE QUE TOUTES LES CLÉS DU COCKPIT : les identifiants vivent dans
 * `ceo_app_setting.smtp` et ne quittent jamais le serveur — l'écran ne reçoit
 * que l'état (hôte, port, utilisateur, « mot de passe défini »), jamais le
 * mot de passe. Sans SMTP configuré, l'envoi retombe sur mail() de PHP.
 */
final class Smtp
{
    public static ?string $lastError = null;

    public static function config(): array
    {
        $s = setting('smtp');
        if (!is_array($s)) { $s = []; }
        $c = [
            'hote' => trim((string) ($s['hote'] ?? '')),
            'port' => (int) ($s['port'] ?? 587),
            'securite' => in_array($s['securite'] ?? '', ['ssl', 'tls', 'aucune'], true) ? $s['securite'] : 'tls',
            'utilisateur' => trim((string) ($s['utilisateur'] ?? '')),
            'motDePasse' => (string) ($s['motDePasse'] ?? ''),
            'expediteur' => trim((string) ($s['expediteur'] ?? '')),
            'source' => 'cockpit',
        ];
        // La config du cockpit prime quand elle est COMPLÈTE : hôte, expéditeur,
        // et le mot de passe si un utilisateur est posé. Incomplète, on retombe
        // sur les réglages du CRM candidat (crm_settings, MÊME base partagée) —
        // le mot de passe reste en base, il ne transite jamais par un écran.
        $complet = $c['hote'] !== '' && $c['expediteur'] !== ''
            && ($c['utilisateur'] === '' || $c['motDePasse'] !== '');
        if ($complet) { return $c; }
        try {
            $crm = [];
            foreach (Db::rows("SELECT skey, svalue FROM crm_settings WHERE skey LIKE 'smtp\\_%'") as $r) {
                $crm[(string) $r['skey']] = (string) $r['svalue'];
            }
            if (($crm['smtp_host'] ?? '') !== '' && ($crm['smtp_from'] ?? '') !== '') {
                $sec = strtolower($crm['smtp_secure'] ?? 'tls');
                $nom = trim($crm['smtp_from_name'] ?? '');
                return [
                    'hote' => trim((string) preg_replace('#^\w+://#', '', $crm['smtp_host'])),
                    'port' => (int) (($crm['smtp_port'] ?? '') ?: 587),
                    'securite' => $sec === 'ssl' || $sec === 'smtps' ? 'ssl' : ($sec === 'none' ? 'aucune' : 'tls'),
                    'utilisateur' => trim($crm['smtp_user'] ?? ''),
                    'motDePasse' => $crm['smtp_pass'] ?? '',
                    'expediteur' => $nom !== '' ? $nom . ' <' . $crm['smtp_from'] . '>' : $crm['smtp_from'],
                    'source' => 'crm',
                ];
            }
        } catch (PDOException $e) { /* table du CRM absente : rien à reprendre */ }
        return $c;
    }

    public static function configured(): bool
    {
        $c = self::config();
        return $c['hote'] !== '' && $c['expediteur'] !== '';
    }

    /** L'état pour l'écran — jamais le mot de passe. */
    public static function statut(): array
    {
        $c = self::config();
        return ['configure' => self::configured(), 'hote' => $c['hote'], 'port' => $c['port'],
            'securite' => $c['securite'], 'utilisateur' => $c['utilisateur'],
            'expediteur' => $c['expediteur'], 'motDePasseDefini' => $c['motDePasse'] !== '',
            'source' => $c['source']];
    }

    /** Envoie un HTML. Rend vrai/faux ; le détail d'un échec est dans $lastError. */
    public static function envoyer(string $a, string $sujet, string $html): bool
    {
        self::$lastError = null;
        $c = self::config();
        if (!self::configured()) { self::$lastError = 'SMTP non configuré'; return false; }

        $hote = ($c['securite'] === 'ssl' ? 'ssl://' : '') . $c['hote'];
        $fp = @stream_socket_client($hote . ':' . $c['port'], $errno, $errstr, 12);
        if ($fp === false) { self::$lastError = 'connexion impossible — ' . $errstr; return false; }
        stream_set_timeout($fp, 15);

        $lire = function () use ($fp): string {
            $out = '';
            while (($l = fgets($fp, 1024)) !== false) {
                $out .= $l;
                if (strlen($l) < 4 || $l[3] !== '-') { break; } // fin de réponse multi-lignes
            }
            return $out;
        };
        $dire = function (string $cmd, array $attendu) use ($fp, $lire): bool {
            if ($cmd !== '') { fwrite($fp, $cmd . "\r\n"); }
            $rep = $lire();
            $code = (int) substr($rep, 0, 3);
            if (!in_array($code, $attendu, true)) {
                self::$lastError = trim($cmd === '' ? $rep : (explode(' ', $cmd)[0] . ' → ' . trim($rep)));
                return false;
            }
            return true;
        };

        try {
            if (!$dire('', [220])) { return false; }
            $moi = gethostname() ?: 'cockpit.local';
            if (!$dire('EHLO ' . $moi, [250])) { return false; }
            if ($c['securite'] === 'tls') {
                if (!$dire('STARTTLS', [220])) { return false; }
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    self::$lastError = 'STARTTLS — le chiffrement a échoué'; return false;
                }
                if (!$dire('EHLO ' . $moi, [250])) { return false; }
            }
            if ($c['utilisateur'] !== '') {
                if (!$dire('AUTH LOGIN', [334])) { return false; }
                if (!$dire(base64_encode($c['utilisateur']), [334])) { self::$lastError = 'authentification — utilisateur refusé'; return false; }
                if (!$dire(base64_encode($c['motDePasse']), [235])) { self::$lastError = 'authentification refusée (mot de passe ou application non autorisée)'; return false; }
            }
            $exp = preg_match('/<([^>]+)>/', $c['expediteur'], $m) ? $m[1] : $c['expediteur'];
            if (!$dire('MAIL FROM:<' . $exp . '>', [250])) { return false; }
            if (!$dire('RCPT TO:<' . $a . '>', [250, 251])) { return false; }
            if (!$dire('DATA', [354])) { return false; }
            // Les images incorporées en data URI (photos annotées des rapports)
            // deviennent des pièces jointes intégrées (multipart/related, CID) :
            // Gmail n'affiche pas les data URI, il affiche les CID.
            // Les blocs réservés à l'écran (bouton Imprimer/PDF) sortent de
            // l'email — un window.print() n'a aucun sens dans une boîte mail.
            $html = (string) preg_replace('#<!--ecran-->.*?<!--/ecran-->#s', '', $html);
            $images = [];
            $html = preg_replace_callback('#"data:image/(jpeg|png);base64,([A-Za-z0-9+/=]+)"#',
                function ($m2) use (&$images) {
                    $cid = 'img' . (count($images) + 1) . '@cockpit';
                    $images[] = ['cid' => $cid, 'type' => $m2[1], 'b64' => $m2[2]];
                    return '"cid:' . $cid . '"';
                }, $html);
            $entetes = 'From: ' . $c['expediteur'] . "\r\n"
                . 'To: ' . $a . "\r\n"
                . 'Subject: =?UTF-8?B?' . base64_encode($sujet) . "?=\r\n"
                . 'MIME-Version: 1.0' . "\r\n"
                . 'Date: ' . date('r') . "\r\n";
            if ($images === []) {
                $entetes .= 'Content-Type: text/html; charset=UTF-8' . "\r\n"
                    . 'Content-Transfer-Encoding: base64' . "\r\n";
                $corps = chunk_split(base64_encode($html), 76, "\r\n");
            } else {
                $lim = 'lim' . bin2hex(random_bytes(10));
                $entetes .= 'Content-Type: multipart/related; boundary="' . $lim . '"' . "\r\n";
                $corps = '--' . $lim . "\r\n"
                    . 'Content-Type: text/html; charset=UTF-8' . "\r\n"
                    . 'Content-Transfer-Encoding: base64' . "\r\n\r\n"
                    . chunk_split(base64_encode($html), 76, "\r\n");
                foreach ($images as $img) {
                    $corps .= '--' . $lim . "\r\n"
                        . 'Content-Type: image/' . $img['type'] . "\r\n"
                        . 'Content-Transfer-Encoding: base64' . "\r\n"
                        . 'Content-ID: <' . $img['cid'] . '>' . "\r\n"
                        . 'Content-Disposition: inline' . "\r\n\r\n"
                        . chunk_split($img['b64'], 76, "\r\n");
                }
                $corps .= '--' . $lim . '--' . "\r\n";
            }
            if (!$dire($entetes . "\r\n" . $corps . "\r\n.", [250])) { return false; }
            $dire('QUIT', [221]);
            return true;
        } finally {
            fclose($fp);
        }
    }
}
