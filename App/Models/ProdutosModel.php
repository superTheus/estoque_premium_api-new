<?php

namespace App\Models;

use App\Controllers\MarcasController;
use App\Controllers\UploadsController;
use App\Models\BaseModel;

use PDO;
use PDOException;

class ProdutosModel extends BaseModel
{
    private $attributes = [];
    protected $table = 'produtos';

    public function __construct($id = null)
    {
        $this->relationConfig = [
            [
                'property' => 'produtos_estoque',
                'table' => 'produtos_estoque',
                'model' => ProdutosEstoqueModel::class,
                'min_count' => 0,
                'foreign_key' => 'id_produto',
                'key' => 'id_empresa'
            ],
            [
                'property' => 'produtos_imagens',
                'model' => 'produtos_imagens',
                'model' => ProdutosImagensModel::class,
                'min_count' => 0,
                'foreign_key' => 'id_produto',
                'key' => 'id'
            ],
            [
                'property' => 'produtos_kit',
                'model' => 'produtos_kit',
                'model' => ProdutosKitsModel::class,
                'min_count' => 0,
                'foreign_key' => 'id_produto',
                'key' => 'id_produto_kit',
            ],
            [
                'property' => 'produto_movimentacao',
                'model' => 'produto_movimentacao',
                'model' => ProdutosMovimentacaoModel::class,
                'min_count' => 0,
                'foreign_key' => 'id_produto'
            ],
        ];
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

    public function search($searchTerm, $idConta, $idEmpresa = null, $limit = 10, $offset = 0)
    {
        try {
            $queryBuilder = new QueryBuilder($this->conn, "produtos");
            $result = $queryBuilder->select("DISTINCT produtos.*")
                ->leftJoin("marcas", "marcas.id = produtos.id_marca")
                ->leftJoin("categorias", "categorias.id = produtos.id_categoria")
                ->leftJoin("subcategorias", "subcategorias.id = produtos.id_subcategoria")
                ->leftJoin("fornecedores", "fornecedores.id = produtos.id_fornecedor")
                ->leftJoin("produtos_estoque", "produtos_estoque.id_produto = produtos.id")
                ->multipleOrWhere([
                    ["produtos.descricao", "%$searchTerm%", 'LIKE'],
                    ["produtos.ncm", "%$searchTerm%", 'LIKE'],
                    ["marcas.descricao", "%$searchTerm%", 'LIKE'],
                    ["categorias.descricao", "%$searchTerm%", 'LIKE'],
                    ["subcategorias.descricao", "%$searchTerm%", 'LIKE'],
                    ["fornecedores.nome", "%$searchTerm%", 'LIKE'],
                ])->where("produtos.id_conta", $idConta);
                    
            if ($idEmpresa !== null) {
                $queryBuilder->where("produtos_estoque.id_empresa", $idEmpresa);
            }

            $result = $queryBuilder->limit($limit)->offset($offset)->execute();

            return $result;
        } catch (\PDOException $e) {
            throw new \PDOException($e->getMessage());
        }
    }

    public function findById($id)
    {
        $sql = "SELECT * FROM {$this->table} WHERE id = :id";

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':id', $id);
            $stmt->execute();

            $user = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($user) {
                foreach ($user as $key => $value) {
                    $this->attributes[$key] = $value;
                }
            }
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
            echo $e->getMessage();
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

            if (isset($data['foto']) && $data['foto']) {
                $uploadsController = new UploadsController();
                $data['foto'] = $uploadsController->uploadFile($data['foto'], "user");
            }

            if (isset($data['senha']) && $data['senha']) {
                $hash = password_hash($data['senha'], PASSWORD_BCRYPT);
                $data['senha'] = $hash;
            }

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

                    $valor = $data[$columnName];

                    $valores[":{$columnName}"] = $valor;
                    $this->attributes[$columnName] = $valor;
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

            foreach ($this->relationConfig as $relation) {
                $property = $relation['property'];

                if (isset($relation['ignore']) && $relation['ignore']) {
                    continue;
                }

                if (isset($data[$property]) && is_array($data[$property])) {
                    foreach ($data[$property] as $item) {
                        $item[$relation['foreign_key']] = $newId;
                        $relatedModel = new $relation['model']();
                        $relatedModel->insert($item);
                    }
                }
            }

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

    public function formatData($records = null)
    {
        try {
            $formattedRecords = [];
            foreach ($records as $record) {
                $statusMap = [
                    'A' => 'Ativo',
                    'I' => 'Inativo',
                ];

                $formattedRecord = [
                    'id' => str_pad($record['id'], 6, '0', STR_PAD_LEFT),
                    'nome' => $record['nome'],
                    'email' => $record['email'],
                    'status' => $statusMap[$record['status']],
                ];

                $formattedRecords[] = $formattedRecord;
            }

            return $formattedRecords;
        } catch (\Exception $e) {
            throw new \Exception("Erro ao formatar dados: " . $e->getMessage());
        }
    }

    public function verifyMinimum($relation)
    {
        try {
            $tableRelation = (new $relation['model']())->getTableName();
            $total = (new QueryBuilder($this->conn, $this->table))
                ->select("COUNT(*) as total")
                ->join($tableRelation, "{$this->table}.id = {$tableRelation}.{$relation['foreign_key']}")
                ->where("{$this->table}.id", "{$this->attributes['id']}")
                ->execute(false);

            $total = $total[0]['total'] ?? 0;

            return $total <= $relation['min_count'];
        } catch (PDOException $e) {
            throw new PDOException("Erro ao buscar dados para DataTable: " . $e->getMessage());
        }
    }
}
