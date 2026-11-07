<?php
declare(strict_types=1);

namespace App\Services\Workshop\Referentiel;

use App\Domain\Params\ParamRules;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Logistics\LogisticsRepository;
use App\Repositories\Workshop\GroupRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Repositories\Workshop\ShiftTemplateRepository;
use App\Repositories\Workshop\TeamRepository;
use App\Repositories\Workshop\WorkstationRepository;
use App\Services\Logistics\LogisticsService;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Tableau à remplir des données réelles de l'atelier : un classeur Excel pré-rempli avec les données de l'ERP
 * (postes et capacités, shifts, équipes et effectifs, tournées et heures de départ, paramètres dont la capacité
 * du surgélateur), puis l'import du classeur rempli. L'aperçu passe chaque ligne par les services habituels
 * (mêmes contrôles) dans une transaction annulée : créer / modifier / inchangé, ou les erreurs de la ligne.
 * L'application refait la même chose et n'enregistre que si le fichier n'a aucune erreur (tout ou rien).
 * Une ligne absente du fichier n'est jamais supprimée : « Aktywne = nie » la désactive.
 */
final class DataSheetService
{
    /** Paramètres proposés dans le tableau : données réelles de l'atelier. */
    public const PARAMS = [
        'cold.freezer_capacity_cartons',
        'planning.default_departure_time',
        'planning.loading_time_min',
        'week.production_days',
        'week.fte_hours',
        'logistics.default_pallet_places',
        'alerts.complaint_days',
        'alerts.complaint_escalate_days',
        'alerts.cost_due_day',
        'shipping_issue.decision_days',
        'shipping_issue.min_amount',
        'shipping_issue.lookback_days',
        'send_status.stuck_minutes',
        'send_status.lookback_days',
    ];
    private const MAX_BYTES = 2_000_000;
    /** Lignes préparées pour la saisie (listes déroulantes, colonnes en texte). */
    private const ROWS = 300;
    private const DAYS_HINT = '1 = pon./lun. … 7 = niedz./dim.';

    /**
     * feuille => [titre, colonnes champ => [en-tête, type]] ; la première colonne est la clé de la ligne.
     * Types : code, text, choice, decimal, int, bool, time, days, group, shift, param.
     */
    private const SHEETS = [
        'workstations' => ['Stanowiska (postes)', [
            'code' => ['Kod / Code *', 'code'],
            'group' => ['Grupa / Groupe *', 'group'],
            'name_pl' => ['Nazwa PL *', 'text'],
            'name_fr' => ['Nom FR *', 'text'],
            'type' => ['Typ / Type *', 'choice'],
            'robot_mode' => ['Tryb robota / Mode du robot', 'choice'],
            'operator_share' => ['Udział operatora / Part opérateur (0–1)', 'decimal'],
            'capacity_per_pass' => ['Pojemność na przejście / Capacité par passage', 'decimal'],
            'capacity_unit' => ['Jednostka / Unité', 'choice'],
            'hourly_cost' => ['Koszt godziny / Coût horaire', 'decimal'],
            'active' => ['Aktywne / Actif', 'bool'],
            'notes' => ['Uwagi / Remarques', 'text'],
        ]],
        'shifts' => ['Zmiany (horaires)', [
            'code' => ['Kod / Code *', 'code'],
            'name_pl' => ['Nazwa PL *', 'text'],
            'name_fr' => ['Nom FR *', 'text'],
            'start_time' => ['Początek / Début * (HH:MM)', 'time'],
            'end_time' => ['Koniec / Fin * (HH:MM)', 'time'],
            'active_days' => ['Dni / Jours (' . self::DAYS_HINT . ')', 'days'],
            'premium_pct' => ['Dodatek / Prime (%)', 'decimal'],
            'is_night' => ['Nocna / Nuit', 'bool'],
            'sort_order' => ['Kolejność / Ordre', 'int'],
            'active' => ['Aktywna / Active', 'bool'],
        ]],
        'teams' => ['Zespoły (équipes)', [
            'code' => ['Kod / Code *', 'code'],
            'group' => ['Grupa / Groupe *', 'group'],
            'shift' => ['Zmiana / Horaire *', 'shift'],
            'name' => ['Nazwa / Nom *', 'text'],
            'planned_headcount' => ['Planowana obsada / Effectif prévu (osoby / personnes)', 'int'],
            'active' => ['Aktywny / Active', 'bool'],
        ]],
        'tours' => ['Trasy (tournées)', [
            'id' => ['Nr (nie zmieniać / ne pas modifier)', 'int'],
            'name' => ['Nazwa trasy / Nom de la tournée *', 'text'],
            'departure_time' => ['Godzina wyjazdu / Heure de départ (HH:MM)', 'time'],
            'days' => ['Dni / Jours (' . self::DAYS_HINT . ')', 'days'],
            'active' => ['Aktywna / Active', 'bool'],
            'notes' => ['Uwagi / Remarques', 'text'],
        ]],
        'params' => ['Parametry (paramètres)', [
            'code' => ['Kod / Code', 'text'],
            'description' => ['Opis / Description', 'text'],
            'unit' => ['Jednostka / Unité', 'text'],
            'value' => ['Wartość / Valeur *', 'param'],
            'default' => ['Domyślnie / Par défaut', 'text'],
        ]],
    ];
    /** Colonnes sans lesquelles une feuille ne peut pas être lue. */
    private const KEY_COLUMNS = ['workstations' => ['code'], 'shifts' => ['code'], 'teams' => ['code'], 'tours' => ['name'], 'params' => ['code', 'value']];
    /** Champs qu'une cellule vide efface ; ailleurs, une cellule vide laisse la valeur actuelle. */
    private const NULLABLE = ['workstations' => ['robot_mode', 'operator_share', 'notes'], 'tours' => ['departure_time', 'notes']];
    /** Colonnes affichées seulement (pas importées). */
    private const INFO_COLUMNS = ['params' => ['description', 'unit', 'default']];
    /** Ordre d'import : les équipes peuvent viser un shift ajouté dans le même fichier. */
    private const ORDER = ['shifts', 'workstations', 'teams', 'tours', 'params'];
    private const GROUPS_TITLE = 'Grupy (groupes)';

