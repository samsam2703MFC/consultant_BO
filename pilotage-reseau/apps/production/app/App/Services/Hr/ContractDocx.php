<?php
declare(strict_types=1);

namespace App\Services\Hr;

/**
 * [276] Umowa / pismo kadrowe w Wordzie : A4, tytuł wyśrodkowany, akapity wyjustowane, dwa miejsca na podpis.
 * Plik do edycji ; wersja do podpisu : PDF w dokumentach pracownika.
 */
final class ContractDocx
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** @param list<string> $paragraphs @param array{0: string, 1: string} $parties */
    public static function build(string $title, array $paragraphs, array $parties): string
    {
        $body = self::p(self::run($title, true, 28), 'center', 240);
        foreach ($paragraphs as $para) {
            $lines = explode("\n", $para);
            $runs = '';
            foreach ($lines as $i => $line) $runs .= ($i ? '<w:r><w:br/></w:r>' : '') . self::run($line);
            $body .= self::p($runs, 'both', 160);
        }
        $body .= self::p('', 'left', 600);
        $body .= '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/></w:tblPr><w:tblGrid><w:gridCol w:w="4500"/><w:gridCol w:w="4500"/></w:tblGrid><w:tr>';
        foreach ($parties as $label) {
            $body .= '<w:tc><w:tcPr><w:tcW w:w="2500" w:type="pct"/></w:tcPr>' . self::p(self::run('……………………………………'), 'center', 0) . self::p(self::run($label, false, 18), 'center', 0) . '</w:tc>';
        }
        $body .= '</w:tr></w:tbl>';
        $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="' . self::W . '"><w:body>' . $body
            . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="567" w:footer="567" w:gutter="0"/></w:sectPr></w:body></w:document>';

        $tmp = tempnam(sys_get_temp_dir(), 'hrdoc');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('word/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="' . self::W . '"><w:docDefaults><w:rPrDefault><w:rPr>'
            . '<w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/><w:sz w:val="21"/><w:lang w:val="pl-PL"/></w:rPr></w:rPrDefault>'
            . '<w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="276" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults></w:styles>');
        $zip->addFromString('word/document.xml', $doc);
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    private static function p(string $runs, string $align, int $after): string
    {
        return '<w:p><w:pPr><w:jc w:val="' . $align . '"/><w:spacing w:after="' . $after . '"/></w:pPr>' . $runs . '</w:p>';
    }

    private static function run(string $text, bool $bold = false, int $size = 0): string
    {
        $pr = ($bold ? '<w:b/>' : '') . ($size ? '<w:sz w:val="' . $size . '"/>' : '');
        return '<w:r>' . ($pr ? '<w:rPr>' . $pr . '</w:rPr>' : '') . '<w:t xml:space="preserve">' . htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</w:t></w:r>';
    }
}
