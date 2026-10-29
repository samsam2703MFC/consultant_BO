<?php
declare(strict_types=1);

/**
 * Produit les devis des maquettes avec TCPDF — le moteur d'OfferService :
 * avant (le document d'aujourd'hui) et les propositions A, B, C1, C2, C3, chacune en
 * trois variantes (livraison, retrait, offre longue sur deux pages), en PDF
 * et en JPG.
 *
 *   composer require tecnickcom/tcpdf   (dans un dossier de travail)
 *   TCPDF_AUTOLOAD=/chemin/vendor/autoload.php php docs/maquettes/offre-pdf/generer.php
 *
 * pdftoppm (poppler-utils) fait les JPG. La planche : node docs/maquettes/offre-pdf/planche.js
 */

$autoload = getenv('TCPDF_AUTOLOAD') ?: __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "TCPDF introuvable : TCPDF_AUTOLOAD=/chemin/vendor/autoload.php\n");
    exit(1);
}
require $autoload;

// Ce que le panel fournit autour d'OfferService, réduit au nécessaire.
class TCPDFModel extends TCPDF {}
final class Language { public static function getCallLanguageCode(): string { return 'fr'; } }
final class CurrencySymbol { public static function getSymbol(): string { return '€'; } }
function getTranslation(string $groupe, string $cle, string $langue): string
{
    return libelles_offre()[$cle] ?? $cle;
}

require __DIR__ . '/donnees.php';
require __DIR__ . '/commun.php';
require __DIR__ . '/avant.php';
require __DIR__ . '/proposition-a.php';
require __DIR__ . '/proposition-b.php';
require __DIR__ . '/proposition-c.php';

$tmp = sys_get_temp_dir() . '/offre-pdf-' . getmypid() . '/';
@mkdir($tmp . 'franchise-images', 0775, true);
OffreCharte::$dossierPolices = sys_get_temp_dir() . '/offre-pdf-polices/';
OffreCharte::$logo = __DIR__ . '/../../../public/assets/img/logo.png';
OffreCharte::$logoBlanc = __DIR__ . '/../../../public/assets/img/newsletter/logo-white.png';
OffreCharte::$maintenant = strtotime('2026-10-29 10:42');

// Le document d'aujourd'hui lit un JPG sous SHARED_FILES_URL : le même logo, sur fond blanc.
$png = imagecreatefrompng(OffreCharte::$logo);
$jpg = imagecreatetruecolor(imagesx($png), imagesy($png));
imagefill($jpg, 0, 0, imagecolorallocate($jpg, 255, 255, 255));
imagecopy($jpg, $png, 0, 0, 0, 0, imagesx($png), imagesy($png));
imagejpeg($jpg, $tmp . 'franchise-images/pdf-logo.jpg', 95);
define('SHARED_FILES_URL', rtrim($tmp, '/'));

$documents = [
    'avant' => fn(array $o, array $s) => (new OffreAvant())->document($o, $s),
    'a' => fn(array $o, array $s) => (new OffrePdfA($o, $s, '€'))->rendre(),
    'b' => fn(array $o, array $s) => (new OffrePdfB($o, $s, '€'))->rendre(),
    'c1' => fn(array $o, array $s) => (new OffrePdfC1($o, $s, '€'))->rendre(),
    'c2' => fn(array $o, array $s) => (new OffrePdfC2($o, $s, '€'))->rendre(),
    'c3' => fn(array $o, array $s) => (new OffrePdfC3($o, $s, '€'))->rendre(),
];
$seulement = $argv[1] ?? null;
foreach ($documents as $id => $fabrique) {
    if ($seulement && $seulement !== $id) {
        continue;
    }
    foreach (['' => 'livraison', '-retrait' => 'retrait', '-long' => 'long'] as $suffixe => $variante) {
        [$offre, $magasin] = offre_exemple($variante);
        $pdf = $fabrique($offre, $magasin);
        $fichier = __DIR__ . "/{$id}{$suffixe}.pdf";
        $pages = $pdf->getNumPages(); // avant Output(), qui referme le document
        file_put_contents($fichier, $pdf->Output('offre.pdf', 'S'));
        // L'offre principale en 110 dpi pour la planche ; les variantes plus légères.
        $dpi = $suffixe === '' ? 110 : 80;
        exec(sprintf('pdftoppm -r %d -jpeg -jpegopt quality=85 %s %s', $dpi, escapeshellarg($fichier), escapeshellarg($tmp . 'p')));
        foreach (glob($tmp . 'p-*.jpg') as $i => $page) {
            $n = $i + 1;
            rename($page, __DIR__ . "/{$id}{$suffixe}" . ($pages > 1 ? "-{$n}" : '') . '.jpg');
        }
        if ($suffixe !== '' && $suffixe !== '-long') {
            unlink($fichier); // le PDF des variantes courtes n'apporte rien de plus que son JPG
        }
        printf("%-12s %d page%s\n", $id . $suffixe, $pages, $pages > 1 ? 's' : '');
    }
}
array_map('unlink', glob($tmp . 'franchise-images/*'));
@rmdir($tmp . 'franchise-images');
@rmdir($tmp);
