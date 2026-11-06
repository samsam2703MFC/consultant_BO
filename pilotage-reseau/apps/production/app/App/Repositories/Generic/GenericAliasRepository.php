<?php

namespace App\Repositories\Generic;

use App\Core\Helpers\BaseRepository;
use App\Models\Generic\GenericAliasModel;

class GenericAliasRepository extends BaseRepository
{
    private array $meta;
    protected string $table = '';

    public function __construct(\PDO $pdo) {
        parent::__construct($pdo);
    }

    public function withMeta(array $meta): self {
        $clone = clone $this;
        $clone->meta = $meta;
        return $clone;
    }

    public function upsert(array $data) {
        $sql = "INSERT INTO {$this->meta['alias_table']} ({$this->meta['fk_col']}, lang_code, alias, alias_type)
                VALUES (:fk_id, :lang_code, :alias, :alias_type)
                ON DUPLICATE KEY UPDATE alias=VALUES(alias), alias_type=VALUES(alias_type), update_timestamp=NOW()";
        return $this->customUpdate($sql, $data);
    }

    public function deleteByKey(int $fkId, string $langCode, string $aliasType='default') {
        $sql = "DELETE FROM {$this->meta['alias_table']}
                WHERE {$this->meta['fk_col']} = :id AND lang_code = :lang AND alias_type = :type";
        return $this->customUpdate($sql, ['id'=>$fkId,'lang'=>$langCode,'type'=>$aliasType]);
    }

    public function getAllByLangCode(string $langCode, string $aliasType='default'): array {
        $sql = "SELECT 
                    base.id AS fk_id,
                    base.{$this->meta['base_field']} AS base_value,
                    alias.alias AS alias_value,
                    COALESCE(alias.alias, base.{$this->meta['base_field']}) AS effective_value,
                    alias.lang_code, alias.alias_type
                FROM {$this->meta['base_table']} base
                LEFT JOIN {$this->meta['alias_table']} alias
                  ON base.id = alias.{$this->meta['fk_col']}
                 AND alias.lang_code = :lang
                 AND alias.alias_type = :type
                ORDER BY base_value ASC";
        $res = $this->query($sql, ['lang'=>$langCode, 'type'=>$aliasType]);
        return array_map(fn($item) => call_user_func([GenericAliasModel::class, 'fromArray'], $item), $res);

    }
}

