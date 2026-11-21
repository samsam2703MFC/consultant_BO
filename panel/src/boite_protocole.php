<?php
declare(strict_types=1);

/**
 * La boîte mail du magasin — les protocoles (15/11/2026), sans bibliothèque (le serveur n'a pas Composer, ni
 * l'extension imap de PHP) : IMAP pour lire la boîte, MIME pour lire un e-mail, SMTP pour l'envoyer.
 *
 *   BmImap   une session IMAP (RFC 3501) : LOGIN, LIST, STATUS, SELECT, UID SEARCH, UID FETCH, UID STORE, UID MOVE
 *            (ou COPY + EXPUNGE), APPEND. Les littéraux {n} sont lus octet pour octet, jamais devinés.
 *   bmMime*  un e-mail brut → son arbre (multipart, quoted-printable, base64, jeux de caractères, mots encodés des
 *            en-têtes, noms de fichier RFC 2231) ; le texte, le HTML, les pièces jointes et les images intégrées.
 *   bmComposer  un e-mail à envoyer : texte + HTML, réponse (In-Reply-To, References), pièces jointes.
 *   BmSmtp   l'envoi par le serveur de la boîte du magasin (AUTH LOGIN, SSL ou STARTTLS, points doublés).
 *
 * Testé contre un vrai serveur (Dovecot) et des e-mails réels de forme : bin/tests_boite_mail.php.
 */

/** Un serveur : [hôte, port, sécurité ssl | tls | aucune]. « aucune » n'est admis que pour la machine elle-même (tests). */
function bmServeur(string $s, string $defaut): array
{
    $p = explode(':', trim($s) !== '' ? trim($s) : $defaut);
    $hote = strtolower(trim($p[0] ?? ''));
    $port = (int) ($p[1] ?? 0);
    $sec = in_array($p[2] ?? '', ['ssl', 'tls', 'aucune'], true) ? $p[2] : 'ssl';
    if ($sec === 'aucune' && !in_array($hote, ['127.0.0.1', 'localhost', '::1'], true)) { $sec = 'ssl'; }
    if ($port <= 0) { $port = 993; }
    return ['hote' => $hote, 'port' => $port, 'securite' => $sec];
}

/** Une connexion chiffrée (ou en clair pour la machine elle-même), certificat vérifié. */
function bmConnecter(array $srv, int $delai = 12)
{
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $srv['hote'], 'SNI_enabled' => true]]);
    $adr = ($srv['securite'] === 'ssl' ? 'ssl://' : 'tcp://') . $srv['hote'] . ':' . $srv['port'];
    $fp = @stream_socket_client($adr, $errno, $errstr, $delai, STREAM_CLIENT_CONNECT, $ctx);
    if ($fp === false) { throw new RuntimeException('le serveur ' . $srv['hote'] . ' ne répond pas (' . trim($errstr ?: (string) $errno) . ')'); }
    stream_set_timeout($fp, 30);
    return $fp;
}

/* =================================================================================================================
 * IMAP
 * =============================================================================================================== */

final class BmImap
{
    /** @var resource */
    private $fp;
    private int $n = 0;
    public array $caps = [];
    public ?string $dossier = null;
    public array $infos = [];   // UIDVALIDITY, UIDNEXT, EXISTS du dossier ouvert

