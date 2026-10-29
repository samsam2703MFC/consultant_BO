<?php
namespace App\Services\Communication;

use App\Repositories\Communication\CommunicationMessageRepository;
use App\Repositories\Workshop\ParamRepository;

/**
 * Rappels de paiement automatiques (audit 2, P5) : les niveaux 1 à reminders.auto_levels (0 = désactivé, au plus 2)
 * partent seuls au passage du cron mail_dispatch ; les niveaux suivants restent relus dans Przypomnienia płatności.
 * Un niveau n'est envoyé qu'une fois par proforma (même en échec : on le reprend dans Stan wysyłek), au plus un niveau
 * par passage, avec l'écart prévu entre deux niveaux depuis le dernier rappel ; les proformas de plus de 90 jours
 * (arriérés de saisie des paiements) ne sont jamais relancées seules.
 */
class PaymentReminderAutoService
{
    public const AUTHOR = 'auto-reminder';
    public const MAX_LEVEL = 2;
    private const MAX_AGE_DAYS = 90;
    private const MAX_PER_RUN = 25;

    public function __construct(
        private CommunicationService $communication,
        private CommunicationMessageRepository $messages,
        private ParamRepository $params,
    ) {
    }

    /** Niveaux envoyés automatiquement (0 = désactivé). */
    public function levels(): int
    {
        return max(0, min(self::MAX_LEVEL, (int) $this->params->get('reminders.auto_levels', '0')));
    }

    /**
     * Rappels qui partiraient maintenant si l'envoi automatique couvrait $levels niveaux (aperçu sur la page).
     * @return list<array{id:int, doc_num:string, company:string, level:int, days:int}>
     */
    public function plan(?string $today = null, ?int $levels = null): array
    {
        $levels ??= $this->levels();
        if ($levels < 1) return [];
        $today ??= date('Y-m-d');
        $days = CommunicationService::PROFORMA_REMINDER_DAYS;
        $out = [];
        foreach ($this->communication->selectableUnpaidProformas($today) as $p) {
            $next = $p['next_level'];
            if (!$p['due'] || $next === null || $next > $levels || $p['days'] > self::MAX_AGE_DAYS) continue;
            // Écart entre deux niveaux (1 → 2 : 4 jours) compté depuis le dernier rappel envoyé.
            if ($p['last_reminder_at'] !== null && $next > 1
                && (strtotime($today) - strtotime(substr((string) $p['last_reminder_at'], 0, 10))) / 86400 < $days[$next] - $days[$next - 1]) continue;
            if ($this->messages->autoReminderExists((int) $p['id'], 'proforma_reminder_' . $next, self::AUTHOR)) continue;
            $out[] = ['id' => (int) $p['id'], 'doc_num' => (string) $p['doc_num'], 'company' => (string) ($p['company'] ?? ''), 'level' => $next, 'days' => (int) $p['days']];
        }
        return $out;
    }

    /** Passage du cron : met en file les rappels prévus (au plus 25), les erreurs n'arrêtent pas les suivants. */
    public function run(?string $today = null): array
    {
        $levels = $this->levels();
        if ($levels < 1) return ['skipped' => 'off'];
        $queued = 0;
        $failed = [];
        foreach (array_slice($this->plan($today, $levels), 0, self::MAX_PER_RUN) as $p) {
            try {
                $this->communication->queueProformaReminder($p['id'], $p['level'], self::AUTHOR);
                $queued++;
            } catch (\Throwable $e) {
                $failed[] = ['proforma' => $p['doc_num'], 'level' => $p['level'], 'error' => $e->getMessage()];
                // Sans compte e-mail actif, aucun autre rappel ne peut partir : on s'arrête.
                if (str_contains($e->getMessage(), 'konto e-mail')) break;
            }
        }
        return ['levels' => $levels, 'queued' => $queued, 'failed' => $failed];
    }
}
