<?php
declare(strict_types=1);

namespace App\Domain\Payroll;

/**
 * [277] Virements des salaires : fichier XML ISO 20022 pain.001.001.03 (« paczka płacowa », catégorie SALA), importé dans
 * la banque (ING Business : Administracja i Narzędzia › Import/Export). L'ERP ne transmet rien : le fichier est
 * téléchargé puis importé et signé dans la banque par une personne autorisée.
 *
 * ING refuse dans un virement national les caractères @ # $ * \ < > (et d'autres pour les virements en devises) : ils
 * sont retirés des noms et des titres. Encodage UTF-8 (caractères polonais conservés).
 */
final class BankXml
{
    /** Retire de la saisie tout ce qui n'est pas un chiffre ; accepte « PL », espaces et tirets. Rend « PL » + 26 chiffres ou null. */
    public static function iban(?string $raw): ?string
    {
        $s = strtoupper(preg_replace('/[\s-]+/', '', (string) $raw));
        if (str_starts_with($s, 'PL')) $s = substr($s, 2);
        if (!preg_match('/^\d{26}$/', $s)) return null;
        $iban = 'PL' . $s;
        return self::mod97(substr($iban, 4) . '2521' . substr($iban, 2, 2)) === 1 ? $iban : null;   // P = 25, L = 21
    }

    /** « PL61 1090 … » lisible. */
    public static function pretty(string $iban): string
    {
        return trim(chunk_split(substr($iban, 0, 4), 4, ' ') . chunk_split(substr($iban, 4), 4, ' '));
    }

    /** Masqué pour l'écran et la fiche : « PL** **** … 4417 ». */
    public static function masked(?string $iban): ?string
    {
        return $iban ? 'PL** **** … ' . substr($iban, -4) : null;
    }

    /** Texte accepté par la banque : sans @ # $ * \ < > " ' ` ~ ^ | { } [ ] % = ;, espaces simples, longueur bornée. */
    public static function clean(string $text, int $max): string
    {
        $t = preg_replace('/[@#$*\\\\<>"\'`~^|{}\[\]%=;]+/u', ' ', $text);
        $t = trim(preg_replace('/\s+/u', ' ', (string) $t));
        return mb_substr($t, 0, $max);
    }

    /**
     * @param array{name: string, iban: string, bic: ?string} $debtor
     * @param list<array{id: string, name: string, iban: string, amount: float, title: string}> $transfers
     */
    public static function build(string $msgId, array $debtor, string $execDate, array $transfers, ?string $createdAt = null): string
    {
        $x = new \XMLWriter();
        $x->openMemory();
        $x->setIndent(true);
        $x->startDocument('1.0', 'UTF-8');
        $x->startElement('Document');
        $x->writeAttribute('xmlns', 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.03');
        $x->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $x->startElement('CstmrCdtTrfInitn');

        $sum = number_format(array_sum(array_map(fn($t) => round($t['amount'], 2), $transfers)), 2, '.', '');
        $x->startElement('GrpHdr');
        $x->writeElement('MsgId', self::clean($msgId, 35));
        $x->writeElement('CreDtTm', $createdAt ?? date('Y-m-d\TH:i:s'));
        $x->writeElement('NbOfTxs', (string) count($transfers));
        $x->writeElement('CtrlSum', $sum);
        $x->startElement('InitgPty');
        $x->writeElement('Nm', self::clean($debtor['name'], 70));
        $x->endElement();
        $x->endElement();

        $x->startElement('PmtInf');
        $x->writeElement('PmtInfId', self::clean($msgId, 35));
        $x->writeElement('PmtMtd', 'TRF');
        $x->writeElement('NbOfTxs', (string) count($transfers));
        $x->writeElement('CtrlSum', $sum);
        $x->startElement('PmtTpInf');
        $x->startElement('CtgyPurp');
        $x->writeElement('Cd', 'SALA');
        $x->endElement();
        $x->endElement();
        $x->writeElement('ReqdExctnDt', $execDate);
        $x->startElement('Dbtr');
        $x->writeElement('Nm', self::clean($debtor['name'], 70));
        $x->endElement();
        $x->startElement('DbtrAcct');
        $x->startElement('Id');
        $x->writeElement('IBAN', $debtor['iban']);
        $x->endElement();
        $x->writeElement('Ccy', 'PLN');
        $x->endElement();
        $x->startElement('DbtrAgt');
        $x->startElement('FinInstnId');
        if (!empty($debtor['bic'])) {
            $x->writeElement('BIC', strtoupper(preg_replace('/\s+/', '', $debtor['bic'])));
        } else {
            $x->startElement('Othr');
            $x->writeElement('Id', 'NOTPROVIDED');
            $x->endElement();
        }
        $x->endElement();
        $x->endElement();

        foreach ($transfers as $t) {
            $x->startElement('CdtTrfTxInf');
            $x->startElement('PmtId');
            $x->writeElement('EndToEndId', self::clean($t['id'], 35));
            $x->endElement();
            $x->startElement('Amt');
            $x->startElement('InstdAmt');
            $x->writeAttribute('Ccy', 'PLN');
            $x->text(number_format(round($t['amount'], 2), 2, '.', ''));
            $x->endElement();
            $x->endElement();
            $x->startElement('Cdtr');
            $x->writeElement('Nm', self::clean($t['name'], 70));
            $x->endElement();
            $x->startElement('CdtrAcct');
            $x->startElement('Id');
            $x->writeElement('IBAN', $t['iban']);
            $x->endElement();
            $x->endElement();
            $x->startElement('RmtInf');
            $x->writeElement('Ustrd', self::clean($t['title'], 140));
            $x->endElement();
            $x->endElement();
        }
        $x->endElement(); // PmtInf
        $x->endElement(); // CstmrCdtTrfInitn
        $x->endElement(); // Document
        $x->endDocument();
        return $x->outputMemory();
    }

    private static function mod97(string $digits): int
    {
        $r = 0;
        foreach (str_split($digits, 7) as $chunk) $r = (int) (($r . $chunk) % 97);
        return $r;
    }
}
