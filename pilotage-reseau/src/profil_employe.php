<?php

declare(strict_types=1);

/**
 * Le profil de l'employé (30/10/2026, Sam : « dans le profil de l'application employé, que l'employé puisse encoder
 * toutes ses données » ; maquette validée : docs/maquettes/profil-employe). L'employé remplit lui-même, depuis l'app,
 * six fiches : identité, coordonnées, contact d'urgence, paie (statut, numéro national, IBAN), tenue, disponibilités —
 * et la photo de sa carte d'identité, recto et verso.
 *
 * Qui voit quoi (validé) : l'employé, son franchisé (page franchisé du cockpit, « Les fiches de l'équipe ») et la
 * marque. Tout le profil est gardé chiffré (AES-256-GCM) dans mot_profil ; la clé vit hors du site web
 * (profilDossier()/profil.key, 0600) et les photos de la carte, chiffrées elles aussi, à côté. Le numéro national et
 * l'IBAN s'affichent masqués côté cockpit ; les montrer en clair, ou ouvrir une photo de la carte, s'écrit au journal.
 *
 * Le prénom, le nom, le téléphone et l'e-mail viennent du panel : l'app les envoie en même temps que la fiche, et ce
 * que l'employé y change est gardé ici et signalé au magasin (« corrections »), jamais écrit au panel.
 *
 * Les routes : l'app, avec l'identité signée de « Mes primes » (primesIdentiteSignee, ou le jeton d'employé que le
 * panel confirme) — GET /ventes/moi/profil, POST /ventes/moi/profil, POST /ventes/moi/profil/carte ; le cockpit, avec
 * sa session — GET /ventes/equipe/profils?shop=, GET /ventes/equipe/profil?emp=[&voir=1],
 * GET /ventes/equipe/profil/carte?emp=&face=.
 */

const PROFIL_SECTIONS = ['identite', 'coordonnees', 'urgence', 'paie', 'tenue', 'dispo'];
const PROFIL_STATUTS = ['employe', 'etudiant', 'flexi'];
const PROFIL_TAILLES = ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL'];
const PROFIL_JOURS = ['lu', 'ma', 'me', 'je', 've', 'sa', 'di'];
const PROFIL_CARTE_MAX = 6 * 1024 * 1024;           // une photo de la carte : 6 Mo au plus (l'app la réduit avant)
/** La longueur d'un IBAN par pays (les pays des magasins et de leurs voisins). */
const PROFIL_IBAN_LONGUEUR = ['BE' => 16, 'FR' => 27, 'NL' => 18, 'LU' => 20, 'DE' => 22, 'PL' => 28, 'IT' => 27, 'ES' => 24,
    'PT' => 25, 'GB' => 22, 'IE' => 22, 'AT' => 20, 'CH' => 21, 'MA' => 28, 'TR' => 26, 'RO' => 24];

/* ======================================================================
   Stockage : la table, le dossier privé, le chiffrement
   ====================================================================== */

