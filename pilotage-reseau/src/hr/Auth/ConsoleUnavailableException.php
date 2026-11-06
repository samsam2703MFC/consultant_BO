<?php

declare(strict_types=1);

namespace Hr\Auth;

/** La console franchisé ne sait pas (encore) dire qui est connecté : pas configurée, ou injoignable. */
final class ConsoleUnavailableException extends \RuntimeException
{
}
