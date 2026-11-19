#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests des images envoyées à l'assistance à la notation (Contrôles › vérification IA) — sans base, sans réseau,
 * sans clé.
 *
 *   php bin/tests_ia_photo.php
 *
 * Le téléchargement et l'API Anthropic sont remplacés par des fonctions qui rejouent des réponses. Ce qui se vérifie :
 * une photo de téléphone réduite sous les limites de l'API (5 Mo en base64), redressée selon l'EXIF et réencodée ; le
 * type lu dans les octets et non dans l'en-tête ; la HEIC et la page d'erreur refusées avec leur raison ; chaque image
 * de la demande (photo, fiche produit, photos du rayon) sous la limite. La requête et la réponse du modèle sont
 * couvertes par bin/tests_ia_note.php.
 */

function setting(string $k, mixed $d = null): mixed { return $k === 'anthropic' ? ['cle' => 'sk-test', 'modele' => 'modele-essai'] : $d; }
require __DIR__ . '/../src/anthropic.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $vrai, string $detail = ''): void
{
    global $ok, $ko;
    if ($vrai) { $ok++; echo "  ok  $nom\n"; } else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

/**
 * Une photo de téléphone : 4032 × 3024, orientation EXIF à 6 (couchée). Des pavés de 12 px, à cheval sur les blocs
 * JPEG, la font peser 4,7 Mo : sous les 5 Mo que l'ancien contrôle acceptait, au-dessus en base64 (6,3 Mo).
 */
function photoTelephone(int $w = 4032, int $h = 3024, int $qualite = 92): string
{
    $im = imagecreatetruecolor($w, $h);
    mt_srand(7);
    for ($y = 0; $y < $h; $y += 12) {
        for ($x = 0; $x < $w; $x += 12) { imagefilledrectangle($im, $x, $y, $x + 11, $y + 11, mt_rand(0, 0xFFFFFF)); }
    }
    ob_start(); imagejpeg($im, null, $qualite); $jpeg = (string) ob_get_clean();
    imagedestroy($im);
    // Un segment APP1 Exif minimal (TIFF petit-boutiste, une entrée : Orientation = 6), juste après SOI.
    $tiff = 'II' . pack('v', 42) . pack('V', 8) . pack('v', 1) . pack('vvVv', 0x0112, 3, 1, 6) . "\0\0" . pack('V', 0);
    $app1 = "Exif\0\0" . $tiff;
    return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
}
$b64 = fn (string $o): int => (int) ceil(strlen($o) / 3) * 4;

echo "Image : préparation pour l'API\n";
$lourde = photoTelephone();
verifie('la photo d\'essai : moins de 5 Mo bruts, plus de 5 Mo en base64 (le cas réel)', strlen($lourde) < 5 * 1024 * 1024 && $b64($lourde) > Anthropic::IMAGE_MAX_B64, round($b64($lourde) / 1048576, 1) . ' Mo');
[$img, $mime] = Anthropic::preparerImage($lourde, 'binary/octet-stream');
$dim = $img !== null ? getimagesizefromstring($img) : null;
verifie('réduite et réencodée en JPEG', $img !== null && $mime === 'image/jpeg', (string) Anthropic::$lastError);
verifie('sous 5 Mo en base64', $img !== null && $b64($img) <= Anthropic::IMAGE_MAX_B64);
verifie('grand côté ramené à ' . Anthropic::IMAGE_PX . ' px', is_array($dim) && max($dim[0], $dim[1]) === Anthropic::IMAGE_PX);
verifie('redressée selon l\'EXIF (paysage 4032 × 3024 → portrait)', is_array($dim) && $dim[1] > $dim[0], is_array($dim) ? $dim[0] . ' × ' . $dim[1] : '');

$petite = photoTelephone(800, 600, 80);
[$img2, $mime2] = Anthropic::preparerImage($petite, 'application/octet-stream');
verifie('une petite photo part telle quelle, type lu dans les octets', $img2 === $petite && $mime2 === 'image/jpeg');
$png = (function () { $im = imagecreatetruecolor(40, 30); ob_start(); imagepng($im); imagedestroy($im); return (string) ob_get_clean(); })();
verifie('un PNG annoncé en JPEG garde son vrai type', Anthropic::preparerImage($png, 'image/jpeg')[1] === 'image/png');
[$h] = Anthropic::preparerImage("\0\0\0\x18ftypheic" . str_repeat("\0", 64), 'image/heic');
verifie('une HEIC est refusée avec sa raison', $h === null && str_contains((string) Anthropic::$lastError, 'HEIC'));
[$x] = Anthropic::preparerImage('<html>AccessDenied</html>', 'application/xml');
verifie('une page d\'erreur n\'est pas prise pour une image', $x === null && str_contains((string) Anthropic::$lastError, 'non reconnu'));

echo "Proposition : les images de la demande\n";
$NIVEAUX = [['n' => 4, 'nom' => 'Exemplaire'], ['n' => 3, 'nom' => 'Conforme'], ['n' => 2, 'nom' => 'À reprendre'], ['n' => 1, 'nom' => 'Non conforme']];
$envois = [];
Anthropic::$transport = function (array $corps, array $entetes) use (&$envois) {
    $envois[] = $corps;
    return [200, ['stop_reason' => 'end_turn', 'content' => [['type' => 'text',
        'text' => json_encode(['niveau' => 3, 'constats' => ['Vitrine garnie'], 'commentaire' => 'Rien à corriger.', 'confiance' => 'haute'])]]]];
};
Anthropic::$telechargeur = fn (string $url) => [$url === 'https://ref' ? $lourde : $lourde, 'binary/octet-stream'];
$r = Anthropic::noterPhoto('Comptoir viennoiseries', 'https://photo', 'https://ref', $NIVEAUX);
$imgs = array_values(array_filter($envois[0]['messages'][0]['content'] ?? [], fn ($b) => $b['type'] === 'image'));
verifie('une photo de téléphone ne bloque plus la proposition', $r['erreur'] === null && $r['niveau'] === 3, (string) $r['erreur']);
verifie('fiche produit et photo : deux images, en JPEG, chacune sous 5 Mo', count($imgs) === 2
    && !array_filter($imgs, fn ($i) => $i['source']['media_type'] !== 'image/jpeg' || strlen($i['source']['data']) > Anthropic::IMAGE_MAX_B64));

// Les photos du rayon (planogramme, exemples) sont des fichiers du cockpit : une lourde ne s'écarte plus en silence.
$tmp = sys_get_temp_dir() . '/tests_ia_photo_' . getmypid();
@mkdir($tmp);
file_put_contents("$tmp/plano.jpg", $lourde);
file_put_contents("$tmp/exemple.png", $png);
$envois = [];
$r = Anthropic::noterPhoto('Photo du comptoir', 'https://photo', null, $NIVEAUX, '',
    ['refs' => [['chemin' => "$tmp/plano.jpg", 'lib' => 'Planogramme :'], ['chemin' => "$tmp/exemple.png", 'lib' => 'Exemple :'], ['chemin' => "$tmp/absent.jpg"]]]);
$imgs = array_values(array_filter($envois[0]['messages'][0]['content'] ?? [], fn ($b) => $b['type'] === 'image'));
verifie('planogramme lourd réduit et gardé, exemple PNG gardé, fichier absent ignoré', count($imgs) === 3
    && $imgs[0]['source']['media_type'] === 'image/jpeg' && strlen($imgs[0]['source']['data']) <= Anthropic::IMAGE_MAX_B64
    && $imgs[1]['source']['media_type'] === 'image/png', (string) count($imgs));
array_map('unlink', glob("$tmp/*")); @rmdir($tmp);

Anthropic::$telechargeur = fn () => [null, null];
$appels = 0; Anthropic::$transport = function () use (&$appels) { $appels++; return [500, null]; };
$r = Anthropic::noterPhoto('T', 'https://photo', null, $NIVEAUX);
verifie('photo introuvable : pas d\'appel au modèle', $appels === 0 && str_contains((string) $r['erreur'], 'photo illisible'));
Anthropic::$telechargeur = fn () => ["\0\0\0\x18ftypheic" . str_repeat("\0", 64), 'image/heic'];
$r = Anthropic::noterPhoto('T', 'https://photo', null, $NIVEAUX);
verifie('photo HEIC : la raison arrive à l\'écran, sans appel', $appels === 0 && str_contains((string) $r['erreur'], 'HEIC'), (string) $r['erreur']);
Anthropic::$transport = null; Anthropic::$telechargeur = null;

echo "\n$ok ok, $ko KO\n";
exit($ko === 0 ? 0 : 1);
