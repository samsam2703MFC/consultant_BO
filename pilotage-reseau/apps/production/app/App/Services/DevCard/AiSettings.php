<?php
declare(strict_types=1);

namespace App\Services\DevCard;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\RequestOptions;
use App\Exceptions\CustomException;
use App\Repositories\Translation\AppSecretRepository;
use App\Repositories\Workshop\DevCardRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Mail\MailboxSecretCipher;
use App\Services\Organization\PageAccessService;

/**
 * Ustawienia › Asystent AI [196] : klucz Anthropic (ANTHROPIC_API_KEY w .env serwera, a bez niego klucz wpisany
 * w Ustawieniach — n_app_secret, zaszyfrowany ; ERP pokazuje tylko 4 ostatnie znaki, nigdy nie wysyła klucza do
 * przeglądarki), model (parametr ai.model) i prompt karty technologicznej w wersjach (n_ai_prompt). Zmiany : administrator.
 */
final class AiSettings
{
    public const MODELS = [
        'claude-opus-5-5' => 'Claude Opus 5.5 (domyślny, najlepsza jakość)',
        'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (szybszy, tańszy)',
    ];
    public const DEFAULT_MODEL = 'claude-opus-5-5';
    public const PROMPT_CODE = 'dev_card';
    public const MAX_PROMPT_CHARS = 20000;
    private const SECRET = 'anthropic_api_key';
    /** Czas odpowiedzi API (s) : karta z ~30 klatkami to zwykle 30–90 s. */
    public const TIMEOUT = 240.0;
    /** Testy : fn(string $key): Client — klient wskazujący lokalny serwer zamiast api.anthropic.com. */
    public static ?\Closure $clientFactory = null;
    private ?array $resolved = null;

    /** Prompt domyślny : instrukcje z PROMPT_karta_technologiczna_z_reela.md (kroki 3–4 ; odczyt linku, klatki i plik .docx robi ERP). */
    public const DEFAULT_PROMPT = <<<'TXT'
Jesteś technologiem piekarni i cukierni L’Atelier By (SL Food). Dostajesz opis filmu z przepisem (reel Facebook albo pin Pinterest) i ponumerowane klatki z tego filmu. Przygotuj kartę technologiczną po polsku.

PRZEPIS PO POLSKU
- Przetłumacz opis na polski (z tureckiego, angielskiego, francuskiego itp.). Zachowaj wszystkie gramatury w gramach/ml; jednostki „cup / tbsp / tsp” zamień na g/ml tam, gdzie to jednoznaczne, w innym wypadku zostaw „łyżka / łyżeczka”. Temperatury w °C.
- Uporządkuj składniki według podprzepisów (ciasto, nadzienie, sos, kruszonka, lukier…). Nazwy podprzepisów pisz WIELKIMI LITERAMI.
- Sposób wykonania jako numerowane kroki: jeden krok = jedna czynność, w kolejności produkcyjnej. Karta zawsze ma kroki : gdy opis ich nie podaje, odtwórz je z klatek filmu (to, co widać, w kolejności filmu) i napisz w uwagach „kroki odtworzone z filmu”.
- Uzupełnij nagłówek: wydajność (ile sztuk / jaka forma), pieczenie (temperatura, tryb, czas, nagrzany czy nie), łączny czas (wyrabianie, rozrosty, pieczenie). Każde pole krótko (najwyżej 120 znaków) ; czego brak w źródle — „nie podano”, szczegóły w uwagach.
- Jeśli opis nie zawiera gramatur, nie wymyślaj ich: w uwagach napisz „brak gramatur w źródle” i podaj, gdzie jest pełny przepis (strona autora), jeśli to wiadomo.
- Tytuł: krótka polska nazwa wyrobu. Oryginalna nazwa (w języku źródła) i autor (nazwa strony albo konta) osobno.

ZDJĘCIA ETAPÓW
- Z klatek wybierz 6–9 pokazujących kluczowe etapy: przygotowanie masy/ciasta, nadzienie, krojenie, formowanie, układanie na blasze, smarowanie, efekt po upieczeniu, przekrój.
- Pomijaj klatki z twarzą autora, ujęcia rozmyte i klatki, w których napis zasłania produkt (znak wodny albo logo w rogu nie przeszkadza). Zachowaj kolejność filmu.
- Każde zdjęcie z krótkim podpisem po polsku (2–4 słowa), np. „Krojenie na paski”.
- Gdy klatek nie ma albo żadna się nie nadaje, zostaw listę zdjęć pustą i napisz to w uwagach.

UWAGI
- Krótko: czego brakuje (gramatury, temperatura, czas), czy pin nie zawiera przepisu, znaki wodne, słaba jakość wideo.
- Gramatury będą sprawdzone w próbie przed wdrożeniem do produkcji.
TXT;

    public function __construct(private AppSecretRepository $secrets, private MailboxSecretCipher $cipher, private ParamRepository $params,
                                private DevCardRepository $cards, private PageAccessService $access)
    {
    }