function profilTable(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS mot_profil ('
        . 'id_employee INT NOT NULL PRIMARY KEY,'
        . 'shop VARCHAR(20) NULL,'
        . 'donnees MEDIUMTEXT NOT NULL,'              // le profil, chiffré (profilChiffrer)
        . 'complet TINYINT UNSIGNED NOT NULL DEFAULT 0,'
        . 'manque VARCHAR(400) NULL,'                 // les clés de ce qui manque (rien de personnel)
        . 'corrections VARCHAR(200) NULL,'            // les champs du panel que l'employé a changés
        . 'cree TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . 'maj TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
        . 'KEY ix_mot_profil_shop (shop)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
}

/**
 * Le dossier privé, hors du site web et que la livraison ne touche pas : /var/lib/consulant_bo/prive (créé par
 * bin/deploy.sh), sinon config.php › prive, sinon var/prive à côté du code (essais).
 */
function profilDossier(): string
{
    static $d = null;
    if ($d !== null) { return $d; }
    $cfg = method_exists('Db', 'config') ? (string) (Db::config()['prive'] ?? '') : '';
    foreach (array_filter([$cfg, getenv('COCKPIT_PRIVE') ?: '', '/var/lib/consulant_bo/prive', dirname(__DIR__) . '/var/prive']) as $c) {
        if ((is_dir($c) || @mkdir($c, 0700, true)) && is_writable($c)) { return $d = rtrim($c, '/'); }
    }
    throw new RuntimeException('aucun dossier privé où écrire (profil des employés)');
}

/** La clé du chiffrement : 32 octets tirés une fois, gardés dans le dossier privé (0600). */
function profilCle(): string
{
    static $k = null;
    if ($k !== null) { return $k; }
    $f = profilDossier() . '/profil.key';
    if (!is_file($f)) {
        $tmp = $f . '.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, base64_encode(random_bytes(32)));
        chmod($tmp, 0600);
        if (!@rename($tmp, $f) && !is_file($f)) { throw new RuntimeException('clé du profil impossible à écrire'); }
        @unlink($tmp);
    }
    $k = base64_decode(trim((string) file_get_contents($f)), true);
    if ($k === false || strlen($k) !== 32) { throw new RuntimeException('clé du profil illisible'); }
    return $k;
}

/** Chiffre (AES-256-GCM) : « v1: » + base64(iv 12 | tag 16 | données). */
function profilChiffrer(string $clair): string
{
    $iv = random_bytes(12);
    $tag = '';
    $c = openssl_encrypt($clair, 'aes-256-gcm', profilCle(), OPENSSL_RAW_DATA, $iv, $tag, 'mot_profil', 16);
    if ($c === false) { throw new RuntimeException('chiffrement impossible'); }
    return 'v1:' . base64_encode($iv . $tag . $c);
}

/** Déchiffre ; null si la donnée a été altérée ou si la clé a changé. */
function profilDechiffrer(string $chiffre): ?string
{
    if (!str_starts_with($chiffre, 'v1:')) { return null; }
    $b = base64_decode(substr($chiffre, 3), true);
    if ($b === false || strlen($b) < 29) { return null; }
    $c = openssl_decrypt(substr($b, 28), 'aes-256-gcm', profilCle(), OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16), 'mot_profil');
    return $c === false ? null : $c;
}

/* ======================================================================
   Contrôles : numéro national, IBAN, téléphone, date
   ====================================================================== */

/** Le numéro national belge (NISS) : 11 chiffres, le contrôle modulo 97 (« 2 » devant pour les naissances depuis 2000). */
function profilNissValide(string $niss): bool
{
    $n = preg_replace('/\D/', '', $niss) ?? '';
    if (strlen($n) !== 11) { return false; }
    $base = substr($n, 0, 9); $ctrl = (int) substr($n, 9, 2);
    return 97 - ((int) $base % 97) === $ctrl || 97 - ((int) ('2' . $base) % 97) === $ctrl;
}

/** « 04.05.14-123.45 » */
function profilNissFormat(string $niss): string
{
    $n = preg_replace('/\D/', '', $niss) ?? '';
    return strlen($n) === 11 ? substr($n, 0, 2) . '.' . substr($n, 2, 2) . '.' . substr($n, 4, 2) . '-' . substr($n, 6, 3) . '.' . substr($n, 9, 2) : $niss;
}

/** L'IBAN sans espaces, en capitales. */
function profilIbanBrut(string $iban): string
{
    return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $iban) ?? '');
}