    public function __construct(
        private GroupRepository $groups,
        private WorkstationRepository $workstationRepo,
        private ShiftTemplateRepository $shiftRepo,
        private TeamRepository $teamRepo,
        private ParamRepository $paramRepo,
        private LogisticsRepository $logisticsRepo,
        private WorkstationService $workstations,
        private ShiftTemplateService $shifts,
        private TeamService $teams,
        private ParamService $params,
        private LogisticsService $logistics,
        private TransactionManager $tx,
        private AuditTrail $audit,
    ) {
    }

    /** Classeur pré-rempli : {filename, mime, content_base64}. */
    public function export(ApiContext $context): array
    {
        $file = tempnam(sys_get_temp_dir(), 'tfbsheet');
        try {
            (new XlsxWriter($this->build($context)))->save($file);
            $content = (string) file_get_contents($file);
        } finally {
            @unlink($file);
        }
        return [
            'filename' => 'tabela-pracowni-' . date('Y-m-d') . '.xlsx',
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'content_base64' => base64_encode($content),
        ];
    }

    /** Aperçu : rien n'est enregistré. */
    public function preview(array $input, ApiContext $context): array
    {
        return $this->run($input, $context, false);
    }

    /** Application : enregistré seulement si aucune ligne n'est en erreur. */
    public function apply(array $input, ApiContext $context): array
    {
        return $this->run($input, $context, true);
    }

    private function run(array $input, ApiContext $context, bool $commit): array
    {
        $sheets = $this->read($input['file'] ?? null);
        $rollback = new \RuntimeException('data-sheet-rollback');
        $result = [];
        try {
            $this->tx->execute(function () use ($sheets, $context, $commit, $rollback, &$result) {
                $result = $this->process($sheets, $context);
                if (!$commit || $result['error_count'] > 0) throw $rollback;
            });
        } catch (\RuntimeException $e) {
            if ($e !== $rollback) throw $e;
        }
        $result['applied'] = $commit && $result['error_count'] === 0 && $result['change_count'] > 0;
        return $result;
    }

    /* ---------- import ---------- */

    private function process(array $sheets, ApiContext $context): array
    {
        $groupCodes = [];
        foreach ($this->groups->list(null) as $g) $groupCodes[(string) $g['code']] = (int) $g['id'];
        $rows = [];
        foreach (self::ORDER as $sheet) {
            if (!isset($sheets[$sheet])) continue;
            $rows = [...$rows, ...match ($sheet) {
                'shifts' => $this->importShifts($sheets[$sheet], $context),
                'workstations' => $this->importWorkstations($sheets[$sheet], $groupCodes, $context),
                'teams' => $this->importTeams($sheets[$sheet], $groupCodes, $context),
                'tours' => $this->importTours($sheets[$sheet], $context),
                'params' => $this->importParams($sheets[$sheet], $context),
            }];
        }
        $summary = [];
        foreach (array_keys($sheets) as $sheet) $summary[$sheet] = ['create' => 0, 'update' => 0, 'unchanged' => 0, 'error' => 0];
        foreach ($rows as $row) $summary[$row['sheet']][$row['action']]++;
        $count = fn(string $action) => count(array_filter($rows, fn($r) => $r['action'] === $action));
        return [
            'rows' => $rows,
            'summary' => $summary,
            'sheets' => array_map(fn($k) => ['key' => $k, 'title' => self::SHEETS[$k][0]], array_keys($summary)),
            'change_count' => $count('create') + $count('update'),
            'error_count' => $count('error'),
        ];
    }

