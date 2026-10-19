<?php

namespace App\Core\Web;

use App\Core\Helpers\GlobalRegistry;
use App\Core\Twig\AppExtension;
use App\Exceptions\DataNotFoundException;
use App\Exceptions\ProtectedResourceException;
use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\Loader\FilesystemLoader;

class BaseController
{
    public $errors = []; // krytyczne błędy, blokują działanie
    public $information = []; // informacje niezwiązane bezpośrednio z akcją
    public $warnings = []; // błędy operacji, nie blokują GUI
    public $successes = []; // sukcesy operacji
    public $ignored_messages = [];

    public function view($name, $data = [])
    {
        $reflector = new \ReflectionClass($this);
        $namespace = $reflector->getNamespaceName();

        $baseViewPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
        if (strpos($namespace, 'App\\Controllers') !== false) {
            $baseViewPath .= "App/Views/";
        }

        $filename = $baseViewPath . $name . ".view.php";


        $moduleName = "login";
        $splittedPathElems = explode("/", $name);
        //nazwą modułu jest katalog w którym znajdują się pliki otwierane przez Controller/view
        if(isset($splittedPathElems[0])){
            $moduleName = $splittedPathElems[0];
        }

        $langCode = GlobalRegistry::get('lang_code') ?? "pl";

        $globalTranslations = loadTranslations('page', $langCode, 'page_components');
        $moduleTranslations = loadTranslations('page', $langCode, $moduleName);

        $data['translations'] = array_merge($globalTranslations, $moduleTranslations);

        $data['errors'] = $this->errors;
        $data['information'] = $this->information;
        $data['warnings'] = $this->warnings;
        $data['successes'] = $this->successes;

        $data['root'] = ROOT;
        $data['currency_symbol'] = CURRENCY_SYMBOL;


        $user = GlobalRegistry::get('user');

        $data['user'] = [
            'displayName' => $user ? $user->getDisplayName() : null,
            'role' => $user ? $user->getRole() : null,
        ];



        // Jeśli istnieje plik .twig, renderuj przez Twig
        $twigTemplate = $name . ".twig";
        if (file_exists($baseViewPath . $twigTemplate)) {
            $loader = new FilesystemLoader($baseViewPath);
            $twig = new Environment($loader, [
                'cache' => false, // Możesz zmienić na ścieżkę do cache w produkcji
                'autoescape' => 'html',
                'debug' => true, // Włącz debugowanie, jeśli potrzebujesz
            ]);
            $twig->addExtension(new DebugExtension());
            $twig->addExtension(new AppExtension($_POST));
            echo $twig->render($twigTemplate, $data);
            return;
        }

        // Fallback: klasyczny widok .view.php
        if (!empty($data)) {
            extract($data);
        }

        if (file_exists($filename)) {
            require $filename;
        } else {
            require $baseViewPath . "404.view.php";
        }
    }

    public function getInternalData()
    {
        header('Content-Type: application/json');
        echo json_encode([
            'lang' => GlobalRegistry::get('lang_code'),
            'tax_rates' => GlobalRegistry::get('tax_rates'),
            'currency' => GlobalRegistry::get('currency'),
            'currency_symbol' => GlobalRegistry::get('currency_symbol')
        ]);
    }


    protected function getJson(Request $request): array
    {
        return json_decode($request->getContent(), true) ?? [];
    }

    protected function json($data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status);
    }

    public function handleAjaxResponse(mixed $result, string $successMessage = 'Operacja zakończona sukcesem', string $errorMessage = 'Wystąpił błąd')
    {
        if ($result === true) {
            return $this->json(['message' => $successMessage], 200);
        }

        if ($result === false) {
            return $this->json(['message' => $errorMessage], 500);
        }

        if (is_array($result)) {
            // Jeśli wynik zawiera kod, to go szanujemy
            if (isset($result['code'])) {
                return $this->json($result, $result['code']);
            }

            // Inaczej traktujemy jako poprawną odpowiedź
            return $this->json($result, 200);
        }

        // W przypadku np. null albo czegoś nieoczekiwanego
        return $this->json(['message' => $errorMessage], 400);
    }

    /**
     * Obsługuje akcję (np. metodę serwisu), przechwytując wyjątki i zapisując komunikaty.
     *
     * @param callable $action — funkcja anonimowa zawierająca wywołanie serwisu
     * @param mixed|null $default — co zwrócić, jeśli wystąpi wyjątek (np. `null`, `[]`)
     * @return mixed — wynik działania lub domyślny fallback
     *
     */
    protected function handleAction(callable $action, mixed $default = null): mixed
    {
        try {
            $out = $action();
            // normalizuj wynik
            if (is_array($out)) {
                // jeśli nie ma code, ustaw 200
                $out['code'] = $out['code'] ?? 200;
                return $out;
            }
            return ['ok' => (bool)$out, 'code' => $out ? 200 : 500];
        } catch (\App\Exceptions\CustomException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'code' => $e->getCode() ?: 400];
        } catch (\Throwable $e) {
            error_log((string)$e);
            return ['ok' => false, 'error' => 'INTERNAL' . $e, 'code' => 500];
        }
    }

    /**
     * Bezpieczne pobieranie danych z serwisu z obsługą wyjątków.
     * @param callable $callback - Funkcja zwracająca dane.
     * @param array &$errors - Tablica błędów do zapisu komunikatów.
     * @param mixed $default - Wartość domyślna w przypadku błędu (np. pusta tablica).
     * @return mixed - Zwraca wynik funkcji lub wartość domyślną.
     */
    protected function safeFetch(callable $callback, array &$errors, mixed $params = null, $default = []) {
        try {
            if ($params === null) {
                return call_user_func($callback);
            } elseif (is_array($params)) {
                return call_user_func_array($callback, $params);
            } else {
                return call_user_func($callback, $params);
            }
        } catch (DataNotFoundException $e) {
            $errors[] = $e->getMessage();
            return $default;
        } catch (ProtectedResourceException $e) {
            $errors[] = $e->getMessage();
            return $default;
        } catch (Exception $e) {
            $errors[] = "Nieoczekiwany błąd: " . $e->getMessage();
            error_log($e->getMessage());
            return $default;
        }
    }
}