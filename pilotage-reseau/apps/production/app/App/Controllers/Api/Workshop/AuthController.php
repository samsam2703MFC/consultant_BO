<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Core\Api\Workshop\Validator;
use App\Services\Workshop\Auth\AuthService;

final class AuthController
{
    public function __construct(private AuthService $auth)
    {
    }

    public function login(ApiRequest $request): ApiResult
    {
        $v = (new Validator($request->body()))->only(['login', 'password'])
            ->string('login', 255);
        if (!is_string($request->body()['password'] ?? null) || $request->body()['password'] === '') {
            $v->add('password', 'REQUIRED');
        }
        $v->throwIfInvalid();
        $tokens = $this->auth->login($v->value('login'), $request->body()['password'], $this->ip($request), $request->http->headers->get('User-Agent'));
        return new ApiResult($this->publicTokens($tokens));
    }

    public function refresh(ApiRequest $request): ApiResult
    {
        $token = $this->refreshToken($request);
        return new ApiResult($this->publicTokens($this->auth->refresh($token, $this->ip($request), $request->http->headers->get('User-Agent'))));
    }

    public function logout(ApiRequest $request): ApiResult
    {
        $this->auth->logout($this->refreshToken($request));
        return new ApiResult(['logged_out' => true]);
    }

    public function me(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->auth->me($request->context));
    }

    private function refreshToken(ApiRequest $request): string
    {
        $v = (new Validator($request->body()))->only(['refresh_token'])->string('refresh_token', 255);
        $v->throwIfInvalid();
        return $v->value('refresh_token');
    }

    private function publicTokens(array $tokens): array
    {
        unset($tokens['_refresh_id']);
        return $tokens;
    }

    private function ip(ApiRequest $request): string
    {
        return (string) ($request->http->getClientIp() ?? '0.0.0.0');
    }
}