    /** @return array{key:string, source:?string, hint:?string} source : env | settings | null */
    private function resolve(): array
    {
        if ($this->resolved !== null) return $this->resolved;
        $env = trim((string) ($_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY') ?: ''));
        if ($env !== '') return $this->resolved = ['key' => $env, 'source' => 'env', 'hint' => substr($env, -4)];
        $row = $this->secrets->get(self::SECRET);
        $key = $row ? (string) $this->cipher->decrypt($row['secret']) : '';
        return $this->resolved = $key !== '' ? ['key' => $key, 'source' => 'settings', 'hint' => $row['hint']] : ['key' => '', 'source' => null, 'hint' => null];
    }

    public function configured(): bool
    {
        return $this->resolve()['key'] !== '';
    }

    public function model(): string
    {
        $m = $this->params->get('ai.model', self::DEFAULT_MODEL);
        return isset(self::MODELS[$m]) ? $m : self::DEFAULT_MODEL;
    }

    /** @return array{id:?int, body:string, created_at:?string, default:bool} */
    public function prompt(): array
    {
        $p = $this->cards->prompt(self::PROMPT_CODE);
        return $p ? ['id' => (int) $p['id'], 'body' => (string) $p['body'], 'created_at' => $p['created_at'], 'default' => false]
            : ['id' => null, 'body' => self::DEFAULT_PROMPT, 'created_at' => null, 'default' => true];
    }

    /** Klient API z kluczem serwera (nigdy nie trafia do przeglądarki). */
    public function client(): Client
    {
        $key = $this->resolve()['key'];
        if ($key === '') throw new CustomException('Asystent AI nie jest włączony : administrator dodaje klucz Anthropic w Ustawieniach › Asystent AI.', 'errors', 409);
        return self::makeClient($key);
    }

    public static function makeClient(string $key): Client
    {
        if (self::$clientFactory !== null) return (self::$clientFactory)($key);
        return new Client(apiKey: $key, requestOptions: RequestOptions::with(timeout: self::TIMEOUT, maxRetries: 2,
            transporter: new \GuzzleHttp\Client(['timeout' => self::TIMEOUT, 'connect_timeout' => 15])));
    }

    /** Stan dla strony ustawień i kreatora (bez klucza). */
    public function state(?int $userId): array
    {
        $r = $this->resolve();
        $p = $this->prompt();
        return ['configured' => $r['key'] !== '', 'source' => $r['source'], 'hint' => $r['hint'], 'model' => $this->model(),
            'models' => array_map(fn($code, $label) => ['code' => $code, 'label' => $label], array_keys(self::MODELS), self::MODELS),
            'prompt' => $p, 'default_prompt' => self::DEFAULT_PROMPT, 'versions' => $this->cards->promptVersions(self::PROMPT_CODE),
            'is_admin' => $this->access->isAdmin($userId)];
    }

    /** Klucz wpisany w Ustawieniach : sprawdzony w API (lista modeli, bez kosztu) przed zapisem ; pusty = usunięcie. */
    public function saveKey(string $key, ?int $userId): array
    {
        $this->admin($userId);
        $key = trim($key);
        if ($key === '') {
            $this->secrets->delete(self::SECRET);
            $this->resolved = null;
            return $this->state($userId);
        }
        if (!preg_match('/^sk-ant-[A-Za-z0-9_\-]{20,250}$/', $key)) {
            throw new CustomException('To nie wygląda na klucz Anthropic (zaczyna się od « sk-ant- »).', 'errors', 422, ['fields' => ['key' => 'Wklej klucz z konsoli Anthropic (API keys).']]);
        }
        $this->check($key);
        $this->secrets->put(self::SECRET, $this->cipher->encrypt($key), substr($key, -4), $userId);
        $this->resolved = null;
        return $this->state($userId);
    }

    /** Test połączenia z zapisanym kluczem. */
    public function test(?int $userId): array
    {
        $this->admin($userId);
        $key = $this->resolve()['key'];
        if ($key === '') throw new CustomException('Brak klucza Anthropic.', 'errors', 409);
        $this->check($key);
        return ['ok' => true, 'model' => $this->model()];
    }

    public function saveModel(string $model, ?int $userId): array
    {
        $this->admin($userId);
        if (!isset(self::MODELS[$model])) throw new CustomException('Nieznany model.', 'errors', 422);
        $this->params->setValue('ai.model', $model, $userId);
        return $this->state($userId);
    }

    /** Nowa wersja promptu (poprzednie zostają w historii) ; « przywróć domyślny » = nowa wersja z treścią domyślną. */
    public function savePrompt(string $body, ?int $userId): array
    {
        $this->admin($userId);
        $body = trim(str_replace("\r\n", "\n", $body));
        if (mb_strlen($body) < 50) throw new CustomException('Prompt : co najmniej 50 znaków.', 'errors', 422, ['fields' => ['prompt' => 'Za krótki prompt.']]);
        if (mb_strlen($body) > self::MAX_PROMPT_CHARS) throw new CustomException('Prompt : najwyżej ' . self::MAX_PROMPT_CHARS . ' znaków.', 'errors', 422, ['fields' => ['prompt' => 'Za długi prompt.']]);
        if ($body !== $this->prompt()['body']) $this->cards->addPrompt(self::PROMPT_CODE, $body, $userId);
        return $this->state($userId);
    }

    private function check(string $key): void
    {
        try {
            self::makeClient($key)->models->list(limit: 1);
        } catch (AuthenticationException|PermissionDeniedException) {
            throw new CustomException('Anthropic odrzucił klucz (nieprawidłowy albo wyłączony).', 'errors', 422, ['fields' => ['key' => 'Klucz odrzucony przez Anthropic.']]);
        } catch (APIStatusException $e) {
            throw new CustomException('Anthropic odpowiedział błędem ' . ($e->status ?? '?') . ' — spróbuj później.', 'errors', 502);
        } catch (APIConnectionException) {
            throw new CustomException('Brak połączenia z api.anthropic.com z serwera.', 'errors', 502);
        } catch (\Throwable $e) {
            // Inny błąd biblioteki : komunikat dla administratora zamiast « błąd serwera » (bez treści zapytania).
            throw new CustomException('Nie udało się sprawdzić klucza (' . (new \ReflectionClass($e))->getShortName() . ') — spróbuj ponownie.', 'errors', 502);
        }
    }

    private function admin(?int $userId): void
    {
        if (!$this->access->isAdmin($userId)) throw new CustomException('Asystenta AI ustawia administrator.', 'errors', 403);
    }
}