/** Contrôle d'un IBAN : la longueur de son pays (connu), puis le modulo 97. Rend null si valide, sinon le motif. */
function profilIbanErreur(string $iban): ?string
{
    $i = profilIbanBrut($iban);
    if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{8,30}$/', $i)) { return 'IBAN illisible'; }
    $pays = substr($i, 0, 2);
    if (isset(PROFIL_IBAN_LONGUEUR[$pays]) && strlen($i) !== PROFIL_IBAN_LONGUEUR[$pays]) {
        return 'un IBAN ' . $pays . ' a ' . PROFIL_IBAN_LONGUEUR[$pays] . ' caractères (ici ' . strlen($i) . ')';
    }
    $num = '';
    foreach (str_split(substr($i, 4) . substr($i, 0, 4)) as $ch) { $num .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch; }
    $reste = 0;
    foreach (str_split($num, 7) as $morceau) { $reste = (int) ($reste . $morceau) % 97; }
    return $reste === 1 ? null : 'IBAN invalide (chiffres de contrôle)';
}

/** « BE68 5390 0754 7034 » */
function profilIbanFormat(string $iban): string
{
    return trim(chunk_split(profilIbanBrut($iban), 4, ' '));
}

/** Un téléphone : chiffres, « + » devant, 8 à 15 chiffres ; le 0 belge devient +32. Null si illisible. */
function profilTelephone(string $t): ?string
{
    $n = preg_replace('/[^\d+]/', '', $t) ?? '';
    if ($n === '') { return null; }
    if (str_starts_with($n, '00')) { $n = '+' . substr($n, 2); }
    elseif (str_starts_with($n, '0')) { $n = '+32' . substr($n, 1); }
    elseif (!str_starts_with($n, '+')) { $n = '+' . $n; }
    return preg_match('/^\+\d{8,15}$/', $n) ? $n : null;
}

/** Une date AAAA-MM-JJ réelle, ou null. */
function profilDate(string $d): ?string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($d), $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) { return null; }
    return trim($d);
}

/* ======================================================================
   Les fiches : contrôle d'une section, ce qui manque
   ====================================================================== */

/**
 * Contrôle et met au propre une section envoyée par l'app. Rend [valeurs, erreurs] — erreurs : champ → motif ; une
 * section avec une erreur n'est pas enregistrée. Un champ vide efface la valeur.
 */
