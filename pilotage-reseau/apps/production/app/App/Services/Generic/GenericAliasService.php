<?php
namespace App\Services\Generic;



use App\Repositories\Generic\GenericAliasRepository;

class GenericAliasService
{
    public function __construct(
        private GenericAliasRepository $repo
    )
    {}

    public function getAllByLangCodeSingle(array $meta, string $lang, string $type = 'default'): array
    {
        return $this->repo->withMeta($meta)->getAllByLangCode($lang, $type);
    }

    public function getAllByLangCodeField(array $meta, string $lang, string $type = 'default'): array
    {
        return $this->repo->withMeta($meta)->getAllByLangCode($lang, $type);
    }

    public function upsertSingle(array $meta, int $id, array $data, bool $emptyDeletes = false)
    {
        $this->validate($meta, $id, $data);
        $r = $this->repo->withMeta($meta);
        return $emptyDeletes && ($data['alias'] === '' || $data['alias'] === null)
            ? $r->deleteByKey($id, strtolower($data['lang_code']), $data['alias_type'] ?? 'default')
            : $r->upsert([
                'fk_id' => $id,
                'lang_code' => strtolower($data['lang_code']),
                'alias' => $data['alias'],
                'alias_type' => !isset($data['alias_type']) || $data['alias_type'] == "" ? 'default' : $data['alias_type']
            ]);
    }

    public function upsertField(array $meta, int $id, array $data, bool $emptyDeletes = false)
    {
        return $this->upsertSingle($meta, $id, $data, $emptyDeletes);
    }

    public function deleteSingle(array $meta, int $id, string $lang, string $type = 'default')
    {
        return $this->repo->withMeta($meta)->deleteByKey($id, $lang, $type);
    }

    public function deleteField(array $meta, int $id, string $lang, string $type = 'default')
    {
        return $this->deleteSingle($meta, $id, $lang, $type);
    }

    private function validate(array $meta, int $id, array &$data): void
    {
        // walidacje: id>0, lang_code, długość alias vs value_type ('short'<=255, 'long'<=65535)
        // … (jak w poprzednim poście)
    }
}