    private function importShifts(array $rows, ApiContext $context): array
    {
        $current = [];
        foreach ($this->shiftRepo->list(null) as $s) {
            $current[$s['code']] = ['id' => (int) $s['id'], 'code' => $s['code'], 'name_pl' => $s['name_pl'], 'name_fr' => $s['name_fr'],
                'start_time' => substr((string) $s['start_time'], 0, 5), 'end_time' => substr((string) $s['end_time'], 0, 5),
                'active_days' => self::dayList((string) $s['active_days']), 'premium_pct' => (float) $s['premium_pct'],
                'is_night' => (bool) $s['is_night'], 'sort_order' => (int) $s['sort_order'], 'active' => (bool) $s['active']];
        }
        return $this->importRows('shifts', $rows, fn($row) => $row['cells']['code'] ?? null, fn($key) => $current[$key] ?? null,
            fn(array $body) => $body,
            fn(array $body) => $this->shifts->create($body, $context),
            fn(int $id, array $body) => $this->shifts->update($id, $body, $context), $context);
    }

    private function importWorkstations(array $rows, array $groupCodes, ApiContext $context): array
    {
        $groupById = array_flip($groupCodes);
        $current = [];
        foreach ($this->workstationRepo->list(null, null, null) as $w) {
            $current[$w['code']] = ['id' => (int) $w['id'], 'code' => $w['code'], 'group' => $groupById[(int) $w['id_group']] ?? null,
                'name_pl' => $w['name_pl'], 'name_fr' => $w['name_fr'], 'type' => $w['type'], 'robot_mode' => $w['robot_mode'],
                'operator_share' => $w['operator_share'] !== null ? (float) $w['operator_share'] : null,
                'capacity_per_pass' => (float) $w['capacity_per_pass'], 'capacity_unit' => $w['capacity_unit'],
                'hourly_cost' => (float) $w['hourly_cost'], 'active' => (bool) $w['active'], 'notes' => $w['notes']];
        }
        return $this->importRows('workstations', $rows, fn($row) => $row['cells']['code'] ?? null, fn($key) => $current[$key] ?? null,
            fn(array $body) => $this->resolve($body, 'group', 'group_id', $groupCodes, 'DATA_SHEET_UNKNOWN_GROUP'),
            fn(array $body) => $this->workstations->create($body, $context),
            fn(int $id, array $body) => $this->workstations->update($id, $body, $context), $context);
    }

    private function importTeams(array $rows, array $groupCodes, ApiContext $context): array
    {
        $groupById = array_flip($groupCodes);
        // Shifts relus : ceux ajoutés plus haut dans le même fichier comptent.
        $shiftCodes = [];
        foreach ($this->shiftRepo->list(null) as $s) $shiftCodes[(string) $s['code']] = (int) $s['id'];
        $shiftById = array_flip($shiftCodes);
        $current = [];
        foreach ($this->teamRepo->list(null, null, null) as $t) {
            $current[$t['code']] = ['id' => (int) $t['id'], 'code' => $t['code'], 'group' => $groupById[(int) $t['id_group']] ?? null,
                'shift' => $shiftById[(int) $t['id_shift_template']] ?? null, 'name' => $t['name'],
                'planned_headcount' => (int) $t['planned_headcount'], 'active' => (bool) $t['active']];
        }
        return $this->importRows('teams', $rows, fn($row) => $row['cells']['code'] ?? null, fn($key) => $current[$key] ?? null,
            fn(array $body) => $this->resolve($this->resolve($body, 'group', 'group_id', $groupCodes, 'DATA_SHEET_UNKNOWN_GROUP'),
                'shift', 'shift_template_id', $shiftCodes, 'DATA_SHEET_UNKNOWN_SHIFT'),
            fn(array $body) => $this->teams->create($body, $context),
            fn(int $id, array $body) => $this->teams->update($id, $body, $context), $context);
    }

    /** Tournées : reconnues par leur numéro, sinon par leur nom ; sans numéro ni nom connu = nouvelle tournée. */
    private function importTours(array $rows, ApiContext $context): array
    {
        $byId = [];
        $byName = [];
        foreach ($this->logisticsRepo->tours() as $t) {
            $byId[(int) $t['id']] = ['id' => (int) $t['id'], 'name' => $t['name'], 'departure_time' => $t['departure_time'],
                'days' => self::maskDays((int) $t['weekdays']), 'active' => (bool) $t['active'], 'notes' => $t['notes']];
            $byName[mb_strtolower($t['name'])] = (int) $t['id'];
        }
        foreach ($rows as &$row) {
            $id = $row['cells']['id'] ?? null;
            $name = $row['cells']['name'] ?? null;
            $row['key'] = is_int($id) ? '#' . $id : ($name !== null ? mb_strtolower((string) $name) : null);
        }
        unset($row);
        $find = function (?string $key) use ($byId, $byName): ?array {
            if ($key === null) return null;
            if (str_starts_with($key, '#')) {
                $id = (int) substr($key, 1);
                return $byId[$id] ?? throw new ApiException('DATA_SHEET_TOUR_NOT_FOUND', 404, [], ['id' => $id]);
            }
            return isset($byName[$key]) ? $byId[$byName[$key]] : null;
        };
        $userId = $context->userId;
        return $this->importRows('tours', $rows, fn($row) => $row['key'], $find,
            function (array $body) { unset($body['id']); return $body; },
            function (array $body) use ($userId, $context) {
                $id = $this->logistics->createTour($body, $userId);
                $this->audit->record($context, 'create', 'tour', $id, null, $body);
            },
            function (int $id, array $body) use ($userId, $context, $byId) {
                $this->logistics->updateTour($id, $body, $userId);
                $this->audit->record($context, 'update', 'tour', $id, $byId[$id] ?? null, $body);
            }, $context, ['id'], fn($row) => $row['cells']['name'] ?? ('#' . ($row['cells']['id'] ?? '?')));
    }

