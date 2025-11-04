<?php

namespace App\Models;

use App\Models\BaseModel;
use PDO;
use PDOException;

class MercadoPagoModel extends BaseModel
{
    private $attributes = [];
    protected $table = 'mercado_pago_pagamentos';

    public function __construct($id = null)
    {
        parent::__construct($id);
    }

    public function __get($property)
    {
        return $this->attributes[$property] ?? null;
    }

    public function __set($property, $value)
    {
        $this->attributes[$property] = $value;
    }

    public function __call($method, $arguments)
    {
        if (strpos($method, 'get') === 0) {
            $property = lcfirst(substr($method, 3));
            return $this->__get($property);
        } elseif (strpos($method, 'set') === 0) {
            $property = lcfirst(substr($method, 3));
            $this->__set($property, $arguments[0]);
            return $this;
        }

        throw new \BadMethodCallException("Método {$method} não existe");
    }

    public function findById($id)
    {
        $sql = "SELECT * FROM {$this->table} WHERE id = :id";

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':id', $id);
            $stmt->execute();

            $record = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($record) {
                foreach ($record as $key => $value) {
                    $this->attributes[$key] = $value;
                }
            }
        } catch (\PDOException $e) {
            throw new \PDOException($e->getMessage());
        }
    }

    public function findByPaymentId($paymentId)
    {
        $sql = "SELECT * FROM {$this->table} WHERE payment_id = :payment_id";

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':payment_id', $paymentId);
            $stmt->execute();

            return $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new \PDOException($e->getMessage());
        }
    }

    public function totalCount($filters = [])
    {
        $sql = "SELECT COUNT(id) as total FROM {$this->table}";
        $conditions = [];
        $bindings = [];

        if (!empty($filters)) {
            $this->buildFilterConditions($filters, $conditions, $bindings);
        }

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }

        try {
            $stmt = $this->conn->prepare($sql);

            foreach ($bindings as $param => $value) {
                $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue($param, $value, $type);
            }

            $stmt->execute();
            return $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new \PDOException($e->getMessage());
        }
    }

    public function find($filters = [], $limit = null, $offset = null, $order = [])
    {
        try {
            $sql = "SELECT * FROM {$this->table}";
            $conditions = [];
            $bindings = [];

            if (!empty($filters)) {
                $this->buildFilterConditions($filters, $conditions, $bindings);
            }

            if (!empty($conditions)) {
                $sql .= " WHERE " . implode(" AND ", $conditions);
            }

            if (!empty($order)) {
                $orderClauses = [];
                $direction = strtoupper($order['direction'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

                foreach ($order['cols'] ?? [] as $column) {
                    $orderClauses[] = "$column $direction";
                }

                if (!empty($orderClauses)) {
                    $sql .= " ORDER BY " . implode(", ", $orderClauses);
                }
            }

            if ($limit !== null) {
                $sql .= " LIMIT :limit";
                $bindings[':limit'] = (int) $limit;
            }

            if ($offset !== null) {
                $sql .= " OFFSET :offset";
                $bindings[':offset'] = (int) $offset;
            }

            $stmt = $this->conn->prepare($sql);

            foreach ($bindings as $param => $value) {
                $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue($param, $value, $type);
            }

            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new PDOException($e->getMessage());
        }
    }

    public function current()
    {
        if (empty($this->attributes['id'])) {
            return [];
        }

        return $this->attributes;
    }

    public function insert($data)
    {
        try {
            $colunas = [];
            $valores = [];
            $placeholders = [];

            foreach ($this->structureTable as $column) {
                $columnName = $column['name'];
                $isPrimaryKey = in_array('primary_key', $column['flags']);
                $isTimestamp = $column['native_type'] === 'TIMESTAMP';

                if ($isPrimaryKey || $isTimestamp)
                    continue;

                if (isset($data[$columnName]) && $data[$columnName] !== '') {
                    $colunas[] = $columnName;
                    $placeholders[] = ":{$columnName}";
                    $valores[":{$columnName}"] = $data[$columnName];
                    $this->attributes[$columnName] = $data[$columnName];
                }
            }

            $sql = "INSERT INTO {$this->table} (" . implode(', ', $colunas) . ")
              VALUES (" . implode(', ', $placeholders) . ")";

            $stmt = $this->conn->prepare($sql);

            foreach ($valores as $placeholder => $valor) {
                $stmt->bindValue($placeholder, $valor);
            }

            $stmt->execute();
            $newId = $this->conn->lastInsertId();
            $this->attributes['id'] = $newId;
            $this->findById($newId);

            return $this->current();
        } catch (\PDOException $e) {
            throw new \PDOException($e->getMessage());
        }
    }

    public function update($data)
    {
        try {
            foreach ($data as $column => $value) {
                if ($column === 'id')
                    continue;

                $this->attributes[$column] = $value;
            }

            $sets = [];
            $values = [];

            foreach ($this->structureTable as $structure) {
                $columnName = $structure['name'];
                $isPrimaryKey = in_array('primary_key', $structure['flags']);

                if ($isPrimaryKey) {
                    continue;
                }

                if (isset($data[$columnName])) {
                    $sets[] = "{$columnName} = :{$columnName}";
                    $values[":{$columnName}"] = $this->attributes[$columnName];
                }
            }

            $sql = "UPDATE {$this->table} SET " . implode(', ', $sets) . " WHERE id = :id";

            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(':id', $this->attributes['id']);

            foreach ($values as $placeholder => $value) {
                $stmt->bindValue($placeholder, $value);
            }

            $result = $stmt->execute();

            if ($result) {
                $this->findById($this->attributes['id']);
            }

            return $this->current();
        } catch (\PDOException $e) {
            throw new \PDOException($e->getMessage());
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            $sql = "DELETE FROM {$this->table} WHERE id = :id";

            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':id', $this->attributes['id']);

            $stmt->execute();

            return $this->current();
        } catch (\PDOException $e) {
            throw new \PDOException($e->getMessage());
        }
    }

    public function getTableName()
    {
        return $this->table;
    }

    /**
     * Cancela um pagamento no banco de dados (atualiza status para 'cancelled')
     * 
     * @param int $id ID do pagamento no banco
     * @return array Dados atualizados do pagamento
     */
    public function cancelarPagamento($id)
    {
        try {
            $sql = "UPDATE {$this->table} SET status = 'cancelled' WHERE id = :id";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':id', $id);
            $result = $stmt->execute();

            if ($result) {
                // Buscar os dados atualizados
                $this->findById($id);
                return $this->current();
            }

            throw new \Exception("Erro ao cancelar pagamento no banco de dados");
        } catch (\PDOException $e) {
            throw new \PDOException("Erro ao cancelar pagamento: " . $e->getMessage());
        }
    }
}
