<?php
declare(strict_types=1);
/*
 * Nowy produkt z filmu [196] : treść karty (ilości, składniki w polu tekstowym, kroki, prognoza kwartałów, nazwa pliku,
 * odpowiedź asystenta → klatki), karta .docx (zapis i odczyt w układzie kart testowych, plik niezaufany), odczyt linku
 * Facebook / Pinterest (opis, autor, wideo, ściana logowania, przekierowania i domeny — bez sieci), wywołanie asystenta AI
 * przez SDK Anthropic (kształt zapytania, odmowa, ucięcie, błędy HTTP — odpowiedzi z MockHandler, bez sieci) i cały
 * kreator na bazie jednorazowej : szkic, klatki, generowanie, poprawki, zapis → produkt w rozwoju, tablet, .docx / PDF,
 * import gotowej karty. Krok « Koszt i cena » [198] : rdzenie nazw, ilości (łyżki, sztuki, « na sztukę »), wydajność,
 * listy współczynników ; na bazie : przypisania do surowców, pamięć wyborów, ceny (fabryka, sklep, sprzedawalność), cobot.
 *
 *   php tests/dev_card_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp, migration 122 appliquée). La base erp_dev_card_test est
 * recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Core\Api\Workshop\WorkshopConfig;
use App\Exceptions\CustomException;
use App\Services\DevCard\AiSettings;
use App\Services\DevCard\CardDocx;
use App\Services\DevCard\CardGenerator;
use App\Services\DevCard\CardSchema;
use App\Services\DevCard\DevCardCostService;
use App\Services\DevCard\DevCardService;
use App\Services\DevCard\IngredientMatcher as IM;
use App\Services\DevCard\SourceReader;
use App\Services\Workshop\Execution\DevProductService;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

function expectCard(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

/** Wyjątek z oczekiwanym kodem HTTP (i opcjonalnie polem). */
function expectFail(callable $fn, int $code, string $message, ?string $field = null): CustomException
{
    try {
        $fn();
    } catch (CustomException $e) {
        expectCard((int) $e->getCode() === $code, $message . ' : kod ' . $e->getCode() . ' (' . $e->getMessage() . ')');
        if ($field !== null) expectCard(isset($e->getErrors()['fields'][$field]), $message . ' : pole ' . $field);
        return $e;
    }
    throw new RuntimeException('FAILED: ' . $message . ' : brak wyjątku');
}

function jpeg(int $w, int $h, array $rgb): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, ...$rgb));
    ob_start();
    imagejpeg($im, null, 85);
    imagedestroy($im);
    return (string) ob_get_clean();
}

// --- 1. Treść karty --------------------------------------------------------------------------------------
expectCard(CardSchema::splitQty('500 g mąki') === ['qty' => '500 g', 'name' => 'mąki'], 'ilość : 500 g');
expectCard(CardSchema::splitQty('1½ łyżeczki cynamonu') === ['qty' => '1½ łyżeczki', 'name' => 'cynamonu'], 'ilość : 1½ łyżeczki');
expectCard(CardSchema::splitQty('≈ 14 szt. po 60 g')['qty'] === '≈ 14 szt.', 'ilość : ≈ 14 szt.');
expectCard(CardSchema::splitQty('posiekana natka pietruszki') === ['qty' => '', 'name' => 'posiekana natka pietruszki'], 'bez ilości');
expectCard(CardSchema::splitQty('• 2 jajka') === ['qty' => '2', 'name' => 'jajka'], 'wypunktowanie usunięte');
$groups = CardSchema::ingredientsFromText("CIASTO:\n500 g mąki\n10 g soli\n\nSOS\n4 łyżki sosu\nnadzienie serowe:\n150 g serka");
expectCard(array_column($groups, 'group') === ['CIASTO', 'SOS', 'NADZIENIE SEROWE'], 'podprzepisy z pola tekstowego : ' . implode('/', array_column($groups, 'group')));
expectCard(CardSchema::ingredientsFromText(CardSchema::ingredientsToText($groups)) === $groups, 'składniki : tekst ↔ struktura');
expectCard(CardSchema::stepsFromText("1. Wymieszać\n\n2) Upiec\nOstudzić") === ['Wymieszać', 'Upiec', 'Ostudzić'], 'kroki bez numeracji i pustych linii');
$f = CardSchema::forecast(120, '4,1');
expectCard($f['per_quarter'] === [1 => 1560, 2 => 0, 3 => 0, 4 => 1560] && $f['year'] === 3120 && $f['seasonal'], 'prognoza sezonowa Q1 + Q4');
$f = CardSchema::forecast(120, null);
expectCard($f['year'] === 6240 && !$f['seasonal'] && $f['quarters'] === [1, 2, 3, 4], 'prognoza całoroczna');
expectCard(CardSchema::forecast(null, null)['year'] === 0, 'prognoza bez sprzedaży');
expectCard(CardSchema::fileName(6, 'Słone skręcane bułeczki z sucukiem i kaşarem', 'docx') === '06_Slone_skrecane_buleczki_z_sucukiem.docx', 'nazwa pliku NN_Nazwa');
$ai = ['title' => ' Tytuł ', 'original_name' => '', 'author' => 'Chef', 'source_language' => 'tr', 'yield' => '8 szt.', 'baking' => '180 °C', 'time' => '1 h',
    'ingredients' => [['group' => 'ciasto', 'items' => [['qty' => '500 g', 'name' => 'mąki'], ['qty' => '', 'name' => '']]], ['group' => 'PUSTE', 'items' => []]],
    'steps' => ['Krok', ' '], 'photos' => [['frame' => 2, 'caption' => 'B'], ['frame' => 9, 'caption' => 'brak klatki'], ['frame' => 2, 'caption' => 'powtórka'], ['frame' => 1, 'caption' => 'A']],
    'notes' => [], 'has_quantities' => false];
$card = CardSchema::fromAi($ai, [1 => 101, 2 => 102]);
expectCard($card['title'] === 'Tytuł' && $card['ingredients'] === [['group' => 'CIASTO', 'items' => [['qty' => '500 g', 'name' => 'mąki']]]], 'odpowiedź AI : puste pozycje usunięte, nagłówek wielkimi literami');
expectCard($card['photos'] === [['frame_id' => 102, 'caption' => 'B'], ['frame_id' => 101, 'caption' => 'A']], 'odpowiedź AI : klatki nieznane i powtórzone pominięte');
expectCard(in_array('Brak gramatur w źródle.', $card['notes'], true) && $card['steps'] === ['Krok'], 'odpowiedź AI : uwaga o braku gramatur');
expectFail(fn() => CardSchema::assertComplete(CardSchema::normalize([])), 422, 'karta pusta', 'title');
$schema = CardSchema::aiSchema();
expectCard($schema['additionalProperties'] === false && count($schema['required']) === count($schema['properties']), 'schemat AI : wszystkie pola wymagane, bez dodatkowych');

