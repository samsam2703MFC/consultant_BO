<?php
declare(strict_types=1);

namespace App\Repositories\Logistics;

use App\Core\Helpers\BaseRepository;

/** Historia powiadomień klientów o dostawie (e-mail, SMS). */
class DeliveryNoticeRepository extends BaseRepository
{
    protected string $table = 'n_delivery_notice';

    protected $allowedColumns = [
        'ship_date', 'id_customer', 'channel', 'language', 'recipient', 'subject', 'body', 'status', 'id_message', 'provider', 'error', 'created_by',
    ];

    /** Powiadomienia dnia : najnowsze najpierw. */
    public function forDay(string $date): array
    {
        return $this->query(
            'SELECT n.*, c.company, m.status AS mail_status
             FROM n_delivery_notice n
             JOIN customer c ON c.id = n.id_customer
             LEFT JOIN communication_message m ON m.id = n.id_message
             WHERE n.ship_date = :d ORDER BY n.id DESC',
            ['d' => $date]
        ) ?: [];
    }

    /** Klient => ostatnie powiadomienie dnia per kanał. */
    public function lastByCustomer(string $date): array
    {
        $out = [];
        foreach ($this->forDay($date) as $row) $out[(int) $row['id_customer']][$row['channel']] ??= $row;
        return $out;
    }
}
