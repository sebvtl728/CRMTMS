<?php

namespace App\Modules\Core\Security;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A database error message carries the bound values of the statement and
 * PostgreSQL adds the key values in DETAIL (e-mails, names, file content):
 * none of it may reach the logs. Only the exception class, the SQL state
 * and the statement with its placeholders are kept.
 */
final class DatabaseErrorLog
{
    /**
     * The database error at the origin of $e, if any (itself or one of its
     * previous exceptions).
     */
    public static function queryException(Throwable $e): ?QueryException
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof QueryException) {
                return $current;
            }
        }

        return null;
    }

    /**
     * Log context describing $e: scrubbed when a database error is involved,
     * the full exception otherwise.
     *
     * @return array<string, mixed>
     */
    public static function context(Throwable $e): array
    {
        $sql = self::queryException($e);
        if ($sql === null) {
            return ['exception' => $e];
        }

        return [
            'exception_class' => $e::class,
            'sqlstate' => $sql->errorInfo[0] ?? (is_string($sql->getCode()) ? $sql->getCode() : null),
            'sql' => $sql->getSql(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
    }

    /**
     * Global reporter: logs a database error in its scrubbed shape and tells
     * the handler to stop (false), so the default report never writes the
     * message. Other exceptions continue to the default report (null).
     */
    public static function report(Throwable $e): ?bool
    {
        if (self::queryException($e) === null) {
            return null;
        }

        Log::error('Erreur de base de données', self::context($e));

        return false;
    }
}