function profilSection(string $section, array $v): array
{
    $t = static fn (string $k, int $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($v[$k] ?? '')) ?? ''), 0, $max);
    $out = []; $err = [];
    switch ($section) {
        case 'identite':
            foreach (['prenom' => 60, 'nom' => 60, 'nationalite' => 40] as $k => $max) { $out[$k] = $t($k, $max); }
            foreach (['prenom', 'nom'] as $k) {
                if ($out[$k] !== '' && !preg_match("/^[\\p{L}][\\p{L}' .-]*$/u", $out[$k])) { $err[$k] = 'lettres seulement'; }
            }
            $out['naissance'] = '';
            if (trim((string) ($v['naissance'] ?? '')) !== '') {
                $d = profilDate((string) $v['naissance']);
                $age = $d !== null ? (int) date_diff(date_create($d), date_create('today'))->y : -1;
                if ($d === null) { $err['naissance'] = 'date illisible'; }
                elseif ($age < 14 || $age > 100) { $err['naissance'] = 'date peu probable'; }
                else { $out['naissance'] = $d; }
            }
            break;
        case 'coordonnees':
            foreach (['rue' => 80, 'numero' => 10, 'boite' => 10, 'cp' => 10, 'ville' => 60, 'pays' => 40] as $k => $max) { $out[$k] = $t($k, $max); }
            $out['telephone'] = '';
            if ($t('telephone', 30) !== '') {
                $tel = profilTelephone((string) $v['telephone']);
                if ($tel === null) { $err['telephone'] = 'numéro illisible'; } else { $out['telephone'] = $tel; }
            }
            $out['email'] = mb_strtolower($t('email', 120));
            if ($out['email'] !== '' && filter_var($out['email'], FILTER_VALIDATE_EMAIL) === false) { $err['email'] = 'adresse e-mail illisible'; }
            if ($out['cp'] !== '' && !preg_match('/^[A-Za-z0-9 -]{3,10}$/', $out['cp'])) { $err['cp'] = 'code postal illisible'; }
            break;
        case 'urgence':
            $out = ['nom' => $t('nom', 80), 'lien' => $t('lien', 30), 'telephone' => ''];
            if ($t('telephone', 30) !== '') {
                $tel = profilTelephone((string) $v['telephone']);
                if ($tel === null) { $err['telephone'] = 'numéro illisible'; } else { $out['telephone'] = $tel; }
            }
            break;
        case 'paie':
            $out['statut'] = in_array($v['statut'] ?? '', PROFIL_STATUTS, true) ? (string) $v['statut'] : '';
            $out['titulaire'] = $t('titulaire', 80);
            $out['niss'] = '';
            $niss = preg_replace('/\D/', '', (string) ($v['niss'] ?? '')) ?? '';
            if ($niss !== '') {
                if (!profilNissValide($niss)) { $err['niss'] = strlen($niss) !== 11 ? 'le numéro national a 11 chiffres (ici ' . strlen($niss) . ')' : 'numéro national invalide (chiffres de contrôle)'; }
                else { $out['niss'] = $niss; }
            }
            $out['iban'] = '';
            $iban = profilIbanBrut((string) ($v['iban'] ?? ''));
            if ($iban !== '') {
                $e = profilIbanErreur($iban);
                if ($e !== null) { $err['iban'] = $e; } else { $out['iban'] = $iban; }
            }
            break;
        case 'tenue':
            foreach (['tshirt', 'veste'] as $k) {
                $x = strtoupper(trim((string) ($v[$k] ?? '')));
                if ($x !== '' && !in_array($x, PROFIL_TAILLES, true)) { $err[$k] = 'taille inconnue'; }
                $out[$k] = in_array($x, PROFIL_TAILLES, true) ? $x : '';
            }
            $out['chaussures'] = '';
            $p = trim((string) ($v['chaussures'] ?? ''));
            if ($p !== '') {
                if (!preg_match('/^\d{2}(\.5)?$/', $p) || (float) $p < 30 || (float) $p > 50) { $err['chaussures'] = 'pointure de 30 à 50'; }
                else { $out['chaussures'] = $p; }
            }
            break;
        case 'dispo':
            $g = is_array($v['grille'] ?? null) ? $v['grille'] : [];
            $out['grille'] = [];
            foreach (PROFIL_JOURS as $j) { $out['grille'][$j] = ['m' => !empty($g[$j]['m']), 'a' => !empty($g[$j]['a'])]; }
            $out['remarque'] = $t('remarque', 200);
            break;
        default:
            $err['section'] = 'section inconnue';
    }
    return [$out, $err];
}