// --- 2. Karta .docx ----------------------------------------------------------------------------------------
$images = [11 => ['mime' => 'image/jpeg', 'image' => jpeg(72, 128, [200, 120, 40])], 12 => ['mime' => 'image/jpeg', 'image' => jpeg(128, 72, [40, 120, 200])]];
$card = CardSchema::normalize(['title' => 'Bułeczki z borówką & serkiem <test>', 'original_name' => 'Blueberry rolls', 'author' => 'Mumsfoodies',
    'yield' => '8 bułeczek', 'baking' => '180 °C 10 min', 'time' => 'Rozrost 60 min',
    'ingredients' => [['group' => 'CIASTO', 'items' => [['qty' => '480 g', 'name' => 'mąki chlebowej'], ['qty' => '1', 'name' => 'jajko']]], ['group' => 'NADZIENIE', 'items' => [['qty' => '100 g', 'name' => 'borówek']]]],
    'steps' => ['Wymieszać składniki.', 'Piec 22 min.'], 'photos' => [['frame_id' => 11, 'caption' => 'Rozsmarować'], ['frame_id' => 12, 'caption' => 'Efekt']]]);
$docx = CardDocx::build($card, $images);
expectCard(str_starts_with($docx, 'PK'), '.docx : archiwum ZIP');
$tmp = tempnam(sys_get_temp_dir(), 'cardtest');
file_put_contents($tmp, $docx);
$zip = new ZipArchive();
$zip->open($tmp);
$xml = (string) $zip->getFromName('word/document.xml');
expectCard(str_contains($xml, 'Cormorant Garamond') && str_contains($xml, 'F2C9A0') && str_contains($xml, 'L’Atelier By · Karta technologiczna'), '.docx : styl L’Atelier (fonty, morela, nagłówek)');
expectCard(str_contains($xml, 'borówką &amp; serkiem &lt;test&gt;'), '.docx : tekst zakodowany XML');
expectCard($zip->locateName('word/media/image2.jpg') !== false && (new DOMDocument())->loadXML($xml), '.docx : zdjęcia i poprawny XML');
$zip->close();
$back = CardDocx::read($tmp);
expectCard($back['card']['title'] === $card['title'] && $back['card']['original_name'] === 'Blueberry rolls' && $back['card']['author'] === 'Mumsfoodies', '.docx → karta : tytuł, oryginał, autor');
expectCard($back['card']['yield'] === '8 bułeczek' && $back['card']['baking'] === '180 °C 10 min' && $back['card']['time'] === 'Rozrost 60 min', '.docx → karta : pasek');
expectCard(CardSchema::normalize($back['card'])['ingredients'] === $card['ingredients'] && $back['card']['steps'] === $card['steps'], '.docx → karta : składniki i kroki');
expectCard(array_column($back['images'], 'caption') === ['Rozsmarować', 'Efekt'] && $back['images'][0]['image'] === $images[11]['image'], '.docx → karta : zdjęcia z podpisami');
file_put_contents($tmp, 'to nie jest docx');
expectFail(fn() => CardDocx::read($tmp), 422, 'plik nie-docx', 'file');
$zip = new ZipArchive();
$zip->open($tmp, ZipArchive::OVERWRITE);
$zip->addFromString('word/document.xml', '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:tbl/></w:body></w:document>');
$zip->close();
expectFail(fn() => CardDocx::read($tmp), 422, '.docx bez karty (encja zewnętrzna nie czytana)', 'file');
@unlink($tmp);

// --- 3. Odczyt linku (bez sieci) ---------------------------------------------------------------------------
expectCard(SourceReader::detect('http://fb.watch/abc')['kind'] === 'facebook' && SourceReader::detect('http://fb.watch/abc')['url'] === 'https://fb.watch/abc', 'link : fb.watch → https');
expectCard(SourceReader::detect('https://pin.it/xyz')['kind'] === 'pinterest' && SourceReader::detect('https://pl.pinterest.com/pin/1/')['kind'] === 'pinterest', 'link : Pinterest');
expectFail(fn() => SourceReader::detect('https://www.instagram.com/reel/x'), 422, 'link spoza FB / Pinterest', 'url');
expectFail(fn() => SourceReader::detect('https://facebook.com.evil.example/reel/1'), 422, 'domena podszywająca się pod Facebook', 'url');
expectFail(fn() => SourceReader::detect('javascript:alert(1)'), 422, 'link nie-http', 'url');
$fbHtml = '<html><head><meta property="og:title" content="Kıvrık Poğaça | By Chef Burcin | Facebook"><meta property="og:description" content="Kısa açıklama…">'
    . '<meta content="https://scontent.xx.fbcdn.net/v/cover.jpg" property="og:image"></head><body><script>{"message":{"text":"Kıvrık Poğaça\\n500 gr un, 300 ml süt, 10 gr tuz \\u0026 maya"},'
    . '"owner":{"__typename":"Page","id":"1","name":"Chefburcinarda"},"browser_native_hd_url":"https:\/\/video.xx.fbcdn.net\/v\/t42.mp4?_nc=1&oh=2","browser_native_sd_url":"https:\/\/video.xx.fbcdn.net\/v\/t42sd.mp4?oh=3"}</script></body></html>';
