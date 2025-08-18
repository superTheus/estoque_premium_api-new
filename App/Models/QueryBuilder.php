<?php

namespace App\Models;

use PDO;

class QueryBuilder
{
    private $connection;
    private $table;
    private $select = "*";
    private $joins = [];
    private $where = [];
    private $params = [];
    private $order = [];
    private $limit = null;
    private $offset = null;
    private $groupBy = [];

    public function __construct($connection, $table)
    {
        $this->connection = $connection;
        $this->table = $table;
    }

    public function select($fields)
    {
        $this->select = $fields;
        return $this;
    }

    public function join($table, $condition, $type = 'INNER')
    {
        $this->joins[] = [
            'table' => $table,
            'condition' => $condition,
            'type' => $type
        ];
        return $this;
    }

    public function leftJoin($table, $condition)
    {
        return $this->join($table, $condition, 'LEFT');
    }

    public function rightJoin($table, $condition)
    {
        return $this->join($table, $condition, 'RIGHT');
    }

    public function where($field, $value, $operator = '=', $logicalOperator = 'AND', $rawValue = false)
    {
        $this->where[] = [
            'field' => $field,
            'value' => $value,
            'operator' => $operator,
            'logicalOperator' => $logicalOperator,
            'rawValue' => $rawValue
        ];
        return $this;
    }

    public function orWhere($field, $value, $operator = '=')
    {
        return $this->where($field, $value, $operator, 'OR');
    }

    public function whereLike($field, $value, $logicalOperator = 'AND')
    {
        return $this->where($field, $value, 'LIKE', $logicalOperator);
    }

    public function orWhereLike($field, $value)
    {
        return $this->whereLike($field, $value, 'OR');
    }

    public function whereIn($field, $values, $logicalOperator = 'AND')
    {
        $this->where[] = [
            'field' => $field,
            'value' => $values,
            'operator' => 'IN',
            'logicalOperator' => $logicalOperator
        ];
        return $this;
    }

    public function whereBetween($field, $min, $max, $logicalOperator = 'AND')
    {
        $this->where[] = [
            'field' => $field,
            'value' => [$min, $max],
            'operator' => 'BETWEEN',
            'logicalOperator' => $logicalOperator
        ];
        return $this;
    }

    public function multipleWhere($conditions, $logicalOperator = 'AND')
    {
        if (empty($conditions)) {
            return $this;
        }

        $this->where[] = [
            'type' => 'parenthesis_open',
            'logicalOperator' => count($this->where) > 0 ? $logicalOperator : ''
        ];

        $isFirst = true;
        foreach ($conditions as $condition) {
            $currentLogicalOp = $isFirst ? '' : $logicalOperator;

            if (is_array($condition) && count($condition) === 3) {
                $this->where($condition[0], $condition[1], $condition[2], $currentLogicalOp);
            } elseif (is_array($condition) && count($condition) === 2) {
                $this->where($condition[0], $condition[1], '=', $currentLogicalOp);
            }

            $isFirst = false;
        }

        $this->where[] = [
            'type' => 'parenthesis_close'
        ];

        return $this;
    }

    public function multipleOrWhere($conditions, $logicalOperator = 'OR')
    {
        if (empty($conditions)) {
            return $this;
        }

        $this->where[] = [
            'type' => 'parenthesis_open',
            'logicalOperator' => count($this->where) > 0 ? $logicalOperator : ''
        ];

        $isFirst = true;
        foreach ($conditions as $condition) {
            $currentLogicalOp = $isFirst ? '' : 'OR';

            if (is_array($condition) && count($condition) === 3) {
                $this->where($condition[0], $condition[1], $condition[2], $currentLogicalOp);
            } elseif (is_array($condition) && count($condition) === 2) {
                $this->where($condition[0], $condition[1], '=', $currentLogicalOp);
            }

            $isFirst = false;
        }

        $this->where[] = [
            'type' => 'parenthesis_close'
        ];

        return $this;
    }