/** Ce qui manque au profil, dans l'ordre de la page : [clé → libellé]. */
function profilManque(array $p): array
{
    // Le prénom, le nom, le téléphone et l'e-mail que le panel connaît comptent comme remplis.
    $duPanel = ['identite.prenom' => 'prenom', 'identite.nom' => 'nom', 'coordonnees.telephone' => 'telephone', 'coordonnees.email' => 'email'];
    $v = static fn (string $s, string $k) => trim((string) ($p[$s][$k] ?? '')) !== ''
        || (isset($duPanel[$s . '.' . $k]) && trim((string) ($p['panel'][$duPanel[$s . '.' . $k]] ?? '')) !== '');
    $m = [];
    foreach (['prenom' => 'Prénom', 'nom' => 'Nom', 'naissance' => 'Date de naissance', 'nationalite' => 'Nationalité'] as $k => $l) { if (!$v('identite', $k)) { $m['identite.' . $k] = $l; } }
    if (!$v('coordonnees', 'telephone')) { $m['coordonnees.telephone'] = 'Téléphone'; }
    if (!$v('coordonnees', 'email')) { $m['coordonnees.email'] = 'E-mail'; }
    if (!$v('coordonnees', 'rue') || !$v('coordonnees', 'cp') || !$v('coordonnees', 'ville')) { $m['coordonnees.adresse'] = 'Adresse'; }
    if (!$v('urgence', 'nom') || !$v('urgence', 'telephone')) { $m['urgence'] = 'Contact d’urgence'; }
    if (!$v('paie', 'statut')) { $m['paie.statut'] = 'Statut'; }
    if (!$v('paie', 'niss')) { $m['paie.niss'] = 'Numéro national'; }
    if (!$v('paie', 'iban')) { $m['paie.iban'] = 'IBAN'; }
    if (!$v('tenue', 'tshirt')) { $m['tenue.tshirt'] = 'Taille de t-shirt'; }
    if (!$v('tenue', 'veste')) { $m['tenue.veste'] = 'Taille de veste'; }
    if (!$v('tenue', 'chaussures')) { $m['tenue.chaussures'] = 'Pointure'; }
    $dispo = false;
    foreach ((array) ($p['dispo']['grille'] ?? []) as $j) { if (!empty($j['m']) || !empty($j['a'])) { $dispo = true; } }
    if (!$dispo) { $m['dispo'] = 'Disponibilités'; }
    if (empty($p['carte']['recto']) || empty($p['carte']['verso'])) { $m['carte'] = 'Carte d’identité'; }
    return $m;
}

/** Le pourcentage rempli : 16 éléments (les quatre de l'identité, trois coordonnées, l'urgence, trois de paie, trois de tenue, les disponibilités, la carte). */
function profilComplet(array $p): int
{
    $total = count(profilManque([]));
    return (int) round(100 * ($total - count(profilManque($p))) / $total);
}

/** Les champs du panel (prénom, nom, téléphone, e-mail) que l'employé a changés : [clé → valeur du panel]. */
function profilCorrections(array $p): array
{
    $out = [];
    $panel = (array) ($p['panel'] ?? []);
    foreach (['identite.prenom' => 'prenom', 'identite.nom' => 'nom', 'coordonnees.telephone' => 'telephone', 'coordonnees.email' => 'email'] as $chemin => $k) {
        [$s, $c] = explode('.', $chemin);
        $mien = trim((string) ($p[$s][$c] ?? ''));
        $sien = trim((string) ($panel[$k] ?? ''));
        if ($k === 'telephone') { $sien = profilTelephone($sien) ?? $sien; }
        if ($mien !== '' && $sien !== '' && mb_strtolower($mien) !== mb_strtolower($sien)) { $out[$chemin] = $sien; }
    }
    return $out;
}

/* ======================================================================
   Lecture, écriture
   ====================================================================== */

/** Le profil d'un employé, déchiffré : [] s'il n'a encore rien rempli. */
function profilLire(int $emp): array
{
    profilTable();
    $r = Db::row('SELECT donnees FROM mot_profil WHERE id_employee = ?', [$emp]);
    if ($r === null) { return []; }
    $c = profilDechiffrer((string) $r['donnees']);
    $p = $c !== null ? json_decode($c, true) : null;
    return is_array($p) ? $p : [];
}

/** Enregistre le profil (chiffré) avec ce qu'en lisent les listes sans le déchiffrer : le pourcentage, ce qui manque, les corrections. */
function profilEcrire(int $emp, ?string $shop, array $p): void
{
    profilTable();
    $donnees = profilChiffrer((string) json_encode($p, JSON_UNESCAPED_UNICODE));
    $manque = implode(',', array_keys(profilManque($p)));
    $corr = implode(',', array_keys(profilCorrections($p)));
    Db::exec('INSERT INTO mot_profil (id_employee, shop, donnees, complet, manque, corrections) VALUES (?,?,?,?,?,?)
              ON DUPLICATE KEY UPDATE shop = VALUES(shop), donnees = VALUES(donnees), complet = VALUES(complet), manque = VALUES(manque), corrections = VALUES(corrections)',
        [$emp, $shop, $donnees, profilComplet($p), $manque, $corr]);
}

