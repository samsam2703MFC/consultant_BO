<?php
declare(strict_types=1);

namespace App\Services\Finances\PaymentProof;

use App\Exceptions\CustomException;
use App\Repositories\Finances\PaymentProofRepository;
use App\Services\Finances\PaymentsDayService;
use App\Services\Mail\UserMailService;

/**
 * Wpłata z e-maila [233] (makieta 9) : potwierdzenie przelewu od klienta (PDF, zdjęcie) w Poczcie →
 *  1. odczyt : tekst PDF (pdftotext), a gdy PDF to skan albo zdjęcie — OCR w przeglądarce (Tesseract) i tekst tutaj ;
 *  2. kwota, data, numer(y) proform, rachunek odbiorcy (nasz?), płacący (= klient wiadomości?) ;
 *  3. proforma dopasowana (numer z dokumentu, inaczej kwota równa jednej proformie klienta) — inne proformy klienta do
 *     zaznaczenia (przelew za kilka) ;
 *  4. « Zatwierdź wpłatę » = proformy opłacone (Płatności dnia : status « paid », data + kwota w n_proforma_payment),
 *     dowód zapisany (zaszyfrowany) przy proformie, przypomnienia zatrzymane (proforma opłacona nie wchodzi do list) ;
 *     « To nie jest wpłata » = wiadomość znika z listy.
 * Tylko skrzynka zalogowanego użytkownika (załączniki pobierane jego kontem).
 */
final class PaymentProofService
{
    public const MAX_BYTES = 15 * 1024 * 1024;
    public const DAYS = 45;

    public function __construct(
        private PaymentProofRepository $repo,
        private PaymentProofCipher $cipher,
        private PaymentsDayService $payments,
        private UserMailService $mail,
    ) {
    }

    /** Lista « Z poczty : potwierdzenia do sprawdzenia » (Płatności dnia, krok 1). */
    public function candidates(int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $rows = [];
        $unpaidCache = [];
        foreach ($this->repo->candidates($userId, date('Y-m-d', strtotime($today . ' -' . self::DAYS . ' days'))) as $m) {
            $cid = (int) $m['id_customer'];
            $unpaidCache[$cid] ??= $this->repo->unpaid($cid);
            $unpaid = $unpaidCache[$cid];
            $proofs = $this->repo->proofsOfMail((int) $m['id']);
            $read = null;
            foreach ($proofs as $p) if ($p['status'] === 'to_check' && ($read === null || $p['read_amount'] !== null)) $read = $p;
            $match = $read ? $this->suggest($unpaid, $read['read_numbers'] ? explode(',', $read['read_numbers']) : [], $read['read_amount'] !== null ? (float) $read['read_amount'] : null) : null;
            $expected = $match ? array_sum(array_map(fn($p) => (float) $p['total'], $match)) : null;
            $state = 'unread';
            if ($read) {
                if ($read['read_amount'] === null) $state = 'no_amount';
                elseif ($expected === null) $state = 'no_match';
                else $state = abs((float) $read['read_amount'] - $expected) < 0.01 ? 'ok' : 'diff';
            }
            $rows[] = [
                'mail_id' => (int) $m['id'], 'subject' => $m['subject'], 'from' => $m['from_name'] ?: $m['from_email'], 'date' => $m['sent_at'],
                'customer' => ['id' => $cid, 'name' => $m['company']], 'unpaid' => ['count' => count($unpaid), 'sum' => round(array_sum(array_map(fn($p) => (float) $p['total'], $unpaid)), 2)],
                'proof' => $read ? ['id' => (int) $read['id'], 'filename' => $read['filename'], 'amount' => $read['read_amount'] !== null ? (float) $read['read_amount'] : null,
                    'currency' => $read['read_currency'], 'date' => $read['read_date'], 'method' => $read['method']] : null,
                'match' => $match ? array_map(fn($p) => $p['doc_num'], $match) : [], 'expected' => $expected !== null ? round($expected, 2) : null,
                'diff' => $read && $expected !== null && $read['read_amount'] !== null ? round((float) $read['read_amount'] - $expected, 2) : null,
                'state' => $state,
            ];
        }
        return ['rows' => $rows, 'days' => self::DAYS];
    }

    /** Podpowiedź w Poczcie : « Zweryfikuj wpłatę », gdy wiadomość od klienta z proformą niezapłaconą. */
    public function mailHint(int $userId, int $mailId): ?array
    {
        $m = $this->repo->mail($userId, $mailId);
        if (!$m || $m['folder'] !== 'INBOX' || $m['id_customer'] === null) return null;
        $n = $this->repo->unpaidCount((int) $m['id_customer']);
        if ($n === 0) return null;
        $proofs = [];
        foreach ($this->repo->proofsOfMail($mailId) as $i => $p) $proofs[] = ['index' => $i, 'id' => (int) $p['id'], 'status' => $p['status']];
        return ['unpaid' => $n, 'customer' => $m['company'], 'proofs' => $proofs];
    }

