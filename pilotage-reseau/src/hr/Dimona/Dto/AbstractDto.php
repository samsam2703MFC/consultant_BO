<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Base des types générés depuis le YAML Dimona v2 (tools/hr-dimona/generate.py).
 *
 * Chaque classe fille déclare ses champs (`fields()`) et ceux que le YAML rend obligatoires
 * (`required()`). Cette base sait :
 *   - lire un tableau JSON décodé en objets typés (`fromArray`), champs inconnus ignorés ;
 *   - le réécrire en tableau prêt à encoder (`toArray`), champs vides omis — l'ONSS refuse un null
 *     là où il attend une absence ;
 *   - valider contre les contraintes du YAML (`validate`) : required, pattern, maxLength, minLength,
 *     minimum, maximum, enum, format date. Les règles métier (type de travailleur, heures d'un
 *     étudiant…) vivent ailleurs (Hr\Dimona\PayloadBuilder) : elles ne sont pas dans le YAML.
 */
abstract class AbstractDto implements \JsonSerializable
{
    /** @return array<string, array<string, mixed>> */
    abstract public static function fields(): array;

    /** @return list<string> */
    abstract public static function required(): array;

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        $o = new static();
        foreach (static::fields() as $name => $spec) {
            if (!array_key_exists($name, $data) || $data[$name] === null) {
                continue;
            }
            $o->{$name} = self::hydrate($spec, $data[$name]);
        }
        return $o;
    }

    private static function hydrate(array $spec, mixed $v): mixed
    {
        if (isset($spec['ref'])) {
            if ($v instanceof AbstractDto) {
                return $v;
            }
            return is_array($v) ? $spec['ref']::fromArray($v) : null;
        }
        switch ($spec['type'] ?? 'mixed') {
            case 'array':
                if (!is_array($v)) {
                    return null;
                }
                $out = [];
                foreach ($v as $item) {
                    $out[] = self::hydrate($spec['items'] ?? ['type' => 'mixed'], $item);
                }
                return $out;
            case 'string':
                return is_scalar($v) ? (string) $v : null;
            case 'integer':
                return is_numeric($v) ? (int) $v : null;
            case 'number':
                return is_numeric($v) ? $v + 0 : null;
            case 'boolean':
                return is_bool($v) ? $v : (bool) $v;
            default:
                return $v;
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [];
        foreach (static::fields() as $name => $spec) {
            $v = $this->{$name};
            if ($v === null) {
                continue;
            }
            $out[$name] = self::dehydrate($v);
        }
        return $out;
    }

    private static function dehydrate(mixed $v): mixed
    {
        if ($v instanceof AbstractDto) {
            return (object) $v->toArray();
        }
        if (is_array($v)) {
            return array_map([self::class, 'dehydrate'], $v);
        }
        return $v;
    }

    public function jsonSerialize(): mixed
    {
        return (object) $this->toArray();
    }

    /**
     * Contrôle contre le YAML. Rend la liste des écarts, vide si tout va bien :
     * [['path' => 'dimonaIn.startDate', 'rule' => 'required', 'message' => '…'], …].
     *
     * @return list<array{path: string, rule: string, message: string}>
     */
    public function validate(string $path = ''): array
    {
        $errors = [];
        $data = $this->toArray();
        foreach (static::required() as $r) {
            if (!array_key_exists($r, $data)) {
                $errors[] = ['path' => self::join($path, $r), 'rule' => 'required', 'message' => 'champ obligatoire (' . static::SCHEMA . ')'];
            }
        }
        foreach (static::fields() as $name => $spec) {
            $v = $this->{$name};
            if ($v === null) {
                continue;
            }
            $errors = array_merge($errors, self::check($spec, $v, self::join($path, $name)));
        }
        return $errors;
    }

    /** @return list<array{path: string, rule: string, message: string}> */
    private static function check(array $spec, mixed $v, string $path): array
    {
        if (isset($spec['ref'])) {
            return $v instanceof AbstractDto ? $v->validate($path) : [];
        }
        $e = [];
        $type = $spec['type'] ?? 'mixed';
        if ($type === 'array' && is_array($v)) {
            foreach (array_values($v) as $i => $item) {
                $e = array_merge($e, self::check($spec['items'] ?? ['type' => 'mixed'], $item, $path . '[' . $i . ']'));
            }
            return $e;
        }
        if (isset($spec['enum']) && !in_array($v, $spec['enum'], true)) {
            $e[] = ['path' => $path, 'rule' => 'enum', 'message' => 'valeur hors liste : ' . implode(', ', array_map('strval', $spec['enum']))];
        }
        if ($type === 'string' && is_string($v)) {
            $len = mb_strlen($v);
            if (isset($spec['maxLength']) && $len > $spec['maxLength']) {
                $e[] = ['path' => $path, 'rule' => 'maxLength', 'message' => $spec['maxLength'] . ' caractères au plus'];
            }
            if (isset($spec['minLength']) && $len < $spec['minLength']) {
                $e[] = ['path' => $path, 'rule' => 'minLength', 'message' => $spec['minLength'] . ' caractères au moins'];
            }
            if (isset($spec['pattern']) && !preg_match('/' . str_replace('/', '\/', $spec['pattern']) . '/u', $v)) {
                $e[] = ['path' => $path, 'rule' => 'pattern', 'message' => 'format attendu : ' . $spec['pattern']];
            }
            if (($spec['format'] ?? '') === 'date' && !self::isDate($v)) {
                $e[] = ['path' => $path, 'rule' => 'format', 'message' => 'date attendue (AAAA-MM-JJ)'];
            }
        }
        if (($type === 'integer' || $type === 'number') && is_numeric($v)) {
            if (isset($spec['minimum']) && $v < $spec['minimum']) {
                $e[] = ['path' => $path, 'rule' => 'minimum', 'message' => 'au moins ' . $spec['minimum']];
            }
            if (isset($spec['maximum']) && $v > $spec['maximum']) {
                $e[] = ['path' => $path, 'rule' => 'maximum', 'message' => 'au plus ' . $spec['maximum']];
            }
        }
        return $e;
    }

    private static function isDate(string $v): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function join(string $path, string $name): string
    {
        return $path === '' ? $name : $path . '.' . $name;
    }
}
