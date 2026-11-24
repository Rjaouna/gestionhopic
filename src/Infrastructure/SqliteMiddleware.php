<?php

namespace App\Infrastructure;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

#[AsMiddleware]
final class SqliteMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(array $params): Connection
            {
                $connection = parent::connect($params);
                $connection->exec('PRAGMA foreign_keys = ON');
                $connection->exec('PRAGMA busy_timeout = 5000');
                if (!($params['memory'] ?? false)) {
                    $connection->exec('PRAGMA journal_mode = WAL');
                }

                return $connection;
            }
        };
    }
}
