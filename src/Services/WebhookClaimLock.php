<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use Closure;
use Nvl\Payments\Definitions\Tables\PaymentsTables;
use PDO;

/**
 * Serializes work for an inbox receipt across worker processes without a DB transaction.
 * Session locks are released when a database connection closes. SQLite uses OS file locks.
 *
 * @internal
 */
final class WebhookClaimLock
{
    /** @param Closure():void $work */
    public function run(string $id, Closure $work): void
    {
        $connection = PaymentsModelRegistry::newAttempt()->getConnection();
        $database = $connection->getDatabaseName();
        if ($database === '') {
            throw new \LogicException('Payments inbox requires a named database.');
        }
        $table = PaymentsTables::get(PaymentsTables::WebhookInbox);
        $key = hash('sha256', $database.':'.$table.':'.$id);
        $driver = $connection->getDriverName();
        if ($driver === 'sqlite') {
            $this->fileLock($key, $work);

            return;
        }

        $pdo = $connection->getPdo();
        if ($driver !== 'pgsql') {
            throw new \LogicException('The Payments inbox supports SQLite and PostgreSQL.');
        }
        if (! in_array($this->execute($pdo, 'SELECT pg_try_advisory_lock(hashtextextended(?, 0))', $key), [true, 1, '1', 't'], true)) {
            return;
        }
        try {
            $work();
        } finally {
            $this->execute($pdo, 'SELECT pg_advisory_unlock(hashtextextended(?, 0))', $key);
        }
    }

    private function execute(PDO $pdo, string $sql, string $key): mixed
    {
        $statement = $pdo->prepare($sql);
        $statement->execute([$key]);

        return $statement->fetchColumn();
    }

    /** @param Closure():void $work */
    private function fileLock(string $key, Closure $work): void
    {
        $file = fopen(sys_get_temp_dir().'/nvl-payments-inbox-'.substr($key, 0, 2).'.lock', 'c');
        if ($file === false) {
            throw new \RuntimeException('Could not open a local Payments inbox lock.');
        }
        try {
            if (flock($file, LOCK_EX | LOCK_NB)) {
                $work();
            }
        } finally {
            fclose($file);
        }
    }
}
