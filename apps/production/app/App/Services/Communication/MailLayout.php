<?php
declare(strict_types=1);

namespace App\Services\Communication;

use App\Services\Utils\TCPDFService;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Wygląd e-maili ERP (jak wydruki) : logo SL Food i logo L'Atelier w nagłówku, treść szablonu, linia w kolorze marki.
 * Treść zostaje tekstem (szablony) ; wersja tekstowa wiadomości (AltBody) bez zmian.
 */
final class MailLayout
{
    private const PRODUCER_CID = 'logo-slfood';
    private const NETWORK_CID = 'logo-atelier';

    public static function html(string $body): string
    {
        $text = nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $brand = sprintf('#%02x%02x%02x', ...TCPDFService::BRAND);
        $producer = is_file(TCPDFService::PRODUCER_LOGO) ? '<img src="cid:' . self::PRODUCER_CID . '" alt="SL Food" height="56" style="display:block;border:0;">' : '<strong>SL Food</strong>';
        $network = is_file(TCPDFService::NETWORK_LOGO) ? '<img src="cid:' . self::NETWORK_CID . '" alt="L\'Atelier By" width="170" style="display:block;border:0;">' : "<strong>L'Atelier By</strong>";
        return <<<HTML
<!doctype html>
<html><body style="margin:0;padding:0;background:#f4f5f7;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 0;">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;color:#212529;">
<tr><td style="padding:20px 28px 14px 28px;border-bottom:3px solid {$brand};">
  <table role="presentation" cellpadding="0" cellspacing="0"><tr>
    <td style="padding-right:14px;border-right:1px solid #dee2e6;">{$producer}</td>
    <td style="padding-left:14px;">{$network}</td>
  </tr></table>
</td></tr>
<tr><td style="padding:24px 28px;font-size:14px;line-height:1.55;">{$text}</td></tr>
<tr><td style="padding:12px 28px 18px 28px;border-top:1px solid #dee2e6;font-size:11px;color:#6c757d;">SL Food · L'Atelier By</td></tr>
</table>
</td></tr>
</table>
</body></html>
HTML;
    }

    /** Logo jako załączniki osadzone (cid) : widoczne bez pobierania obrazków z internetu. */
    public static function embed(PHPMailer $mail): void
    {
        if (is_file(TCPDFService::PRODUCER_LOGO)) $mail->addEmbeddedImage(TCPDFService::PRODUCER_LOGO, self::PRODUCER_CID, 'slfood.jpg', PHPMailer::ENCODING_BASE64, 'image/jpeg');
        if (is_file(TCPDFService::NETWORK_LOGO)) $mail->addEmbeddedImage(TCPDFService::NETWORK_LOGO, self::NETWORK_CID, 'latelier.jpg', PHPMailer::ENCODING_BASE64, 'image/jpeg');
    }
}
