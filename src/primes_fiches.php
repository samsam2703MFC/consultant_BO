<?php
declare(strict_types=1);

/*
 * Les fiches imprimables des primes (31/10/2026, Sam : « les PDF peuvent être imprimés de la page édition prime, avec
 * envoi par mail à l'équipe »). Trois PDF A4, imprimés depuis docs/primes (node docs/primes/generer.js) dans
 * public/assets/primes : le mode d'emploi du franchisé, l'affiche de l'équipe en français et en néerlandais (le QR code
 * de l'app employé). La page d'un magasin (#/primes?shop=…) les ouvre pour l'impression, et envoie l'affiche à chaque
 * personne de l'équipe qui a une adresse — un courrier par personne, jamais de copie : personne ne voit l'adresse des
 * autres.
 *
 * L'adresse d'une personne : celle de « Mon profil » dans l'app, sinon celle que le panel connaît (recopiée au profil
 * quand elle ouvre l'app). Sans l'une ni l'autre, elle est listée « sans adresse ».
 */

const PRIMES_FICHES = [
    'franchise' => ['fichier' => 'primes-franchise-mode-emploi.pdf', 'lib' => 'Mode d’emploi du franchisé'],
    'fr' => ['fichier' => 'primes-personnel-qr.pdf', 'lib' => 'Affiche de l’équipe — français'],
    'nl' => ['fichier' => 'primes-personnel-qr-nl.pdf', 'lib' => 'Affiche de l’équipe — néerlandais'],
];
const PRIMES_FICHES_APP = 'https://185.180.206.46/consulant_bo/employee/';

/** Le dossier servi des fiches (public/assets/primes, à côté de src/ comme public/uploads). */
function primesFichesDossier(): string
{
    return __DIR__ . '/../public/assets/primes';
}

/** « julie.m@gmail.com » → « j•••@gmail.com » : l'écran sait qui a une adresse, sans la montrer. */
function primesFichesMasque(string $mail): string
{
    $at = strpos($mail, '@');
    return $at === false || $at < 1 ? '•••' : mb_substr($mail, 0, 1) . '•••' . substr($mail, $at);
}

/** Une adresse lisible, en minuscules, ou null. */
function primesFichesAdresse($v): ?string
{
    $m = mb_strtolower(trim((string) $v));
    return $m !== '' && filter_var($m, FILTER_VALIDATE_EMAIL) !== false ? $m : null;
}

/**
 * L'équipe d'un magasin et l'adresse de chacune — les mêmes personnes que « Les fiches de l'équipe »
 * (ep_profils_equipe) : celles du magasin, et celles dont le profil est rangé à ce magasin.
 *
 * @return list<array{emp:int,nom:string,email:?string}>
 */
function primesFichesEquipe(string $shop): array
{
    profilTable();
    $range = [];
    foreach (Db::rows('SELECT id_employee FROM mot_profil WHERE shop = ?', [$shop]) as $r) { $range[(int) $r['id_employee']] = true; }
    $out = [];
    foreach (venteEmployes() as $id => $e) {
        if ((string) $e['shop'] !== $shop && !isset($range[$id])) { continue; }
        $p = profilLire((int) $id);
        $mail = primesFichesAdresse($p['coordonnees']['email'] ?? '') ?? primesFichesAdresse($p['panel']['email'] ?? '');
        $out[] = ['emp' => (int) $id, 'nom' => (string) $e['nom'], 'email' => $mail];
    }
    usort($out, static fn ($a, $b) => strcmp(mb_strtolower($a['nom']), mb_strtolower($b['nom'])));
    return $out;
}

/** Le courrier d'une personne, dans la langue de l'affiche (les deux si les deux partent) : [sujet, html]. */
function primesFichesCourrier(string $nom, array $langues): array
{
    $prenom = htmlspecialchars(explode(' ', trim($nom))[0] ?: $nom, ENT_QUOTES);
    $lien = '<a href="' . PRIMES_FICHES_APP . '" style="color:#8D1D2C;font-weight:bold">' . PRIMES_FICHES_APP . '</a>';
    $fr = '<p>Bonjour ' . $prenom . ',</p>'
        . '<p>Ce que tu peux gagner ce mois-ci, comment y arriver, où tu en es : tout est dans <b>l’app employé</b>, sur ton téléphone.'
        . ' L’affiche est en pièce jointe ; depuis ton téléphone, ouvre directement ' . $lien . '.</p>'
        . '<p>Connecte-toi avec ton identifiant et ton mot de passe d’employé, puis garde l’app sur ton écran d’accueil.'
        . ' Un souci de connexion ? Demande à ton responsable.</p>';
    $nl = '<p>Hallo ' . $prenom . ',</p>'
        . '<p>Wat je deze maand kunt verdienen, hoe je er komt en waar je staat: alles staat in <b>de medewerkersapp</b>, op je telefoon.'
        . ' De affiche zit in de bijlage; open op je telefoon meteen ' . $lien . '.</p>'
        . '<p>Log in met je login en wachtwoord van medewerker en zet de app op je beginscherm.'
        . ' Problemen met inloggen? Vraag het aan je manager.</p>';
    $deux = in_array('fr', $langues, true) && in_array('nl', $langues, true);
    $sujet = $deux ? 'Tes primes, dans ta poche · Je premies, altijd op zak'
        : (in_array('nl', $langues, true) ? 'Je premies, altijd op zak — de medewerkersapp' : 'Tes primes, dans ta poche — l’app employé');
    $corps = $deux ? $fr . '<hr style="border:0;border-top:1px solid #e5ded4;margin:18px 0">' . $nl : (in_array('nl', $langues, true) ? $nl : $fr);
    return [$sujet, '<div style="font-family:Helvetica,Arial,sans-serif;font-size:14px;color:#222;line-height:1.55;max-width:560px">' . $corps
        . '<p style="color:#7a736a;font-size:11px;margin-top:18px">L’Atelier by — envoyé depuis le cockpit par ton magasin.</p></div>'];
}

