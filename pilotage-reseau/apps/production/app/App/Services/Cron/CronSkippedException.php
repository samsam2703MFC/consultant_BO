<?php
declare(strict_types=1);

namespace App\Services\Cron;

use RuntimeException;

final class CronSkippedException extends RuntimeException
{
}
