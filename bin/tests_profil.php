#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests du profil de l'employé (« Mon profil » de l'app, 30/10/2026) — sans base, sans réseau, sans token.
 *
 *   php bin/tests_profil.php
 *
 * Ce qui se vérifie : le numéro national, l'IBAN, le téléphone et la date ; chaque fiche contrôlée et mise au propre
 * (une erreur n'enregistre rien) ; ce qui manque, avec le prénom, le nom, le téléphone et l'e-mail du panel comptés
 * comme remplis ; les corrections du panel ; le chiffrement (aller-retour, altération, clé 0600) ; le profil gardé
 * chiffré en base ; le numéro national et l'IBAN masqués pour le cockpit, en clair au journal ; la photo de la carte
 * chiffrée sur disque ; les routes de l'app et du cockpit.
 *
 * `Db`, l'identité signée de l'app et les employés des ventes sont remplacés par des doublures ; le dossier privé est
 * un dossier temporaire (COCKPIT_PRIVE).
 */

// --- doublures ---------------------------------------------------------------------------
// La sortie est gardée jusqu'à la fin : sans cela, http_response_code() ne se pose plus après le premier echo.
ob_start();
$prive = sys_get_temp_dir() . '/tests_profil_' . bin2hex(random_bytes(4));
mkdir($prive, 0700, true);
putenv('COCKPIT_PRIVE=' . $prive);

/** mot_profil en mémoire : id_employee → ligne. */
$GLOBALS['PROFILS'] = [];
final class Db
{
    public static function rows(string $sql, array $a = []): array
    {
        if (str_contains($sql, 'FROM mot_profil WHERE shop = ?')) {
            return array_values(array_filter($GLOBALS['PROFILS'], static fn ($r) => $r['shop'] === $a[0]));
        }
        return [];
    }
    public static function row(string $sql, array $a = []): ?array
    {
        if (str_contains($sql, 'FROM mot_profil WHERE id_employee = ?')) { return $GLOBALS['PROFILS'][(int) $a[0]] ?? null; }
        return null;
    }
    public static function exec(string $sql, array $a = []): int
    {
        if (str_starts_with($sql, 'INSERT INTO mot_profil')) {
            [$emp, $shop, $donnees, $complet, $manque, $corr] = $a;
            $GLOBALS['PROFILS'][(int) $emp] = ['id_employee' => $emp, 'shop' => $shop, 'donnees' => $donnees, 'complet' => $complet,
                'manque' => $manque, 'corrections' => $corr, 'maj' => '2026-10-30 10:00:00'];
            return 1;
        }
        return 0;
    }
}
$GLOBALS['JOURNAL'] = [];
function journalAdd(string $actor, string $kind, ?string $project, string $message, ?string $ts = null): void { $GLOBALS['JOURNAL'][] = $message; }
$GLOBALS['CORPS'] = [];
function body(): array { return $GLOBALS['CORPS']; }
/** L'identité signée de l'app : null = pas d'identité. */
$GLOBALS['IDENT'] = null;
function primesIdentiteSignee(): ?array { return $GLOBALS['IDENT']; }
function primesJetonRecu(): ?string { return null; }
function primesEmployeDuJeton(string $j): ?int { return null; }
function primesResoudreEmploye(int $emp, string $nom): ?int { return null; }
/** Les employés des ventes : deux à Halle (4), une à Namur (5). */
function venteEmployes(): array
{
    return [11 => ['nom' => 'Anna Peeters', 'shop' => '4'], 12 => ['nom' => 'Bram Claes', 'shop' => '4'], 21 => ['nom' => 'Chloé Dubois', 'shop' => '5']];
}

require __DIR__ . '/../src/profil_employe.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
/** Un profil plein : les six fiches et les deux faces de la carte. */
function plein(): array
{
    return [
        'identite' => ['prenom' => 'Anna', 'nom' => 'Peeters', 'naissance' => '1985-07-30', 'nationalite' => 'Belge'],
        'coordonnees' => ['rue' => 'Rue Haute', 'numero' => '12', 'boite' => '', 'cp' => '1500', 'ville' => 'Halle', 'pays' => 'Belgique', 'telephone' => '+32470123456', 'email' => 'anna@example.be'],
        'urgence' => ['nom' => 'Jan Peeters', 'lien' => 'Conjoint', 'telephone' => '+32471000000'],
        'paie' => ['statut' => 'etudiant', 'titulaire' => 'Anna Peeters', 'niss' => '85073012335', 'iban' => 'BE68539007547034'],
        'tenue' => ['tshirt' => 'M', 'veste' => 'L', 'chaussures' => '39'],
        'dispo' => ['grille' => ['lu' => ['m' => true, 'a' => false]], 'remarque' => ''],
        'carte' => ['recto' => '2026-10-30 09:00', 'verso' => '2026-10-30 09:01'],
    ];
}

echo "Contrôles\n";
verifie('numéro national valide (né avant 2000)', profilNissValide('85.07.30-123.35'));
verifie('numéro national valide (né depuis 2000, « 2 » devant)', profilNissValide('04051412390'));
verifie('numéro national : mauvais chiffres de contrôle', !profilNissValide('85073012336'));
verifie('numéro national : 10 chiffres refusés', !profilNissValide('8507301233'));
verifie('numéro national mis en forme', profilNissFormat('85073012335') === '85.07.30-123.35', profilNissFormat('85073012335'));
verifie('IBAN belge valide (espaces, minuscules)', profilIbanErreur('be68 5390 0754 7034') === null);
verifie('IBAN français valide', profilIbanErreur('FR76 3000 6000 0112 3456 7890 189') === null);
verifie('IBAN : chiffres de contrôle faux', profilIbanErreur('BE68539007547035') === 'IBAN invalide (chiffres de contrôle)', (string) profilIbanErreur('BE68539007547035'));
verifie('IBAN : longueur du pays', profilIbanErreur('BE6853900754703') === 'un IBAN BE a 16 caractères (ici 15)', (string) profilIbanErreur('BE6853900754703'));
verifie('IBAN : illisible', profilIbanErreur('1234') === 'IBAN illisible');
verifie('IBAN mis en forme par 4', profilIbanFormat('be68539007547034') === 'BE68 5390 0754 7034');
verifie('téléphone : le 0 belge devient +32', profilTelephone('0470 12 34 56') === '+32470123456');
verifie('téléphone : 00 devient +', profilTelephone('0033 6 12 34 56 78') === '+33612345678');
verifie('téléphone : trop court ou illisible', profilTelephone('123') === null && profilTelephone('abc') === null);
verifie('date : 29 février d’une année non bissextile refusé', profilDate('2001-02-29') === null && profilDate('2000-02-29') === '2000-02-29');

echo "\nLes fiches\n";
[$o, $e] = profilSection('identite', ['prenom' => '  Anna  Maria ', 'nom' => 'Peeters2', 'naissance' => date('Y-m-d', strtotime('-5 years')), 'nationalite' => 'Belge']);
verifie('identité : espaces resserrés', $o['prenom'] === 'Anna Maria');
verifie('identité : chiffre dans le nom, naissance peu probable', ($e['nom'] ?? '') === 'lettres seulement' && ($e['naissance'] ?? '') === 'date peu probable', json_encode($e));
[$o, $e] = profilSection('identite', ['prenom' => "Marie-Ève", 'nom' => "D'Hondt", 'naissance' => '1990-03-04']);
verifie('identité : trait d’union, apostrophe, accents acceptés', $e === [] && $o['naissance'] === '1990-03-04', json_encode($e));
[$o, $e] = profilSection('coordonnees', ['telephone' => '0470/12.34.56', 'email' => 'Anna@Example.BE', 'cp' => '1500', 'ville' => 'Halle']);
verifie('coordonnées : téléphone et e-mail mis au propre', $e === [] && $o['telephone'] === '+32470123456' && $o['email'] === 'anna@example.be', json_encode([$o, $e]));
[, $e] = profilSection('coordonnees', ['telephone' => '12', 'email' => 'anna@', 'cp' => '1']);
verifie('coordonnées : trois erreurs', array_keys($e) === ['telephone', 'email', 'cp'], json_encode($e));
[$o, $e] = profilSection('paie', ['statut' => 'patron', 'niss' => '85 07 30 123 3', 'iban' => 'be68 5390 0754 7034']);
verifie('paie : statut inconnu laissé vide, IBAN gardé sans espaces', $o['statut'] === '' && $o['iban'] === 'BE68539007547034');
verifie('paie : numéro national de 10 chiffres', ($e['niss'] ?? '') === 'le numéro national a 11 chiffres (ici 10)', json_encode($e));
[$o, $e] = profilSection('paie', ['statut' => 'flexi', 'niss' => '85.07.30-123.35', 'iban' => '']);
verifie('paie : numéro national gardé en chiffres', $e === [] && $o['niss'] === '85073012335' && $o['iban'] === '');
[$o, $e] = profilSection('tenue', ['tshirt' => 'xl', 'veste' => 'XXXL', 'chaussures' => '29']);
verifie('tenue : xl → XL ; XXXL et pointure 29 refusés', $o['tshirt'] === 'XL' && isset($e['veste'], $e['chaussures']), json_encode([$o, $e]));
[$o, $e] = profilSection('tenue', ['chaussures' => '42.5']);
verifie('tenue : demi-pointure', $e === [] && $o['chaussures'] === '42.5');
[$o, $e] = profilSection('dispo', ['grille' => ['lu' => ['m' => '1'], 'xx' => ['m' => '1']], 'remarque' => 'Pas le dimanche']);
verifie('disponibilités : les sept jours, rien d’inventé', array_keys($o['grille']) === PROFIL_JOURS && $o['grille']['lu'] === ['m' => true, 'a' => false] && $o['remarque'] === 'Pas le dimanche');
[, $e] = profilSection('salaire', []);
verifie('section inconnue', isset($e['section']));

echo "\nCe qui manque, les corrections\n";
verifie('profil vide : 16 éléments manquent, 0 %', count(profilManque([])) === 16 && profilComplet([]) === 0);
$panel = ['panel' => ['prenom' => 'Anna', 'nom' => 'Peeters', 'telephone' => '0470 12 34 56', 'email' => 'anna@example.be']];
verifie('le prénom, le nom, le téléphone et l’e-mail du panel comptent', count(profilManque($panel)) === 12 && !isset(profilManque($panel)['identite.prenom']), implode(',', array_keys(profilManque($panel))));
verifie('profil plein : 100 %', profilManque(plein()) === [] && profilComplet(plein()) === 100);
$sansVerso = plein(); unset($sansVerso['carte']['verso']);
verifie('une face de la carte ne suffit pas', array_keys(profilManque($sansVerso)) === ['carte'] && profilComplet($sansVerso) === 94);
$p = plein() + $panel;
verifie('même téléphone écrit autrement : pas une correction', profilCorrections($p) === [], json_encode(profilCorrections($p)));
$p['identite']['nom'] = 'Peeters-Janssens';
verifie('nom changé : correction, avec la valeur du panel', profilCorrections($p) === ['identite.nom' => 'Peeters'], json_encode(profilCorrections($p)));

echo "\nChiffrement\n";
$c = profilChiffrer('{"niss":"85073012335"}');
verifie('chiffré : « v1: », pas le numéro en clair', str_starts_with($c, 'v1:') && !str_contains($c, '85073012335') && !str_contains(base64_decode(substr($c, 3)), '85073012335'));
verifie('aller-retour', profilDechiffrer($c) === '{"niss":"85073012335"}');
$b = base64_decode(substr($c, 3)); $b[strlen($b) - 1] = chr(ord($b[strlen($b) - 1]) ^ 1);
verifie('donnée altérée : illisible', profilDechiffrer('v1:' . base64_encode($b)) === null && profilDechiffrer('abc') === null);
verifie('deux chiffrements du même texte diffèrent', profilChiffrer('x') !== profilChiffrer('x'));
verifie('la clé : dans le dossier privé, 0600', is_file($prive . '/profil.key') && (fileperms($prive . '/profil.key') & 0777) === 0600);

echo "\nEn base, et pour le cockpit\n";
profilEcrire(11, '4', plein() + $panel);
$ligne = $GLOBALS['PROFILS'][11];
verifie('en base : chiffré, avec le pourcentage', !str_contains($ligne['donnees'], 'Peeters') && $ligne['complet'] === 100 && $ligne['manque'] === '');
verifie('relu tel quel', profilLire(11)['paie']['iban'] === 'BE68539007547034' && profilLire(99) === []);
$v = profilVue(11, profilLire(11), true);
verifie('masqué : numéro national', $v['profil']['paie']['niss'] === '85.07.30-•••.••', $v['profil']['paie']['niss']);
verifie('masqué : IBAN', $v['profil']['paie']['iban'] === 'BE68 •••• •••• 7034', $v['profil']['paie']['iban']);
verifie('masqué : la carte à part, 100 %', $v['carte']['recto'] === '2026-10-30 09:00' && !isset($v['profil']['carte']) && $v['complet'] === 100 && $v['masque'] === true);
$v = profilVue(11, profilLire(11), false);
verifie('en clair : mis en forme', $v['profil']['paie']['niss'] === '85.07.30-123.35' && $v['profil']['paie']['iban'] === 'BE68 5390 0754 7034');

echo "\nLa carte d'identité\n";
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
verifie('photo enregistrée', profilCarteEcrire(11, 'recto', $png) === [true, null]);
$f = $prive . '/cartes/11-recto.bin';
verifie('sur disque : chiffrée, 0600', is_file($f) && !str_contains((string) file_get_contents($f), "PNG") && (fileperms($f) & 0777) === 0600);
verifie('relue : type et octets', profilCarteLire(11, 'recto') === ['image/png', $png]);
verifie('pas une image : refusée', profilCarteEcrire(11, 'verso', '%PDF-1.4 bonjour')[0] === false && profilCarteLire(11, 'verso') === null);
verifie('face inconnue : refusée', profilCarteEcrire(11, '../x', $png) === [false, 'face inconnue'] && profilCarteLire(11, '../x') === null);

echo "\nLes routes de l'app\n";
$GLOBALS['IDENT'] = null;
$r = ep_profil_moi();
verifie('sans identité : 401', http_response_code() === 401 && isset($r['error']));
http_response_code(200);
$GLOBALS['IDENT'] = ['emp' => 12, 'nom' => 'Bram Claes'];
$_GET = ['panel' => base64_encode((string) json_encode(['prenom' => 'Bram', 'nom' => 'Claes', 'telephone' => '0470 99 88 77', 'email' => 'bram@example.be', 'role' => 'x']))];
$r = ep_profil_moi();
verifie('profil vide : 4 éléments du panel comptent, rien d’autre gardé', $r['emp'] === 12 && count($r['manque']) === 12 && !isset($r['profil']['panel']['role']) && !isset($GLOBALS['PROFILS'][12]));
$GLOBALS['CORPS'] = ['section' => 'paie', 'valeurs' => ['statut' => 'employe', 'niss' => '85073012336']];
$r = wr_profil_moi();
verifie('fiche avec une erreur : 422, rien enregistré', http_response_code() === 422 && isset($r['erreurs']['niss']) && !isset($GLOBALS['PROFILS'][12]));
http_response_code(200);
$GLOBALS['CORPS'] = ['section' => 'paie', 'valeurs' => ['statut' => 'employe', 'niss' => '04051412390', 'iban' => 'BE68 5390 0754 7034'], 'panel' => ['prenom' => 'Bram', 'nom' => 'Claes']];
$r = wr_profil_moi();
verifie('fiche enregistrée, chiffrée, dans son magasin', http_response_code() === 200 && $r['profil']['paie']['niss'] === '04.05.14-123.90'
    && ($GLOBALS['PROFILS'][12]['shop'] ?? null) === '4' && !str_contains($GLOBALS['PROFILS'][12]['donnees'], '04051412390'));
$GLOBALS['CORPS'] = ['section' => 'salaire', 'valeurs' => []];
wr_profil_moi();
verifie('section inconnue : 400', http_response_code() === 400);
http_response_code(200);
$GLOBALS['CORPS'] = ['face' => 'verso', 'data' => 'data:image/png;base64,' . base64_encode($png)];
$r = wr_profil_carte();
verifie('photo par l’app (préfixe data: accepté)', http_response_code() === 200 && $r['carte']['verso'] !== null && profilCarteLire(12, 'verso') !== null);

echo "\nLes routes du cockpit\n";
$_GET = ['shop' => '4'];
$r = ep_profils_equipe();
$noms = array_column($r['fiches'], 'nom');
verifie('Halle : ses deux personnes, la moins remplie d’abord, pas celle de Namur', $noms === ['Bram Claes', 'Anna Peeters'], json_encode($noms));
verifie('libellés de ce qui manque', in_array('Date de naissance', $r['fiches'][0]['manque'], true) && $r['fiches'][1]['manque'] === [] && $r['fiches'][1]['complet'] === 100);
$_GET = ['shop' => '5'];
$r = ep_profils_equipe();
verifie('Namur : pas encore de profil, 0 %, tout manque', count($r['fiches']) === 1 && $r['fiches'][0]['complet'] === 0 && count($r['fiches'][0]['manque']) === 16 && $r['fiches'][0]['maj'] === null);
$_GET = [];
ep_profils_equipe();
verifie('sans magasin : 400', http_response_code() === 400);
http_response_code(200);
$GLOBALS['JOURNAL'] = [];
$_GET = ['emp' => '11'];
$r = ep_profil_equipe();
verifie('une fiche : masquée, avec le nom, rien au journal', $r['masque'] === true && $r['nom'] === 'Anna Peeters' && $GLOBALS['JOURNAL'] === []);
$_GET = ['emp' => '11', 'voir' => '1'];
$r = ep_profil_equipe();
verifie('en clair : au journal', $r['masque'] === false && $r['profil']['paie']['iban'] === 'BE68 5390 0754 7034' && count($GLOBALS['JOURNAL']) === 1 && str_contains($GLOBALS['JOURNAL'][0], 'employé 11'));

// Les routes sont branchées : l'app avant la session, le cockpit derrière.
$index = (string) file_get_contents(__DIR__ . '/../public/api/index.php');
verifie('routes branchées dans l’API', str_contains($index, "require __DIR__ . '/../../src/profil_employe.php'")
    && str_contains($index, "=> wr_profil_moi()") && str_contains($index, "=> wr_profil_carte()")
    && str_contains($index, "'/ventes/equipe/profils'        => ep_profils_equipe()") && str_contains($index, "=> ep_profil_carte()")
    && strpos($index, "str_starts_with(\$path, '/ventes/moi/profil') && primesVoieApp()") < strpos($index, 'function routeGardee'));

// --- ménage --------------------------------------------------------------------------------
foreach (array_merge(glob($prive . '/cartes/*') ?: [], glob($prive . '/*') ?: []) as $x) { is_dir($x) ? @rmdir($x) : @unlink($x); }
@rmdir($prive);

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