/** Le profil prêt à rendre : complet, ce qui manque (libellés), les corrections ; numéro national et IBAN masqués si $masquer. */
function profilVue(int $emp, array $p, bool $masquer): array
{
    if ($masquer) {
        if (!empty($p['paie']['niss'])) { $n = (string) $p['paie']['niss']; $p['paie']['niss'] = substr(profilNissFormat($n), 0, 9) . '•••.••'; }
        if (!empty($p['paie']['iban'])) { $i = (string) $p['paie']['iban']; $p['paie']['iban'] = substr($i, 0, 4) . ' •••• •••• ' . substr($i, -4); }
    } else {
        if (!empty($p['paie']['niss'])) { $p['paie']['niss'] = profilNissFormat((string) $p['paie']['niss']); }
        if (!empty($p['paie']['iban'])) { $p['paie']['iban'] = profilIbanFormat((string) $p['paie']['iban']); }
    }
    $carte = (array) ($p['carte'] ?? []);
    unset($p['carte']);
    return ['emp' => $emp, 'profil' => $p, 'carte' => ['recto' => $carte['recto'] ?? null, 'verso' => $carte['verso'] ?? null],
        'complet' => profilComplet($p + ['carte' => $carte]), 'manque' => profilManque($p + ['carte' => $carte]),
        'corrections' => profilCorrections($p), 'masque' => $masquer];
}

/** Le magasin d'un employé (franchisee_employee, sinon le panel), ou null. */
function profilShop(int $emp): ?string
{
    try { $e = function_exists('venteEmployes') ? (venteEmployes()[$emp] ?? null) : null; } catch (Throwable $x) { $e = null; }
    return $e !== null && (string) ($e['shop'] ?? '') !== '' ? (string) $e['shop'] : null;
}

/* ======================================================================
   La carte d'identité : deux photos chiffrées dans le dossier privé
   ====================================================================== */

function profilCarteFichier(int $emp, string $face): string
{
    $d = profilDossier() . '/cartes';
    if (!is_dir($d)) { @mkdir($d, 0700, true); }
    return $d . '/' . $emp . '-' . $face . '.bin';
}

/** Enregistre une face : [ok, motif]. L'image est contrôlée (JPEG, PNG ou WebP lisible, 6 Mo au plus) puis chiffrée. */
function profilCarteEcrire(int $emp, string $face, string $octets): array
{
    if (!in_array($face, ['recto', 'verso'], true)) { return [false, 'face inconnue']; }
    if ($octets === '' || strlen($octets) > PROFIL_CARTE_MAX) { return [false, 'photo trop lourde (6 Mo au plus)']; }
    $info = @getimagesizefromstring($octets);
    $types = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp'];
    if ($info === false || !isset($types[$info[2]])) { return [false, 'photo illisible (JPEG, PNG ou WebP)']; }
    $ok = file_put_contents(profilCarteFichier($emp, $face), profilChiffrer($types[$info[2]] . "\n" . $octets));
    if ($ok === false) { return [false, 'photo impossible à enregistrer']; }
    @chmod(profilCarteFichier($emp, $face), 0600);
    return [true, null];
}

/** Une face lue et déchiffrée : [type, octets], ou null. */
function profilCarteLire(int $emp, string $face): ?array
{
    if (!in_array($face, ['recto', 'verso'], true)) { return null; }
    $f = profilCarteFichier($emp, $face);
    if (!is_file($f)) { return null; }
    $c = profilDechiffrer((string) file_get_contents($f));
    if ($c === null || !str_contains($c, "\n")) { return null; }
    [$type, $octets] = explode("\n", $c, 2);
    return [$type, $octets];
}

/* ======================================================================
   Les routes de l'app (l'employé, identité signée)
   ====================================================================== */

