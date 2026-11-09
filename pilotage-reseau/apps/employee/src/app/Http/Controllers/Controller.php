<?php

namespace App\Employee\app\Http\Controllers;


use App\Employee\core\Exceptions\DataNotFoundException;
use App\Employee\core\Exceptions\ProtectedResourceException;
use App\Employee\core\Support\GlobalRegistry;
use App\Employee\core\Twig\AppExtension;
use Exception;
use ReflectionClass;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\Loader\FilesystemLoader;

class Controller
{
    public $errors = []; // krytyczne błędy, blokują działanie
    public $information = []; // informacje niezwiązane bezpośrednio z akcją
    public $warnings = []; // błędy operacji, nie blokują GUI
    public $successes = []; // sukcesy operacji
    public $ignored_messages = [];

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

    public function view($name, $data = [])
    {
        $reflector = new ReflectionClass($this);
        $namespace = $reflector->getNamespaceName();

        $baseViewPath =  __DIR__ . "/../../../app/";
        if (strpos($namespace, 'App\\Employee\\app\\Http\\Controllers') !== false) {
            $baseViewPath .= "Views/";
        }

        $moduleName = "login";
        $splittedPathElems = explode("/", $name);
        //nazwą modułu jest katalog w którym znajdują się pliki otwierane przez Controller/view
        if(isset($splittedPathElems[0])){
            $moduleName = $splittedPathElems[0];
        }
        $langCode = GlobalRegistry::get('lang_code');

        $globalTranslations = loadTranslations('page', $langCode, 'components');
        $moduleTranslations = loadTranslations('page', $langCode, $moduleName);

        $data['translations'] = array_merge($globalTranslations, $moduleTranslations);

        $data['errors'] = $this->errors;
        $data['information'] = $this->information;
        $data['warnings'] = $this->warnings;
        $data['successes'] = $this->successes;

        $data['ROOT'] = ROOT;
        $data['shared_files_url'] = SHARED_FILES_URL;
        $data['currency_symbol'] = CURRENCY_SYMBOL;
        $user = GlobalRegistry::get('user');
        $data['permissions'] = $user ? $user->getPermissions() : null;

        // Jeśli istnieje plik .twig, renderuj przez Twig
        $twigTemplate = $name . ".twig";
        if (file_exists($baseViewPath . $twigTemplate)) {
            $this->render($baseViewPath, $twigTemplate, $data);
            return;
        } else {
            $this->render($baseViewPath, "errors/404.twig", $data);
        }
    }

    private function render($baseViewPath, $twigTemplate, $data)
    {
        $loader = new FilesystemLoader($baseViewPath);
        $twig = new Environment($loader, [
            'cache' => false, // Możesz zmienić na ścieżkę do cache w produkcji
            'autoescape' => 'html',
            'debug' => true, // Włącz debugowanie, jeśli potrzebujesz
        ]);

        $twig->addExtension(new DebugExtension());
        $twig->addExtension(new AppExtension($_POST));
        echo $twig->render($twigTemplate, $data);
    }

    protected function getJson(Request $request): array
    {
        return json_decode($request->getContent(), true) ?? [];
    }

    protected function json($data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status);
    }
}