$calls = [];
SourceReader::$transport = function (string $url, int $max) use (&$calls, $fbHtml) {
    $calls[] = $url;
    return match (true) {
        $url === 'https://fb.watch/abc' => [302, '', 'https://www.facebook.com/reel/123'],
        $url === 'https://www.facebook.com/reel/123' => [200, $fbHtml, null],
        $url === 'https://fb.watch/wall' => [302, '', 'https://www.facebook.com/login/?next=reel'],
        $url === 'https://www.facebook.com/login/?next=reel' => [200, '<html><form id="login_form"></form><meta property="og:image" content="https://scontent.xx.fbcdn.net/c.jpg"></html>', null],
        $url === 'https://fb.watch/evil' => [302, '', 'https://evil.example/steal'],
        str_starts_with($url, 'https://pin.it/') => [301, '', 'https://pl.pinterest.com/pin/77/'],
        $url === 'https://pl.pinterest.com/pin/77/' => [200, '<meta property="og:title" content="Cinnamon rolls"><meta property="og:description" content="Best rolls"><meta name="pinterestapp:source" content="https://blog.example/rolls">'
            . '<script>{"pinner":{"full_name":"Baker Ann"},"videos":{"video_list":{"V_720P":{"width":720,"url":"https:\/\/v1.pinimg.com\/videos\/mc\/720p\/aa\/bb.mp4"}}}}</script>', null],
        str_starts_with($url, 'https://video.xx.fbcdn.net/') => [200, 'MP4DATA', null],
        default => [404, '', null],
    };
};
$reader = new SourceReader();
$r = $reader->read('https://fb.watch/abc');
expectCard($r['kind'] === 'facebook' && $r['url'] === 'https://www.facebook.com/reel/123', 'Facebook : przekierowanie fb.watch → reel');
expectCard($r['text'] === "Kıvrık Poğaça\n500 gr un, 300 ml süt, 10 gr tuz & maya", 'Facebook : pełny opis zamiast skróconego og:description');
expectCard($r['author'] === 'Chefburcinarda', 'Facebook : autor ' . $r['author']);
expectCard(SourceReader::detect('https://www.facebook.com/x/videos/1/')['kind'] === 'facebook', 'Facebook : film /videos/');
expectCard($r['video'] === 'https://video.xx.fbcdn.net/v/t42.mp4?_nc=1&oh=2' && $r['video_sd'] === 'https://video.xx.fbcdn.net/v/t42sd.mp4?oh=3', 'Facebook : wideo HD + zapasowe SD (długi film)');
expectCard($r['image'] === 'https://scontent.xx.fbcdn.net/v/cover.jpg' && !$r['login_wall'], 'Facebook : okładka, bez ściany logowania');
$wall = $reader->read('https://fb.watch/wall');
expectCard($wall['login_wall'] && $wall['text'] === null && $wall['video'] === null && str_contains(implode(' ', $wall['notes']), 'ścianę logowania'), 'Facebook : ściana logowania = uwaga, okładka zostaje');
expectFail(fn() => $reader->read('https://fb.watch/evil'), 422, 'przekierowanie poza Facebooka');
$pin = $reader->read('https://pin.it/q');
expectCard($pin['kind'] === 'pinterest' && $pin['video'] === 'https://v1.pinimg.com/videos/mc/720p/aa/bb.mp4' && $pin['author'] === 'Baker Ann', 'Pinterest : wideo 720p i autor');
expectCard($pin['source_link'] === 'https://blog.example/rolls' && str_contains($pin['notes'][0], 'nie zawiera pełnego przepisu'), 'Pinterest : źródło i uwaga o przepisie');
$dest = tempnam(sys_get_temp_dir(), 'vid');
expectCard($reader->downloadVideo('https://video.xx.fbcdn.net/v/t42.mp4', $dest) === 7 && file_get_contents($dest) === 'MP4DATA', 'wideo : pobrane do pliku');
@unlink($dest);
expectFail(fn() => $reader->downloadVideo('https://evil.example/v.mp4', '/tmp/x'), 422, 'wideo spoza fbcdn / pinimg');
expectFail(fn() => SourceReader::assertFileHost('http://video.xx.fbcdn.net/v.mp4'), 422, 'wideo bez https');
expectCard($reader->image('https://evil.example/c.jpg') === null, 'okładka spoza fbcdn / pinimg pominięta');
$meta = SourceReader::meta('<meta content="A &amp; B" property="og:title"><META NAME="description" CONTENT="x">');
expectCard($meta['og:title'] === 'A & B' && $meta['description'] === 'x', 'meta : oba porządki atrybutów, encje');
SourceReader::$transport = null;

// --- 3b. Koszt i cena [198] : nazwy, ilości, wydajność (bez bazy) --------------------------------------------
$w = fn(string $x) => IM::words($x)[0];
expectCard(IM::sameStem($w('mąki'), $w('Mąka')) && IM::sameStem($w('soli'), $w('Sól')) && IM::sameStem($w('cukru'), $w('cukier'))
    && IM::sameStem($w('czosnku'), $w('Czosnek')) && IM::sameStem($w('pomidorowego'), $w('pomidory')), 'rdzenie : mąki ↔ mąka, soli ↔ sól, cukru ↔ cukier, czosnku ↔ czosnek, pomidorowego ↔ pomidory');
expectCard(!IM::sameStem($w('maku'), $w('Mąka')) && !IM::sameStem($w('mak'), $w('mąka')) && !IM::sameStem($w('pasty'), $w('pasteryzowane'))
    && !IM::sameStem($w('sucuku'), $w('sucre')) && !IM::sameStem($w('musu'), $w('muszkatołowa')), 'rdzenie : mak ≠ mąka, pasty ≠ pasteryzowane, sucuk ≠ sucre');
expectCard(IM::nameKey('startego kaşaru (lub ementalera)') === 'kasaru' && IM::mainPart('1 ząbek czosnku, pieprz, oregano') === '1 ząbek czosnku', 'nazwa : klucz bez opisów i nawiasu, pierwszy składnik linii');
expectCard(IM::isFree('wody') && IM::isFree('odrobina wrzątku') && IM::isFree('kostki lodu') && !IM::isFree('wody różanej'), 'woda i lód : bez kosztu');
$q = IM::parseQty('½ łyżeczki');
expectCard($q['amount'] === 2.5 && $q['kind'] === 'g' && $q['approx'], 'ilość : ½ łyżeczki ≈ 2,5 g');
expectCard(IM::parseQty('2-3 łyżki')['amount'] === 37.5 && IM::parseQty('1 kg')['amount'] === 1000.0 && IM::parseQty('1,5 l') === ['amount' => 1500.0, 'kind' => 'ml', 'approx' => false, 'word' => 'l'],
    'ilość : 2–3 łyżki = 37,5 g, 1 kg = 1000 g, 1,5 l = 1500 ml');
expectCard(IM::parseQty('1', 'plaster sera na sztukę')['amount'] === 20.0 && IM::parseQty('')['kind'] === null && IM::parseQty('1 kostka')['kind'] === 'kostka', 'ilość : jednostka w nazwie (plaster ≈ 20 g), brak ilości, kostka');
$t = IM::toMaterial(IM::parseQty('1'), 'g', 'jajko', 'jajka');
expectCard($t['qty'] === 55.0 && $t['known'] && $t['note'] === '1 szt. ≈ 55 g', 'sztuki → gramy : jajko ≈ 55 g');
$t = IM::toMaterial(IM::parseQty('7 g'), 'g', 'drożdży instant', 'Drożdże');
expectCard($t['qty'] === 21.0 && str_contains((string) $t['note'], 'suche → świeże × 3'), 'drożdże instant → świeże × 3');
expectCard(IM::toMaterial(IM::parseQty('1'), 'g', 'parówka', 'Szynka')['known'] && !IM::toMaterial(IM::parseQty(''), 'g', 'miód', 'miód')['known']
    && IM::toMaterial(IM::parseQty('300 g'), 'kg', 'mąki', 'Mąka')['qty'] === 0.3 && IM::toMaterial(IM::parseQty('2'), 'szt', 'jajka', 'Jajko L')['qty'] === 2.0, 'jednostka surowca : kg, szt, brak ilości');
expectCard(IM::perPiece('1 parówka na sztukę') && !IM::perPiece('mąki'), '« na sztukę » rozpoznane');
expectCard(IM::parseQty('2½ cup (ok. 310 g)')['amount'] === 310.0 && IM::parseQty('½ cup + ⅓ cup + ⅓ cup (ok. 280 ml)') === ['amount' => 280.0, 'kind' => 'ml', 'approx' => true, 'word' => null]
    && IM::parseQty('1 łyżeczka (5 ml)')['amount'] === 5.0 && IM::parseQty('1 cup')['amount'] === 240.0, 'reel : gramatura w nawiasie ważniejsza niż « cup », 1 cup ≈ 240 ml');
expectCard(IM::isFree('wody (dodawać stopniowo)') && IM::yieldPieces('nie podano (ok. 20 kulek z wersji chrupiącej)') === 20, 'reel : woda z dopiskiem, wydajność « 20 kulek »');
expectCard(IM::yieldPieces('2 płaty 35×45 cm ≈ 16 szt.') === 16 && IM::yieldPieces('8 bułeczek') === 8 && IM::yieldPieces('ok. 20-24 sztuk') === 22
    && IM::yieldPieces('blacha 30x40') === null && IM::yieldPieces('≈ 12 muffinów') === 12, 'wydajność : sztuki z tekstu karty');