    /** Ouvre la session : connexion, STARTTLS si demandé, LOGIN. Lève une RuntimeException en français. */
    public static function ouvrir(array $srv, string $utilisateur, string $motDePasse): self
    {
        $s = new self();
        $s->fp = bmConnecter($srv);
        $accueil = $s->ligne();
        if (!str_starts_with($accueil, '* OK') && !str_starts_with($accueil, '* PREAUTH')) { throw new RuntimeException('le serveur de la boîte refuse la connexion'); }
        if ($srv['securite'] === 'tls') {
            [$st] = $s->cmd('STARTTLS');
            if ($st !== 'OK' || !@stream_socket_enable_crypto($s->fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { throw new RuntimeException('le chiffrement (STARTTLS) a échoué'); }
        }
        [$st, $txt] = $s->cmdParts(['LOGIN ', ['ch' => $utilisateur], ' ', ['ch' => $motDePasse]]);
        if ($st !== 'OK') { throw new BmRefus('la boîte refuse l’adresse ou le mot de passe' . ($txt !== '' ? ' (' . $txt . ')' : '')); }
        if (preg_match('/\[CAPABILITY ([^\]]+)\]/i', $txt, $m)) { $s->caps = array_map('strtoupper', preg_split('/\s+/', trim($m[1]))); }
        if (!$s->caps) {
            [, , $u] = $s->cmd('CAPABILITY');
            foreach ($u as [$t]) { if (preg_match('/^CAPABILITY (.+)$/i', $t, $m)) { $s->caps = array_map('strtoupper', preg_split('/\s+/', trim($m[1]))); } }
        }
        return $s;
    }

    public function __destruct()
    {
        if (is_resource($this->fp ?? null)) { @fwrite($this->fp, 'Z' . ($this->n + 1) . " LOGOUT\r\n"); @fclose($this->fp); }
    }

    /** Une ligne entière, si longue soit-elle (une liste d'UID peut dépasser le tampon). */
    private function ligne(): string
    {
        $l = '';
        do {
            $b = fgets($this->fp, 1 << 16);
            if ($b === false) { throw new RuntimeException('le serveur de la boîte ne répond plus'); }
            $l .= $b;
        } while (!str_ends_with($l, "\n"));
        return $l;
    }

    /** Une chaîne IMAP : atome si possible, sinon chaîne entre guillemets, sinon littéral (8 bits, retours à la ligne). */
    private static function chaine(string $v): array|string
    {
        if ($v !== '' && preg_match('/^[\x21\x23\x24\x26\x27\x2B-\x5A\x5E-\x7A\x7C\x7E]+$/', $v)) { return $v; }
        if (preg_match('/^[\x20-\x7E]*$/', $v)) { return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"'; }
        return ['lit' => $v];
    }

    public function cmd(string $c): array { return $this->cmdParts([$c]); }

    /**
     * Une commande, faite de morceaux : texte brut, ['ch' => chaîne à citer], ['lit' => octets en littéral].
     * Rend [statut OK | NO | BAD, texte du statut, réponses non étiquetées [[texte, littéraux]]].
     */
    public function cmdParts(array $parts): array
    {
        $tag = 'A' . (++$this->n);
        $plus = in_array('LITERAL+', $this->caps, true);
        $morceaux = [$tag . ' '];
        foreach ($parts as $p) {
            if (is_array($p) && array_key_exists('ch', $p)) { $p = self::chaine((string) $p['ch']); }
            $morceaux[] = $p;
        }
        $tampon = '';
        foreach ($morceaux as $m) {
            if (!is_array($m)) { $tampon .= $m; continue; }
            $octets = (string) $m['lit'];
            $tampon .= '{' . strlen($octets) . ($plus ? '+' : '') . "}\r\n";
            fwrite($this->fp, $tampon); $tampon = '';
            if (!$plus) {
                $l = $this->ligne();
                if (!str_starts_with($l, '+')) { return ['BAD', trim($l), []]; }
            }
            fwrite($this->fp, $octets);
        }
        fwrite($this->fp, $tampon . "\r\n");
        return $this->lireReponse($tag);
    }

    /** Lit jusqu'à la ligne étiquetée ; chaque réponse « * … » garde ses littéraux à part (marqueurs \x01n\x02). */
    private function lireReponse(string $tag): array
    {
        $non = [];
        while (true) {
            $l = $this->ligne();
            $texte = ''; $lits = [];
            while (true) {
                if (preg_match('/\{(\d+)\+?\}\r?\n$/', $l, $m)) {
                    $texte .= substr($l, 0, -strlen($m[0]));
                    $n = (int) $m[1]; $o = '';
                    while (strlen($o) < $n) {
                        $b = fread($this->fp, min(1 << 16, $n - strlen($o)));
                        if ($b === false || $b === '') { throw new RuntimeException('le serveur de la boîte a coupé la lecture'); }
                        $o .= $b;
                    }
                    $texte .= "\x01" . count($lits) . "\x02"; $lits[] = $o;
                    $l = $this->ligne();
                    continue;
                }
                $texte .= rtrim($l, "\r\n");
                break;
            }
            if (str_starts_with($texte, $tag . ' ')) {
                $r = substr($texte, strlen($tag) + 1);
                $st = strtoupper((string) strtok($r, ' '));
                return [$st, trim(substr($r, strlen($st))), $non];
            }
            if (str_starts_with($texte, '* ')) { $non[] = [substr($texte, 2), $lits]; }
        }
    }

    /** Le texte d'une réponse en jetons : chaînes, null (NIL), listes ; les littéraux reprennent leurs octets. */
    public static function jetons(string $s, array $lits): array
    {
        $i = 0; $n = strlen($s);
        $lire = static function () use (&$lire, &$i, $s, $n, $lits): array {
            $out = [];
            while ($i < $n) {
                $c = $s[$i];
                if ($c === ' ') { $i++; continue; }
                if ($c === ')') { $i++; return $out; }
                if ($c === '(') { $i++; $out[] = $lire(); continue; }
                if ($c === '"') {
                    $i++; $v = '';
                    while ($i < $n && $s[$i] !== '"') { if ($s[$i] === '\\' && $i + 1 < $n) { $i++; } $v .= $s[$i]; $i++; }
                    $i++; $out[] = $v; continue;
                }
                if ($c === "\x01") { $j = strpos($s, "\x02", $i); $out[] = (string) ($lits[(int) substr($s, $i + 1, $j - $i - 1)] ?? ''); $i = $j + 1; continue; }
                $v = '';
                while ($i < $n && !in_array($s[$i], [' ', '(', ')'], true)) {
                    if ($s[$i] === '[') { $j = strpos($s, ']', $i); $j = $j === false ? $n - 1 : $j; $v .= substr($s, $i, $j - $i + 1); $i = $j + 1; continue; }
                    $v .= $s[$i]; $i++;
                }
                $out[] = strtoupper($v) === 'NIL' ? null : $v;
            }
            return $out;
        };
        return $lire();
    }

    /** Le séparateur de la hiérarchie des dossiers (« . » ou « / »), lu au dernier LIST. */
    public string $separateur = '.';

    /**
     * Les rôles reconnus au NOM du dossier, quand le serveur ne les annonce pas (SPECIAL-USE) : les noms que posent
     * les webmails et les messageries en français, en néerlandais (les magasins de Flandre), en anglais et en allemand.
     * « bin » seul, pas « binnenkomend » (la réception en néerlandais).
     */
    public const ROLES_NOMS = [
        'sent' => '/^(sent|envoy|messages envoy|éléments envoy|elements envoy|verzonden|verstuurd|gesendet)/u',
        'archive' => '/^(archiv|archief)/u',
        'trash' => '/^(trash|corbeille|deleted|bin$|éléments supprim|elements supprim|messages supprim|prullenbak|verwijderd|papierkorb)/u',
        'drafts' => '/^(draft|brouillon|concept|entw)/u',
        'junk' => '/^(junk|spam|courrier indésirable|indésirable|ongewenst|bulk)/u',
    ];

    /** Le rôle d'un dossier : ses attributs SPECIAL-USE, sinon son nom (sans le préfixe « INBOX. »). */
    public static function roleDe(string $brut, string $nom, array $drapeaux): ?string
    {
        if (strtoupper($brut) === 'INBOX') { return 'inbox'; }
        foreach (['\\sent' => 'sent', '\\archive' => 'archive', '\\trash' => 'trash', '\\drafts' => 'drafts', '\\junk' => 'junk'] as $f => $r) { if (in_array($f, $drapeaux, true)) { return $r; } }
        $court = mb_strtolower(trim((string) preg_replace('/^INBOX[.\/]/i', '', $nom)));
        foreach (self::ROLES_NOMS as $r => $re) { if (preg_match($re, $court)) { return $r; } }
        return null;
    }

    /** Les dossiers : [{nom (UTF-8), brut, role inbox|sent|archive|trash|drafts|junk|null}]. */
    public function dossiers(): array
    {
        [$st, , $u] = $this->cmd('LIST "" "*"');
        if ($st !== 'OK') { return [['nom' => 'Réception', 'brut' => 'INBOX', 'role' => 'inbox']]; }
        $out = []; $vus = [];
        foreach ($u as [$t, $lits]) {
            if (!preg_match('/^LIST /i', $t)) { continue; }
            $j = self::jetons(substr($t, 5), $lits);
            $drapeaux = array_map('strtolower', array_map('strval', (array) ($j[0] ?? [])));
            if (is_string($j[1] ?? null) && strlen($j[1]) === 1) { $this->separateur = $j[1]; }
            $brut = (string) ($j[2] ?? '');
            if ($brut === '' || in_array('\\noselect', $drapeaux, true) || in_array('\\nonexistent', $drapeaux, true)) { continue; }
            $nom = @mb_convert_encoding($brut, 'UTF-8', 'UTF7-IMAP') ?: $brut;
            $role = self::roleDe($brut, $nom, $drapeaux);
            // Un seul dossier par rôle : le premier annoncé (deux « Corbeille » feraient deux onglets identiques).
            if ($role !== null && $role !== 'inbox' && isset($vus[$role])) { $role = null; }
            if ($role !== null) { $vus[$role] = true; }
            $out[] = ['nom' => $nom, 'brut' => $brut, 'role' => $role];
        }
        return $out;
    }

    /**
     * Le préfixe des dossiers personnels : NAMESPACE quand le serveur le sait (« INBOX. » chez Courier et d'anciens
     * hébergements), sinon déduit des dossiers présents (tous sous « INBOX. » ?), sinon rien.
     */
    public function prefixe(array $dossiers): string
    {
        if (in_array('NAMESPACE', $this->caps, true)) {
            [$st, , $u] = $this->cmd('NAMESPACE');
            foreach ($st === 'OK' ? $u : [] as [$t, $lits]) {
                if (!preg_match('/^NAMESPACE /i', $t)) { continue; }
                $j = self::jetons(substr($t, 10), $lits);
                $perso = $j[0][0] ?? null;
                if (is_array($perso) && is_string($perso[0] ?? null)) {
                    if (is_string($perso[1] ?? null) && strlen($perso[1]) === 1) { $this->separateur = $perso[1]; }
                    return $perso[0];
                }
            }
        }
        $autres = array_filter($dossiers, static fn ($d) => strtoupper($d['brut']) !== 'INBOX');
        $pref = 'INBOX' . $this->separateur;
        if ($autres && !array_filter($autres, static fn ($d) => stripos($d['brut'], $pref) !== 0)) { return $pref; }
        return '';
    }

    /**
     * Crée un dossier (son nom UTF-8 sous le préfixe des dossiers personnels) et s'y abonne, pour que les webmails et
     * les téléphones le montrent. $usage : \Archive, \Trash, \Sent… annoncé quand le serveur sait CREATE-SPECIAL-USE.
     * Rend le nom brut (UTF-7 IMAP). Un dossier qui existe déjà est repris tel quel.
     */
    public function creerDossier(string $nom, ?string $usage = null): string
    {
        $nom = trim((string) preg_replace('/[\x00-\x1F\x7F*%"\\\\]+/u', ' ', $nom));
        $nom = trim(str_replace($this->separateur, ' ', $nom));
        $nom = mb_substr((string) preg_replace('/\s{2,}/u', ' ', $nom), 0, 60);
        if ($nom === '' || strtoupper($nom) === 'INBOX') { throw new RuntimeException('ce nom de dossier n’est pas admis'); }
        $dossiers = $this->dossiers();
        $brut = $this->prefixe($dossiers) . mb_convert_encoding($nom, 'UTF7-IMAP', 'UTF-8');
        foreach ($dossiers as $d) { if (strcasecmp($d['brut'], $brut) === 0) { return $d['brut']; } }
        $use = $usage !== null && in_array('CREATE-SPECIAL-USE', $this->caps, true) ? ' (USE (' . $usage . '))' : '';
        [$st, $txt] = $this->cmdParts(['CREATE ', ['ch' => $brut], $use]);
        if ($st !== 'OK' && $use !== '') { [$st, $txt] = $this->cmdParts(['CREATE ', ['ch' => $brut]]); }
        if ($st !== 'OK' && !preg_match('/ALREADYEXISTS|already exists|existe/i', $txt)) {
            throw new RuntimeException('la boîte refuse de créer le dossier « ' . $nom . ' »' . ($txt !== '' ? ' (' . $txt . ')' : ''));
        }
        $this->cmdParts(['SUBSCRIBE ', ['ch' => $brut]]);
        return $brut;
    }

    /** Des UID en ensemble IMAP compact : [1,2,3,7,9,10] → « 1:3,7,9:10 » (une longue sélection reste une ligne courte). */
    public static function ensemble(array $uids): string
    {
        $u = array_values(array_unique(array_filter(array_map('intval', $uids), static fn ($x) => $x > 0)));
        sort($u);
        $out = [];
        for ($i = 0, $n = count($u); $i < $n; $i++) {
            $debut = $u[$i];
            while ($i + 1 < $n && $u[$i + 1] === $u[$i] + 1) { $i++; }
            $out[] = $debut === $u[$i] ? (string) $debut : $debut . ':' . $u[$i];
        }
        return implode(',', $out);
    }

    /** MESSAGES et UNSEEN d'un dossier, sans l'ouvrir. */
    public function etat(string $brut): array
    {
        [$st, , $u] = $this->cmdParts(['STATUS ', ['ch' => $brut], ' (MESSAGES UNSEEN)']);
        $r = ['total' => null, 'nonLus' => null];
        if ($st !== 'OK') { return $r; }
        foreach ($u as [$t]) {
            if (preg_match('/MESSAGES (\d+)/i', $t, $m)) { $r['total'] = (int) $m[1]; }
            if (preg_match('/UNSEEN (\d+)/i', $t, $m)) { $r['nonLus'] = (int) $m[1]; }
        }
        return $r;
    }

    /** Ouvre un dossier (SELECT, ou EXAMINE en lecture seule). */
    public function ouvrirDossier(string $brut, bool $lecture = false): void
    {
        if ($this->dossier === $brut && !$lecture) { return; }
        [$st, $txt, $u] = $this->cmdParts([$lecture ? 'EXAMINE ' : 'SELECT ', ['ch' => $brut]]);
        if ($st !== 'OK') { throw new RuntimeException('le dossier « ' . $brut . ' » ne s’ouvre pas' . ($txt !== '' ? ' (' . $txt . ')' : '')); }
        $this->dossier = $lecture ? null : $brut;
        $this->infos = [];
        foreach ($u as [$t]) {
            if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $t, $m)) { $this->infos['uidvalidity'] = (int) $m[1]; }
            if (preg_match('/\[UIDNEXT (\d+)\]/i', $t, $m)) { $this->infos['uidnext'] = (int) $m[1]; }
            if (preg_match('/^(\d+) EXISTS/i', $t, $m)) { $this->infos['exists'] = (int) $m[1]; }
        }
    }

    /** UID SEARCH : les UID du dossier ouvert, du plus ancien au plus récent. $criteres : morceaux de cmdParts. */
    public function chercher(array $criteres): array
    {
        $utf8 = false;
        foreach ($criteres as $c) { if (is_array($c) && preg_match('/[^\x00-\x7F]/', (string) ($c['ch'] ?? $c['lit'] ?? ''))) { $utf8 = true; } }
        [$st, $txt, $u] = $this->cmdParts(array_merge(['UID SEARCH ' . ($utf8 ? 'CHARSET UTF-8 ' : '')], $criteres));
        if ($st !== 'OK') { throw new RuntimeException('la recherche dans la boîte a échoué' . ($txt !== '' ? ' (' . $txt . ')' : '')); }
        $uids = [];
        foreach ($u as [$t]) { if (preg_match('/^SEARCH(.*)$/i', $t, $m)) { foreach (preg_split('/\s+/', trim($m[1])) as $x) { if (ctype_digit($x)) { $uids[] = (int) $x; } } } }
        sort($uids);
        return $uids;
    }

    /** UID FETCH : [uid => [élément => valeur]] (FLAGS, INTERNALDATE, RFC822.SIZE, BODYSTRUCTURE, BODY[…]). */
    public function lire(array $uids, string $items): array
    {
        if (!$uids) { return []; }
        [$st, $txt, $u] = $this->cmd('UID FETCH ' . implode(',', array_map('intval', $uids)) . ' (' . $items . ')');
        if ($st !== 'OK') { throw new RuntimeException('la lecture des e-mails a échoué' . ($txt !== '' ? ' (' . $txt . ')' : '')); }
        $out = [];
        foreach ($u as [$t, $lits]) {
            if (!preg_match('/^\d+ FETCH /i', $t)) { continue; }
            $j = self::jetons(substr($t, strpos($t, 'FETCH') + 6), $lits);
            $l = (array) ($j[0] ?? []);
            $m = [];
            for ($i = 0; $i + 1 < count($l); $i += 2) {
                $k = strtoupper((string) $l[$i]);
                // BODY[HEADER.FIELDS (…)] → BODY[HEADER] ; BODY[] reste BODY[]. BODY.PEEK revient sous le nom BODY.
                $k = preg_replace('/^BODY\[HEADER\.FIELDS[^\]]*\]$/', 'BODY[HEADER]', $k);
                $m[$k] = $l[$i + 1];
            }
            if (isset($m['UID'])) { $out[(int) $m['UID']] = $m; }
        }
        return $out;
    }

    /** Par lots de 500 UID : une sélection de tout un dossier ne fait pas une commande démesurée. */
    private const LOT = 500;

    public function drapeaux(array $uids, string $op, string $drapeaux): void
    {
        foreach (array_chunk($uids, self::LOT) as $lot) {
            [$st, $txt] = $this->cmd('UID STORE ' . self::ensemble($lot) . ' ' . $op . 'FLAGS.SILENT (' . $drapeaux . ')');
            if ($st !== 'OK') { throw new RuntimeException('la boîte refuse le changement' . ($txt !== '' ? ' (' . $txt . ')' : '')); }
        }
    }

    /** Déplace vers un autre dossier : MOVE quand le serveur le sait, sinon COPY + \Deleted + EXPUNGE. */
    public function deplacer(array $uids, string $vers): void
    {
        foreach (array_chunk($uids, self::LOT) as $lot) {
            $liste = self::ensemble($lot);
            if (in_array('MOVE', $this->caps, true)) {
                [$st, $txt] = $this->cmdParts(['UID MOVE ' . $liste . ' ', ['ch' => $vers]]);
                if ($st !== 'OK') { throw new RuntimeException('le déplacement a échoué' . ($txt !== '' ? ' (' . $txt . ')' : '')); }
                continue;
            }
            [$st, $txt] = $this->cmdParts(['UID COPY ' . $liste . ' ', ['ch' => $vers]]);
            if ($st !== 'OK') { throw new RuntimeException('le déplacement a échoué' . ($txt !== '' ? ' (' . $txt . ')' : '')); }
            $this->effacer($lot);
        }
    }

    /**
     * Efface pour de bon (\Deleted puis EXPUNGE) les e-mails du dossier ouvert. Sans UIDPLUS, EXPUNGE efface aussi ce
     * qu'une autre messagerie y avait déjà marqué \Deleted : c'est ce qu'elle aurait fait elle-même.
     */
    public function effacer(array $uids): void
    {
        foreach (array_chunk($uids, self::LOT) as $lot) {
            $this->drapeaux($lot, '+', '\\Deleted');
            [$st, $txt] = $this->cmd(in_array('UIDPLUS', $this->caps, true) ? 'UID EXPUNGE ' . self::ensemble($lot) : 'EXPUNGE');
            if ($st !== 'OK') { throw new RuntimeException('la boîte n’efface pas ces e-mails' . ($txt !== '' ? ' (' . $txt . ')' : '')); }
        }
    }

    /** Range un e-mail dans un dossier (la copie de ce qui part, dans « Envoyés »). */
    public function deposer(string $brut, string $message, string $drapeaux = '\\Seen'): bool
    {
        [$st] = $this->cmdParts(['APPEND ', ['ch' => $brut], ' (' . $drapeaux . ') ', ['lit' => $message]]);
        return $st === 'OK';
    }
}

/** Le refus de l'adresse ou du mot de passe (distinct d'une panne du serveur). */
final class BmRefus extends RuntimeException {}

/* =================================================================================================================
 * MIME : lire un e-mail
 * =============================================================================================================== */

/** Un texte en UTF-8, quel que soit son jeu de caractères annoncé (ou pas). */
function bmUtf8(string $s, string $charset = ''): string
{
    $cs = strtolower(trim($charset, " \"'"));
    if ($cs === '' || $cs === 'us-ascii' || $cs === 'utf-8' || $cs === 'utf8') {
        return mb_check_encoding($s, 'UTF-8') ? $s : (string) mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }
    if (in_array($cs, ['iso-8859-1', 'latin1', 'latin-1'], true)) { $cs = 'Windows-1252'; }   // le cas courant, et il couvre € et ’
    $r = @mb_convert_encoding($s, 'UTF-8', $cs);
    if (!is_string($r) || $r === '') { $r = @iconv($cs, 'UTF-8//IGNORE', $s); }
    return is_string($r) && $r !== '' ? $r : (string) mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
}

/** Un en-tête décodé (mots encodés =?…?=), en UTF-8. */
function bmEntete(?string $v): string
{
    $v = trim((string) $v);
    if ($v === '') { return ''; }
    if (!str_contains($v, '=?')) { return bmUtf8($v); }
    // Deux mots encodés côte à côte se recollent sans l'espace qui les sépare (RFC 2047 §6.2).
    $v = (string) preg_replace('/(\?=)\s+(=\?)/', '$1$2', $v);
    $r = (string) preg_replace_callback('/=\?([^?]+)\?([bBqQ])\?([^?]*)\?=/', static function ($m) {
        $cs = preg_replace('/\*.*$/', '', $m[1]);
        $o = strtoupper($m[2]) === 'B' ? (string) base64_decode($m[3]) : quoted_printable_decode(str_replace('_', ' ', $m[3]));
        return bmUtf8($o, (string) $cs);
    }, $v);
    return bmUtf8($r);
}

/** Les paramètres d'un en-tête (Content-Type, Content-Disposition), noms en minuscules ; RFC 2231 compris. */
function bmParams(string $v): array
{
    $out = []; $suite = [];
    if (!preg_match_all('/;\s*([A-Za-z0-9_.\-]+(?:\*\d+)?\*?)\s*=\s*("(?:[^"\\\\]|\\\\.)*"|[^;]*)/', $v, $ms, PREG_SET_ORDER)) { return $out; }
    foreach ($ms as $m) {
        $k = strtolower($m[1]); $val = trim($m[2]);
        if ($val !== '' && $val[0] === '"') { $val = stripcslashes(substr($val, 1, -1)); }
        if (preg_match('/^(.+?)\*(\d+)(\*?)$/', $k, $p)) { $suite[$p[1]][(int) $p[2]] = [$val, $p[3] === '*']; continue; }
        if (str_ends_with($k, '*')) { $suite[substr($k, 0, -1)][0] = [$val, true]; continue; }
        $out[$k] = $val;
    }
    foreach ($suite as $k => $morceaux) {
        ksort($morceaux);
        $cs = ''; $txt = '';
        foreach ($morceaux as $i => [$val, $enc]) {
            if ($enc && $i === 0 && preg_match("/^([^']*)'[^']*'(.*)$/", $val, $x)) { $cs = $x[1]; $val = $x[2]; }
            $txt .= $enc ? rawurldecode($val) : $val;
        }
        $out[$k] = bmUtf8($txt, $cs);
    }
    return $out;
}

/** Les adresses d'un en-tête : [['nom' => …, 'adresse' => …]]. Les virgules entre guillemets ne coupent pas. */
function bmAdresses(?string $v): array
{
    $v = bmEntete($v);
    $out = []; $cur = ''; $q = false; $ang = false;
    for ($i = 0, $n = strlen($v); $i <= $n; $i++) {
        $c = $i < $n ? $v[$i] : ',';
        if ($c === '"') { $q = !$q; }
        if (!$q && $c === '<') { $ang = true; }
        if (!$q && $c === '>') { $ang = false; }
        if ($c === ',' && !$q && !$ang) {
            $x = trim($cur); $cur = '';
            if ($x === '') { continue; }
            if (preg_match('/^(.*)<([^>]+)>\s*$/s', $x, $m)) { $out[] = ['nom' => trim($m[1], " \t\"'"), 'adresse' => strtolower(trim($m[2]))]; }
            elseif (preg_match('/[^\s<>"]+@[^\s<>"]+/', $x, $m)) { $out[] = ['nom' => '', 'adresse' => strtolower($m[0])]; }
            continue;
        }
        $cur .= $c;
    }
    return array_values(array_filter($out, static fn ($a) => filter_var($a['adresse'], FILTER_VALIDATE_EMAIL) !== false));
}

/**
 * Un e-mail brut → [entetes (minuscules → dernière valeur), feuilles [{id, type, params, disposition, nom, cid, octets}]].
 * Les feuilles sont les parties finales, dans l'ordre ; un e-mail joint (message/rfc822) reste une pièce jointe (.eml).
 */
function bmMimeLire(string $brut): array
{
    $feuilles = [];
    $entetes = bmMimePartie($brut, '1', $feuilles, 0);
    return ['entetes' => $entetes, 'feuilles' => $feuilles];
}

/** Sépare en-têtes et corps, déplie les en-têtes. */
function bmMimeCouper(string $brut): array
{
    $p = preg_split("/\r?\n\r?\n/", $brut, 2);
    $h = (string) preg_replace("/\r?\n[ \t]+/", ' ', (string) ($p[0] ?? ''));
    $e = [];
    foreach (preg_split("/\r?\n/", $h) as $l) {
        if (!str_contains($l, ':')) { continue; }
        [$k, $v] = explode(':', $l, 2);
        $e[strtolower(trim($k))] = trim($v);
    }
    return [$e, (string) ($p[1] ?? '')];
}

function bmMimePartie(string $brut, string $id, array &$feuilles, int $prof): array
{
    [$e, $corps] = bmMimeCouper($brut);
    $ct = $e['content-type'] ?? 'text/plain';
    $type = strtolower(trim((string) strtok($ct, ';')));
    if ($type === '' || !str_contains($type, '/')) { $type = 'text/plain'; }
    $params = bmParams($ct);
    if (str_starts_with($type, 'multipart/') && !empty($params['boundary']) && $prof < 12) {
        $b = preg_quote($params['boundary'], '/');
        // L'épilogue, après la limite de fin, n'est pas une partie.
        if (preg_match('/(?:^|\n)--' . $b . '--/', $corps, $fin, PREG_OFFSET_CAPTURE)) { $corps = substr($corps, 0, (int) $fin[0][1]); }
        $morceaux = preg_split('/\r?\n?--' . $b . '(?:--)?[ \t]*(?:\r?\n|$)/', "\n" . $corps);
        array_shift($morceaux);   // le préambule
        $i = 0;
        foreach ($morceaux as $m) {
            if (trim($m) === '') { continue; }
            bmMimePartie($m, $id . '.' . (++$i), $feuilles, $prof + 1);
        }
        return $e;
    }
    $cte = strtolower(trim($e['content-transfer-encoding'] ?? ''));
    $octets = $cte === 'base64' ? (string) base64_decode((string) preg_replace('/\s+/', '', $corps))
        : ($cte === 'quoted-printable' ? quoted_printable_decode($corps) : $corps);
    $disp = $e['content-disposition'] ?? '';
    $dp = bmParams($disp);
    $nom = bmEntete($dp['filename'] ?? $params['name'] ?? '');
    $feuilles[] = ['id' => $id, 'type' => $type, 'params' => $params, 'disposition' => strtolower(trim((string) strtok($disp, ';'))),
        'nom' => $nom, 'cid' => trim((string) ($e['content-id'] ?? ''), '<> '), 'octets' => $octets];
    return $e;
}

/** Ce qu'on affiche d'un e-mail : le texte, le HTML, les pièces jointes, les images intégrées (cid). */
function bmMimeContenu(array $lu): array
{
    $texte = null; $html = null; $pieces = []; $cids = [];
    foreach ($lu['feuilles'] as $f) {
        $attache = $f['disposition'] === 'attachment' || ($f['nom'] !== '' && $f['disposition'] !== 'inline' && !str_starts_with($f['type'], 'text/'));
        if (!$attache && $f['type'] === 'text/plain' && $texte === null) { $texte = bmUtf8($f['octets'], (string) ($f['params']['charset'] ?? '')); continue; }
        if (!$attache && $f['type'] === 'text/html' && $html === null) { $html = bmUtf8($f['octets'], (string) ($f['params']['charset'] ?? '')); continue; }
        if ($f['cid'] !== '' && str_starts_with($f['type'], 'image/') && !$attache) { $cids[$f['cid']] = $f['id']; continue; }
        if (str_starts_with($f['type'], 'text/') && !$attache && $f['nom'] === '') { continue; }   // une deuxième version du texte
        $pieces[] = ['id' => $f['id'], 'nom' => $f['nom'] !== '' ? $f['nom'] : ($f['type'] === 'message/rfc822' ? 'e-mail-joint.eml' : 'piece-' . $f['id']),
            'type' => $f['type'], 'taille' => strlen($f['octets'])];
    }
    if ($texte === null && $html !== null) { $texte = bmHtmlTexte($html); }
    return ['texte' => $texte ?? '', 'html' => $html, 'pieces' => $pieces, 'cids' => $cids];
}

/** Le texte d'un HTML (aperçu, citation dans une réponse, lecture des messages automatiques). */
function bmHtmlTexte(string $h): string
{
    $h = (string) preg_replace('#<(script|style|head)\b.*?</\1>#is', '', $h);
    $h = (string) preg_replace('#<br\s*/?>#i', "\n", $h);
    $h = (string) preg_replace('#</(p|div|tr|li|h[1-6]|blockquote)>#i', "\n", $h);
    $t = html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = (string) preg_replace("/[ \t\x{00A0}]+/u", ' ', $t);
    return trim((string) preg_replace("/\n\s*\n\s*\n+/", "\n\n", $t));
}

/**
 * Le HTML d'un e-mail, nettoyé pour l'affichage (il s'affiche de plus dans un cadre sans script) : ni script, ni cadre,
 * ni formulaire, ni attribut on…, ni javascript: ; les images intégrées (cid:) pointent vers $urlPiece.
 */
function bmHtmlPropre(string $h, array $cids, callable $urlPiece): string
{
    $h = (string) preg_replace('#<(script|iframe|object|embed|applet|form|meta|link|base)\b[^>]*>.*?</\1>#is', '', $h);
    $h = (string) preg_replace('#<(script|iframe|object|embed|applet|form|meta|link|base)\b[^>]*/?>#is', '', $h);
    $h = (string) preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $h);
    $h = (string) preg_replace('#(href|src|action)\s*=\s*(["\']?)\s*(javascript|vbscript|data:text/html)[^"\'>\s]*\2#i', '$1="#"', $h);
    return (string) preg_replace_callback('#cid:([^"\'\s>)]+)#i', static function ($m) use ($cids, $urlPiece) {
        $c = rawurldecode($m[1]);
        return isset($cids[$c]) ? $urlPiece($cids[$c]) : '#';
    }, $h);
}

