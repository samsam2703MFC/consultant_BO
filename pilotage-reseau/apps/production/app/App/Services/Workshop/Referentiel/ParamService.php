<?php
declare(strict_types=1);

namespace App\Services\Workshop\Referentiel;

use App\Domain\Params\ParamRules;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Repositories\Workshop\ParamRepository;

/** Paramètres métier [PARAM] : lecture typée et modification validée selon le type. */
final class ParamService
{
    public function __construct(
        private ParamRepository $params,
        private TransactionManager $tx,
        private AuditTrail $audit,
    ) {
    }

    public function list(ApiContext $context): array
    {
        return array_map(fn($row) => $this->present($row, $context), $this->params->list());
    }

    public function get(string $code, ApiContext $context): array
    {
        return $this->present($this->load($code), $context);
    }

    /** $checkOrder = false : l'appelant (import Excel) a déjà vérifié les règles entre paramètres sur l'état final. */
    public function update(string $code, array $input, ApiContext $context, bool $checkOrder = true): array
    {
        $param = $this->load($code);
        $v = (new Validator($input))->only(['value']);
        $value = $this->validateValue($v, $param);
        $v->throwIfInvalid();
        // Bornes et règles entre paramètres : les mêmes que la page Ustawienia › Pozostałe (ParamRules).
        if (isset(ParamRules::RANGES[$code]) && ParamRules::rangeError($code, $value) !== null) {
            [$min, $max] = ParamRules::RANGES[$code];
            throw ApiException::validation([['field' => 'value', 'code' => 'OUT_OF_RANGE', 'params' => ['min' => $min, 'max' => $max]]]);
        }
        if ($checkOrder) {
            foreach (ParamRules::orderErrors([$code => $value], fn($c) => $this->params->findByCode($c)['value'] ?? null) as [$error]) {
                throw ApiException::validation([['field' => 'value', 'code' => $error, 'params' => []]]);
            }
        }

        $this->tx->execute(function () use ($code, $value, $param, $context) {
            $this->params->setValue($code, $value, $context->userId);
            $this->audit->record($context, 'update', 'param', $code, ['value' => $param['value']], ['value' => $value]);
        });
        return $this->get($code, $context);
    }

    /** Valeur à stocker (texte), après contrôle du type et des bornes connues. */
    private function validateValue(Validator $v, array $param): string
    {
        switch ($param['value_type']) {
            case 'int':
                $v->integer('value', true, 0, 1000000);
                return (string) $v->value('value', '');
            case 'decimal':
                $max = $param['unit'] === '%' ? 200 : 1000000;
                $v->decimal('value', true, 0, $max);
                return (string) $v->value('value', '');
            case 'bool':
                $v->boolean('value', true);
                return $v->value('value') ? '1' : '0';
            case 'time':
                $v->time('value');
                return substr((string) $v->value('value', ''), 0, 5);
            case 'json':
                if (!$v->hasRaw('value')) { $v->add('value', 'REQUIRED'); return ''; }
                $raw = $v->raw('value');
                if (!is_array($raw)) { $v->add('value', 'INVALID_JSON_VALUE'); return ''; }
                if ($param['code'] === 'workstation.capacity_units' && (!array_is_list($raw) || $raw === [] || array_filter($raw, fn($u) => !is_string($u) || !preg_match('/^[a-z_]{1,16}$/', $u)))) {
                    $v->add('value', 'INVALID_JSON_VALUE');
                }
                return (string) json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            default:
                if ($param['code'] === 'api.default_language') {
                    $v->choice('value', ['pl', 'fr']);
                } else {
                    $v->string('value', 255);
                }
                return (string) $v->value('value', '');
        }
    }

    private function load(string $code): array
    {
        return $this->params->findByCode($code) ?? throw ApiException::notFound('param', $code);
    }

    public static function typed(string $value, string $type): mixed
    {
        return match ($type) {
            'int' => (int) $value,
            'decimal' => (float) $value,
            'bool' => $value === '1',
            'json' => json_decode($value, true),
            default => $value,
        };
    }

    private function present(array $row, ApiContext $context): array
    {
        $lang = $context->translator->lang();
        return [
            'code' => $row['code'],
            'value' => self::typed($row['value'], $row['value_type']),
            'default_value' => self::typed($row['default_value'], $row['value_type']),
            'is_default' => $row['value'] === $row['default_value'],
            'value_type' => $row['value_type'],
            'unit' => $row['unit'],
            'description' => $row['description_' . $lang],
            'description_pl' => $row['description_pl'],
            'description_fr' => $row['description_fr'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