    /** Wiadomość do okna « Wpłata » (załączniki PDF / zdjęcia). */
    public function mailInfo(int $userId, int $mailId): array
    {
        $m = $this->repo->mail($userId, $mailId) ?? throw new CustomException('Nie znaleziono wiadomości.', 'errors', 404);
        $full = $this->mail->open($userId, $mailId);
        $files = array_values(array_filter($full['attachments'] ?? [], fn($a) => self::readable((string) $a['mime'], (string) $a['filename'])));
        return ['mail_id' => $mailId, 'subject' => $m['subject'], 'from' => $m['from_name'] ?: $m['from_email'], 'date' => $m['sent_at'],
            'customer' => $m['id_customer'] !== null ? ['id' => (int) $m['id_customer'], 'name' => $m['company']] : null,
            'attachments' => $files, 'proofs' => array_values(array_map(fn($p) => ['index' => $p['attachment_index'], 'id' => (int) $p['id'], 'status' => $p['status']], $this->repo->proofsOfMail($mailId))),
            'offline' => $full['offline'] ?? null];
    }

    /**
     * Odczyt załącznika : PDF z tekstem → od razu ; skan albo zdjęcie → ['need_ocr' => true], przeglądarka robi OCR i
     * wysyła tekst. Potwierdzenie już zatwierdzone / odrzucone : bez ponownego odczytu.
     */
    public function read(int $userId, int $mailId, int $index, ?string $ocrText): array
    {
        $m = $this->repo->mail($userId, $mailId) ?? throw new CustomException('Nie znaleziono wiadomości.', 'errors', 404);
        $old = $this->repo->proofByAttachment($mailId, $index);
        if ($old && $old['status'] !== 'to_check') return $this->view($userId, (int) $old['id']);
        $a = $this->mail->attachment($userId, $mailId, $index);
        return $this->readContent($userId, $m, $index, $a['filename'], $a['mime'], $a['content'], $ocrText);
    }

    /** @internal (testy) — odczyt pliku już pobranego. */
    public function readContent(int $userId, array $mail, int $index, string $filename, string $mime, string $content, ?string $ocrText, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        if (strlen($content) > self::MAX_BYTES) throw new CustomException('Plik jest za duży (najwyżej 15 MB).', 'errors', 413);
        $kind = self::kind($content);
        if ($kind === null) throw new CustomException('To nie jest PDF ani zdjęcie (JPG, PNG, WebP) — nie da się odczytać wpłaty.', 'errors', 422);
        $ocrText = $ocrText !== null ? trim(mb_substr($ocrText, 0, 100000)) : null;
        $text = '';
        $method = 'ocr';
        if ($kind === 'pdf' && ($ocrText === null || $ocrText === '')) {
            $text = $this->pdfText($content);
            $method = 'pdf_text';
            if (mb_strlen(trim((string) preg_replace('/\s+/', ' ', $text))) < 20) return ['need_ocr' => true, 'kind' => 'pdf', 'filename' => $filename];
        } elseif ($ocrText === null || $ocrText === '') {
            return ['need_ocr' => true, 'kind' => 'image', 'filename' => $filename];
        } else {
            $text = $ocrText;
        }
        $r = PaymentProofParser::parse($text, $today);
        $fields = [
            'id_customer' => $mail['id_customer'] !== null ? (int) $mail['id_customer'] : null,
            'filename' => mb_substr($filename !== '' ? $filename : 'potwierdzenie', 0, 255), 'mime' => self::MIMES[$kind], 'size_bytes' => strlen($content),
            'content_sha256' => hash('sha256', $content), 'content' => $this->cipher->encrypt($content), 'method' => $method,
            'text_enc' => $this->cipher->encrypt(mb_substr($text, 0, 200000)),
            'read_amount' => $r['amount'], 'read_currency' => $r['currency'], 'read_date' => $r['date'],
            'read_numbers' => $r['numbers'] ? mb_substr(implode(',', $r['numbers']), 0, 255) : null,
            'iban_ours' => ($own = PaymentProofParser::ownAccount($r['accounts'], $this->repo->ourAccounts())) === null ? null : (int) $own,
            'payer_enc' => $r['payer'] !== null ? $this->cipher->encrypt($r['payer']) : null,
        ];
        $old = $this->repo->proofByAttachment((int) $mail['id'], $index);
        if ($old) {
            $this->repo->updateProof((int) $old['id'], $fields);
            $id = (int) $old['id'];
        } else {
            $id = $this->repo->insertProof($fields + ['id_user' => $userId, 'id_mail' => (int) $mail['id'], 'attachment_index' => $index, 'status' => 'to_check',
                'created_by' => $userId, 'created_at' => date('Y-m-d H:i:s')]);
        }
        return $this->view($userId, $id, $today);
    }

