<?php
namespace App\Core;

use PDO;
use PDOException;

/**
 * Thin PDO wrapper. Supports mysql / sqlsrv / pgsql so the app DB and the
 * biometric source DB can be different engines.
 */
class Database
{
    private PDO $pdo;
    private string $driver;
    private static ?Database $app = null;

    public function __construct(array $cfg)
    {
        $this->driver = $cfg['driver'] ?? 'mysql';
        $this->pdo = new PDO(
            self::dsn($cfg),
            $cfg['username'] ?? null,
            $cfg['password'] ?? null,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }

    /** Shared application-database instance. */
    public static function app(): Database
    {
        if (self::$app === null) {
            $cfg = Config::get('db');
            self::$app = new self($cfg);
        }
        return self::$app;
    }

    private static function dsn(array $cfg): string
    {
        $driver = $cfg['driver'] ?? 'mysql';
        $host   = $cfg['host'] ?? '127.0.0.1';
        $port   = $cfg['port'] ?? null;
        $db     = $cfg['database'] ?? '';
        switch ($driver) {
            case 'sqlite':
                return 'sqlite:' . $db;   // $db is a file path (or :memory:)
            case 'sqlsrv':
                $p = $port ? ",{$port}" : '';
                return "sqlsrv:Server={$host}{$p};Database={$db};TrustServerCertificate=true";
            case 'pgsql':
                $p = $port ?: 5432;
                return "pgsql:host={$host};port={$p};dbname={$db}";
            case 'mysql':
            default:
                $p = $port ?: 3306;
                $charset = $cfg['charset'] ?? 'utf8mb4';
                return "mysql:host={$host};port={$p};dbname={$db};charset={$charset}";
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function value(string $sql, array $params = [])
    {
        $row = $this->run($sql, $params)->fetch(PDO::FETCH_NUM);
        return $row === false ? null : $row[0];
    }

    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $ph   = array_map(fn($c) => ':' . $c, $cols);
        $sql  = "INSERT INTO {$table} (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")";
        $this->run($sql, $data);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = implode(', ', array_map(fn($c) => "{$c} = :{$c}", array_keys($data)));
        $sql = "UPDATE {$table} SET {$set} WHERE {$where}";
        return $this->run($sql, array_merge($data, $whereParams))->rowCount();
    }

    /**
     * Append a driver-appropriate row limit to a query that already ends with
     * an ORDER BY. SQL Server uses OFFSET/FETCH; everything else uses LIMIT.
     */
    public function limit(string $sql, int $n, int $offset = 0): string
    {
        if ($this->driver === 'sqlsrv') {
            return $sql . " OFFSET {$offset} ROWS FETCH NEXT {$n} ROWS ONLY";
        }
        return $sql . " LIMIT {$n} OFFSET {$offset}";
    }

    public function driver(): string { return $this->driver; }

    /** True if the column is an IDENTITY column (SQL Server). Other drivers: false. */
    public function isIdentity(string $table, string $col): bool
    {
        if ($this->driver === 'sqlsrv') {
            try {
                return (int) $this->value(
                    "SELECT COLUMNPROPERTY(OBJECT_ID(:t), :c, 'IsIdentity')",
                    [':t' => $table, ':c' => $col]
                ) === 1;
            } catch (\Throwable $e) {
                return false;
            }
        }
        return false; // mysql/sqlite tolerate an explicit id
    }

    /** Next id for a non-identity key column: MAX(col)+1. */
    public function nextId(string $table, string $col): int
    {
        return (int) $this->value("SELECT COALESCE(MAX({$col}), 0) + 1 FROM {$table}");
    }

    /**
     * Insert into a legacy table whose primary key may have lost its IDENTITY
     * property (SELECT INTO drops IDENTITY). If the caller didn't supply the id
     * and the column isn't an identity, fill it with MAX(id)+1 so the insert
     * can't fail with "Cannot insert NULL into <id>". Returns the row id.
     */
    public function insertLegacy(string $table, array $data, string $idCol = 'ID'): int
    {
        if (!array_key_exists($idCol, $data) && !$this->isIdentity($table, $idCol)) {
            $id = $this->nextId($table, $idCol);
            $this->insert($table, [$idCol => $id] + $data);
            return $id;
        }
        return $this->insert($table, $data);
    }

    public function begin(): void  { $this->pdo->beginTransaction(); }
    public function commit(): void { $this->pdo->commit(); }
    public function rollback(): void { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); }
}
