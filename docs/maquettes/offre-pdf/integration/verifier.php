<?php
/**
 * Vérifie l'intégration de bout en bout : le vrai OfferService modifié appelle
 * OfferPdf, avec des dépôts factices qui servent l'offre d'exemple
 * (../donnees.php) et TCPDF. Les traductions simulent le panel d'aujourd'hui :
 * les libellés existants sont traduits, les nouveaux pas encore (OfferPdf
 * prend alors ses libellés par défaut).
 *
 *   TCPDF_AUTOLOAD=/chemin/vendor/autoload.php php docs/maquettes/offre-pdf/integration/verifier.php [dossier de sortie]
 *
 * LANGUE=nl pour la langue de l'appel ; SANS_POLICES=1 pour vérifier le repli en DejaVu.
 */

namespace {
    $autoload = getenv('TCPDF_AUTOLOAD') ?: __DIR__ . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        fwrite(STDERR, "TCPDF introuvable : TCPDF_AUTOLOAD=/chemin/vendor/autoload.php\n");
        exit(1);
    }
    require $autoload;
}

namespace App\Api\Core {
    class Language
    {
        public static function getCallLanguageCode()
        {
            return getenv('LANGUE') ?: 'fr';
        }
    }
    class TransactionManager {}
}

namespace App\Api\Exceptions {
    class CustomException extends \Exception
    {
        public function __construct($message, $group = '', $code = 0)
        {
            parent::__construct($message, (int) $code);
        }
    }
}

namespace App\Api\Repositories\Utils {
    class TCPDFModel extends \TCPDF {}
}

namespace App\Api\Repositories\Client {
    class OfferRepository
    {
        public function first($where)
        {
            $o = $GLOBALS['offre'];
            unset($o['products'], $o['client']);
            return $o;
        }
    }
    class OfferProductRepository
    {
        public function getProductsByOfferId($id)
        {
            return $GLOBALS['offre']['products'];
        }
    }
    class ClientRepository
    {
        public function first($where)
        {
            return $GLOBALS['offre']['client'];
        }
    }
    class OrderRepository {}
    class OrderProductRepository {}
}

namespace App\Api\Repositories\Shop {
    class ShopRepository
    {
        public function getById($id)
        {
            return $GLOBALS['magasin'];
        }
    }
}

namespace {
    use App\Api\Repositories\Utils\OfferPdf;
    use App\Api\Services\Client\OfferService;

    class CurrencySymbol
    {
        public static function getSymbol()
        {
            return '€';
        }
    }

    require __DIR__ . '/../donnees.php';
    function getTranslation($group, $key, $lang)
    {
        $existants = ['client_information', 'tax_number_short', 'phone_short', 'email', 'commercial_offer_no', 'date',
            'detailed_product_information', 'product', 'quantity_short', 'unit_price_short', 'total', 'gross', 'discount',
            'after_discount', 'net', 'total_general', 'delivery_information', 'in_store_pickup', 'delivery', 'delivery_address',
            'shipping_cost', 'conditions', 'comment', 'general_terms_available_upon_request', 'document_generated_on', 'offer'];
        return in_array($key, $existants, true) ? libelles_offre()[$key] : $key;
    }

    $out = rtrim($argv[1] ?? sys_get_temp_dir() . '/offre-pdf-verif', '/') . '/';
    @mkdir($out . 'franchise-images', 0775, true);
    @mkdir($out . 'polices', 0775, true);

    // Le logo de franchise lu sous SHARED_FILES_URL, comme aujourd'hui.
    $png = imagecreatefrompng(__DIR__ . '/../../../../public/assets/img/logo.png');
    $jpg = imagecreatetruecolor(imagesx($png), imagesy($png));
    imagefill($jpg, 0, 0, imagecolorallocate($jpg, 255, 255, 255));
    imagecopy($jpg, $png, 0, 0, 0, 0, imagesx($png), imagesy($png));
    imagejpeg($jpg, $out . 'franchise-images/pdf-logo.jpg', 95);
    define('SHARED_FILES_URL', rtrim($out, '/'));

    // Les polices de la charte, comme elles seront posées dans le panel.
    if (!getenv('SANS_POLICES')) {
        foreach (['Gotham-Light.ttf', 'Gotham-Medium.ttf', 'Gotham-Black.ttf'] as $f) {
            copy(__DIR__ . '/../polices/' . $f, $out . 'polices/' . $f);
        }
        copy(__DIR__ . '/../../../../public/assets/ds/fonts/GC_Vank.ttf', $out . 'polices/GC_Vank.ttf');
    }

    require __DIR__ . '/OfferPdf.php';
    require __DIR__ . '/OfferService.php';
    OfferPdf::$fontsDir = $out . 'polices/';
    OfferPdf::$fontsCacheDir = $out . 'cache-polices/';
    OfferPdf::$now = strtotime('2026-10-29 10:42');

    $service = new OfferService(
        new App\Api\Repositories\Client\OfferRepository(), new App\Api\Repositories\Client\OfferProductRepository(),
        new App\Api\Repositories\Client\ClientRepository(), new App\Api\Core\TransactionManager(),
        new App\Api\Repositories\Shop\ShopRepository(), new App\Api\Repositories\Client\OrderRepository(),
        new App\Api\Repositories\Client\OrderProductRepository()
    );
    foreach (['livraison', 'retrait', 'long'] as $variante) {
        [$GLOBALS['offre'], $GLOBALS['magasin']] = offre_exemple($variante);
        $r = $service->getPDF(187);
        $fichier = $out . $variante . '-' . $r['filename'] . '.pdf';
        file_put_contents($fichier, $r['pdf']);
        exec(sprintf('pdftoppm -r 80 -jpeg %s %s', escapeshellarg($fichier), escapeshellarg($out . $variante)));
        printf("%-10s %s  %d octets\n", $variante, basename($fichier), strlen($r['pdf']));
    }
}
