<?php
namespace App\Services\Communication;

/**
 * Où partent réellement les e-mails (variable MAIL_DELIVERY_MODE du .env) :
 *   live      envoi normal par le compte SMTP actif
 *   redirect  tout part vers MAIL_REDIRECT_TO (objet préfixé du vrai destinataire) — recette
 *   log_only  rien ne part : le message est enregistré comme traité, sans connexion SMTP
 * La copie de test de l'ERP tourne en log_only (deploy/server.sh, dev/start.sh) : elle contient
 * de vraies adresses de clients.
 */
final class MailDeliveryPolicy
{
    public const MODES = ['live', 'redirect', 'log_only'];
    public const LOG_ONLY_PREFIX = 'LOG_ONLY:';

    private string $mode;
    private string $redirectTo;

    public function __construct(?string $mode = null, ?string $redirectTo = null)
    {
        $mode ??= (string) ($_ENV['MAIL_DELIVERY_MODE'] ?? getenv('MAIL_DELIVERY_MODE') ?: 'live');
        $redirectTo ??= (string) ($_ENV['MAIL_REDIRECT_TO'] ?? getenv('MAIL_REDIRECT_TO') ?: '');
        $this->mode = in_array($mode, self::MODES, true) ? $mode : 'log_only';
        $this->redirectTo = trim($redirectTo);
        // Une redirection sans adresse valide ne doit jamais retomber sur un envoi réel.
        if ($this->mode === 'redirect' && !filter_var($this->redirectTo, FILTER_VALIDATE_EMAIL)) {
            $this->mode = 'log_only';
        }
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function redirectTo(): string
    {
        return $this->redirectTo;
    }

    /** Un compte SMTP actif n'est nécessaire que si quelque chose part vraiment. */
    public function requiresAccount(): bool
    {
        return $this->mode !== 'log_only';
    }

    public function label(): string
    {
        return match ($this->mode) {
            'live' => 'Wysyłka rzeczywista',
            'redirect' => 'Tryb testowy: wszystkie wiadomości trafiają na ' . $this->redirectTo,
            default => 'Tryb testowy: wiadomości są tylko rejestrowane, nic nie jest wysyłane',
        };
    }

    public static function isLogOnly(?string $providerMessageId): bool
    {
        return str_starts_with((string) $providerMessageId, self::LOG_ONLY_PREFIX);
    }
}
