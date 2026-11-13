<?php

declare(strict_types=1);

namespace App\Shared;

use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Small SQL helper over yiisoft/db. Rows come back with id / *_id / is_* columns cast to int;
 * money columns stay as decimal strings.
 */
final class Db
{
    private int $transactionLevel = 0;

    public function __construct(private readonly ConnectionInterface $connection) {}

    public function connection(): ConnectionInterface
    {
        return $this->connection;
    }

    /** @return list<array<string, mixed>> */
    public function all(string $sql, array $params = []): array
    {
        $rows = $this->connection->createCommand($sql, $params)->queryAll();
        return array_map(self::normalize(...), array_values($rows));
    }

    /** @return array<string, mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->connection->createCommand($sql, $params)->queryOne();
        return $row === null || $row === false ? null : self::normalize($row);
    }

    public function scalar(string $sql, array $params = []): mixed
    {
        $value = $this->connection->createCommand($sql, $params)->queryScalar();
        return $value === false ? null : $value;
    }

    /** @return list<mixed> */
    public function column(string $sql, array $params = []): array
    {
        return array_values($this->connection->createCommand($sql, $params)->queryColumn());
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->connection->createCommand($sql, $params)->execute();
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $placeholders = array_map(static fn(string $c): string => ':' . $c, $cols);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::quote($table),
            implode(', ', array_map(self::quote(...), $cols)),
            implode(', ', $placeholders),
        );
        $this->execute($sql, self::params($row));
        return (int) $this->scalar('SELECT LAST_INSERT_ID()');
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $where column => value (AND)
     */
    public function update(string $table, array $row, array $where): int
    {
        if ($row === []) {
            return 0;
        }
        $set = [];
        $params = [];
        foreach ($row as $col => $value) {
            $set[] = self::quote($col) . ' = :s_' . $col;
            $params[':s_' . $col] = self::value($value);
        }
        [$whereSql, $whereParams] = self::where($where);
        return $this->execute(
            sprintf('UPDATE %s SET %s WHERE %s', self::quote($table), implode(', ', $set), $whereSql),
            $params + $whereParams,
        );
    }

    /**
     * @param array<string, mixed> $where
     */
    public function delete(string $table, array $where): int
    {
        [$whereSql, $params] = self::where($where);
        return $this->execute(sprintf('DELETE FROM %s WHERE %s', self::quote($table), $whereSql), $params);
    }

    /**
     * Runs $fn in a transaction. Nested calls join the outer transaction.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->transactionLevel > 0) {
            $this->transactionLevel++;
            try {
                return $fn();
            } finally {
                $this->transactionLevel--;
            }
        }

        $this->execute('START TRANSACTION');
        $this->transactionLevel = 1;
        try {
            $result = $fn();
            $this->execute('COMMIT');
            return $result;
        } catch (Throwable $e) {
            $this->execute('ROLLBACK');
            throw $e;
        } finally {
            $this->transactionLevel = 0;
        }
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function quote(string $name): string
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $name)) {
            throw new \InvalidArgumentException("Bad identifier: $name");
        }
        return '`' . $name . '`';
    }

    /**
     * Builds "a IN (:p0, :p1)" safely.
     *
     * @param list<int|string> $values
     * @return array{0: string, 1: array<string, int|string>}
     */
    public static function in(string $column, array $values, string $prefix = 'in'): array
    {
        if ($values === []) {
            return ['1 = 0', []];
        }
        $params = [];
        foreach (array_values($values) as $i => $v) {
            $params[":{$prefix}{$i}"] = $v;
        }
        return [$column . ' IN (' . implode(', ', array_keys($params)) . ')', $params];
    }

    /**
     * @param array<string, mixed> $where
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function where(array $where): array
    {
        $parts = [];
        $params = [];
        foreach ($where as $col => $value) {
            if ($value === null) {
                $parts[] = self::quote($col) . ' IS NULL';
                continue;
            }
            $parts[] = self::quote($col) . ' = :w_' . $col;
            $params[':w_' . $col] = self::value($value);
        }
        return [$parts === [] ? '1 = 1' : implode(' AND ', $parts), $params];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function params(array $row): array
    {
        $params = [];
        foreach ($row as $col => $value) {
            $params[':' . $col] = self::value($value);
        }
        return $params;
    }

    private static function value(mixed $value): mixed
    {
        return match (true) {
            is_bool($value) => $value ? 1 : 0,
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            default => $value,
        };
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        foreach ($row as $key => $value) {
            if ($value === null || !is_string($key)) {
                continue;
            }
            if ($key === 'id' || str_ends_with($key, '_id') || str_starts_with($key, 'is_') || $key === 'auto_bill'
                || $key === 'created_by' || $key === 'total_count'
                || $key === 'billing_day' || $key === 'fy_start_month' || $key === 'due_days' || $key === 'escalation_months'
                || $key === 'sort' || $key === 'size' || $key === 'width' || $key === 'height' || $key === 'next_no'
            ) {
                if (is_numeric($value)) {
                    $row[$key] = (int) $value;
                }
            }
        }
        return $row;
    }
}
