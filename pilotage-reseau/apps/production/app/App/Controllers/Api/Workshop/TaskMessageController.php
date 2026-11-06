<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Services\Organization\TeamTasksService;
use App\Services\Workshop\Execution\KioskAuthService;

/**
 * Komunikaty kierownika produkcji na tabletach [232] (tablica « Zadania zespołu », makieta 8) : tablet grupy (grupa
 * tabletu albo dotknięta na tablecie ogólnym ; także przypomnienia brygadzisty), tablet pakowania (?for=packing),
 * tablet kierowcy (?for=driver). « Przeczytane » : osoba + PIN (bez PIN-u : osoba obecna, która go nie ma).
 * Komunikat znika sam, gdy zadanie się zaliczy.
 */
final class TaskMessageController
{
    private const FOR = ['group', 'packing', 'driver'];

    public function __construct(private TeamTasksService $team, private KioskAuthService $auth)
    {
    }

    public function list(ApiRequest $request): ApiResult
    {
        return ApiResult::list($this->team->tabletMessages($request->context, $this->for($request->query('for'))));
    }

    public function read(ApiRequest $request): ApiResult
    {
        $body = $request->body();
        $for = $this->for(is_string($body['for'] ?? null) ? $body['for'] : $request->query('for'));
        return new ApiResult($this->team->readTabletMessage($request->id(), $body, $request->context, $for,
            fn(array $pin) => $this->auth->verifyPin($pin, $request->context)));
    }

    private function for(?string $v): string
    {
        if ($v !== null && !in_array($v, self::FOR, true)) throw ApiException::validation([['field' => 'for', 'code' => 'INVALID_CHOICE', 'params' => ['field' => 'for', 'allowed' => implode(', ', self::FOR)]]]);
        return $v ?? 'group';
    }
}
