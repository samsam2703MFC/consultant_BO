<?php
declare(strict_types=1);

namespace App\Repositories\Logistics;

use App\Repositories\Workshop\WorkshopRepository;

/** Dzień wysyłki : liczniki etapów dnia (dokumenty, KSeF, sklepy, powiadomienia, tablet kierowcy) — tylko odczyt. */
final class ShippingDayRepository extends WorkshopRepository
{
    protected string $table = 'shipment_log';

    /** Wysyłki dnia (dokumenty WZ / CMR / WDT) : klient => [id wysyłki, status KSeF]. */
    public function shipments(string $date): array
    {
        $out = [];
        foreach ($this->all('SELECT sl.id, sl.id_customer, k.status AS ksef FROM shipment_log sl
                LEFT JOIN ksef_submission k ON k.id_shipment_log = sl.id WHERE sl.shipment_date = ? ORDER BY sl.id', [$date]) as $r) {
            $out[(int) $r['id_customer']] = ['id' => (int) $r['id'], 'ksef' => $r['ksef']];
        }
        return $out;
    }

    /** Powiadomienia « wysłane » do sklepów dla zamówień z WDT tego dnia : status => liczba. */
    public function shopNotices(string $date): array
    {
        $out = [];
        foreach ($this->all('SELECT w.status, COUNT(*) AS n FROM webhook_outbox_shipments w
                JOIN customer_order co ON co.id = w.id_order JOIN shipment_log sl ON sl.id_wdt = co.id_wdt
                WHERE sl.shipment_date = ? GROUP BY w.status', [$date]) as $r) $out[$r['status']] = (int) $r['n'];
        return $out;
    }

    /** Klienci powiadomieni o dostawie tego dnia (e-mail lub SMS, bez błędów). */
    public function notifiedCustomers(string $date): array
    {
        return array_map('intval', array_column($this->all("SELECT DISTINCT id_customer FROM n_delivery_notice WHERE ship_date = ? AND status NOT IN ('failed', 'logged')", [$date]), 'id_customer'));
    }

    /** Postoje z tabletu kierowcy : klient => status (delivered, problem, arrived). */
    public function stops(string $date): array
    {
        $out = [];
        foreach ($this->all('SELECT id_customer, status FROM n_delivery_stop WHERE ship_date = ?', [$date]) as $r) $out[(int) $r['id_customer']] = $r['status'];
        return $out;
    }

    /** Przejazdy otwarte na tablecie kierowcy (pojazdy). */
    public function runs(string $date): int
    {
        return (int) ($this->all('SELECT COUNT(DISTINCT id_carrier_vehicle) AS n FROM n_delivery_run WHERE ship_date = ?', [$date])[0]['n'] ?? 0);
    }
}
