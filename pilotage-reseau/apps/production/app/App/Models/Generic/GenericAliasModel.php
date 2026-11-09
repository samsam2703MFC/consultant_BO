<?php

namespace App\Models\Generic;

class GenericAliasModel implements \JsonSerializable
{
    protected int|string|null $idSubject;
    protected string $base_value;
    protected string $langCode;
    protected ?string $alias_value;
    protected ?string $aliasType;

    public function __construct(array $d)
    {
        $this->idSubject = $d['fk_id'] ?? null;
        $this->base_value = $d['base_value'] ?? '';
        $this->langCode = $d['lang_code'] ?? '';
        $this->alias_value = array_key_exists('alias_value', $d) ? $d['alias_value'] : null;
        $this->aliasType = array_key_exists('alias_type', $d) ? $d['alias_type'] : null;
    }

    public static function fromArray(array $d): static
    {
        return new static($d);
    }

    public function jsonSerialize(): array
    {
        return [
            'fk_id' => $this->idSubject,
            'base_value' => $this->base_value,
            'lang_code' => $this->langCode,
            'alias_value' => $this->alias_value ?? '',
            'alias_type' => $this->aliasType ?? '',
        ];
    }

    // gettery wspólne…
    public function getIdSubject(): int|string|null
    {
        return $this->idSubject;
    }

    public function getName(): string
    {
        return $this->base_value;
    }

    public function getLangCode(): string
    {
        return $this->langCode;
    }

    public function getAlias(): string
    {
        return $this->alias_value ?? '';
    }

    public function getAliasType(): string
    {
        return $this->aliasType ?? '';
    }
}
