<?php

declare(strict_types=1);

namespace Hr\Directory;

/**
 * Un annuaire fixe : les tests, et le serveur de développement local (HR_DEV_DIRECTORY = un fichier JSON
 * {"shops": {"4": "Wavre"}, "employees": {"4": [{"id": 12, "name": "…", "surname": "…"}]}, "profiles": {…}}).
 */
final class StaticDirectory implements Directory
{
    /** @param array{shops?: array<string, string>, employees?: array<string, list<array<string, mixed>>>, profiles?: array<string, array<string, mixed>>} $data */
    public function __construct(private array $data)
    {
    }

    public static function fromFile(string $file): self
    {
        $j = json_decode((string) @file_get_contents($file), true);
        return new self(is_array($j) ? $j : []);
    }

    public function shops(): array
    {
        $out = [];
        foreach ($this->data['shops'] ?? [] as $id => $name) {
            $out[(string) $id] = (string) $name;
        }
        return $out;
    }

    public function employees(string $shopId): array
    {
        $out = [];
        foreach ($this->data['employees'][$shopId] ?? [] as $e) {
            $given = (string) ($e['name'] ?? '');
            $family = (string) ($e['surname'] ?? '');
            $out[] = ['id' => (int) $e['id'], 'givenName' => $given, 'familyName' => $family, 'name' => trim($given . ' ' . $family), 'active' => (bool) ($e['active'] ?? true)];
        }
        return $out;
    }

    public function profile(int $employeeId): ?array
    {
        $p = $this->data['profiles'][(string) $employeeId] ?? null;
        if (!is_array($p)) {
            return null;
        }
        return ['givenName' => $p['givenName'] ?? null, 'familyName' => $p['familyName'] ?? null, 'birthDate' => $p['birthDate'] ?? null,
            'ssin' => $p['ssin'] ?? null, 'status' => $p['status'] ?? null];
    }
}