    private function importParams(array $rows, ApiContext $context): array
    {
        $current = [];
        foreach (self::PARAMS as $code) {
            $p = $this->paramRepo->findByCode($code);
            if ($p !== null) $current[$code] = ['id' => 0, 'code' => $code, 'type' => $p['value_type'], 'value' => self::normalize($p['value_type'], $p['value'])];
        }
        $canWrite = $context->canSomewhere('prod.params.write');
        // Valeur lue selon le type du paramètre (entier, décimal, heure, oui/non).
        foreach ($rows as &$row) {
            $code = $row['cells']['code'] ?? null;
            if (isset($current[$code]) && array_key_exists('value', $row['cells'])) {
                $row['cells']['value'] = self::normalize($current[$code]['type'], $row['raw']['value'] ?? null);
            }
        }
        unset($row);
        // Règles entre paramètres (alerte urgente ≥ alerte…) sur l'état final du fichier, comme la page Pozostałe.
        $fileValues = [];
        foreach ($rows as $row) {
            $code = $row['cells']['code'] ?? null;
            if (isset($current[$code]) && array_key_exists('value', $row['cells']) && $row['cells']['value'] !== null) $fileValues[$code] = (string) $row['cells']['value'];
        }
        $orderErrors = ParamRules::orderErrors($fileValues, fn($c) => $this->paramRepo->findByCode($c)['value'] ?? null);
        return $this->importRows('params', $rows, fn($row) => $row['cells']['code'] ?? null,
            fn($key) => $current[$key] ?? throw new ApiException('DATA_SHEET_UNKNOWN_PARAM', 422, [], ['code' => (string) $key]),
            function (array $body) use ($canWrite) {
                if (!$canWrite) throw new ApiException('FORBIDDEN', 403, [], ['permission' => 'prod.params.write']);
                return ['value' => $body['value'] ?? null];
            },
            fn(array $body) => null,
            fn(int $id, array $body, string $key) => isset($orderErrors[$key])
                ? throw ApiException::validation([['field' => 'value', 'code' => $orderErrors[$key][0], 'params' => []]])
                : $this->params->update($key, $body, $context, false), $context, ['code']);
    }

