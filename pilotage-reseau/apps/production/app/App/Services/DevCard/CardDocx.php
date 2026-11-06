<?php
declare(strict_types=1);

namespace App\Services\DevCard;

use App\Exceptions\CustomException;

/**
 * Karta technologiczna w Wordzie [196] — ten sam układ co karty testowe (01_Pogacza…, 05_Hotdog…) : A4, tytuł
 * Cormorant Garamond w rubinie #8D1D2C, linia « oryginał — wg autora | L’Atelier By · Karta technologiczna », pasek
 * moreli #F2C9A0 (WYDAJNOŚĆ / PIECZENIE / CZAS), dwie kolumny (Składniki ~40 % z podprzepisami, Sposób wykonania
 * numerowany), « Etapy – zdjęcia » 4 w rzędzie z podpisami, stopka. Plik edytowalny ; czytanie takiego pliku (import
 * gotowych kart) : read().
 */
final class CardDocx
{
    private const RUBY = '8D1D2C';
    private const APRICOT = 'F2C9A0';
    private const TITLE_FONT = 'Cormorant Garamond';
    private const TEXT_FONT = 'Outfit';
    private const PHOTO_W = 1095375; // EMU, jak w kartach testowych
    public const MAX_UPLOAD_BYTES = 25_000_000;
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * @param array $card karta (CardSchema::normalize)
     * @param array<int, array{mime:string, image:string}> $images id klatki → obraz
     */
    public static function build(array $card, array $images): string
    {
        $media = [];
        $rels = [];
        $photos = [];
        foreach ($card['photos'] as $i => $p) {
            $img = $images[$p['frame_id']] ?? null;
            if ($img === null) continue;
            $jpeg = self::jpeg($img['mime'], $img['image']);
            if ($jpeg === null) continue;
            $n = count($media) + 1;
            $rid = 'rId' . (10 + $n);
            $media['word/media/image' . $n . '.jpg'] = $jpeg['bytes'];
            $rels[] = '<Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image' . $n . '.jpg"/>';
            $photos[] = ['rid' => $rid, 'n' => $n, 'w' => $jpeg['w'], 'h' => $jpeg['h'], 'caption' => $p['caption']];
        }
        $tmp = tempnam(sys_get_temp_dir(), 'card');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="jpg" ContentType="image/jpeg"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>'
            . '<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>');
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/>'
            . implode('', $rels) . '</Relationships>');
        $zip->addFromString('word/styles.xml', self::styles());
        $zip->addFromString('word/numbering.xml', self::numbering());
        $zip->addFromString('word/settings.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:settings xmlns:w="' . self::W . '"><w:defaultTabStop w:val="720"/><w:compat><w:compatSetting w:name="compatibilityMode" w:uri="http://schemas.microsoft.com/office/word" w:val="15"/></w:compat></w:settings>');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . self::x($card['title']) . '</dc:title><dc:creator>L’Atelier By · ERP</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>');
        $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>L’Atelier By ERP</Application></Properties>');
        $zip->addFromString('word/document.xml', self::document($card, $photos));
        foreach ($media as $path => $bytes) $zip->addFromString($path, $bytes);
        $zip->close();
        $out = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $out;
    }

    private static function document(array $card, array $photos): string
    {
        $b = [];
        $b[] = self::p(self::run($card['title'], ['font' => self::TITLE_FONT, 'b' => true, 'color' => self::RUBY, 'sz' => 44]), ['after' => 0]);
        $origin = trim($card['original_name'] . ($card['author'] !== '' ? ($card['original_name'] !== '' ? ' — ' : '') . 'wg ' . $card['author'] : ''));
        $b[] = '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:color="' . self::RUBY . '" w:sz="18" w:space="4"/></w:pBdr><w:tabs><w:tab w:val="right" w:pos="10400"/></w:tabs><w:spacing w:after="160"/></w:pPr>'
            . self::run($origin, ['color' => '555555', 'sz' => 18]) . self::run("\t" . 'L’Atelier By · Karta technologiczna', ['color' => self::RUBY, 'sz' => 18]) . '</w:p>';
        // Pasek : wydajność, pieczenie, czas.
        $cells = '';
        foreach ([['WYDAJNOŚĆ', $card['yield']], ['PIECZENIE', $card['baking']], ['CZAS', $card['time']]] as [$label, $value]) {
            $cells .= '<w:tc><w:tcPr><w:tcW w:type="dxa" w:w="3466"/><w:shd w:fill="' . self::APRICOT . '" w:color="auto" w:val="clear"/><w:tcMar><w:top w:type="dxa" w:w="80"/><w:left w:type="dxa" w:w="120"/><w:bottom w:type="dxa" w:w="80"/><w:right w:type="dxa" w:w="120"/></w:tcMar></w:tcPr>'
                . self::p(self::run($label, ['b' => true, 'color' => self::RUBY, 'sz' => 14]), ['after' => 0])
                . self::p(self::run($value !== '' ? $value : '—', ['sz' => 17]), ['after' => 0]) . '</w:tc>';
        }
        $b[] = self::table([3466, 3467, 3467], '<w:tr>' . $cells . '</w:tr>');
        $b[] = self::p('', ['after' => 120]);
        // Składniki | Sposób wykonania.
        $left = self::p(self::run('Składniki', ['font' => self::TITLE_FONT, 'b' => true, 'color' => self::RUBY, 'sz' => 28]), ['after' => 40]);
        foreach ($card['ingredients'] as $g) {
            if ($g['group'] !== '') $left .= self::p(self::run($g['group'], ['b' => true, 'sz' => 16]), ['after' => 20, 'before' => 100]);
            foreach ($g['items'] as $it) $left .= self::p(self::run(trim($it['qty'] . ' ' . $it['name']), ['sz' => 17]), ['after' => 10, 'num' => 2]);
        }
        $right = self::p(self::run('Sposób wykonania', ['font' => self::TITLE_FONT, 'b' => true, 'color' => self::RUBY, 'sz' => 28]), ['after' => 40]);
        foreach ($card['steps'] as $s) $right .= self::p(self::run($s, ['sz' => 18]), ['after' => 70, 'num' => 3]);
        $b[] = self::table([4000, 6400], '<w:tr><w:tc><w:tcPr><w:tcW w:type="dxa" w:w="4000"/><w:tcMar><w:right w:type="dxa" w:w="200"/></w:tcMar><w:vAlign w:val="top"/></w:tcPr>' . $left . '</w:tc>'
            . '<w:tc><w:tcPr><w:tcW w:type="dxa" w:w="6400"/><w:tcMar><w:left w:type="dxa" w:w="200"/></w:tcMar><w:vAlign w:val="top"/></w:tcPr>' . $right . '</w:tc></w:tr>');
        if ($photos) {
            $b[] = self::p(self::run('Etapy – zdjęcia', ['font' => self::TITLE_FONT, 'b' => true, 'color' => self::RUBY, 'sz' => 28]), ['after' => 80, 'before' => 200]);
            $rows = '';
            foreach (array_chunk($photos, 4) as $chunk) {
                $row = '';
                foreach ($chunk as $i => $ph) {
                    $h = (int) round(self::PHOTO_W * $ph['h'] / max(1, $ph['w']));
                    $row .= '<w:tc><w:tcPr><w:tcW w:type="dxa" w:w="2600"/><w:tcMar><w:top w:type="dxa" w:w="40"/><w:left w:type="dxa" w:w="40"/><w:bottom w:type="dxa" w:w="40"/><w:right w:type="dxa" w:w="40"/></w:tcMar></w:tcPr>'
                        . '<w:p><w:pPr><w:spacing w:after="20"/><w:jc w:val="center"/></w:pPr><w:r>' . self::drawing($ph['rid'], $ph['n'], self::PHOTO_W, $h) . '</w:r></w:p>'
                        . '<w:p><w:pPr><w:spacing w:after="0"/><w:jc w:val="center"/></w:pPr>' . self::run($ph['n'] . '. ', ['b' => true, 'color' => self::RUBY, 'sz' => 15]) . self::run($ph['caption'], ['sz' => 15]) . '</w:p></w:tc>';
                }
                for ($k = count($chunk); $k < 4; $k++) $row .= '<w:tc><w:tcPr><w:tcW w:type="dxa" w:w="2600"/></w:tcPr><w:p><w:pPr><w:spacing w:after="40"/></w:pPr></w:p></w:tc>';
                $rows .= '<w:tr>' . $row . '</w:tr>';
            }
            $b[] = self::table([2600, 2600, 2600, 2600], $rows);
        }
        $b[] = self::p(self::run(CardSchema::FOOTER, ['color' => '888888', 'sz' => 14]), ['before' => 120]);
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="' . self::W . '" xmlns:r="' . self::R . '"'
            . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            . ' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><w:body>' . implode('', $b)
            . '<w:sectPr><w:pgSz w:w="11906" w:h="16838" w:orient="portrait"/><w:pgMar w:top="680" w:right="680" w:bottom="680" w:left="680" w:header="708" w:footer="708" w:gutter="0"/><w:docGrid w:linePitch="360"/></w:sectPr></w:body></w:document>';
    }

    private static function table(array $grid, string $rows): string
    {
        $none = '<w:top w:val="none" w:color="FFFFFF" w:sz="0"/><w:left w:val="none" w:color="FFFFFF" w:sz="0"/><w:bottom w:val="none" w:color="FFFFFF" w:sz="0"/><w:right w:val="none" w:color="FFFFFF" w:sz="0"/><w:insideH w:val="none" w:color="FFFFFF" w:sz="0"/><w:insideV w:val="none" w:color="FFFFFF" w:sz="0"/>';
        return '<w:tbl><w:tblPr><w:tblW w:type="dxa" w:w="' . array_sum($grid) . '"/><w:tblBorders>' . $none . '</w:tblBorders><w:tblLayout w:type="fixed"/></w:tblPr><w:tblGrid>'
            . implode('', array_map(fn($w) => '<w:gridCol w:w="' . $w . '"/>', $grid)) . '</w:tblGrid>' . $rows . '</w:tbl>';
    }

    private static function p(string $runs, array $o = []): string
    {
        $pPr = '';
        if (isset($o['num'])) $pPr .= '<w:pStyle w:val="ListParagraph"/><w:numPr><w:ilvl w:val="0"/><w:numId w:val="' . $o['num'] . '"/></w:numPr>';
        $pPr .= '<w:spacing' . (isset($o['after']) ? ' w:after="' . $o['after'] . '"' : '') . (isset($o['before']) ? ' w:before="' . $o['before'] . '"' : '') . '/>';
        return '<w:p><w:pPr>' . $pPr . '</w:pPr>' . $runs . '</w:p>';
    }

    private static function run(string $text, array $o = []): string
    {
        if ($text === '') return '';
        $font = $o['font'] ?? self::TEXT_FONT;
        $rPr = '<w:rFonts w:ascii="' . $font . '" w:cs="' . $font . '" w:eastAsia="' . $font . '" w:hAnsi="' . $font . '"/>'
            . (!empty($o['b']) ? '<w:b/><w:bCs/>' : '') . (isset($o['color']) ? '<w:color w:val="' . $o['color'] . '"/>' : '')
            . '<w:sz w:val="' . ($o['sz'] ?? 17) . '"/><w:szCs w:val="' . ($o['sz'] ?? 17) . '"/>';
        $parts = explode("\t", $text);
        $out = '';
        foreach ($parts as $i => $part) {
            if ($i > 0) $out .= '<w:tab/>';
            if ($part !== '') $out .= '<w:t xml:space="preserve">' . self::x($part) . '</w:t>';
        }
        return '<w:r><w:rPr>' . $rPr . '</w:rPr>' . $out . '</w:r>';
    }

    private static function drawing(string $rid, int $n, int $cx, int $cy): string
    {
        return '<w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:effectExtent t="0" r="0" b="0" l="0"/>'
            . '<wp:docPr id="' . $n . '" name="Zdjęcie ' . $n . '"/><wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr>'
            . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic><pic:nvPicPr><pic:cNvPr id="' . $n . '" name="image' . $n . '.jpg"/><pic:cNvPicPr><a:picLocks noChangeAspect="1"/></pic:cNvPicPr></pic:nvPicPr>'
            . '<pic:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing>';
    }

    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="' . self::W . '"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="' . self::TEXT_FONT . '" w:hAnsi="' . self::TEXT_FONT . '" w:cs="' . self::TEXT_FONT . '" w:eastAsia="' . self::TEXT_FONT . '"/><w:sz w:val="18"/><w:szCs w:val="18"/><w:lang w:val="pl-PL"/></w:rPr></w:rPrDefault>'
            . '<w:pPrDefault><w:pPr><w:spacing w:after="80" w:line="264" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
            . '<w:style w:type="paragraph" w:styleId="ListParagraph"><w:name w:val="List Paragraph"/><w:basedOn w:val="Normal"/><w:pPr><w:ind w:left="720"/></w:pPr></w:style>'
            . '<w:style w:type="table" w:default="1" w:styleId="TableNormal"><w:name w:val="Normal Table"/><w:tblPr><w:tblInd w:w="0" w:type="dxa"/><w:tblCellMar><w:top w:w="0" w:type="dxa"/><w:left w:w="108" w:type="dxa"/><w:bottom w:w="0" w:type="dxa"/><w:right w:w="108" w:type="dxa"/></w:tblCellMar></w:tblPr></w:style></w:styles>';
    }

    private static function numbering(): string
    {
        $bullet = '<w:abstractNum w:abstractNumId="1"><w:multiLevelType w:val="hybridMultilevel"/><w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="bullet"/><w:lvlText w:val="●"/><w:lvlJc w:val="left"/><w:pPr><w:ind w:left="360" w:hanging="220"/></w:pPr><w:rPr><w:color w:val="' . self::RUBY . '"/><w:sz w:val="12"/></w:rPr></w:lvl></w:abstractNum>';
        $decimal = '<w:abstractNum w:abstractNumId="2"><w:multiLevelType w:val="hybridMultilevel"/><w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="decimal"/><w:lvlText w:val="%1."/><w:lvlJc w:val="left"/><w:pPr><w:ind w:left="400" w:hanging="300"/></w:pPr><w:rPr><w:b/><w:color w:val="' . self::RUBY . '"/></w:rPr></w:lvl></w:abstractNum>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:numbering xmlns:w="' . self::W . '">' . $bullet . $decimal
            . '<w:num w:numId="2"><w:abstractNumId w:val="1"/></w:num><w:num w:numId="3"><w:abstractNumId w:val="2"/></w:num></w:numbering>';
    }

    /** @return array{bytes:string, w:int, h:int}|null JPEG (PNG / WebP przekonwertowane) */
    private static function jpeg(string $mime, string $bytes): ?array
    {
        $info = @getimagesizefromstring($bytes);
        if (!is_array($info)) return null;
        if (($info['mime'] ?? '') === 'image/jpeg') return ['bytes' => $bytes, 'w' => (int) $info[0], 'h' => (int) $info[1]];
        $im = @imagecreatefromstring($bytes);
        if ($im === false) return null;
        $w = imagesx($im);
        $h = imagesy($im);
        $bg = imagecreatetruecolor($w, $h);
        imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
        imagecopy($bg, $im, 0, 0, 0, 0, $w, $h);
        ob_start();
        imagejpeg($bg, null, 88);
        imagedestroy($im);
        imagedestroy($bg);
        return ['bytes' => (string) ob_get_clean(), 'w' => $w, 'h' => $h];
    }

    private static function x(string $s): string
    {
        return htmlspecialchars(preg_replace('/[^\P{Cc}\t\n]/u', '', $s) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    // --- Import gotowej karty ---------------------------------------------------------------------------

    /**
     * Karta z pliku .docx w tym układzie (tytuł, linia autora, pasek, składniki / kroki, zdjęcia z podpisami).
     * Plik niezaufany : tylko document.xml, jego relacje i obrazy, limity rozmiaru, XML bez sieci i encji zewnętrznych.
     * @return array{card:array, images:list<array{mime:string, image:string, caption:string}>}
     */
    public static function read(string $path): array
    {
        $bad = fn(string $m) => new CustomException($m, 'errors', 422, ['fields' => ['file' => $m]]);
        if (!is_file($path) || filesize($path) > self::MAX_UPLOAD_BYTES) throw $bad('Plik .docx : najwyżej 25 MB.');
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) throw $bad('To nie jest plik .docx (Word).');
        try {
            $doc = self::entry($zip, 'word/document.xml', 8_000_000) ?? throw $bad('Plik .docx bez treści (word/document.xml).');
            $relsXml = self::entry($zip, 'word/_rels/document.xml.rels', 1_000_000) ?? '';
            $rels = [];
            if ($relsXml !== '' && preg_match_all('~<Relationship\b[^>]*\bId="([^"]+)"[^>]*\bTarget="([^"]+)"~', $relsXml, $m, PREG_SET_ORDER)) {
                foreach ($m as $r) $rels[$r[1]] = $r[2];
            }
            $dom = new \DOMDocument();
            if (!@$dom->loadXML($doc, LIBXML_NONET | LIBXML_COMPACT)) throw $bad('Uszkodzony plik .docx.');
            $xp = new \DOMXPath($dom);
            $xp->registerNamespace('w', self::W);
            $xp->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
            $xp->registerNamespace('r', self::R);
            $body = $xp->query('/w:document/w:body')->item(0) ?? throw $bad('Uszkodzony plik .docx.');
            $card = ['title' => '', 'original_name' => '', 'author' => '', 'yield' => '', 'baking' => '', 'time' => '', 'ingredients' => [], 'steps' => [], 'photos' => [], 'notes' => []];
            $images = [];
            $paras = 0;
            foreach ($body->childNodes as $node) {
                if (!$node instanceof \DOMElement) continue;
                if ($node->localName === 'p') {
                    $text = self::text($xp, $node);
                    if (trim($text) === '') continue;
                    $paras++;
                    if ($paras === 1) $card['title'] = trim($text);
                    elseif ($paras === 2 && str_contains($text, "\t")) self::origin($card, trim(explode("\t", $text)[0]));
                    continue;
                }
                if ($node->localName !== 'tbl') continue;
                $cells = iterator_to_array($xp->query('.//w:tc', $node));
                $all = implode("\n", array_map(fn($c) => self::text($xp, $c), $cells));
                if (preg_match('~WYDAJNO|PIECZENIE~u', $all) && !$xp->query('.//a:blip', $node)->length) {
                    foreach ($cells as $c) {
                        $ps = array_values(array_filter(array_map(fn($p) => trim(self::text($xp, $p)), iterator_to_array($xp->query('./w:p', $c))), fn($t) => $t !== ''));
                        if (!$ps) continue;
                        $key = match (true) { str_starts_with(mb_strtoupper($ps[0]), 'WYDAJNO') => 'yield', str_starts_with(mb_strtoupper($ps[0]), 'PIECZEN') => 'baking', str_starts_with(mb_strtoupper($ps[0]), 'CZAS') => 'time', default => null };
                        if ($key) $card[$key] = implode(' ', array_slice($ps, 1));
                    }
                } elseif (str_contains($all, 'Składniki') || str_contains($all, 'Sposób wykonania')) {
                    foreach ($cells as $c) {
                        $ps = iterator_to_array($xp->query('./w:p', $c));
                        $head = trim(self::text($xp, $ps[0] ?? $c));
                        if (str_starts_with($head, 'Składniki')) {
                            $group = null;
                            foreach (array_slice($ps, 1) as $p) {
                                $t = trim(self::text($xp, $p));
                                if ($t === '') continue;
                                if ($xp->query('./w:pPr/w:numPr', $p)->length) {
                                    if ($group === null) $card['ingredients'][] = ['group' => '', 'items' => []];
                                    $group = count($card['ingredients']) - 1;
                                    $card['ingredients'][$group]['items'][] = CardSchema::splitQty($t);
                                } else {
                                    $card['ingredients'][] = ['group' => $t, 'items' => []];
                                    $group = count($card['ingredients']) - 1;
                                }
                            }
                        } elseif (str_starts_with($head, 'Sposób')) {
                            foreach (array_slice($ps, 1) as $p) {
                                $t = trim(self::text($xp, $p));
                                if ($t !== '') $card['steps'][] = $t;
                            }
                        }
                    }
                } elseif ($xp->query('.//a:blip', $node)->length) {
                    foreach ($cells as $c) {
                        $blip = $xp->query('.//a:blip', $c)->item(0);
                        if (!$blip instanceof \DOMElement) continue;
                        $target = $rels[$blip->getAttributeNS(self::R, 'embed')] ?? null;
                        if ($target === null || str_contains($target, '..') || preg_match('~^[a-z]+:~i', $target)) continue;
                        $bytes = self::entry($zip, 'word/' . ltrim($target, '/'), 10_000_000);
                        $info = $bytes !== null ? @getimagesizefromstring($bytes) : false;
                        $mime = is_array($info) ? (string) $info['mime'] : '';
                        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || count($images) >= CardSchema::MAX_PHOTOS) continue;
                        $caption = '';
                        foreach ($xp->query('./w:p', $c) as $p) {
                            $t = trim(self::text($xp, $p));
                            if ($t !== '') $caption = $t;
                        }
                        $images[] = ['mime' => $mime, 'image' => $bytes, 'caption' => trim((string) preg_replace('~^\d+\.\s*~u', '', $caption))];
                    }
                }
            }
        } finally {
            $zip->close();
        }
        if ($card['title'] === '' && !$card['ingredients']) throw $bad('Nie rozpoznano karty technologicznej w pliku (tytuł, składniki, kroki).');
        return ['card' => $card, 'images' => $images];
    }

    private static function origin(array &$card, string $line): void
    {
        if (preg_match('~^(.*?)\s*—\s*wg\s+(.+)$~u', $line, $m)) {
            $card['original_name'] = trim($m[1]);
            $card['author'] = trim($m[2]);
        } elseif (preg_match('~^wg\s+(.+)$~u', $line, $m)) {
            $card['author'] = trim($m[1]);
        } else {
            $card['original_name'] = $line;
        }
    }

    /** Tekst akapitu / komórki : w:t, tabulatory, łamania. */
    private static function text(\DOMXPath $xp, \DOMNode $node): string
    {
        $out = '';
        foreach ($xp->query('.//w:r/w:t | .//w:r/w:tab | .//w:r/w:br', $node) as $n) { // bez w:pPr/w:tabs (pozycje tabulatorów)
            $out .= match ($n->localName) { 't' => $n->textContent, 'tab' => "\t", default => "\n" };
        }
        return $out;
    }

    private static function entry(\ZipArchive $zip, string $name, int $max): ?string
    {
        $st = $zip->statName($name);
        if ($st === false || $st['size'] > $max) return null;
        $data = $zip->getFromName($name);
        return $data === false ? null : $data;
    }
}