    /** Okno « Wpłata — sprawdź i zatwierdź » : odczyt, kontrole, proformy klienta, podpowiedź. */
    public function view(int $userId, int $id, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $p = $this->own($userId, $id);
        $text = (string) $this->cipher->decrypt($p['text_enc']);
        $r = $text !== '' ? PaymentProofParser::parse($text, $today) : ['numbers' => [], 'accounts' => [], 'payer' => null, 'executed' => false];
        $mail = $p['id_mail'] !== null ? $this->repo->mail($userId, (int) $p['id_mail']) : null;
        $customerId = $p['id_customer'] !== null ? (int) $p['id_customer'] : null;
        $unpaid = $customerId !== null ? $this->repo->unpaid($customerId) : [];
        $numbers = $p['read_numbers'] ? explode(',', $p['read_numbers']) : [];
        $byNumber = $this->repo->byNumbers($numbers);
        $amount = $p['read_amount'] !== null ? (float) $p['read_amount'] : null;
        $suggested = array_map(fn($x) => (int) $x['id'], $this->suggest($unpaid, $numbers, $amount));
        $list = [];
        foreach ($unpaid as $u) $list[(int) $u['id']] = self::proformaRow($u, 'unpaid');
        // Numer z dokumentu : found (proforma klienta do zapłaty) > paid (już opłacona) > other_client > none (brak w ERP) ; missing = brak numeru.
        $rank = ['missing' => 0, 'none' => 1, 'other_client' => 2, 'paid' => 3, 'found' => 4];
        $numberState = $numbers ? 'none' : 'missing';
        foreach ($byNumber as $b) {
            $own = $customerId !== null && (int) $b['id_customer'] === $customerId;
            $state = $b['status'] === 'paid' ? 'paid' : ($own ? 'found' : 'other_client');
            if (in_array($b['status'], ['cancelled', 'archived'], true)) continue;
            if ($rank[$state] > $rank[$numberState]) $numberState = $state;
            if (!isset($list[(int) $b['id']])) $list[(int) $b['id']] = self::proformaRow($b, $state) + ['client' => $b['company']];
            $list[(int) $b['id']]['in_document'] = true;
        }
        $expected = array_sum(array_map(fn($i) => $list[$i]['amount'] ?? 0, $suggested));
        $company = $mail['company'] ?? null;
        $account = $r['accounts'] ? self::mask($r['accounts'][0]) : null;
        foreach ($r['accounts'] as $a) if (PaymentProofParser::ownAccount([$a], $this->repo->ourAccounts())) { $account = self::mask($a); break; }
        return [
            'proof' => ['id' => (int) $p['id'], 'status' => $p['status'], 'filename' => $p['filename'], 'mime' => $p['mime'], 'size' => $p['size_bytes'] !== null ? (int) $p['size_bytes'] : null,
                'method' => $p['method'], 'text' => mb_substr($text, 0, 6000), 'executed' => $r['executed']],
            'read' => ['amount' => $amount, 'currency' => $p['read_currency'], 'date' => $p['read_date'], 'numbers' => $numbers, 'account' => $account,
                'iban_ours' => $p['iban_ours'] === null ? null : (bool) $p['iban_ours'], 'payer' => $this->cipher->decrypt($p['payer_enc'])],
            'mail' => $mail ? ['id' => (int) $mail['id'], 'subject' => $mail['subject'], 'from' => $mail['from_name'] ?: $mail['from_email'], 'date' => $mail['sent_at']] : null,
            'customer' => $customerId !== null ? ['id' => $customerId, 'name' => $company] : null,
            'proformas' => array_values($list), 'suggested' => $suggested,
            'checks' => [
                'amount' => $amount === null ? null : ($suggested ? ['ok' => abs($amount - $expected) < 0.01, 'diff' => round($amount - $expected, 2), 'expected' => round($expected, 2)] : ['ok' => false, 'diff' => null, 'expected' => null]),
                'currency' => $p['read_currency'] === null || $p['read_currency'] === 'EUR',
                'date' => $p['read_date'] !== null, 'number' => $numberState, 'account' => $p['iban_ours'] === null ? null : (bool) $p['iban_ours'],
                'payer' => PaymentProofParser::sameParty($this->cipher->decrypt($p['payer_enc']), $company),
            ],
            'decided' => $p['status'] !== 'to_check' ? ['status' => $p['status'], 'at' => $p['decided_at'], 'paid_on' => $p['paid_on'], 'amount' => $p['amount'] !== null ? (float) $p['amount'] : null,
                'proformas' => array_map(fn($l) => $l['doc_num'], $this->repo->linked((int) $p['id']))] : null,
            'today' => $today,
        ];
    }

