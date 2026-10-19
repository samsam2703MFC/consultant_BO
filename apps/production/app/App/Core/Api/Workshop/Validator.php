<?php
declare(strict_types=1);

namespace App\Core\Api\Workshop;

/**
 * Validation d'un corps JSON champ par champ. Chaque règle lit le champ s'il est présent,
 * l'exige s'il manque en création (en modification partielle, PATCH, un champ absent reste
 * inchangé), et range la valeur nettoyée dans clean(). Toutes les erreurs sont collectées
 * avant de répondre, pour que le client corrige tout en une fois.
 */
final class Validator
{
    private array $errors = [];
    private array $clean = [];

    public function __construct(private array $input, private bool $partial = false)
    {
    }

    /** Refuse les champs inconnus (une faute de frappe ne doit pas passer en silence). */
    public function only(array $allowed): self
    {
        foreach (array_keys($this->input) as $field) {
            if (!in_array($field, $allowed, true)) {
                $this->add((string) $field, 'UNKNOWN_FIELD');
            }
        }
        return $this;
    }

    public function readOnly(array $fields): self
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $this->input)) {
                $this->add($field, 'READ_ONLY');
            }
        }
        return $this;
    }

    public function string(string $field, int $max, bool $required = true, bool $nullable = false, ?string $pattern = null, string $patternCode = 'INVALID_CODE_FORMAT'): self
    {
        if (!$this->present($field, $required, $nullable)) return $this;
        $value = $this->input[$field];
        if (!is_string($value)) return $this->add($field, 'NOT_STRING');
        $value = trim($value);
        if ($value === '') {
            if ($nullable) { $this->clean[$field] = null; return $this; }
            return $this->add($field, 'REQUIRED');
        }
        if (mb_strlen($value) > $max) return $this->add($field, 'TOO_LONG', ['max' => $max]);
        if ($pattern !== null && !preg_match($pattern, $value)) return $this->add($field, $patternCode);
        $this->clean[$field] = $value;
        return $this;
    }

    /** Code technique : majuscules, chiffres, _ (2 à 32 caractères). */
    public function code(string $field, bool $required = true): self
    {
        if (array_key_exists($field, $this->input) && is_string($this->input[$field])) {
            $this->input[$field] = strtoupper(trim($this->input[$field]));
        }
        return $this->string($field, 32, $required, false, '/^[A-Z0-9_]{2,32}$/');
    }

    public function integer(string $field, bool $required = true, ?int $min = null, ?int $max = null, bool $nullable = false): self
    {
        if (!$this->present($field, $required, $nullable)) return $this;
        $value = $this->input[$field];
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) $value = (int) $value;
        if (!is_int($value)) return $this->add($field, 'NOT_INTEGER');
        return $this->range($field, $value, $min, $max, false);
    }

    public function decimal(string $field, bool $required = true, ?float $min = null, ?float $max = null, bool $nullable = false, bool $exclusiveMin = false): self
    {
        if (!$this->present($field, $required, $nullable)) return $this;
        $value = $this->input[$field];
        if (is_string($value) && is_numeric(trim($value))) $value = (float) $value;
        if (!is_int($value) && !is_float($value)) return $this->add($field, 'NOT_NUMBER');
        return $this->range($field, $value + 0, $min, $max, $exclusiveMin);
    }

    public function boolean(string $field, bool $required = false): self
    {
        if (!$this->present($field, $required, false)) return $this;
        $value = $this->input[$field];
        if ($value === 0 || $value === 1) $value = (bool) $value;
        if (!is_bool($value)) return $this->add($field, 'NOT_BOOLEAN');
        $this->clean[$field] = $value;
        return $this;
    }

    public function choice(string $field, array $allowed, bool $required = true, bool $nullable = false): self
    {
        if (!$this->present($field, $required, $nullable)) return $this;
        $value = $this->input[$field];
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            return $this->add($field, 'INVALID_CHOICE', ['allowed' => implode(', ', $allowed)]);
        }
        $this->clean[$field] = $value;
        return $this;
    }

    /** Heure HH:MM (ou HH:MM:SS), rangée en HH:MM:00. */
    public function time(string $field, bool $required = true): self
    {
        if (!$this->present($field, $required, false)) return $this;
        $value = $this->input[$field];
        if (!is_string($value) || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $value, $m)) {
            return $this->add($field, 'INVALID_TIME');
        }
        $this->clean[$field] = sprintf('%s:%s:00', $m[1], $m[2]);
        return $this;
    }

    public function date(string $field, bool $required = false, bool $nullable = true): self
    {
        if (!$this->present($field, $required, $nullable)) return $this;
        $value = $this->input[$field];
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (!$date || $date->format('Y-m-d') !== $value) return $this->add($field, 'INVALID_DATE');
        $this->clean[$field] = $value;
        return $this;
    }

    public function color(string $field, bool $required = true): self
    {
        return $this->string($field, 7, $required, false, '/^#[0-9A-Fa-f]{6}$/', 'INVALID_COLOR');
    }

    /** Lien https direct ; si $base est donné, le lien doit commencer par lui (bucket R2). */
    public function url(string $field, string $base = ''): self
    {
        if (!$this->present($field, false, true)) return $this;
        $value = $this->input[$field];
        if (is_string($value) && trim($value) === '') { $this->clean[$field] = null; return $this; }
        if (!is_string($value) || strlen($value) > 1024 || !filter_var($value, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($value), 'https://')) {
            return $this->add($field, 'INVALID_URL');
        }
        if ($base !== '' && !str_starts_with($value, $base . '/')) {
            return $this->add($field, 'URL_NOT_R2', ['base' => $base]);
        }
        $this->clean[$field] = $value;
        return $this;
    }

    /** Jours ISO 1–7, rangés triés sans doublon sous la forme « 1,2,3 ». */
    public function days(string $field, bool $required = true): self
    {
        if (!$this->present($field, $required, false)) return $this;
        $value = $this->input[$field];
        if (!is_array($value) || $value === [] || array_filter($value, fn($d) => !is_int($d) || $d < 1 || $d > 7)) {
            return $this->add($field, 'INVALID_DAYS');
        }
        $days = array_values(array_unique($value));
        sort($days);
        $this->clean[$field] = implode(',', $days);
        return $this;
    }

    public function add(string $field, string $code, array $params = []): self
    {
        $this->errors[] = ['field' => $field, 'code' => $code, 'params' => $params];
        return $this;
    }

    public function provided(string $field): bool
    {
        return array_key_exists($field, $this->clean);
    }

    public function value(string $field, mixed $default = null): mixed
    {
        return array_key_exists($field, $this->clean) ? $this->clean[$field] : $default;
    }

    public function raw(string $field): mixed
    {
        return $this->input[$field] ?? null;
    }

    public function hasRaw(string $field): bool
    {
        return array_key_exists($field, $this->input);
    }

    public function hasError(string $field): bool
    {
        foreach ($this->errors as $error) {
            if ($error['field'] === $field) return true;
        }
        return false;
    }

    public function clean(): array
    {
        return $this->clean;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function throwIfInvalid(): void
    {
        if ($this->errors) {
            throw ApiException::validation($this->errors);
        }
    }

    private function present(string $field, bool $required, bool $nullable): bool
    {
        if (!array_key_exists($field, $this->input)) {
            if ($required && !$this->partial) $this->add($field, 'REQUIRED');
            return false;
        }
        if ($this->input[$field] === null) {
            if ($nullable) { $this->clean[$field] = null; }
            else { $this->add($field, 'REQUIRED'); }
            return false;
        }
        return true;
    }

    private function range(string $field, int|float $value, ?float $min, ?float $max, bool $exclusiveMin): self
    {
        if ($min !== null && $max !== null && ($value < $min || $value > $max || ($exclusiveMin && $value == $min))) {
            return $this->add($field, 'OUT_OF_RANGE', ['min' => $min, 'max' => $max]);
        }
        if ($min !== null && $max === null) {
            if ($exclusiveMin && $value <= $min) return $this->add($field, 'GREATER_THAN', ['min' => $min]);
            if (!$exclusiveMin && $value < $min) return $this->add($field, 'MIN', ['min' => $min]);
        }
        if ($max !== null && $min === null && $value > $max) {
            return $this->add($field, 'OUT_OF_RANGE', ['min' => '−∞', 'max' => $max]);
        }
        $this->clean[$field] = $value;
        return $this;
    }
}