/* =================================================================================================================
 * Écrire un e-mail
 * =============================================================================================================== */

function bmEnteteMot(string $v): string
{
    return preg_match('/[^\x20-\x7E]/', $v) ? '=?UTF-8?B?' . base64_encode($v) . '?=' : $v;
}

function bmAdresseEntete(array $a): string
{
    $nom = trim((string) ($a['nom'] ?? ''));
    if ($nom === '') { return $a['adresse']; }
    return (preg_match('/[^\x20-\x7E]/', $nom) ? bmEnteteMot($nom) : '"' . str_replace(['"', '\\'], '', $nom) . '"') . ' <' . $a['adresse'] . '>';
}

/** Le HTML d'un texte saisi : paragraphes, retours à la ligne, liens ; la citation (« > ») en bloc de citation. */
function bmTexteHtml(string $t): string
{
    $lignes = preg_split("/\r?\n/", $t);
    $html = ''; $cite = [];
    $vider = static function () use (&$cite, &$html) {
        if ($cite) { $html .= '<blockquote style="margin:12px 0 0;padding:0 0 0 12px;border-left:3px solid #d9d2c7;color:#5c5852">' . bmTexteHtml(implode("\n", $cite)) . '</blockquote>'; $cite = []; }
    };
    foreach ($lignes as $l) {
        if (preg_match('/^>\s?(.*)$/', $l, $m)) { $cite[] = $m[1]; continue; }
        $vider();
        $e = htmlspecialchars($l, ENT_QUOTES, 'UTF-8');
        $e = (string) preg_replace('#(https?://[^\s<]+)#', '<a href="$1">$1</a>', $e);
        $html .= ($e === '' ? '<br>' : $e . '<br>') . "\n";
    }
    $vider();
    return $html;
}

