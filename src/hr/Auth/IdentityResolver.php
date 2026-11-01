<?php

declare(strict_types=1);

namespace Hr\Auth;

use Hr\Http\Request;

interface IdentityResolver
{
    /** L'identité de l'appelant, ou null s'il n'est pas connecté. */
    public function resolve(Request $r): ?Identity;

    /** @return array{bo: ?string, csrf: ?string} */
    public function lastSession(): array;
}
