<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;

/* Is a migration's change in yet? For code that ships before Aron runs the migration on a
   database (venusep_migration_21.sql): it skips the new part instead of failing. Asked
   once per request and database; a static lasts one request on Apache and one test
   method under PHPUnit (processIsolation). */
class SchemaQueries
{
    public static function has(string $table, ?string $column = null): bool
    {
        static $known = [];
        $key = DB::getDatabaseName() . '.' . $table . '.' . $column;
        return $known[$key] ??= $column === null
            ? DB::selectOne('SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]) !== null
            : DB::selectOne('SELECT 1 AS x FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]) !== null;
    }
}