    /**
     * Une feuille, ligne par ligne : clé vide ou en double = erreur ; sinon comparaison aux valeurs actuelles
     * (seuls les champs changés partent au service) puis création ou modification par le service.
     *
     * @param callable(array):?string $keyOf clé de la ligne
     * @param callable(string):?array $currentOf valeurs actuelles (mêmes champs que la feuille, + id) ou null
     * @param callable(array):array $prepare champs changés → corps du service (références résolues)
     * @param list<string> $skip champs jamais envoyés (clé technique)
     */
    private function importRows(string $sheet, array $rows, callable $keyOf, callable $currentOf, callable $prepare,
                                callable $create, callable $update, ApiContext $context, array $skip = [], ?callable $label = null): array
    {
        $columns = self::SHEETS[$sheet][1];
        $info = self::INFO_COLUMNS[$sheet] ?? [];
        $nullable = self::NULLABLE[$sheet] ?? [];
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $key = $keyOf($row);
            $result = ['sheet' => $sheet, 'row' => $row['row'], 'key' => (string) ($label ? $label($row) : $key), 'action' => 'unchanged', 'changes' => [], 'errors' => []];
            try {
                if ($key === null || $key === '') {
                    throw ApiException::validation([['field' => self::KEY_COLUMNS[$sheet][0], 'code' => 'REQUIRED', 'params' => []]]);
                }
                if (isset($seen[$key])) throw new ApiException('DATA_SHEET_DUPLICATE_ROW', 422, [], ['key' => $result['key'], 'row' => $seen[$key]]);
                $seen[$key] = $row['row'];
                $before = $currentOf($key);
                $body = [];
                foreach ($row['cells'] as $field => $value) {
                    if (in_array($field, $info, true) || in_array($field, $skip, true)) continue;
                    if ($value === null && !in_array($field, $nullable, true)) continue;
                    if ($before !== null && self::same($before[$field] ?? null, $value)) continue;
                    $body[$field] = $value;
                    $result['changes'][] = ['field' => $columns[$field][0], 'before' => $before === null ? null : self::display($before[$field] ?? null, $context),
                        'after' => self::display($value, $context)];
                }
                if ($before === null || $body) {
                    $payload = $prepare($body);
                    $before === null ? $create($payload) : $update($before['id'], $payload, $key);
                    $result['action'] = $before === null ? 'create' : 'update';
                }
            } catch (ApiException $e) {
                $result['errors'] = $this->messages($e, $context);
            } catch (CustomException $e) {
                $result['errors'] = [$e->getMessage()];
            }
            if ($result['errors']) {
                $result['action'] = 'error';
            }
            $out[] = $result;
        }
        return $out;
    }

    /** Code de groupe / de shift → identifiant pour le service. */
    private function resolve(array $body, string $field, string $target, array $codes, string $error): array
    {
        if (!array_key_exists($field, $body)) return $body;
        $code = $body[$field];
        unset($body[$field]);
        if (!isset($codes[$code])) throw new ApiException($error, 422, [], ['code' => (string) $code]);
        $body[$target] = $codes[$code];
        return $body;
    }

    /** @return list<string> messages traduits d'une erreur de service */
    private function messages(ApiException $e, ApiContext $context): array
    {
        $t = $context->translator;
        if ($e->fieldErrors()) {
            return array_map(fn($err) => $t->fieldError(preg_replace('/^.*\./', '', $err['field']), $err['code'], $err['params'] ?? []), $e->fieldErrors());
        }
        $params = $e->params();
        if (isset($params['entity'])) $params['entity'] = $t->entity((string) $params['entity']);
        return [$t->error($e->errorCode(), $params)];
    }

    /* ---------- lecture du fichier ---------- */

    /** @return array<string, list<array{row:int, cells:array<string,mixed>, raw:array<string,mixed>}>> */
    private function read(mixed $file): array
    {
        if (!is_string($file) || trim($file) === '') {
            throw ApiException::validation([['field' => 'file', 'code' => 'REQUIRED', 'params' => []]]);
        }
        $binary = base64_decode(preg_replace('/^data:[^,]*,/', '', trim($file)), true);
        if ($binary === false || !str_starts_with($binary, 'PK')) throw new ApiException('DATA_SHEET_INVALID_FILE', 422);
        if (strlen($binary) > self::MAX_BYTES) throw new ApiException('DATA_SHEET_TOO_LARGE', 422, [], ['max' => '2 MB']);

        $tmp = tempnam(sys_get_temp_dir(), 'tfbsheet');
        try {
            file_put_contents($tmp, $binary);
            $reader = new XlsxReader();
            $reader->setReadDataOnly(true);
            $book = $reader->load($tmp);
        } catch (\Throwable) {
            throw new ApiException('DATA_SHEET_INVALID_FILE', 422);
        } finally {
            @unlink($tmp);
        }

        $out = [];
        foreach (self::SHEETS as $key => [$title, $columns]) {
            $sheet = self::findSheet($book, $title);
            if ($sheet === null) continue;
            $map = [];
            $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
            for ($c = 1; $c <= $lastColumn; $c++) {
                $header = self::header((string) self::cell($sheet, $c, 1));
                foreach ($columns as $field => [$label]) {
                    if ($header !== '' && ($header === self::header($label) || $header === $field)) $map[$c] = $field;
                }
            }
            foreach (self::KEY_COLUMNS[$key] as $field) {
                if (!in_array($field, $map, true)) {
                    throw new ApiException('DATA_SHEET_MISSING_COLUMN', 422, [], ['sheet' => $title, 'column' => $columns[$field][0]]);
                }
            }
            $rows = [];
            for ($r = 2, $last = $sheet->getHighestDataRow(); $r <= $last; $r++) {
                $cells = [];
                $raw = [];
                foreach ($map as $c => $field) {
                    $value = self::cell($sheet, $c, $r);
                    $raw[$field] = $value;
                    $cells[$field] = self::normalize($columns[$field][1], $value);
                }
                if (array_filter($cells, fn($v) => $v !== null) === []) continue;
                $rows[] = ['row' => $r, 'cells' => $cells, 'raw' => $raw];
            }
            $out[$key] = $rows;
        }
        if (!$out) throw new ApiException('DATA_SHEET_NO_SHEET', 422);
        return $out;
    }

    /** Feuille par son titre, ou par son premier mot (« Stanowiska ») si elle a été renommée. */
    private static function findSheet(Spreadsheet $book, string $title): ?Worksheet
    {
        $word = mb_strtolower(strtok($title, ' '));
        foreach ($book->getAllSheets() as $sheet) {
            $name = mb_strtolower(trim($sheet->getTitle()));
            if ($name === mb_strtolower($title) || $name === $word || str_starts_with($name, $word . ' ')) return $sheet;
        }
        return null;
    }

    private static function cell(Worksheet $sheet, int $column, int $row): mixed
    {
        $cell = $sheet->getCell([$column, $row]);
        try {
            $value = $cell->getCalculatedValue();
        } catch (\Throwable) {
            $value = $cell->getValue();
        }
        return $value instanceof RichText ? $value->getPlainText() : $value;
    }

    private static function header(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', str_replace('*', '', $text))));
    }

    /** Valeur de cellule → valeur comparable (null si vide) ; une valeur illisible reste telle quelle pour l'erreur du service. */
    private static function normalize(string $type, mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim(preg_replace('/\s+/u', ' ', $value));
            if ($value === '') return null;
        }
        if ($value === null) return null;
        switch ($type) {
            case 'code':
            case 'group':
            case 'shift':
                return strtoupper(self::text($value));
            case 'choice':
                return mb_strtolower(self::text($value));
            case 'decimal':
                return self::number($value);
            case 'int':
                $n = self::number($value);
                return is_float($n) && floor($n) === $n && abs($n) < 1e9 ? (int) $n : $n;
            case 'bool':
                return self::bool($value);
            case 'time':
                return self::time($value);
            case 'days':
                return self::days($value);
            case 'param':
                return $value;
            default:
                return self::text($value);
        }
    }

    private static function text(mixed $value): string
    {
        if (is_bool($value)) return $value ? '1' : '0';
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) return (string) (int) $value;
        return trim((string) $value);
    }

    private static function number(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) return (float) $value;
        $clean = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], (string) $value);
        return is_numeric($clean) ? (float) $clean : $value;
    }

    private static function bool(mixed $value): mixed
    {
        if (is_bool($value)) return $value;
        if (is_int($value) || is_float($value)) return $value == 1 ? true : ($value == 0 ? false : $value);
        $v = mb_strtolower((string) $value);
        if (in_array($v, ['tak', 't', 'oui', 'o', 'yes', 'y', 'x', '1', 'true', 'prawda', 'vrai'], true)) return true;
        if (in_array($v, ['nie', 'n', 'non', 'no', '0', 'false', 'fałsz', 'faux'], true)) return false;
        return $value;
    }

    /** « 6:00 », « 06h00 », « 6 » ou une heure Excel (fraction de jour) → « 06:00 ». */
    private static function time(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            if ($value >= 0 && $value < 1) {
                $minutes = (int) round($value * 1440) % 1440;
                return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
            }
            if (is_int($value) || floor($value) === (float) $value) $value = (string) (int) $value;
        }
        if (is_string($value) && preg_match('/^(\d{1,2})(?:\s*[:hH.]\s*(\d{2})?)?(?::\d{2})?$/', $value, $m)) {
            $h = (int) $m[1];
            $min = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
            if ($h <= 23 && $min <= 59) return sprintf('%02d:%02d', $h, $min);
        }
        return $value;
    }

    /** « 1,2,3 », « 1-5 », « 1;3;5 » → [1,2,3…] (1 = lundi … 7 = dimanche). */
    private static function days(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) $value = self::text($value);
        $days = [];
        foreach (preg_split('/[\s,;\/]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY) as $part) {
            if (preg_match('/^([1-7])\s*[-–]\s*([1-7])$/u', $part, $m) && $m[1] <= $m[2]) {
                $days = [...$days, ...range((int) $m[1], (int) $m[2])];
            } elseif (preg_match('/^[1-7]$/', $part)) {
                $days[] = (int) $part;
            } else {
                return $value;
            }
        }
        if (!$days) return $value;
        $days = array_values(array_unique($days));
        sort($days);
        return $days;
    }

    private static function dayList(string $csv): array
    {
        $days = array_map('intval', array_filter(explode(',', $csv), fn($d) => $d !== ''));
        sort($days);
        return $days;
    }

    private static function maskDays(int $mask): array
    {
        return array_values(array_filter(range(1, 7), fn($d) => $mask & (1 << ($d - 1))));
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) return abs($a - $b) < 1e-9;
        return $a === $b;
    }

    private static function display(mixed $value, ApiContext $context): ?string
    {
        if ($value === null) return null;
        if (is_bool($value)) return self::yesNo($value, $context->translator->lang());
        if (is_array($value)) return implode(',', $value);
        if (is_float($value)) return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
        return (string) $value;
    }

    private static function yesNo(bool $value, string $lang): string
    {
        return $lang === 'fr' ? ($value ? 'oui' : 'non') : ($value ? 'tak' : 'nie');
    }

    /* ---------- classeur à télécharger ---------- */

    private function build(ApiContext $context): Spreadsheet
    {
        $lang = $context->translator->lang();
        $yn = fn($v) => self::yesNo((bool) $v, $lang);
        $book = new Spreadsheet();
        $book->getProperties()->setCreator('ERP TFB')->setTitle('Tabela do wypełnienia — Tableau à remplir');

        $groups = $this->groups->list(null);
        $groupById = array_column($groups, 'code', 'id');
        $shifts = $this->shiftRepo->list(null);
        $shiftById = array_column($shifts, 'code', 'id');
        $units = json_decode($this->paramRepo->get('workstation.capacity_units', '["piece"]'), true) ?: ['piece'];

        $this->instructions($book->getActiveSheet(), $units);

        $this->sheet($book, 'workstations', array_map(fn($w) => [
            $w['code'], $groupById[$w['id_group']] ?? '', $w['name_pl'], $w['name_fr'], $w['type'], $w['robot_mode'] ?? '',
            $w['operator_share'] !== null ? (float) $w['operator_share'] : '', (float) $w['capacity_per_pass'], $w['capacity_unit'],
            (float) $w['hourly_cost'], $yn($w['active']), $w['notes'] ?? '',
        ], $this->workstationRepo->list(null, null, null)), [
            'group' => "'" . self::GROUPS_TITLE . "'!\$A\$2:\$A\$" . self::ROWS,
            'type' => '"' . implode(',', WorkstationService::TYPES) . '"',
            'robot_mode' => '"' . implode(',', WorkstationService::ROBOT_MODES) . '"',
            'capacity_unit' => '"' . implode(',', array_map('strval', $units)) . '"',
            'active' => '"' . $yn(true) . ',' . $yn(false) . '"',
        ]);

        // Bez zmiany nocnej [235] : tylko zmiany dzienne w tabeli (zmiana nocna nieaktywna, nie do wypełnienia).
        $nightIds = array_map('intval', array_column(array_filter($shifts, fn($s) => (int) $s['is_night'] === 1), 'id'));
        $this->sheet($book, 'shifts', array_map(fn($s) => [
            $s['code'], $s['name_pl'], $s['name_fr'], substr((string) $s['start_time'], 0, 5), substr((string) $s['end_time'], 0, 5),
            (string) $s['active_days'], (float) $s['premium_pct'], $yn($s['is_night']), (int) $s['sort_order'], $yn($s['active']),
        ], array_values(array_filter($shifts, fn($s) => (int) $s['is_night'] !== 1))), ['is_night' => '"' . $yn(true) . ',' . $yn(false) . '"', 'active' => '"' . $yn(true) . ',' . $yn(false) . '"']);

        $this->sheet($book, 'teams', array_map(fn($t) => [
            $t['code'], $groupById[$t['id_group']] ?? '', $shiftById[$t['id_shift_template']] ?? '', $t['name'],
            (int) $t['planned_headcount'], $yn($t['active']),
        ], array_values(array_filter($this->teamRepo->list(null, null, null), fn($t) => !in_array((int) $t['id_shift_template'], $nightIds, true)))), [
            'group' => "'" . self::GROUPS_TITLE . "'!\$A\$2:\$A\$" . self::ROWS,
            'shift' => "'" . self::SHEETS['shifts'][0] . "'!\$A\$2:\$A\$" . self::ROWS,
            'active' => '"' . $yn(true) . ',' . $yn(false) . '"',
        ]);

        $this->sheet($book, 'tours', array_map(fn($t) => [
            (int) $t['id'], $t['name'], $t['departure_time'] ?? '', implode(',', self::maskDays((int) $t['weekdays'])), $yn($t['active']), $t['notes'] ?? '',
        ], $this->logisticsRepo->tours()), ['active' => '"' . $yn(true) . ',' . $yn(false) . '"']);

        $paramRows = [];
        foreach (self::PARAMS as $code) {
            $p = $this->paramRepo->findByCode($code);
            if ($p === null) continue;
            $value = match ($p['value_type']) {
                'int' => (int) $p['value'], 'decimal' => (float) $p['value'], 'bool' => $yn($p['value'] === '1'), default => (string) $p['value'],
            };
            $paramRows[] = [$code, $p['description_' . $lang] ?: $p['description_pl'], $p['unit'] ?? '', $value, $p['default_value']];
        }
        $this->sheet($book, 'params', $paramRows, []);

        $ref = $book->createSheet();
        $ref->setTitle(self::GROUPS_TITLE);
        $ref->fromArray([['Kod / Code', 'Nazwa PL', 'Nom FR'], ...array_map(fn($g) => [$g['code'], $g['name_pl'], $g['name_fr']], $groups)], null, 'A1');
        self::styleHeader($ref, 3);

        $book->setActiveSheetIndex(0);
        return $book;
    }

    /** @param array<string,string> $lists champ => formule de liste déroulante */
    private function sheet(Spreadsheet $book, string $key, array $rows, array $lists): void
    {
        [$title, $columns] = self::SHEETS[$key];
        $sheet = $book->createSheet();
        $sheet->setTitle($title);
        $sheet->fromArray([array_column($columns, 0)], null, 'A1');
        if ($rows) $sheet->fromArray($rows, null, 'A2', true);
        self::styleHeader($sheet, count($columns));
        $i = 0;
        foreach ($columns as $field => [, $type]) {
            $letter = Coordinate::stringFromColumnIndex(++$i);
            $range = "{$letter}2:{$letter}" . self::ROWS;
            // Heures, jours et codes en texte : Excel ne les transforme pas en date ou en nombre.
            if (in_array($type, ['time', 'days', 'code', 'group', 'shift'], true) || ($key === 'params' && $field === 'value')) {
                $sheet->getStyle($range)->getNumberFormat()->setFormatCode('@');
            }
            if (isset($lists[$field])) {
                $dv = new DataValidation();
                $dv->setType(DataValidation::TYPE_LIST)->setAllowBlank(true)->setShowDropDown(true)->setShowErrorMessage(false)
                    ->setFormula1($lists[$field]);
                $sheet->setDataValidation($range, $dv);
            }
        }
        if ($key === 'params') {
            foreach (['A', 'B', 'C', 'E'] as $letter) {
                $sheet->getStyle("{$letter}2:{$letter}" . (count($rows) + 1))->getFont()->getColor()->setRGB('6C757D');
            }
            $sheet->getStyle('D2:D' . (count($rows) + 1))->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF8E1');
        }
    }

    private static function styleHeader(Worksheet $sheet, int $count): void
    {
        $last = Coordinate::stringFromColumnIndex($count);
        $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A1:{$last}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('B71C1C');
        $sheet->getStyle("A1:{$last}1")->getAlignment()->setWrapText(true)->setVertical('center');
        $sheet->getRowDimension(1)->setRowHeight(42);
        $sheet->freezePane('A2');
        for ($c = 1; $c <= $count; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth($c === 1 ? 20 : 22);
        }
    }

    private function instructions(Worksheet $sheet, array $units): void
    {
        $sheet->setTitle('Instrukcja (mode d’emploi)');
        $lines = [
            ['Tabela do wypełnienia — dane rzeczywiste pracowni', 'Tableau à remplir — données réelles de l’atelier'],
            ['', ''],
            ['Plik zawiera obecne dane ERP. Popraw wartości albo dopisz nowe wiersze na dole arkusza.', 'Le fichier contient les données actuelles de l’ERP. Corrigez les valeurs ou ajoutez des lignes en bas de la feuille.'],
            ['Stanowiska : maszyny, piece, coboty i ich pojemność na przejście.', 'Postes : machines, fours, cobots et leur capacité par passage.'],
            ['Zmiany : godziny pracy (początek, koniec, dni).', 'Horaires : début, fin et jours de chaque shift.'],
            ['Zespoły : planowana obsada każdej grupy na każdej zmianie.', 'Équipes : effectif prévu de chaque groupe sur chaque shift.'],
            ['Trasy : godzina wyjazdu ciężarówki i dni każdej trasy.', 'Tournées : heure de départ du camion et jours de chaque tournée.'],
            ['Parametry : m.in. pojemność zamrażarki w kartonach — wpisz tylko kolumnę « Wartość ».', 'Paramètres : dont la capacité du surgélateur en cartons — remplissez seulement la colonne « Valeur ».'],
            ['', ''],
            ['Wiersz rozpoznawany jest po kodzie (Trasy : po numerze, a bez numeru po nazwie). Nowy kod = nowy wiersz w ERP.', 'Une ligne est reconnue par son code (Tournées : par son numéro, sinon par son nom). Nouveau code = nouvelle ligne dans l’ERP.'],
            ['Kolumny z * są obowiązkowe w nowym wierszu. Pusta komórka = bez zmian (Uwagi, Tryb robota, Udział operatora, Godzina wyjazdu : pusta = brak).', 'Les colonnes avec * sont obligatoires pour une nouvelle ligne. Cellule vide = inchangé (Remarques, Mode du robot, Part opérateur, Heure de départ : vide = aucune valeur).'],
            ['Wiersz usunięty z pliku nie jest usuwany z ERP — aby wyłączyć, wpisz « nie » w kolumnie Aktywne.', 'Une ligne retirée du fichier n’est pas supprimée de l’ERP — pour la désactiver, mettez « non » dans la colonne Actif.'],
            ['Godziny : HH:MM (np. 06:00). Dni : 1 = poniedziałek … 7 = niedziela, np. 1,2,3,4,5 albo 1-6.', 'Heures : HH:MM (par ex. 06:00). Jours : 1 = lundi … 7 = dimanche, par ex. 1,2,3,4,5 ou 1-6.'],
            ['Grupy : kody w arkuszu « Grupy (groupes) ».', 'Groupes : codes dans la feuille « Grupy (groupes) ».'],
            ['', ''],
            ['Typy stanowisk : manuel = ręczne, machine = maszyna, four = piec, robot, fermentation = komora fermentacyjna, froid = chłodnia, surgelation = zamrażanie.', 'Types de poste : manuel, machine, four, robot, fermentation, froid (chambre froide), surgelation (surgélation).'],
            ['Robot : podaj tryb — autonome (bez obsługi, także w nocy), avec_operateur (z operatorem), collaboratif (cobot) + udział operatora 0–1 (np. 0,25 = 1 osoba na 4 coboty).', 'Robot : indiquez le mode — autonome (sans opérateur, aussi la nuit), avec_operateur, collaboratif (cobot) + part de l’opérateur 0–1 (par ex. 0,25 = 1 personne pour 4 cobots).'],
            ['Jednostki pojemności : ' . implode(', ', $units) . ' (piece = szt., plaque = blacha, chariot = wózek, bac = pojemnik).', 'Unités de capacité : ' . implode(', ', $units) . ' (piece = pièce, plaque, chariot, bac).'],
            ['', ''],
            ['Potem : Produkcja › Tabela do wypełnienia › « Wyślij plik ». Najpierw podgląd zmian — nic nie jest zapisane przed « Zastosuj ».', 'Ensuite : Produkcja › Tabela do wypełnienia › « Envoyer le fichier ». D’abord l’aperçu des changements — rien n’est enregistré avant « Appliquer ».'],
        ];
        $sheet->fromArray($lines, null, 'A1');
        $sheet->getStyle('A1:B1')->getFont()->setBold(true)->setSize(14);
        $sheet->getColumnDimension('A')->setWidth(90);
        $sheet->getColumnDimension('B')->setWidth(90);
        $sheet->getStyle('A1:B' . count($lines))->getAlignment()->setWrapText(true)->setVertical('top');
    }
}