    /**
     * « Zatwierdź wpłatę » : proformy (jedna albo kilka) opłacone — data i kwota (jedna proforma : kwota z wyciągu ; kilka :
     * kwota każdej proformy) ; dowód przy proformach ; przypomnienia zatrzymane (proforma opłacona).
     */
    public function confirm(int $userId, int $id, array $in, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $p = $this->own($userId, $id);
        if ($p['status'] !== 'to_check') throw new CustomException('To potwierdzenie jest już ' . ($p['status'] === 'confirmed' ? 'zatwierdzone.' : 'odrzucone.'), 'errors', 409);
        $ids = array_values(array_unique(array_filter(array_map(fn($x) => is_int($x) || (is_string($x) && ctype_digit($x)) ? (int) $x : 0, (array) ($in['proformas'] ?? [])))));
        if (!$ids) throw new CustomException('Zaznacz proformę, której dotyczy wpłata.', 'errors', 422);
        if (count($ids) > 20) throw new CustomException('Najwyżej 20 proform jednym przelewem.', 'errors', 422);
        $paidOn = is_string($in['paid_on'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['paid_on']) ? $in['paid_on'] : ($p['read_date'] ?? $today);
        $amount = $in['amount'] ?? $p['read_amount'];
        $amount = is_string($amount) ? (preg_match('/^\s*\d+([.,]\d{1,2})?\s*$/', $amount) ? (float) str_replace(',', '.', trim($amount)) : null) : (is_int($amount) || is_float($amount) ? (float) $amount : null);
        if ($amount === null || $amount <= 0) throw new CustomException('Wpisz kwotę wpłaty.', 'errors', 422);
        $unpaid = [];
        $customerId = $p['id_customer'] !== null ? (int) $p['id_customer'] : null;
        $rows = $customerId !== null ? $this->repo->unpaid($customerId) : [];
        foreach ($rows as $u) $unpaid[(int) $u['id']] = $u;
        foreach ($this->repo->byNumbers($p['read_numbers'] ? explode(',', $p['read_numbers']) : []) as $b) {
            if (!in_array($b['status'], ['paid', 'cancelled', 'archived'], true)) $unpaid[(int) $b['id']] ??= $b;
        }
        foreach ($ids as $pid) if (!isset($unpaid[$pid])) throw new CustomException('Proforma nie należy do tego klienta albo jest już opłacona.', 'errors', 422);
        $items = [];
        foreach ($ids as $pid) $items[] = ['id' => $pid, 'paid_on' => $paidOn, 'amount' => count($ids) === 1 ? round($amount, 2) : round((float) $unpaid[$pid]['total'], 2)];
        $res = $this->payments->markPaid($items, $today, $userId);
        if ($res['paid'] === 0) throw new CustomException('Nie udało się oznaczyć proformy jako opłaconej.', 'errors', 422);
        foreach ($items as $it) if (!in_array($it['id'], $res['failed'], true)) $this->repo->linkProforma($id, $it['id'], $it['amount']);
        $this->repo->updateProof($id, ['status' => 'confirmed', 'paid_on' => $paidOn, 'amount' => round($amount, 2), 'decided_by' => $userId, 'decided_at' => date('Y-m-d H:i:s'),
            'id_customer' => $customerId ?? (int) $unpaid[$ids[0]]['id_customer']]);
        $numbers = array_map(fn($pid) => $unpaid[$pid]['doc_num'], array_filter($ids, fn($pid) => !in_array($pid, $res['failed'], true)));
        return ['paid' => $res['paid'], 'failed' => $res['failed'], 'numbers' => array_values($numbers), 'amount' => round($amount, 2), 'paid_on' => $paidOn,
            'diff' => count($ids) > 1 ? round($amount - array_sum(array_map(fn($pid) => (float) $unpaid[$pid]['total'], $ids)), 2) : null];
    }

    /** « To nie jest wpłata » : potwierdzenie (albo cała wiadomość, bez odczytu) znika z listy. */
    public function reject(int $userId, ?int $proofId, ?int $mailId): void
    {
        if ($proofId !== null) {
            $p = $this->own($userId, $proofId);
            if ($p['status'] === 'confirmed') throw new CustomException('Wpłata jest już zatwierdzona.', 'errors', 409);
            $this->repo->updateProof($proofId, ['status' => 'rejected', 'decided_by' => $userId, 'decided_at' => date('Y-m-d H:i:s')]);
            return;
        }
        $m = $mailId !== null ? $this->repo->mail($userId, $mailId) : null;
        if (!$m) throw new CustomException('Nie znaleziono wiadomości.', 'errors', 404);
        $proofs = $this->repo->proofsOfMail($mailId);
        foreach ($proofs as $p) {
            if ($p['status'] === 'to_check') $this->repo->updateProof((int) $p['id'], ['status' => 'rejected', 'decided_by' => $userId, 'decided_at' => date('Y-m-d H:i:s')]);
        }
        if (!$proofs) {
            $this->repo->insertProof(['id_user' => $userId, 'id_mail' => $mailId, 'attachment_index' => null, 'id_customer' => $m['id_customer'], 'status' => 'rejected',
                'decided_by' => $userId, 'decided_at' => date('Y-m-d H:i:s'), 'created_by' => $userId, 'created_at' => date('Y-m-d H:i:s')]);
        }
    }

    /** Plik potwierdzenia (odszyfrowany). @return array{filename:string, mime:string, content:string} */
    public function file(int $userId, int $id): array
    {
        $p = $this->own($userId, $id);
        $content = $this->cipher->decrypt($p['content']);
        if ($content === null) throw new CustomException('Brak pliku potwierdzenia.', 'errors', 404);
        return ['filename' => (string) $p['filename'], 'mime' => (string) $p['mime'], 'content' => $content];
    }

    // --- pomocnicze ------------------------------------------------------------------------------------------------

    private const MIMES = ['pdf' => 'application/pdf', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    private function own(int $userId, int $id): array
    {
        $p = $this->repo->proof($id);
        if (!$p || (int) $p['id_user'] !== $userId) throw new CustomException('Nie znaleziono potwierdzenia wpłaty.', 'errors', 404);
        return $p;
    }

    /** Podpowiedź : numery z dokumentu wśród proform klienta, inaczej jedna proforma o tej kwocie, inaczej wszystkie, gdy suma równa. */
    private function suggest(array $unpaid, array $numbers, ?float $amount): array
    {
        $byNum = array_values(array_filter($unpaid, fn($u) => in_array($u['doc_num'], $numbers, true)));
        if ($byNum) return $byNum;
        if ($amount === null) return [];
        $same = array_values(array_filter($unpaid, fn($u) => abs((float) $u['total'] - $amount) < 0.01));
        if (count($same) === 1) return $same;
        if (count($unpaid) > 1 && abs(array_sum(array_map(fn($u) => (float) $u['total'], $unpaid)) - $amount) < 0.01) return $unpaid;
        return [];
    }

    private static function proformaRow(array $u, string $state): array
    {
        return ['id' => (int) $u['id'], 'number' => $u['doc_num'], 'date' => $u['established_date'], 'days' => (int) $u['days'], 'amount' => round((float) $u['total'], 2),
            'reminders' => (int) $u['reminders'], 'state' => $state, 'in_document' => false];
    }

    private static function mask(string $account): string
    {
        $a = preg_replace('/\s+/', '', $account);
        return strlen($a) <= 10 ? $a : substr($a, 0, 4) . ' … ' . substr($a, -4);
    }

    private static function kind(string $content): ?string
    {
        if (str_starts_with($content, '%PDF')) return 'pdf';
        if (str_starts_with($content, "\xFF\xD8\xFF")) return 'jpeg';
        if (str_starts_with($content, "\x89PNG\r\n\x1A\n")) return 'png';
        if (str_starts_with($content, 'RIFF') && substr($content, 8, 4) === 'WEBP') return 'webp';
        return null;
    }

    public static function readable(string $mime, string $filename): bool
    {
        return (bool) preg_match('#^(application/pdf|image/(jpeg|jpg|png|webp))$#i', $mime) || (bool) preg_match('/\.(pdf|jpe?g|png|webp)$/i', $filename);
    }

    private function pdfText(string $pdf): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tfbpay');
        file_put_contents($tmp, $pdf);
        try {
            $proc = @proc_open(['pdftotext', '-layout', '-enc', 'UTF-8', '-l', '5', $tmp, '-'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($proc)) return '';
            $text = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return proc_close($proc) === 0 ? mb_substr($text, 0, 200000) : '';
        } finally {
            @unlink($tmp);
        }
    }
}