/** GET /ventes/primes-fiches/equipe?shop= — qui recevra l'affiche (adresse masquée), et si le courrier est réglé. */
function ep_primes_fiches_equipe(): array
{
    $shop = trim((string) ($_GET['shop'] ?? ''));
    if ($shop === '') { http_response_code(400); return ['error' => 'magasin attendu']; }
    $fiches = [];
    foreach (PRIMES_FICHES as $cle => $f) { $fiches[] = ['cle' => $cle, 'lib' => $f['lib'], 'present' => is_file(primesFichesDossier() . '/' . $f['fichier'])]; }
    return ['shop' => $shop, 'smtp' => class_exists('Smtp') && Smtp::configured(), 'fiches' => $fiches,
        'equipe' => array_map(static fn ($p) => ['emp' => $p['emp'], 'nom' => $p['nom'], 'email' => $p['email'] !== null ? primesFichesMasque($p['email']) : null],
            primesFichesEquipe($shop))];
}

/**
 * POST /ventes/primes-fiches/envoyer {shop, langues: [fr|nl], emps: [id]} — l'affiche à chaque personne choisie qui a une
 * adresse, un courrier chacune. Rend {ok, envoyes: [noms], sansAdresse: [noms], echecs: [{nom, motif}]} ; au journal.
 */
function wr_primes_fiches_envoyer(): array
{
    $b = body();
    $shop = trim((string) ($b['shop'] ?? ''));
    $langues = array_values(array_intersect(['fr', 'nl'], array_map('strval', (array) ($b['langues'] ?? []))));
    $choisis = array_map('intval', (array) ($b['emps'] ?? []));
    if ($shop === '' || $langues === [] || $choisis === []) { http_response_code(400); return ['error' => 'magasin, langue et destinataires attendus']; }
    if (!class_exists('Smtp') || !Smtp::configured()) { http_response_code(409); return ['error' => 'Le courrier n’est pas réglé (Paramètres › SMTP) : rien n’est parti.']; }
    $pieces = [];
    foreach ($langues as $l) {
        $f = primesFichesDossier() . '/' . PRIMES_FICHES[$l]['fichier'];
        if (!is_file($f)) { http_response_code(500); return ['error' => 'affiche introuvable sur le serveur : ' . PRIMES_FICHES[$l]['fichier']]; }
        $pieces[] = ['nom' => $l === 'nl' ? 'je-premies-altijd-op-zak.pdf' : 'tes-primes-dans-ta-poche.pdf', 'type' => 'application/pdf', 'contenu' => (string) file_get_contents($f)];
    }
    @set_time_limit(180);
    $envoyes = []; $sans = []; $echecs = [];
    foreach (primesFichesEquipe($shop) as $p) {
        if (!in_array($p['emp'], $choisis, true)) { continue; }
        if ($p['email'] === null) { $sans[] = $p['nom']; continue; }
        [$sujet, $html] = primesFichesCourrier($p['nom'], $langues);
        if (Smtp::envoyer($p['email'], $sujet, $html, $pieces)) { $envoyes[] = $p['nom']; }
        else { $echecs[] = ['nom' => $p['nom'], 'motif' => (string) (Smtp::$lastError ?? 'refusé')]; }
    }
    $nomMag = function_exists('primesMagasins') ? (primesMagasins()[$shop] ?? ('Magasin ' . $shop)) : ('Magasin ' . $shop);
    journalAdd('CEO', 'Primes', null, 'Affiche de l’app (' . strtoupper(implode(' + ', $langues)) . ') envoyée à ' . count($envoyes) . ' personne(s) de ' . $nomMag
        . ($sans !== [] ? ' · sans adresse : ' . implode(', ', $sans) : '') . ($echecs !== [] ? ' · en échec : ' . count($echecs) : ''));
    return ['ok' => $echecs === [] || $envoyes !== [], 'envoyes' => $envoyes, 'sansAdresse' => $sans, 'echecs' => $echecs];
}