    public function orderBy($field, $direction = 'ASC')
    {
        $this->order[] = [
            'field' => $field,
            'direction' => $direction
        ];
        return $this;
    }

    public function limit($limit)
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset($offset)
    {
        $this->offset = $offset;
        return $this;
    }

    public function groupBy($field)
    {
        $this->groupBy[] = $field;
        return $this;
    }

    public function buildQuery($countOnly = false)
    {
        // Select
        $sql = $countOnly ? "SELECT COUNT(DISTINCT {$this->table}.id) as total FROM {$this->table}" : "SELECT {$this->select} FROM {$this->table}";

        // Joins
        foreach ($this->joins as $join) {
            $sql .= " {$join['type']} JOIN {$join['table']} ON {$join['condition']}";
        }

        // Where
        if (!empty($this->where)) {
            $sql .= " WHERE";
            $isFirst = true;

            foreach ($this->where as $index => $condition) {
                if (isset($condition['type']) && $condition['type'] === 'parenthesis_open') {
                    if (!$isFirst && !empty($condition['logicalOperator'])) {
                        $sql .= " {$condition['logicalOperator']}";
                    }
                    $sql .= " (";
                    $isFirst = true;
                    continue;
                }

                if (isset($condition['type']) && $condition['type'] === 'parenthesis_close') {
                    $sql .= ")";
                    $isFirst = false;
                    continue;
                }

                if (!$isFirst) {
                    $sql .= " {$condition['logicalOperator']}";
                }

                if ($condition['operator'] === 'IN' && is_array($condition['value'])) {
                    $placeholders = [];
                    foreach ($condition['value'] as $key => $val) {
                        $paramName = "in_{$index}_{$key}";
                        $placeholders[] = ":{$paramName}";
                        $this->params[$paramName] = $val;
                    }
                    $sql .= " {$condition['field']} IN (" . implode(", ", $placeholders) . ")";
                } else if ($condition['operator'] === 'BETWEEN' && is_array($condition['value'])) {
                    $paramName1 = "between_{$index}_min";
                    $paramName2 = "between_{$index}_max";
                    $sql .= " {$condition['field']} BETWEEN :{$paramName1} AND :{$paramName2}";
                    $this->params[$paramName1] = $condition['value'][0];
                    $this->params[$paramName2] = $condition['value'][1];
                } else {
                    if (isset($condition['rawValue']) && $condition['rawValue']) {
                        $sql .= " {$condition['field']} {$condition['operator']} {$condition['value']}";
                    } else {
                        $paramName = "param_{$index}";
                        $sql .= " {$condition['field']} {$condition['operator']} :{$paramName}";
                        $this->params[$paramName] = ($condition['operator'] === 'LIKE') ? "%{$condition['value']}%" : $condition['value'];
                    }
                }

                $isFirst = false;
            }
        }

        // Group By
        if (!$countOnly && !empty($this->groupBy)) {
            $sql .= " GROUP BY " . implode(", ", $this->groupBy);
        }

        // Order
        if (!$countOnly && !empty($this->order)) {
            $sql .= " ORDER BY";
            $isFirst = true;

            foreach ($this->order as $order) {
                if (!$isFirst) {
                    $sql .= ",";
                }

                $sql .= " {$order['field']} {$order['direction']}";
                $isFirst = false;
            }
        }

        // Limit & Offset
        if (!$countOnly && $this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";

            if ($this->offset !== null) {
                $sql .= " OFFSET {$this->offset}";
            }
        }

        return $sql;
    }

    public function getParams()
    {
        return $this->params;
    }

    public function execute($countOnly = false)
    {
        $sql = $this->buildQuery($countOnly);
        $stmt = $this->connection->prepare($sql);

        foreach ($this->params as $key => $value) {
            $stmt->bindValue(":{$key}", $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $stmt->execute();

        if ($countOnly) {
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['total'];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get()
    {
        return $this->execute(false);
    }

    public function count()
    {
        return $this->execute(true);
    }
}
