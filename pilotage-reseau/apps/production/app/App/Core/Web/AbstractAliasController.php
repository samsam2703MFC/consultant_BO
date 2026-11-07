<?php
namespace App\Core\Web;

use App\Exceptions\CustomException;
use App\Services\Generic\GenericAliasService;

abstract class AbstractAliasController extends BaseController
{
    public function __construct(protected GenericAliasService $svc)
    {
    }

    // Dzieci muszą zdefiniować mapę pól aliasowanych.
    // FIELDS:
    //  - single: ['alias_table'=>..., 'fk_col'=>..., 'base_table'=>..., 'base_field'=>..., 'value_type'=>'short|long']
    //  - fields: [ 'name' => [...], 'description' => [...] ]
    abstract protected function aliasConfig(): array;

    protected function afterAliasUpdated(int $id, array $data): void
    {
    }

    protected function afterAliasDeleted(int $id, string $lang, string $type): void
    {
    }

    protected function listInternal(?string $field = null)
    {
        $cfg = $this->aliasConfig();
        $lang = $_GET['lang_code'] ?? 'pl';
        $type = $_GET['alias_type'] ?? 'default';

        if (isset($cfg['single'])) {
            $rows = $this->svc->getAllByLangCodeSingle($cfg['single'], strtolower($lang), $type);
        } else {
            $meta = $cfg['fields'][$field] ?? null;
            if (!$meta) {
                throw new CustomException("UNKNOWN_FIELD", "aliases", 404);
            }
            $rows = $this->svc->getAllByLangCodeField($meta, strtolower($lang), $type);
        }
        return $rows;
    }

    protected function upsertInternal(int $id, $data, ?string $field = null)
    {
        $cfg = $this->aliasConfig();

        if (isset($cfg['single'])) {
            $res = $this->svc->upsertSingle($cfg['single'], $id, $data, true);
        } else {
            $meta = $cfg['fields'][$field] ?? null;
            if (!$meta) {
                throw new CustomException("UNKNOWN_FIELD", "aliases", 404);
            }
            $res = $this->svc->upsertField($meta, $id, $data, true);
        }

        $this->afterAliasUpdated($id, $data);

        return $res;
    }

    protected function deleteInternal(int $id, ?string $field = null)
    {
        $lang = $_GET['lang_code'] ?? 'en';
        $type = $_GET['alias_type'] ?? 'default';
        $cfg = $this->aliasConfig();

        if (isset($cfg['single'])) {
            $res = $this->svc->deleteSingle($cfg['single'], $id, strtolower($lang), $type);
        } else {
            $meta = $cfg['fields'][$field] ?? null;
            if (!$meta) {
                throw new CustomException("UNKNOWN_FIELD", "aliases", 404);
            }
            $res = $this->svc->deleteField($meta, $id, strtolower($lang), $type);
        }

        $this->afterAliasDeleted($id, strtolower($lang), $type);

        return $res;
    }
}