expectCard(DevCardCostService::parseList('2,5 ; 3 ; 4') === [2.5, 3.0, 4.0] && DevCardCostService::parseList('4,3,2.5') === [2.5, 3.0, 4.0]
    && DevCardCostService::parseList('0.5,3') === null && DevCardCostService::parseList('1;2;3;4;5;6;7') === null && DevCardCostService::parseList('') === null, 'listy współczynników : przecinek dziesiętny, zakres 1–10, najwyżej 6');

// --- 4. Baza jednorazowa + asystent AI (SDK, MockHandler) -------------------------------------------------
$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_dev_card_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "dev_card_test: PARTIAL (bez bazy : {$e->getMessage()}) — części 1–3 OK\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$prevKey = getenv('ANTHROPIC_API_KEY');
try {
    foreach (['n_dev_card', 'n_dev_card_frame', 'n_ai_prompt', 'n_prod_group', 'n_prod_param', 'n_app_secret', 'test_product', 'test_product_status', 'test_product_type',
                 'production_sections', 'product_recipe', 'n_dev_product', 'n_dev_product_photo', 'n_dev_trial', 'n_dev_trial_photo', 'n_prod_employee', 'production_employee',
                 'final_product', 'user', 'test_product_changelog', 'test_product_stage', 'n_mgr_launch', 'category_product',
                 'raw_material', 'unit', 'product_recipe_connection', 'erp_company_other_settings', 'n_store_price_config', 'n_dev_ingredient_map'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO n_prod_group (id, code, name_pl, name_fr, color, sort_order, id_section, active) VALUES (1, 'PIEK', 'Piekarnia', 'Boulangerie', '#C17A2A', 1, NULL, 1), (2, 'OLD', 'Stara', 'Ancienne', '#999', 2, NULL, 0)");
    $pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type) VALUES ('ai.model', 'claude-sonnet-5-5', 'claude-opus-5-5', 'string')");
    $pdo->exec("INSERT INTO test_product_type (id, name) VALUES (1, 'Produkt'), (3, 'Pakowanie')");
    $pdo->exec("INSERT INTO category_product (id, name) VALUES (5, 'Pieczywo słone')");
    // Koszt [198] : surowce (cena za g / ml), użycie w recepturach (remis), kurs EUR, progi sprzedawalności.
    $pdo->exec("INSERT INTO unit (id, name) VALUES (3, 'g'), (4, 'ml'), (5, 'szt')");
    $pdo->exec("INSERT INTO raw_material (id, name, id_unit, price_per_unit, is_part_of_package) VALUES (1, 'Mąka pszenna T65', 3, 0.004, 0), (2, 'Mleko 3,2 %', 4, 0.0026, 0),
        (3, 'jajka', 3, 0.0137, 0), (4, 'Sól', 3, 0.0013, 0), (5, 'Drożdże', 3, 0.0064, 0), (6, 'Masło', 3, 0.022, 0), (7, 'Ser żółty', 3, 0.02, 0),
        (8, 'Mak', 3, 0.03, 0), (9, 'Folia na ser', 3, 0.01, 1), (10, 'Mąka żytnia', 3, 0.005, 0)");
    $pdo->exec("INSERT INTO product_recipe_connection (id, parent_recipe_id, child_ingredient_id, quantity) VALUES (1, 100, 1, 1), (2, 101, 1, 1)");
    $pdo->exec("INSERT INTO erp_company_other_settings (id, exchange_rate_euro) VALUES (1, 4.00)");
    $pdo->exec("INSERT INTO n_store_price_config (id, multiplier, limit_coef, reserve_coef) VALUES (1, 2.50, 2.20, 3.20)");
    $pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type) VALUES ('new_product.factory_coefs', '2,5 ; 3 ; 4', '2.5,3,4', 'string')");

    $history = [];
    $queue = [];
    AiSettings::$clientFactory = function (string $key) use (&$history, &$queue): Client {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));
        return new Client(apiKey: $key, baseUrl: 'https://api.anthropic.test', requestOptions: RequestOptions::with(maxRetries: 0,
            transporter: new \GuzzleHttp\Client(['handler' => $stack])));
    };
    $msg = fn(array $card, string $stop = 'end_turn') => new Response(200, ['Content-Type' => 'application/json'], json_encode(['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant',
        'model' => 'claude-sonnet-5-5', 'content' => [['type' => 'thinking', 'thinking' => '', 'signature' => 's'], ['type' => 'text', 'text' => json_encode($card)]],
        'stop_reason' => $stop, 'stop_sequence' => null, 'usage' => ['input_tokens' => 4321, 'output_tokens' => 765]]));
    $aiCard = ['title' => 'Bułeczki z sucukiem', 'original_name' => 'Kıvrık Poğaça', 'author' => 'Chefburcinarda', 'source_language' => 'tr', 'yield' => '16 szt.',
        'baking' => '180 °C', 'time' => '1 h 20 min', 'ingredients' => [['group' => 'CIASTO', 'items' => [['qty' => '500 g', 'name' => 'mąki'], ['qty' => '300 ml', 'name' => 'mleka']]]],
        'steps' => ['Wyrobić ciasto.', 'Piec 20 min.'], 'photos' => [['frame' => 3, 'caption' => 'Krojenie'], ['frame' => 1, 'caption' => 'Ciasto']], 'notes' => ['Wideo 720p.'], 'has_quantities' => true];

    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([PDO::class => $pdo, WorkshopConfig::class => new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('s', 48), 'API_BASE_URL' => 'https://erp.test'])]);
    $c = $builder->build();
    $svc = $c->get(DevCardService::class);
    $settings = $c->get(AiSettings::class);
    putenv('ANTHROPIC_API_KEY');
    expectCard(!$settings->configured(), 'asystent : bez klucza wyłączony');
    expectCard($settings->prompt()['default'] && str_contains($settings->prompt()['body'], 'ZDJĘCIA ETAPÓW'), 'asystent : prompt domyślny');
    expectCard($settings->model() === 'claude-sonnet-5-5', 'asystent : model z parametru ai.model');

    // Szkic : walidacja pól.
    $base = ['name' => 'Bułeczki z sucukiem', 'id_group' => 1, 'weekly_sales' => '120', 'seasonal' => true, 'quarters' => [4, 1], 'text' => "500 gr un\n300 ml süt", 'file' => true];
    $e = expectFail(fn() => $svc->create(['name' => '', 'id_group' => 2, 'weekly_sales' => -5, 'seasonal' => true, 'quarters' => []] + ['text' => 'x'], 7), 422, 'szkic : błędne pola');
    expectCard(array_keys($e->getErrors()['fields']) === ['name', 'id_group', 'weekly_sales', 'quarters'], 'szkic : pola nazwa, grupa (nieaktywna), sprzedaż, kwartały');
    expectFail(fn() => $svc->create(['url' => 'https://www.instagram.com/reel/1'] + $base, 7), 422, 'szkic : link z Instagrama', 'url');
    expectFail(fn() => $svc->create(['text' => '', 'file' => false] + $base, 7), 422, 'szkic : bez linku, opisu i pliku', 'url');
    $state = $svc->create($base, 7);
    $id = $state['id'];
    expectCard($state['status'] === 'draft' && $state['source']['kind'] === 'file' && $state['season_quarters'] === [1, 4] && $state['forecast']['year'] === 3120, 'szkic : plik wideo, sezon Q1 + Q4, 3 120 szt./rok');
    expectCard($pdo->query("SELECT season_quarters FROM n_dev_card WHERE id = {$id}")->fetchColumn() === '1,4', 'szkic : kwartały zapisane « 1,4 »');
    expectFail(fn() => $svc->generate($id), 409, 'generowanie bez klucza Anthropic');
    expectCard($state['id_category'] === null && $state['id_type'] === 1 && $state['label_type'] === 0, 'szkic : bez kategorii, typ Produkt, etykieta mała (domyślnie)');
    $e = expectFail(fn() => $svc->create(['id_category' => 999, 'id_type' => 2, 'label_type' => 3] + $base, 7), 422, 'szczegóły : błędne pola');
    expectCard(array_keys($e->getErrors()['fields']) === ['id_category', 'id_type', 'label_type'], 'szczegóły : kategoria, typ, etykieta sprawdzone');
    $repo = $c->get(App\Repositories\Workshop\DevCardRepository::class);
    expectCard(array_column($repo->categories(), 'name') === ['Pieczywo słone'] && array_map('intval', array_column($repo->types(), 'id')) === [1, 3], 'kreator : kategorie i typy');

    // « Sam pomysł » : produkt od razu, bez karty (dawny formularz /test-products/new i szybki formularz etapów).
    $idea = $svc->createProduct(['name' => 'Rogalik z pistacją', 'id_group' => 1, 'weekly_sales' => 60, 'seasonal' => true, 'quarters' => [4],
        'id_category' => 5, 'id_type' => 3, 'label_type' => 1, 'notes' => 'Na święta', 'target_date' => '2026-12-01'], 7);
    $row = $pdo->query("SELECT tp.name, tp.id_category, tp.id_type, tp.label_type, tp.id_status, d.stage, d.on_tablet, d.id_group, d.weekly_sales, d.season_quarters, d.notes, d.target_date
        FROM test_product tp JOIN n_dev_product d ON d.id_test_product = tp.id WHERE tp.id = " . (int) $idea['product_id'])->fetch();
    expectCard($idea['url'] === '/test-products/' . $idea['product_id'] . '/tablet' && $row['name'] === 'Rogalik z pistacją' && (int) $row['id_category'] === 5
        && (int) $row['id_type'] === 3 && (int) $row['label_type'] === 1 && (int) $row['id_status'] === 1, 'sam pomysł : produkt testowy z kategorią, typem i etykietą');
    expectCard($row['stage'] === 'idea' && (int) $row['on_tablet'] === 1 && (int) $row['weekly_sales'] === 60 && $row['season_quarters'] === '4'
        && $row['notes'] === 'Na święta' && $row['target_date'] === '2026-12-01', 'sam pomysł : etap Pomysł, tablet grupy, prognoza Q4, opis, data');
    expectFail(fn() => $svc->createProduct(['target_date' => '2026-02-31'] + $base, 7), 422, 'sam pomysł : zła data', 'target_date');

    // Klatki z przeglądarki.
    $frames = [];
    foreach ([[220, 40, 40], [40, 220, 40], [40, 40, 220]] as $i => $rgb) $frames[] = ['image' => 'data:image/jpeg;base64,' . base64_encode(jpeg(90, 160, $rgb)), 'at_ms' => 1500 * $i];
    $state = $svc->addFrames($id, ['replace' => true, 'frames' => $frames]);
    expectCard(count($state['frames']) === 3 && $state['frames'][2]['at_ms'] === 3000 && $state['frames'][0]['width'] === 90, 'klatki : 3 zapisane z chwilą i rozmiarem');
    $state = $svc->addFrames($id, ['replace' => true, 'frames' => array_slice($frames, 0, 3)]);
    expectCard(count($state['frames']) === 3, 'klatki : « replace » zastępuje poprzednie');
    expectFail(fn() => $svc->addFrames($id, ['frames' => array_fill(0, DevCardService::MAX_FRAMES, $frames[0])]), 422, 'klatki : limit na film');
    expectFail(fn() => $svc->addFrames($id, ['frames' => [['image' => 'data:text/html;base64,PGI+']]]), 422, 'klatki : tylko obrazy');
    $fids = array_column($state['frames'], 'id');

    // Asystent AI : zapytanie (SDK) i odpowiedź.
    putenv('ANTHROPIC_API_KEY=sk-ant-test-0000000000000000000000000');
    $settings = $c->make(AiSettings::class);
    $svc = $c->make(DevCardService::class, ['ai' => $settings, 'generator' => new CardGenerator($settings)]);
    $queue = [$msg($aiCard)];
    $state = $svc->generate($id);
    expectCard(count($history) === 1, 'AI : jedno zapytanie');
    $req = $history[0]['request'];
    $body = json_decode((string) $req->getBody(), true);
    expectCard((string) $req->getUri() === 'https://api.anthropic.test/v1/messages' && $req->getHeaderLine('anthropic-beta') === 'server-side-fallback-2026-07-01', 'AI : /v1/messages z betą fallback');
    expectCard($req->getHeaderLine('Authorization') === 'Bearer sk-ant-test-0000000000000000000000000' && !str_contains((string) $req->getUri() . $req->getBody(), 'sk-ant-'), 'AI : klucz tylko w nagłówku zapytania');
    expectCard($body['model'] === 'claude-sonnet-5-5' && $body['max_tokens'] === 16000 && $body['thinking'] === ['type' => 'adaptive'] && $body['fallbacks'] === 'default', 'AI : model, tokeny, myślenie, fallback');
    expectCard($body['output_config']['format']['type'] === 'json_schema' && $body['output_config']['effort'] === 'medium' && $body['output_config']['format']['schema'] === CardSchema::aiSchema(), 'AI : wysiłek medium i schemat JSON');
    expectCard($body['system'] === AiSettings::DEFAULT_PROMPT, 'AI : prompt jako instrukcja systemowa');
    $content = $body['messages'][0]['content'];
    expectCard(count(array_filter($content, fn($b) => $b['type'] === 'image')) === 3 && $content[2]['source']['media_type'] === 'image/jpeg', 'AI : 3 klatki JPEG base64');
    expectCard(str_contains($content[0]['text'], "<opis>\n500 gr un") && str_contains($content[0]['text'], 'nie wykonuj') && str_contains($content[1]['text'], 'Klatka 1 · 0:00'), 'AI : opis jako dane, numery klatek i czas');
    expectCard($state['status'] === 'ready' && $state['card']['title'] === 'Bułeczki z sucukiem' && $state['model'] === 'claude-sonnet-5-5' && $state['usage'] === ['input' => 4321, 'output' => 765], 'AI : karta, model, tokeny');
    expectCard($state['card']['photos'] === [['frame_id' => $fids[2], 'caption' => 'Krojenie'], ['frame_id' => $fids[0], 'caption' => 'Ciasto']], 'AI : numery klatek → id');
    // Karta bez kroków (opis z samymi składnikami, jak reel z Facebooka) : jedna ponowna próba — kroki z klatek.
    $history = [];
    $queue = [$msg(['steps' => [], 'photos' => []] + $aiCard), $msg($aiCard)];
    $state = $svc->generate($id);
    $second = json_decode((string) $history[1]['request']->getBody(), true)['messages'][0]['content'];
    expectCard(count($history) === 2 && str_contains(end($second)['text'], 'odtwórz je z klatek') && $state['card']['steps'] === ['Wyrobić ciasto.', 'Piec 20 min.']
        && count($state['card']['photos']) === 2 && $state['usage'] === ['input' => 8642, 'output' => 1530], 'AI : karta bez kroków → druga próba (kroki i zdjęcia z klatek), tokeny zsumowane');
    $history = [];
    $queue = [$msg(['steps' => []] + $aiCard), $msg(['steps' => []] + $aiCard)];
    $state = $svc->generate($id);
    expectCard(count($history) === 2 && $state['card']['steps'] === [] && in_array('Asystent nie podał kroków — wpisz je w « Popraw tekst ».', $state['card']['notes'], true),
        'AI : nadal bez kroków → uwaga dla operatora, bez trzeciej próby');
    expectCard(str_contains(AiSettings::DEFAULT_PROMPT, 'odtwórz je z klatek filmu') && str_contains(AiSettings::DEFAULT_PROMPT, 'znak wodny albo logo w rogu nie przeszkadza'), 'prompt : kroki z klatek, znak wodny nie wyklucza klatki');
    // Błędy API.
    $queue = [$msg($aiCard, 'refusal')];
    expectFail(fn() => $svc->generate($id), 422, 'AI : odmowa modelu');
    $queue = [$msg($aiCard, 'max_tokens')];
    expectFail(fn() => $svc->generate($id), 502, 'AI : odpowiedź ucięta');
    $queue = [new Response(401, ['Content-Type' => 'application/json'], '{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}')];
    expectFail(fn() => $svc->generate($id), 502, 'AI : klucz odrzucony');
    $queue = [new Response(429, ['Content-Type' => 'application/json'], '{"type":"error","error":{"type":"rate_limit_error","message":"slow down"}}')];
    expectFail(fn() => $svc->generate($id), 429, 'AI : limit zapytań');
    $queue = [new Response(200, ['Content-Type' => 'application/json'], json_encode(['id' => 'm', 'type' => 'message', 'role' => 'assistant', 'model' => 'x',
        'content' => [['type' => 'text', 'text' => 'nie JSON']], 'stop_reason' => 'end_turn', 'stop_sequence' => null, 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]))];
    expectFail(fn() => $svc->generate($id), 502, 'AI : odpowiedź bez JSON');
    expectCard($svc->state($id)['card']['title'] === 'Bułeczki z sucukiem', 'AI : nieudane generowanie nie psuje karty');

    // Poprawki.
    $state = $svc->update($id, ['card' => ['yield' => '18 szt.', 'ingredients_text' => "CIASTO:\n500 g mąki\nNADZIENIE:\n100 g sucuku", 'steps_text' => "1. A\n2. B\n3. C",
        'photos' => [['frame_id' => $fids[1], 'caption' => 'Nowe']]]], 7);
    expectCard($state['card']['yield'] === '18 szt.' && count($state['card']['ingredients']) === 2 && $state['card']['steps'] === ['A', 'B', 'C'], 'poprawki : tekst, składniki, kroki');
    expectCard($state['card']['title'] === 'Bułeczki z sucukiem' && $state['card']['photos'] === [['frame_id' => $fids[1], 'caption' => 'Nowe']], 'poprawki : zdjęcia, reszta bez zmian');
    expectFail(fn() => $svc->update($id, ['card' => ['photos' => [['frame_id' => 999999, 'caption' => 'x']]]], 7), 422, 'poprawki : cudza klatka');
    expectFail(fn() => $svc->update($id, ['name' => 'X', 'id_group' => 1, 'weekly_sales' => 10, 'seasonal' => true, 'quarters' => []], 7), 422, 'poprawki produktu : sezon bez kwartałów', 'quarters');
    $state = $svc->update($id, ['name' => 'Pogacza', 'id_group' => 1, 'weekly_sales' => 200, 'seasonal' => false], 7);
    expectCard($state['name'] === 'Pogacza' && !$state['seasonal'] && $state['forecast']['year'] === 10400, 'poprawki produktu : cały rok 200 × 52');
    $state = $svc->update($id, ['name' => 'Pogacza', 'id_group' => 1, 'weekly_sales' => 200, 'seasonal' => true, 'quarters' => [1, 2, 3, 4]], 7);
    expectCard(!$state['seasonal'], 'sezon z 4 kwartałami = cały rok');

    // Pliki.
    $docx = $svc->docx($id);
    $pdf = $svc->pdf($id);
    expectCard(str_starts_with($docx['bytes'], 'PK') && preg_match('~^\d{2}_Bułeczki|^\d{2}_Buleczki_z_sucukiem\.docx$~', $docx['name']) === 1, 'pliki : .docx ' . $docx['name']);
    expectCard(str_starts_with($pdf['bytes'], '%PDF') && str_ends_with($pdf['name'], '.pdf'), 'pliki : PDF');

    // Zapis → produkt w rozwoju.
    // Wybór : najpierw « Drukuj kartę » (produkt bez tabletu), potem « Wyślij też na tablet ».
    $saved = $svc->save($id, 7, false);
    $pid = $saved['product_id'];
    expectCard($saved['status'] === 'saved' && $saved['url'] === "/test-products/{$pid}/tablet" && $saved['on_tablet'] === false, 'zapis do druku : produkt ' . $pid . ' bez tabletu');
    $dev = $pdo->query("SELECT d.*, tp.name, tp.id_type FROM n_dev_product d JOIN test_product tp ON tp.id = d.id_test_product WHERE d.id_test_product = {$pid}")->fetch();
    expectCard($dev['name'] === 'Pogacza' && (int) $dev['id_type'] === 1 && (int) $dev['on_tablet'] === 0 && (int) $dev['id_group'] === 1 && $dev['stage'] === 'idea', 'zapis do druku : produkt testowy, etap Pomysł, niewidoczny na tablecie');
    $again = $svc->save($id, 7);
    expectCard($again['product_id'] === $pid && $again['on_tablet'] === true
        && (int) $pdo->query("SELECT on_tablet FROM n_dev_product WHERE id_test_product = {$pid}")->fetchColumn() === 1, 'wyślij też na tablet : ten sam produkt, widoczny na tablecie');
    expectCard((int) $dev['weekly_sales'] === 200 && $dev['season_quarters'] === null && $dev['video_url'] === null && str_contains($dev['notes'], 'Chefburcinarda'), 'zapis : prognoza, bez filmu (plik), notatka');
    expectCard(array_column($svc->state($id)['frames'], 'id') === [$fids[1]], 'zapis : klatki niewybrane usunięte');
    expectCard($svc->save($id, 7)['product_id'] === $pid, 'zapis : drugi raz bez nowego produktu');
    $tablet = $svc->forTablet($pid);
    expectCard($tablet['title'] === 'Bułeczki z sucukiem' && count($tablet['photos']) === 1 && str_starts_with($tablet['photos'][0]['src'], 'data:image/jpeg;base64,'), 'tablet : karta ze zdjęciami');
    expectCard($tablet['cover'] === $tablet['photos'][0]['src'] && $tablet['footer'] === CardSchema::FOOTER, 'tablet : okładka = ostatnie zdjęcie, stopka');
    $sheet = $c->get(DevProductService::class)->sheet($pid);
    expectCard($sheet['card']['id'] === $id && $sheet['product']['forecast']['year'] === 10400, 'karta produktu w biurze : karta i prognoza');
    $tp = $pdo->query("SELECT id_category, id_type, label_type FROM test_product WHERE id = {$pid}")->fetch();
    expectCard($tp['id_category'] === null && (int) $tp['id_type'] === 1 && (int) $tp['label_type'] === 0, 'zapis : produkt testowy z domyślnymi szczegółami');
    $state = $svc->update($id, ['name' => 'Pogacza skręcana', 'id_group' => 1, 'weekly_sales' => 50, 'seasonal' => true, 'quarters' => [4], 'id_category' => 5, 'id_type' => 3, 'label_type' => 1], 7);
    $dev = $pdo->query("SELECT d.weekly_sales, d.season_quarters, tp.name, tp.id_category, tp.id_type, tp.label_type FROM n_dev_product d JOIN test_product tp ON tp.id = d.id_test_product WHERE d.id_test_product = {$pid}")->fetch();
    expectCard($dev['name'] === 'Pogacza skręcana' && (int) $dev['weekly_sales'] === 50 && $dev['season_quarters'] === '4', 'poprawki po zapisie → produkt w rozwoju');
    expectCard((int) $dev['id_category'] === 5 && (int) $dev['id_type'] === 3 && (int) $dev['label_type'] === 1 && $state['category'] === 'Pieczywo słone' && $state['type'] === 'Pakowanie', 'poprawki po zapisie : kategoria, typ, etykieta');
    expectFail(fn() => $svc->delete($id), 409, 'zapisanej karty nie usuwa się z kreatora');

    // Import gotowej karty .docx.
    $tmp = tempnam(sys_get_temp_dir(), 'imp');
    file_put_contents($tmp, CardDocx::build($card, $images));
    $imp = $svc->importDocx(['name' => 'Bułeczki z borówką', 'id_group' => 1, 'weekly_sales' => 80, 'seasonal' => false], $tmp, 7);
    @unlink($tmp);
    expectCard($imp['status'] === 'ready' && $imp['source']['kind'] === 'docx' && $imp['card']['title'] === $card['title'] && count($imp['frames']) === 2, 'import .docx : karta i 2 zdjęcia');
    expectCard(array_column($imp['card']['photos'], 'caption') === ['Rozsmarować', 'Efekt'] && $imp['card']['photos'][0]['frame_id'] === $imp['frames'][0]['id'], 'import .docx : zdjęcia jako klatki');
    $svc->delete($imp['id']);
    expectCard((int) $pdo->query('SELECT COUNT(*) FROM n_dev_card_frame WHERE id_card = ' . (int) $imp['id'])->fetchColumn() === 0, 'usunięcie szkicu : klatki skasowane');

    // Koszt i cena [198] : karta → surowce, ceny, zapis, pamięć wyborów, cobot.
    $costCard = CardSchema::normalize(['title' => 'Pogacza', 'yield' => '≈ 16 szt.', 'baking' => '180 °C', 'time' => '1 h', 'steps' => ['Wyrobić', 'Skręcić'],
        'ingredients' => [['group' => 'CIASTO', 'items' => [['qty' => '500 g', 'name' => 'mąki'], ['qty' => '300 ml', 'name' => 'letniego mleka'], ['qty' => '1', 'name' => 'jajko'],
            ['qty' => '10 g', 'name' => 'soli'], ['qty' => '7 g', 'name' => 'drożdży instant'], ['qty' => '50 g', 'name' => 'masła · 5 g soli']]],
            ['group' => 'NADZIENIE', 'items' => [['qty' => '100 g', 'name' => 'startego kaşaru (lub ementalera)'], ['qty' => '100 g', 'name' => 'sucuku'],
            ['qty' => '250 ml', 'name' => 'wody'], ['qty' => '1', 'name' => 'plaster sera na sztukę']]]], 'photos' => [], 'notes' => []]);
    $tmp = tempnam(sys_get_temp_dir(), 'imp');
    file_put_contents($tmp, CardDocx::build($costCard, []));
    $cc = $svc->importDocx(['name' => 'Pogacza (koszt)', 'id_group' => 1, 'weekly_sales' => 100], $tmp, 7);
    @unlink($tmp);
    $costs = $c->get(DevCardCostService::class);
    $k = $costs->cost($cc['id']);
    $by = [];
    foreach ($k['lines'] as $l) $by[$l['name']] = $l;
    expectCard(count($k['lines']) === 11 && isset($by['masła'], $by['soli']), 'koszt : 11 linii (« 50 g masła · 5 g soli » rozdzielone)');
    expectCard($by['mąki']['id_raw_material'] === 1 && $by['mąki']['status'] === 'auto' && $by['mąki']['qty'] === 500.0, 'mąki → Mąka pszenna (częściej w recepturach), nie Mak : auto 500 g');
    expectCard($by['letniego mleka']['id_raw_material'] === 2 && $by['jajko']['qty'] === 55.0 && $by['masła']['id_raw_material'] === 6 && $by['masła']['qty'] === 50.0, 'mleko, jajko 55 g, masło 50 g');
    expectCard($by['drożdży instant']['status'] === 'check' && $by['drożdży instant']['qty'] === 21.0, 'drożdże instant : × 3, do sprawdzenia');
    expectCard($by['startego kaşaru (lub ementalera)']['id_raw_material'] === 7 && $by['startego kaşaru (lub ementalera)']['status'] === 'check', 'kaşar → Ser żółty (synonim) : do sprawdzenia');
    expectCard($by['sucuku']['status'] === 'missing' && $by['sucuku']['id_raw_material'] === null && $by['wody']['status'] === 'free', 'sucuk : brak ; woda : bez kosztu');
    expectCard($by['plaster sera na sztukę']['id_raw_material'] === 7 && $by['plaster sera na sztukę']['qty'] === 320.0 && str_contains((string) $by['plaster sera na sztukę']['note'], '× 16 szt.'), 'plaster sera na sztukę : 20 g × 16 szt.');
    expectCard(!in_array(9, array_column($k['materials'], 'id'), true) && $k['yield_pcs'] === 16 && $k['rate'] === 4.0, 'lista bez opakowań, partia 16 z karty, kurs 4,00');
    expectCard($k['total_pln'] === 13.19 && abs($k['cost_pc_pln'] - 0.8242) < 0.0001, 'koszt : partia 13,19 zł, sztuka 0,8242 zł (ilość × najwyższa cena)');
    expectCard($k['factory']['coefs'] === [2.5, 3.0, 4.0] && $k['factory']['coef'] === 3.0 && $k['store']['mult'] === 4.0 && abs($k['factory']['price_eur'] - 0.6182) < 0.0001,
        'fabryka : × 3,0 domyślnie (lista « 2,5 ; 3 ; 4 » z parametru) → 0,6182 €');
    expectCard($k['store']['final_eur'] === 2.5 && $k['sellability']['verdict'] === 'ok' && $k['sellability']['ratio'] === 4.04 && $k['food_share']['factory_pct'] === 33.3,
        'sklep : 0,6182 × 4 → 2,50 € (zaokrąglenie 0,10), sklep ÷ fabryka 4,04 ≥ 2,5, surowce 33,3 % fabryki');
    $lines = array_map(fn($l) => ['key' => $l['key'], 'id_raw_material' => $l['id_raw_material'], 'qty' => $l['qty'], 'price_kg' => null, 'status' => $l['status']], $k['lines']);
    $set = function (array $lines, string $name, array $v) use ($k): array {
        foreach ($k['lines'] as $i => $l) if ($l['name'] === $name) $lines[$i] = $v + $lines[$i];
        return $lines;
    };
    $in = ['yield_pcs' => 20, 'factory_coef' => 4, 'store_mult' => 3, 'store_price_eur' => null, 'cobot' => 'yes', 'cobot_steps' => ['Skręcanie', 'Skręcanie', 'Pakowanie']];
    $e = expectFail(fn() => $costs->save($cc['id'], ['lines' => $set($lines, 'mąki', ['id_raw_material' => 9])] + $in, 7), 422, 'koszt : opakowanie zamiast surowca');
    expectCard(array_keys($e->getErrors()['fields']) === ['lines.0'], 'koszt : błąd w linii 0');
    $e = expectFail(fn() => $costs->save($cc['id'], ['lines' => $lines, 'factory_coef' => 0.5, 'store_mult' => 11, 'cobot' => 'może'] + $in, 7), 422, 'koszt : współczynnik, mnożnik, cobot');
    expectCard(array_keys($e->getErrors()['fields']) === ['factory_coef', 'store_mult', 'cobot'], 'koszt : pola współczynnik, mnożnik, cobot');
    $lines = $set($lines, 'sucuku', ['price_kg' => 45]);
    $lines = $set($lines, 'startego kaşaru (lub ementalera)', ['status' => 'ok']);
    $k = $costs->save($cc['id'], ['lines' => $lines] + $in, 7);
    $by = [];
    foreach ($k['lines'] as $l) $by[$l['name']] = $l;
    expectCard($by['sucuku']['status'] === 'estimate' && $by['sucuku']['cost'] === 4.5 && $by['startego kaşaru (lub ementalera)']['status'] === 'ok', 'zapis : sucuk szacunkowo 45 zł/kg (4,50 zł), kaşar potwierdzony');
    expectCard($k['yield_pcs'] === 20 && $k['factory']['coef'] === 4.0 && $k['store']['mult'] === 3.0 && $k['cobot'] === 'yes' && $k['cobot_steps'] === ['Skręcanie', 'Pakowanie'], 'zapis : partia 20, × 4, × 3, cobot (bez powtórzeń)');
    $row = $pdo->query('SELECT store_price_eur, ROUND(cost_pc_pln, 4) AS pc, cobot_steps FROM n_dev_card WHERE id = ' . (int) $cc['id'])->fetch();
    expectCard((float) $row['pc'] === round((13.1874 + 4.5) / 20, 4) && (float) $row['store_price_eur'] === $k['store']['final_eur'] && $row['cobot_steps'] === 'Skręcanie|Pakowanie', 'zapis : koszt na sztukę, cena końcowa, etapy cobota w bazie');
    expectCard((int) $pdo->query("SELECT id_raw_material FROM n_dev_ingredient_map WHERE name_key = 'kasaru'")->fetchColumn() === 7
        && (int) $pdo->query("SELECT COUNT(*) FROM n_dev_ingredient_map")->fetchColumn() === 1, 'pamięć : tylko potwierdzony wybór (kaşaru → Ser żółty)');
    $p = $svc->state($cc['id'])['pricing'];
    expectCard($p['factory_coef'] === 4.0 && $p['store_eur'] === $k['store']['final_eur'] && $p['cobot'] === 'yes' && $p['verdict'] === $k['sellability']['verdict'], 'stan karty : podsumowanie kosztu i ceny');
    $k = $costs->save($cc['id'], ['lines' => $lines, 'store_price_eur' => '3,90'] + $in, 7);
    expectCard($k['store']['final_eur'] === 3.9 && $k['store']['manual'], 'cena końcowa wpisana ręcznie : 3,90 €');
    // Zmiana tekstu karty : nowa podpowiedź tylko dla zmienionej linii.
    $svc->update($cc['id'], ['card' => ['ingredients_text' => "CIASTO:\n600 g mąki\n100 g sucuku"]], 7);
    $k = $costs->cost($cc['id']);
    expectCard(count($k['lines']) === 2 && $k['lines'][0]['qty'] === 600.0 && $k['lines'][0]['status'] === 'auto' && $k['lines'][1]['status'] === 'estimate', 'karta zmieniona : nowa linia od nowa, sucuk z zapisu');
    // Druga karta : kaşar od razu z pamięci.
    $tmp = tempnam(sys_get_temp_dir(), 'imp');
    file_put_contents($tmp, CardDocx::build($costCard, []));
    $cc2 = $svc->importDocx(['name' => 'Pogacza 2', 'id_group' => 1, 'weekly_sales' => 100], $tmp, 7);
    @unlink($tmp);
    $kas = array_values(array_filter($costs->cost($cc2['id'])['lines'], fn($l) => str_contains($l['name'], 'kaşaru')))[0];
    expectCard($kas['id_raw_material'] === 7 && $kas['status'] === 'auto' && str_contains((string) $kas['note'], 'zapamiętane'), 'druga karta : kaşar → Ser żółty z pamięci');
    expectFail(fn() => $costs->cost($state['id'] + 9999), 404, 'koszt : nieznana karta');

    // Prompt w wersjach (zapis — administrator — sprawdzany na stronie ; tutaj wersje w repozytorium).
    $c->get(App\Repositories\Workshop\DevCardRepository::class)->addPrompt('dev_card', 'Nowy prompt testowy ' . str_repeat('x', 60), 7);
    expectCard($c->make(AiSettings::class)->prompt()['body'] === 'Nowy prompt testowy ' . str_repeat('x', 60), 'prompt : najnowsza wersja używana');
} finally {
    AiSettings::$clientFactory = null;
    $prevKey === false ? putenv('ANTHROPIC_API_KEY') : putenv('ANTHROPIC_API_KEY=' . $prevKey);
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
echo "dev_card_test: OK\n";
