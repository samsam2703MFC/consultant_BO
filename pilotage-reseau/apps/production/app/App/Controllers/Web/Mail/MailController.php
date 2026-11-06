<?php
namespace App\Controllers\Web\Mail;

use App\Core\Web\BaseController;
use App\Exceptions\CustomException;
use App\Services\Mail\UserMailService;
use App\Services\Translation\DeepLTranslator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Poczta użytkownika : skrzynka (odebrane, wysłane, wysłane z ERP), odczyt, odpowiedź, nowa wiadomość ; ustawienia skrzynki.
 * Tłumacz DeepL : wiadomość przychodząca, tekst odpowiedzi (język klienta), stopki i treść w Komunikacji.
 */
class MailController extends BaseController
{
    public function __construct(private UserMailService $mail, private DeepLTranslator $translator,
        private \App\Services\Finances\PaymentProof\PaymentProofService $proofs, private \App\Services\Organization\PageAccessService $access) {}

    public function index()
    {
        return $this->view('mail/index', ['mail_page' => 'inbox']);
    }

    public function settings()
    {
        return $this->view('mail/index', ['mail_page' => 'settings']);
    }

    public function state()
    {
        return $this->run(fn() => $this->mail->state($this->userId()));
    }

    public function saveSettings()
    {
        return $this->run(fn() => $this->mail->saveMailbox($this->userId(), $this->getJson(Request::createFromGlobals())));
    }

    public function deleteSettings()
    {
        return $this->run(function () {
            $this->mail->deleteMailbox($this->userId());
            return ['message' => 'Skrzynka odłączona, wiadomości usunięte z ERP (w skrzynce zostają).'];
        });
    }

    public function test()
    {
        return $this->run(fn() => $this->mail->test($this->userId()));
    }

    public function sync()
    {
        return $this->run(fn() => $this->mail->sync($this->userId()));
    }

    public function messages()
    {
        return $this->run(fn() => $this->mail->list($this->userId(), (string) ($_GET['folder'] ?? 'INBOX'), mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100), (int) ($_GET['page'] ?? 1)));
    }

    public function message($id)
    {
        return $this->run(function () use ($id) {
            $m = $this->mail->open($this->userId(), (int) $id);
            // Wpłata z e-maila [233] : « Zweryfikuj wpłatę », gdy wiadomość od klienta z proformą niezapłaconą (prawo « Płatności dnia »).
            $m['payment'] = null;
            if ($this->access->canSee($this->userId(), '/finances/payments-day')) {
                try { $m['payment'] = $this->proofs->mailHint($this->userId(), (int) $id); } catch (\Throwable $e) { error_log('[mail] payment hint : ' . $e->getMessage()); }
            }
            return $m;
        });
    }

    public function send()
    {
        return $this->run(fn() => $this->mail->send($this->userId(), $this->getJson(Request::createFromGlobals())));
    }

    public function translateMessage($id)
    {
        return $this->run(fn() => $this->mail->translate($this->userId(), (int) $id, (string) ($this->getJson(Request::createFromGlobals())['target'] ?? 'pl')));
    }

    public function contactLang()
    {
        return $this->run(fn() => $this->mail->contactLang($this->userId(), mb_substr((string) ($_GET['email'] ?? ''), 0, 1000)));
    }

    public function translateStatus()
    {
        return $this->run(fn() => ['configured' => $this->translator->configured(), 'to' => DeepLTranslator::TO]);
    }

    /** Dowolny tekst (odpowiedź, stopka, treść wiadomości w Komunikacji) na wybrany język. */
    public function translateText()
    {
        return $this->run(function () {
            $in = $this->getJson(Request::createFromGlobals());
            $target = strtolower((string) ($in['target'] ?? ''));
            $source = isset($in['source']) ? strtolower((string) $in['source']) : null;
            $r = $this->translator->translate([(string) ($in['text'] ?? '')], $target, (string) ($in['context'] ?? 'mail_out'), $this->userId(), $source)[0];
            return $r + ['source_label' => $r['source'] ? DeepLTranslator::label($r['source']) : null, 'target' => $target,
                'target_label' => DeepLTranslator::label($target, false)];
        });
    }

    public function attachment($id, $index)
    {
        try {
            $file = $this->mail->attachment($this->userId(), (int) $id, (int) $index);
        } catch (CustomException $e) {
            http_response_code($e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 404);
            return htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
        // Załącznik zawsze do pobrania (nigdy wyświetlany w ERP : HTML z wiadomości nie może działać na stronie).
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $file['filename']) ?: 'zalacznik';
        header('Content-Type: ' . (preg_match('#^[\w.+-]+/[\w.+-]+$#', $file['mime']) ? $file['mime'] : 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($file['filename']));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        return $file['content'];
    }

    private function run(callable $action): JsonResponse
    {
        try {
            return $this->json($action());
        } catch (CustomException $e) {
            $code = (int) $e->getCode();
            return $this->json(['message' => $e->getMessage(), 'fields' => $e->getErrors()['fields'] ?? null], ($code >= 400 && $code < 600) ? $code : 422);
        } catch (\Throwable $e) {
            error_log('[mail] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return $this->json(['message' => 'Błąd serwera — operacja nie została wykonana.'], 500);
        }
    }

    private function userId(): int
    {
        $id = (int) user('id');
        if ($id <= 0) throw new CustomException('Zaloguj się ponownie.', 'errors', 401);
        return $id;
    }
}