/**
 * Un e-mail prêt à partir : [message brut, Message-ID]. $de, $a, $cc : adresses [{nom, adresse}] ; $pieces :
 * [{nom, type, contenu}] ; $reponse : [messageId, references] de l'e-mail auquel on répond.
 */
function bmComposer(array $de, array $a, array $cc, string $objet, string $texte, array $pieces = [], ?array $reponse = null): array
{
    $dom = substr((string) strrchr($de['adresse'], '@'), 1) ?: 'atelierby.be';
    $mid = '<' . bin2hex(random_bytes(12)) . '.' . time() . '@' . $dom . '>';
    $html = '<!doctype html><html><body style="font-family:Helvetica,Arial,sans-serif;font-size:14px;line-height:1.55;color:#1c1a17">' . bmTexteHtml($texte) . '</body></html>';
    $alt = 'alt' . bin2hex(random_bytes(8));
    $contenu = 'Content-Type: multipart/alternative; boundary="' . $alt . "\"\r\n\r\n"
        . '--' . $alt . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($texte), 76, "\r\n")
        . '--' . $alt . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html), 76, "\r\n")
        . '--' . $alt . "--\r\n";
    $h = 'From: ' . bmAdresseEntete($de) . "\r\n"
        . 'To: ' . implode(', ', array_map('bmAdresseEntete', $a)) . "\r\n"
        . ($cc ? 'Cc: ' . implode(', ', array_map('bmAdresseEntete', $cc)) . "\r\n" : '')
        . 'Subject: ' . bmEnteteMot($objet) . "\r\n"
        . 'Date: ' . date('r') . "\r\n"
        . 'Message-ID: ' . $mid . "\r\n"
        . ($reponse && $reponse[0] !== '' ? 'In-Reply-To: ' . $reponse[0] . "\r\nReferences: " . trim(($reponse[1] ?? '') . ' ' . $reponse[0]) . "\r\n" : '')
        . "MIME-Version: 1.0\r\n";
    if (!$pieces) {
        [$hc, $corps] = explode("\r\n\r\n", $contenu, 2);
        return [$h . $hc . "\r\n\r\n" . $corps, $mid];
    }
    $mix = 'mix' . bin2hex(random_bytes(8));
    $corps = '--' . $mix . "\r\n" . $contenu;
    foreach ($pieces as $p) {
        $nom = (string) ($p['nom'] ?? 'piece-jointe');
        $nomEnt = preg_match('/[^\x20-\x7E]|["\\\\]/', $nom) ? "filename*=UTF-8''" . rawurlencode($nom) : 'filename="' . $nom . '"';
        $corps .= '--' . $mix . "\r\n"
            . 'Content-Type: ' . (preg_match('#^[\w.+-]+/[\w.+-]+$#', (string) ($p['type'] ?? '')) ? $p['type'] : 'application/octet-stream') . "\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . 'Content-Disposition: attachment; ' . $nomEnt . "\r\n\r\n"
            . chunk_split(base64_encode((string) ($p['contenu'] ?? '')), 76, "\r\n");
    }
    $corps .= '--' . $mix . "--\r\n";
    return [$h . 'Content-Type: multipart/mixed; boundary="' . $mix . "\"\r\n\r\n" . $corps, $mid];
}

