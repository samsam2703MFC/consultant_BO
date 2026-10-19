<?php
declare(strict_types=1);

namespace App\Core\Api\Workshop;

/**
 * Messages de l'API du module atelier, en polonais (défaut) ou en français.
 * Fichiers : public/resources/lang/api/{pl,fr}/production.json.
 */
final class Translator
{
    public const LANGUAGES = ['pl', 'fr'];

    private static array $cache = [];
    private array $messages;

    public function __construct(private string $lang)
    {
        if (!in_array($lang, self::LANGUAGES, true)) {
            $this->lang = 'pl';
        }
        $this->messages = self::load($this->lang);
    }

    public function lang(): string
    {
        return $this->lang;
    }

    /** Choisit la langue : ?lang=, sinon la première langue connue d'Accept-Language. */
    public static function negotiate(?string $query, ?string $acceptLanguage, string $default = 'pl'): string
    {
        if ($query !== null && in_array(strtolower($query), self::LANGUAGES, true)) {
            return strtolower($query);
        }
        foreach (explode(',', (string) $acceptLanguage) as $part) {
            $code = strtolower(substr(trim(explode(';', $part)[0]), 0, 2));
            if (in_array($code, self::LANGUAGES, true)) {
                return $code;
            }
        }
        return in_array($default, self::LANGUAGES, true) ? $default : 'pl';
    }

    public function error(string $code, array $params = []): string
    {
        return $this->format($this->messages['errors'][$code] ?? $code, $params);
    }

    public function fieldError(string $field, string $code, array $params = []): string
    {
        $params['field'] = $this->messages['fields'][$field] ?? $field;
        return $this->format($this->messages['field_errors'][$code] ?? $code, $params);
    }

    public function entity(string $entity): string
    {
        return $this->messages['entities'][$entity] ?? $entity;
    }

    private function format(string $message, array $params): string
    {
        foreach ($params as $key => $value) {
            if (is_array($value)) $value = implode(', ', $value);
            $message = str_replace('{' . $key . '}', (string) $value, $message);
        }
        return $message;
    }

    private static function load(string $lang): array
    {
        if (!isset(self::$cache[$lang])) {
            $file = dirname(__DIR__, 4) . "/public/resources/lang/api/{$lang}/production.json";
            $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            self::$cache[$lang] = is_array($data) ? $data : [];
        }
        return self::$cache[$lang];
    }
}
