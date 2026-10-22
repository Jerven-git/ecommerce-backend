<?php

namespace App\Support\Database;

use Closure;
use Illuminate\Support\Facades\DB;

final class Transaction
{
    public const CONCURRENCY_ATTEMPTS = 3;

    public static function run(Closure $callback): mixed
    {
        return DB::transaction($callback, self::CONCURRENCY_ATTEMPTS);
    }
}
