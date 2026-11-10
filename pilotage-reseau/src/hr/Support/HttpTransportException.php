<?php

declare(strict_types=1);

namespace Hr\Support;

/** Le réseau n'a pas répondu (DNS, connexion, délai) : rien à voir avec un statut HTTP. */
final class HttpTransportException extends \RuntimeException
{
}
