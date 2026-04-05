<?php

declare(strict_types=1);
namespace Core;

/**
 * Query Builder
 * 
 * ساخت Query به صورت شیء‌گرا
 */
class QueryBuilder
{
    private $pdo;
    private $table;
    private $select = ['*'];
    private $where = [];
    private $bindings = [];
    private $orderBy = [];
    private $limit;
    private $offset;
    private $join = [];
    
    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * تنظیم جدول
     */
    public function table($table)
    {
        $this->table = $table;
        $this->reset();
        return $this;
    }

    /**
     * انتخاب ستون‌ها
     */
    public function select(...$columns)
    {
        $this->select = $columns;
        return $this;
    }

    /**
     * شرط WHERE
     */
    public function where($column, $operator = '=', $value = null)
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        
        $this->where[] = [
            'type' => 'AND',
            'column' => $column,
            'operator' => $operator,
            'value' => $value
        ];
        
        return $this;
    }

    /**
     * شرط OR WHERE
     */
    public function orWhere($column, $operator = '=', $value = null)
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        
        $this->where[] = [
            'type' => 'OR',
            'column' => $column,
            'operator' => $operator,
            'value' => $value
        ];
        
        return $this;
    }

    /**
     * WHERE IN
     */
    public function whereIn($column, array $values)
    {
        $this->where[] = [
            'type' => 'AND',
            'column' => $column,
            'operator' => 'IN',
            'value' => $values
        ];
        
        return $this;
    }

    /**
     * WHERE NULL
     */
    public function whereNull($column)
    {
        $this->where[] = [
            'type' => 'AND',
            'column' => $column,
            'operator' => 'IS NULL',
            'value' => null
        ];
        
        return $this;
    }

    /**
     * WHERE NOT NULL
     */
    public function whereNotNull($column)
    {
        $this->where[] = [
            'type' => 'AND',
            'column' => $column,
            'operator' => 'IS NOT NULL',
            'value' => null
        ];
        
        return $this;
    }

    /**
     * JOIN
     */
    public function join($table, $first, $operator, $second)
    {
        $this->join[] = [
            'type' => 'INNER',
            'table' => $table,
            'first' => $first,
            'operator' => $operator,
            'second' => $second
        ];
        
        return $this;
    }

    /**
     * LEFT JOIN
     */
    public function leftJoin($table, $first, $operator, $second)
    {
        $this->join[] = [
            'type' => 'LEFT',
            'table' => $table,
            'first' => $first,
            'operator' => $operator,
            'second' => $second
        ];
        
        return $this;
    }

    /**
     * ORDER BY
     */
    public function orderBy($column, $direction = 'ASC')
    {
        // جلوگیری از SQL Injection: فقط کاراکترهای مجاز در نام ستون
        if (!preg_match('/^[a-zA-Z0-9_.]+$/', $column)) {
            throw new \InvalidArgumentException("نام ستون غیرمجاز: {$column}");
        }
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            $direction = 'ASC';
        }
        $this->orderBy[] = [$column, $direction];
        return $this;
    }

    /**
     * LIMIT
     */
    public function limit($limit)
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * OFFSET
     */
    public function offset($offset)
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * دریافت همه رکوردها
     */
    public function get()
    {
        $sql = $this->buildSelectQuery();
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($this->bindings);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            logger('error', 'Query failed: ' . $e->getMessage(), [
                'sql' => $sql,
                'bindings' => $this->bindings
            ]);
            throw $e;
        }
    }

    /**
     * دریافت اولین رکورد
     */
    public function first()
{
    $this->limit = 1;
    $results = $this->get();
    
    if (empty($results)) {
        return null;
    }
    
    // تبدیل آرایه به Object
    return (object) $results[0];
}

    /**
     * دریافت با ID
     */
    public function find($id)
    {
        return $this->where('id', $id)->first();
    }

    /**
     * شمارش
     */
    public function count()
    {
        // FIX C-4: count() مقدار select و limit را ذخیره می‌کند،
        // سپس بعد از اتمام کار آن‌ها را بازیابی می‌کند.
        // قبلاً first() صدا زده می‌شد که limit را به 1 تبدیل می‌کرد
        // و بعد از بازیابی select، limit همچنان 1 باقی می‌ماند.
        $originalSelect = $this->select;
        $originalLimit  = $this->limit;

        $this->select = ['COUNT(*) as count'];
        $this->limit  = null;

        $sql = $this->buildSelectQuery();

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($this->bindings);
            $result = $stmt->fetch(\PDO::FETCH_OBJ);
        } catch (\PDOException $e) {
            $this->select = $originalSelect;
            $this->limit  = $originalLimit;
            throw $e;
        }

        $this->select = $originalSelect;
        $this->limit  = $originalLimit;

        return (int)($result->count ?? 0);
    }

    /**
     * INSERT
     */
    public function insert(array $data)
{
    if (empty($this->table)) {
        throw new \Exception('No table selected for insert.');
    }
    if (empty($data)) {
        throw new \Exception('Insert data is empty.');
    }

    $columns = \array_keys($data);
    $values  = \array_values($data);

    $placeholders = \array_fill(0, \count($columns), '?');

    // بک‌تیک برای ستون‌ها (ایمن‌تر)
    $colsSql = '`' . \implode('`,`', $columns) . '`';

    // بک‌تیک برای نام جدول (فرض: table از داخل سیستم set شده)
    $sql = "INSERT INTO `{$this->table}` ({$colsSql}) VALUES (" . \implode(',', $placeholders) . ")";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($values);

    // تلاش برای گرفتن ID
    $id = $this->pdo->lastInsertId();

    // اگر عددی بود برگردان (برای اینکه create بتواند find کند)
    if ($id !== '' && \ctype_digit((string)$id)) {
        return (int)$id;
    }

    // اگر جدول auto-inc ندارد
    return true;
}

    /**
     * UPDATE
     */
    public function update(array $data)
    {
        $sets = [];
        $bindings = [];

        foreach ($data as $column => $value) {
            // رفع باگ #20: sanitize نام ستون برای جلوگیری از SQL Injection
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
                throw new \InvalidArgumentException("نام ستون غیرمجاز در update: {$column}");
            }
            $sets[] = "`{$column}` = ?";
            $bindings[] = $value;
        }
        
        $sql = "UPDATE {$this->table} SET " . implode(', ', $sets);
        
        if (!empty($this->where)) {
            $sql .= $this->buildWhereClause($bindings);
        }
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            logger('error', 'Update failed: ' . $e->getMessage(), [
                'sql' => $sql,
                'data' => $data
            ]);
            throw $e;
        }
    }

    /**
     * DELETE
     */
    public function delete()
    {
        $sql = "DELETE FROM {$this->table}";
        $bindings = [];
        
        if (!empty($this->where)) {
            $sql .= $this->buildWhereClause($bindings);
        }
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            logger('error', 'Delete failed: ' . $e->getMessage(), [
                'sql' => $sql
            ]);
            throw $e;
        }
    }

    /**
     * ساخت SELECT Query
     */
    private function buildSelectQuery()
    {
        $sql = "SELECT " . implode(', ', $this->select) . " FROM {$this->table}";
        
        // JOIN
        if (!empty($this->join)) {
            foreach ($this->join as $join) {
                $sql .= " {$join['type']} JOIN {$join['table']} ON {$join['first']} {$join['operator']} {$join['second']}";
            }
        }
        
        // WHERE
        if (!empty($this->where)) {
            $sql .= $this->buildWhereClause($this->bindings);
        }
        
        // ORDER BY
        if (!empty($this->orderBy)) {
            $sql .= " ORDER BY ";
            $orders = [];
            foreach ($this->orderBy as $order) {
                $orders[] = "{$order[0]} {$order[1]}";
            }
            $sql .= implode(', ', $orders);
        }
        
        // LIMIT
        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
        }
        
        // OFFSET
        if ($this->offset !== null) {
            $sql .= " OFFSET {$this->offset}";
        }
        
        return $sql;
    }

    /**
     * ساخت WHERE Clause
     */
    private function buildWhereClause(&$bindings)
    {
        $sql = " WHERE ";
        $conditions = [];
        
        foreach ($this->where as $index => $condition) {
            $type = $index === 0 ? '' : " {$condition['type']} ";
            
            // رفع باگ #20: sanitize نام ستون در WHERE clause
            $col = $condition['column'];
            if (!preg_match('/^[a-zA-Z0-9_.]+$/', $col)) {
                throw new \InvalidArgumentException("نام ستون غیرمجاز در WHERE: {$col}");
            }
            // sanitize operator
            $allowedOps = ['=','!=','<','>','<=','>=','LIKE','NOT LIKE','IN','IS NULL','IS NOT NULL'];
            $op = strtoupper($condition['operator']);
            if (!in_array($op, $allowedOps, true)) {
                throw new \InvalidArgumentException("عملگر غیرمجاز در WHERE: {$op}");
            }
            if ($op === 'IN') {
                $placeholders = array_fill(0, count($condition['value']), '?');
                $conditions[] = $type . "{$col} IN (" . implode(', ', $placeholders) . ")";
                $bindings = array_merge($bindings, $condition['value']);
            } elseif ($op === 'IS NULL' || $op === 'IS NOT NULL') {
                $conditions[] = $type . "{$col} {$op}";
            } else {
                $conditions[] = $type . "{$col} {$op} ?";
                $bindings[] = $condition['value'];
            }
        }
        
        $sql .= implode('', $conditions);
        
        return $sql;
    }

    /**
     * Reset کردن Query
     */
    private function reset()
    {
        $this->select = ['*'];
        $this->where = [];
        $this->bindings = [];
        $this->orderBy = [];
        $this->limit = null;
        $this->offset = null;
        $this->join = [];
    }
}