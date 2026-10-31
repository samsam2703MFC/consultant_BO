<?php
declare(strict_types=1);

namespace App\Repositories\Finances;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Wpłata z e-maila [233] : potwierdzenia przelewów klientów z Poczty (n_payment_proof), proformy niezapłacone klienta,
 * nasze rachunki (dane firmy), wiadomości-kandydaci w skrzynce użytkownika.
 */
final class PaymentProofRepository extends WorkshopRepository
{
    protected string $table = 'n_payment_proof';

    /** Słowa w temacie / treści, po których wiadomość z załącznikiem to prawdopodobnie potwierdzenie wpłaty. */
    public const KEYWORDS = 'fp-?[0-9]|proform|przelew|płatno|platno|wpłat|wplat|zapłat|zaplat|potwierdzen|paiement|payement|virement|preuve|payment|paid|betaling|overschrijving|betaalbewijs|zahlung|überweisung|uberweisung';

    private const UNPAID = "p.status NOT IN ('paid', 'cancelled', 'archived')";
    /** Proforma do zapłaty = z pozycjami (bez pozycji nie da się jej oznaczyć jako opłaconej). */
    private const PAYABLE = "p.status NOT IN ('paid', 'cancelled', 'archived') AND EXISTS (SELECT 1 FROM document_proforma_product x WHERE x.id_proforma = p.id AND x.total_price_net > 0)";

    public function mail(int $userId, int $mailId): ?array
    {
        return $this->all(
            'SELECT m.id, m.id_user, m.folder, m.subject, m.from_email, m.from_name, m.sent_at, m.has_attachments, m.id_customer, c.company
             FROM n_user_mail m LEFT JOIN customer c ON c.id = m.id_customer WHERE m.id = ? AND m.id_user = ?',
            [$mailId, $userId]
        )[0] ?? null;
    }

    /** Proformy niezapłacone klienta : numer, data, kwota (netto, €), dni, wysłane przypomnienia. */
    public function unpaid(int $customerId): array
    {
        return $this->all(
            "SELECT p.id, p.doc_num, p.established_date, p.id_customer, p.status,
                    (SELECT ROUND(SUM(pp.total_price_net), 2) FROM document_proforma_product pp WHERE pp.id_proforma = p.id) AS total,
                    DATEDIFF(CURDATE(), p.established_date) AS days,
                    (SELECT COUNT(*) FROM communication_message cm WHERE cm.id_proforma = p.id AND cm.message_type LIKE 'proforma_reminder%') AS reminders
             FROM document_proforma p WHERE p.id_customer = ? AND " . self::UNPAID . ' HAVING total > 0 ORDER BY p.established_date, p.id',
            [$customerId]
        );
    }

    /** Liczba proform niezapłaconych klienta. */
    public function unpaidCount(int $customerId): int
    {
        return (int) ($this->all('SELECT COUNT(*) AS n FROM document_proforma p WHERE p.id_customer = ? AND ' . self::PAYABLE, [$customerId])[0]['n'] ?? 0);
    }

    /** Proformy o numerach z dokumentu (każdy klient, każdy stan). @param list<string> $numbers */
    public function byNumbers(array $numbers): array
    {
        if (!$numbers) return [];
        $in = implode(',', array_fill(0, count($numbers), '?'));
        return $this->all(
            "SELECT p.id, p.doc_num, p.established_date, p.id_customer, p.status, c.company,
                    (SELECT ROUND(SUM(pp.total_price_net), 2) FROM document_proforma_product pp WHERE pp.id_proforma = p.id) AS total,
                    DATEDIFF(CURDATE(), p.established_date) AS days,
                    (SELECT COUNT(*) FROM communication_message cm WHERE cm.id_proforma = p.id AND cm.message_type LIKE 'proforma_reminder%') AS reminders
             FROM document_proforma p LEFT JOIN customer c ON c.id = p.id_customer WHERE p.doc_num IN ({$in})",
            array_values($numbers)
        );
    }

