<?php

declare(strict_types=1);

namespace Core;

use \PDO;
use \PDOException;
/**
 * Database Connection (Singleton)
 * 
 * مدیریت اتصال به دیتابیس با PDO
 */
class Database
{
    private static $instance = null;
    private $pdo;
    private $queryBuilder;

    /**
     * Constructor (Private)
     */
    private function __construct()
    {
        $config = config('database');
        
        $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['name']};charset={$config['charset']}";
        
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_OBJ, // ✅ Object به جای Array
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$config['charset']} COLLATE utf8mb4_unicode_ci"
        ];
        
        try {
            $this->pdo = new \PDO($dsn, $config['user'], $config['pass'], $options);
        } catch (\PDOException $e) {
            throw new \Exception("Database connection failed: " . $e->getMessage());
        }
        
        $this->queryBuilder = new QueryBuilder($this->pdo);
    }

    /**
     * دریافت Instance (Singleton)
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
	
public function prepare(string $sql): \PDOStatement
{
    return $this->pdo->prepare($sql);
}

    /**
     * دریافت PDO
     */
    public function getPdo()
    {
        return $this->pdo;
    }

    /**
     * دریافت Query Builder
     */
    public function table($table)
    {
        return $this->queryBuilder->table($table);
    }
	
	public function fetch(string $sql, array $params = []): ?object
{
    $stmt = $this->pdo->prepare($sql);

    foreach ($params as $key => $value) {
        $param = \is_int($key) ? $key + 1 : ':' . $key;

        $type = \PDO::PARAM_STR;
        if (\is_int($value)) $type = \PDO::PARAM_INT;
        elseif (\is_bool($value)) $type = \PDO::PARAM_BOOL;
        elseif ($value === null) $type = \PDO::PARAM_NULL;

        $stmt->bindValue($param, $value, $type);
    }

    $stmt->execute();

    $row = $stmt->fetch(\PDO::FETCH_OBJ);
    return $row ?: null;
}

public function fetchAll(string $sql, array $params = []): array
{
    $stmt = $this->pdo->prepare($sql);

    foreach ($params as $key => $value) {
        $param = \is_int($key) ? $key + 1 : ':' . $key;

        $type = \PDO::PARAM_STR;
        if (\is_int($value)) $type = \PDO::PARAM_INT;
        elseif (\is_bool($value)) $type = \PDO::PARAM_BOOL;
        elseif ($value === null) $type = \PDO::PARAM_NULL;

        $stmt->bindValue($param, $value, $type);
    }

    $stmt->execute();
    return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
}

public function fetchColumn(string $sql, array $params = [], int $column = 0)
{
    $stmt = $this->pdo->prepare($sql);

    foreach ($params as $key => $value) {
        // ✅ اصلاح: اگر $key رشته است و با : شروع نمیشه، اضافه کن
        if (is_int($key)) {
            $param = $key + 1;
        } else {
            // ✅ چک کن که قبلاً : نداشته باشه
            $param = strpos($key, ':') === 0 ? $key : ':' . $key;
        }

        $type = \PDO::PARAM_STR;
        if (\is_int($value)) {
            $type = \PDO::PARAM_INT;
        } elseif (\is_bool($value)) {
            $type = \PDO::PARAM_BOOL;
        } elseif ($value === null) {
            $type = \PDO::PARAM_NULL;
        }

        $stmt->bindValue($param, $value, $type);
    }

    $stmt->execute();
    return $stmt->fetchColumn($column);
}

    /**
     * اجرای Query مستقیم
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);

            // FIX C-10: قبلاً execute($params) همه مقادیر را به صورت string
            // ارسال می‌کرد. این با برخی integer comparison های MySQL مشکل ایجاد
            // می‌کرد. حالا مانند fetch/fetchAll نوع هر پارامتر را bind می‌کنیم.
            foreach ($params as $key => $value) {
                $param = is_int($key) ? $key + 1 : ':' . ltrim((string)$key, ':');

                $type = \PDO::PARAM_STR;
                if (is_int($value))        $type = \PDO::PARAM_INT;
                elseif (is_bool($value))   $type = \PDO::PARAM_BOOL;
                elseif ($value === null)   $type = \PDO::PARAM_NULL;

                $stmt->bindValue($param, $value, $type);
            }

            $stmt->execute();
            return $stmt;
        } catch (\PDOException $e) {
            logger('error', 'Database query failed: ' . $e->getMessage(), [
                'sql'    => $sql,
                'params' => $params,
            ]);
            throw $e;
        }
    }

/**
 * ✅ متد جدید برای دریافت نتایج
 */
public function select(string $sql, array $params = []): array
{
    $stmt = $this->query($sql, $params);
    return $stmt->fetchAll(\PDO::FETCH_OBJ);
}

    /**
     * SELECT یک رکورد
     */
    public function selectOne(string $sql, array $params = [])
{
    $stmt = $this->query($sql, $params);
    $result = $stmt->fetch(\PDO::FETCH_OBJ);
    return $result !== false ? $result : null;
}
/**
 * دریافت آخرین ID درج شده
 */
public function lastInsertId(): int
{
    return (int) $this->pdo->lastInsertId();
}
    /**
     * INSERT
     */
    public function insert($sql, $params = [])
    {
        $this->query($sql, $params);
        return $this->pdo->lastInsertId();
    }

    /**
     * UPDATE/DELETE
     */
    public function execute($sql, $params = [])
    {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * شروع Transaction
     */
    public function beginTransaction()
    {
        return $this->pdo->beginTransaction();
    }

    /**
     * Commit
     */
    public function commit()
    {
        return $this->pdo->commit();
    }

    /**
     * Rollback
     */
    public function rollback()
    {
        return $this->pdo->rollBack();
    }

    /**
     * جلوگیری از Clone
     */
    private function __clone() {}

    /**
     * جلوگیری از Unserialize
     */
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}