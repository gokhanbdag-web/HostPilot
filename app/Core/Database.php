<?php
declare(strict_types=1);

namespace HostPilot\Core;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private ?PDO $pdo = null;

    /** @param array{host:string,port?:int,database:string,username:string,password:string,charset?:string} $config */
    public function __construct(private readonly array $config)
    {
    }

    public function connection(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        foreach (['host', 'database', 'username', 'password'] as $required) {
            if (!array_key_exists($required, $this->config)) {
                throw new RuntimeException('Missing database configuration: ' . $required);
            }
        }

        $host = (string)$this->config['host'];
        $port = (int)($this->config['port'] ?? 3306);
        $database = (string)$this->config['database'];
        $charset = (string)($this->config['charset'] ?? 'utf8mb4');

        if (!preg_match('/^[a-zA-Z0-9_.:-]{1,255}$/', $host)
            || !preg_match('/^[a-zA-Z0-9_]{1,64}$/', $database)
            || !preg_match('/^[a-zA-Z0-9_]+$/', $charset)
            || $port < 1 || $port > 65535) {
            throw new RuntimeException('Invalid database connection settings.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);

        try {
            $this->pdo = new PDO($dsn, (string)$this->config['username'], (string)$this->config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        } catch (PDOException $exception) {
            throw new RuntimeException('Database connection failed.', 0, $exception);
        }

        return $this->pdo;
    }
}