    /** Nasze rachunki z danych firmy (proforma, WZ, WDT). @return list<string> */
    public function ourAccounts(): array
    {
        $row = $this->all('SELECT * FROM erp_company_data ORDER BY id LIMIT 1')[0] ?? [];
        $out = [];
        foreach ($row as $k => $v) if (str_contains((string) $k, 'bank') && is_string($v) && trim($v) !== '') $out[] = $v;
        return $out;
    }

    /**
     * Wiadomości w skrzynce użytkownika, które wyglądają na potwierdzenie wpłaty : odebrane od klienta z proformą
     * niezapłaconą, z załącznikiem, słowo kluczowe w temacie albo treści (albo już odczytane i czekające) ; bez
     * zatwierdzonych i odrzuconych.
     */
    public function candidates(int $userId, string $since, int $limit = 30): array
    {
        return $this->all(
            "SELECT m.id, m.subject, m.from_email, m.from_name, m.sent_at, m.id_customer, c.company
             FROM n_user_mail m JOIN customer c ON c.id = m.id_customer
             WHERE m.id_user = ? AND m.folder = 'INBOX' AND m.has_attachments = 1 AND m.sent_at >= ?
               AND EXISTS (SELECT 1 FROM document_proforma p WHERE p.id_customer = m.id_customer AND " . self::PAYABLE . ")
               AND NOT EXISTS (SELECT 1 FROM n_payment_proof d WHERE d.id_mail = m.id AND d.status IN ('confirmed', 'rejected'))
               AND (LOWER(CONCAT(COALESCE(m.subject, ''), ' ', COALESCE(LEFT(m.body_text, 3000), ''))) REGEXP ?
                    OR EXISTS (SELECT 1 FROM n_payment_proof t WHERE t.id_mail = m.id AND t.status = 'to_check'))
             ORDER BY m.sent_at DESC LIMIT " . max(1, min(100, $limit)),
            [$userId, $since, self::KEYWORDS]
        );
    }

    /** Potwierdzenia odczytane dla wiadomości (bez treści pliku). @return array<int, array> indeks załącznika → odczyt */
    public function proofsOfMail(int $mailId): array
    {
        $out = [];
        foreach ($this->all('SELECT id, attachment_index, filename, status, read_amount, read_currency, read_date, read_numbers, iban_ours, method
                             FROM n_payment_proof WHERE id_mail = ? ORDER BY id', [$mailId]) as $r) {
            $out[$r['attachment_index'] === null ? -1 : (int) $r['attachment_index']] = $r;
        }
        return $out;
    }

    public function proof(int $id): ?array
    {
        return $this->all('SELECT * FROM n_payment_proof WHERE id = ?', [$id])[0] ?? null;
    }

    public function proofByAttachment(int $mailId, int $index): ?array
    {
        return $this->all('SELECT * FROM n_payment_proof WHERE id_mail = ? AND attachment_index = ?', [$mailId, $index])[0] ?? null;
    }

    public function insertProof(array $f): int
    {
        $cols = array_keys($f);
        $this->db->prepare('INSERT INTO n_payment_proof (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($f));
        return (int) $this->db->lastInsertId();
    }

    public function updateProof(int $id, array $f): void
    {
        if (!$f) return;
        $this->db->prepare('UPDATE n_payment_proof SET ' . implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($f))) . ' WHERE id = ?')
            ->execute([...array_values($f), $id]);
    }

    public function linkProforma(int $proofId, int $proformaId, ?float $amount): void
    {
        $this->db->prepare('INSERT INTO n_payment_proof_proforma (id_proof, id_proforma, amount) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE amount = VALUES(amount)')
            ->execute([$proofId, $proformaId, $amount]);
    }

    /** Proformy opłacone tym potwierdzeniem. */
    public function linked(int $proofId): array
    {
        return $this->all('SELECT l.id_proforma, l.amount, p.doc_num FROM n_payment_proof_proforma l JOIN document_proforma p ON p.id = l.id_proforma WHERE l.id_proof = ? ORDER BY p.doc_num', [$proofId]);
    }
}