/** L'employé qui appelle depuis l'app : l'identité signée de « Mes primes », sinon le jeton que le panel confirme. */
function profilAppelant(): ?int
{
    $ident = function_exists('primesIdentiteSignee') ? primesIdentiteSignee() : null;
    if ($ident === null && function_exists('primesJetonRecu')) {
        $jeton = primesJetonRecu();
        $e = $jeton !== null ? primesEmployeDuJeton($jeton) : null;
        if ($e !== null) { $ident = ['emp' => $e, 'nom' => '']; }
    }
    if ($ident === null) { return null; }
    // L'identifiant du panel fait foi ; il n'a pas besoin d'être déjà connu des ventes pour remplir son profil.
    return (int) $ident['emp'] > 0 ? (int) $ident['emp'] : primesResoudreEmploye(0, (string) $ident['nom']);
}

/** GET /ventes/moi/profil — le profil de l'employé connecté, en clair (c'est le sien). */
function ep_profil_moi(): array
{
    $emp = profilAppelant();
    if ($emp === null) { http_response_code(401); return ['error' => 'jeton d’employé invalide']; }
    $p = profilLire($emp);
    // « panel » (base64 JSON) : ce que le panel sait de la personne, pour compter ce qui manque — lu, jamais gardé ici.
    $panel = json_decode((string) base64_decode((string) ($_GET['panel'] ?? ''), true), true);
    if (is_array($panel)) { $p['panel'] = array_map(static fn ($x) => mb_substr(trim((string) $x), 0, 120), array_intersect_key($panel, array_flip(['prenom', 'nom', 'telephone', 'email']))); }
    return profilVue($emp, $p, false);
}

/**
 * POST /ventes/moi/profil {section, valeurs, panel?} — enregistre une fiche. 422 {erreurs: champ → motif} si un champ
 * ne passe pas ; rien n'est alors enregistré. « panel » : prénom, nom, téléphone, e-mail tels que le panel les connaît.
 */
function wr_profil_moi(): array
{
    $emp = profilAppelant();
    if ($emp === null) { http_response_code(401); return ['error' => 'jeton d’employé invalide']; }
    $b = function_exists('body') ? body() : (json_decode((string) file_get_contents('php://input'), true) ?: []);
    $section = (string) ($b['section'] ?? '');
    if (!in_array($section, PROFIL_SECTIONS, true)) { http_response_code(400); return ['error' => 'section inconnue']; }
    [$valeurs, $erreurs] = profilSection($section, is_array($b['valeurs'] ?? null) ? $b['valeurs'] : []);
    if ($erreurs !== []) { http_response_code(422); return ['error' => 'à corriger', 'erreurs' => $erreurs]; }
    $p = profilLire($emp);
    $p[$section] = $valeurs;
    if (is_array($b['panel'] ?? null)) {
        $p['panel'] = array_map(static fn ($x) => mb_substr(trim((string) $x), 0, 120), array_intersect_key($b['panel'], array_flip(['prenom', 'nom', 'telephone', 'email'])));
    }
    profilEcrire($emp, profilShop($emp), $p);
    return profilVue($emp, $p, false);
}

/** POST /ventes/moi/profil/carte {face, data (base64)} — une photo de la carte d'identité. */
function wr_profil_carte(): array
{
    $emp = profilAppelant();
    if ($emp === null) { http_response_code(401); return ['error' => 'jeton d’employé invalide']; }
    $b = function_exists('body') ? body() : (json_decode((string) file_get_contents('php://input'), true) ?: []);
    $face = (string) ($b['face'] ?? '');
    $data = (string) ($b['data'] ?? '');
    if (str_contains($data, ',')) { $data = substr($data, strpos($data, ',') + 1); }          // « data:image/jpeg;base64,… »
    $octets = base64_decode($data, true);
    [$ok, $motif] = profilCarteEcrire($emp, $face, $octets === false ? '' : $octets);
    if (!$ok) { http_response_code(422); return ['error' => $motif, 'erreurs' => ['carte' => $motif]]; }
    $p = profilLire($emp);
    $p['carte'] = (array) ($p['carte'] ?? []);
    $p['carte'][$face] = date('Y-m-d H:i');
    profilEcrire($emp, profilShop($emp), $p);
    return profilVue($emp, $p, false);
}