/* =================================================================================================================
 * SMTP : envoyer par le serveur de la boîte du magasin
 * =============================================================================================================== */

final class BmSmtp
{
    /** Envoie un message brut ; lève une RuntimeException en français. $rcpts : les adresses de l'enveloppe. */
    public static function envoyer(array $srv, string $utilisateur, string $motDePasse, string $de, array $rcpts, string $message): void
    {
        $fp = bmConnecter($srv, 15);
        $lire = static function () use ($fp): string {
            $out = '';
            while (($l = fgets($fp, 4096)) !== false) { $out .= $l; if (strlen($l) < 4 || $l[3] !== '-') { break; } }
            return $out;
        };
        $dire = static function (string $cmd, array $attendu, string $quoi) use ($fp, $lire): void {
            if ($cmd !== '') { fwrite($fp, $cmd . "\r\n"); }
            $r = $lire();
            if (!in_array((int) substr($r, 0, 3), $attendu, true)) { throw new RuntimeException($quoi . ' (' . trim($r) . ')'); }
        };
        try {
            $dire('', [220], 'le serveur d’envoi refuse la connexion');
            $moi = gethostname() ?: 'cockpit.local';
            $dire('EHLO ' . $moi, [250], 'le serveur d’envoi ne répond pas');
            if ($srv['securite'] === 'tls') {
                $dire('STARTTLS', [220], 'le chiffrement (STARTTLS) est refusé');
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { throw new RuntimeException('le chiffrement (STARTTLS) a échoué'); }
                $dire('EHLO ' . $moi, [250], 'le serveur d’envoi ne répond pas');
            }
            $dire('AUTH LOGIN', [334], 'le serveur d’envoi refuse l’authentification');
            $dire(base64_encode($utilisateur), [334], 'le serveur d’envoi refuse l’adresse');
            $dire(base64_encode($motDePasse), [235], 'le serveur d’envoi refuse le mot de passe');
            $dire('MAIL FROM:<' . $de . '>', [250], 'l’expéditeur est refusé');
            $ok = 0; $refus = [];
            foreach (array_unique($rcpts) as $r) {
                fwrite($fp, 'RCPT TO:<' . $r . ">\r\n");
                $rep = $lire();
                if (in_array((int) substr($rep, 0, 3), [250, 251], true)) { $ok++; } else { $refus[] = $r; }
            }
            if ($ok === 0) { throw new RuntimeException('aucun destinataire accepté (' . implode(', ', $refus) . ')'); }
            $dire('DATA', [354], 'le serveur d’envoi refuse le message');
            // Une ligne qui commence par un point en prend un second (RFC 5321 §4.5.2) : sinon elle finirait le message.
            $corps = (string) preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], ["\n", "\n"], $message));
            fwrite($fp, str_replace("\n", "\r\n", $corps) . "\r\n.\r\n");
            $dire('', [250], 'le message n’est pas parti');
            fwrite($fp, "QUIT\r\n");
            if ($refus) { throw new BmPartiel('parti, sauf pour : ' . implode(', ', $refus)); }
        } finally {
            @fclose($fp);
        }
    }
}

/** Un envoi parti, mais pas à tous les destinataires. */
final class BmPartiel extends RuntimeException {}