/* ======================================================================
   Les routes du cockpit (session) : la page franchisé
   ====================================================================== */

/** GET /ventes/equipe/profils?shop= — les fiches d'un magasin : qui, rempli à combien, ce qui manque, ce qui est corrigé. */
function ep_profils_equipe(): array
{
    $shop = trim((string) ($_GET['shop'] ?? ''));
    if ($shop === '') { http_response_code(400); return ['error' => 'magasin attendu']; }
    profilTable();
    $lignes = [];
    foreach (Db::rows('SELECT id_employee, complet, manque, corrections, maj FROM mot_profil WHERE shop = ?', [$shop]) as $r) { $lignes[(int) $r['id_employee']] = $r; }
    $libManque = profilManque([]);
    $libCorr = ['identite.prenom' => 'Prénom', 'identite.nom' => 'Nom', 'coordonnees.telephone' => 'Téléphone', 'coordonnees.email' => 'E-mail'];
    $out = [];
    foreach (venteEmployes() as $id => $e) {
        if ((string) $e['shop'] !== $shop && !isset($lignes[$id])) { continue; }
        $r = $lignes[$id] ?? null;
        $manque = $r !== null ? array_values(array_filter(explode(',', (string) $r['manque']))) : array_keys($libManque);
        $corr = $r !== null ? array_values(array_filter(explode(',', (string) $r['corrections']))) : [];
        $out[] = ['emp' => (int) $id, 'nom' => (string) $e['nom'], 'complet' => $r !== null ? (int) $r['complet'] : 0,
            'manque' => array_map(static fn ($k) => $libManque[$k] ?? $k, $manque),
            'corrections' => array_map(static fn ($k) => $libCorr[$k] ?? $k, $corr),
            'maj' => $r !== null ? (string) $r['maj'] : null];
    }
    usort($out, static fn ($a, $b) => [$a['complet'], $a['nom']] <=> [$b['complet'], $b['nom']]);
    return ['shop' => $shop, 'fiches' => $out];
}

/** GET /ventes/equipe/profil?emp=[&voir=1] — une fiche ; numéro national et IBAN masqués, en clair avec voir=1 (au journal). */
function ep_profil_equipe(): array
{
    $emp = (int) ($_GET['emp'] ?? 0);
    if ($emp <= 0) { http_response_code(400); return ['error' => 'emp attendu']; }
    $voir = ($_GET['voir'] ?? '') === '1';
    $p = profilLire($emp);
    if ($voir && function_exists('journalAdd')) { journalAdd('CEO', 'Profil', null, 'Données de paie affichées en clair : employé ' . $emp); }
    $nom = (string) (venteEmployes()[$emp]['nom'] ?? ('Employé ' . $emp));
    return profilVue($emp, $p, !$voir) + ['nom' => $nom];
}

/** GET /ventes/equipe/profil/carte?emp=&face= — une photo de la carte, déchiffrée (au journal). Sort l'image, puis exit. */
function ep_profil_carte(): void
{
    $emp = (int) ($_GET['emp'] ?? 0);
    $face = (string) ($_GET['face'] ?? '');
    $c = $emp > 0 ? profilCarteLire($emp, $face) : null;
    if ($c === null) { http_response_code(404); header('Content-Type: application/json'); echo json_encode(['error' => 'photo absente']); exit; }
    if (function_exists('journalAdd')) { journalAdd('CEO', 'Profil', null, 'Carte d’identité ouverte (' . $face . ') : employé ' . $emp); }
    header('Content-Type: ' . $c[0]);
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="carte-' . $emp . '-' . $face . '"');
    echo $c[1];
    exit;
}
