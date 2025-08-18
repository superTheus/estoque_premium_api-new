<?php

namespace App\Models;

use App\Controllers;
use App\Models\Connection;
use PDO;

abstract class BaseModel extends Connection
{
    protected $conn;
    protected $table;
    public $relationConfig = [];

    public $structureTable = null;

    private $structureDatabase = [
        [
            "property" => "contas_usuarios",
            "table" => "contas_usuarios",
            "model" => ContasUsuariosModel::class,
            "controller" => Controllers\ContasUsuariosController::class,
        ],
        [
            "property" => "usuarios",
            "table" => "usuarios",
            "model" => UsuariosModel::class,
            "controller" => Controllers\UsuariosController::class,
        ],
        [
            "property" => "empresas",
            "table" => "empresas",
            "model" => EmpresasModel::class,
            "controller" => Controllers\EmpresasController::class,
        ],
        [
            "property" => "marcas",
            "table" => "marcas",
            "model" => MarcasModel::class,
            "controller" => Controllers\MarcasController::class,
        ],
        [
            "property" => "fornecedores",
            "table" => "fornecedores",
            "model" => FornecedoresModel::class,
            "controller" => Controllers\FornecedoresController::class,
        ],
        [
            "property" => "categorias",
            "table" => "categorias",
            "model" => CategoriasModel::class,
            "controller" => Controllers\CategoriasController::class,
        ],
        [
            "property" => "subcategorias",
            "table" => "subcategorias",
            "model" => SubcategoriasModel::class,
            "controller" => Controllers\SubcategoriasController::class,
        ],
        [
            "property" => "cliente",
            "table" => "cliente",
            "model" => ClientesModel::class,
            "controller" => Controllers\ClientesController::class,
        ],
        [
            "property" => "produtos_estoque",
            "table" => "produtos_estoque",
            "model" => ProdutosEstoqueModel::class,
            "controller" => Controllers\ProdutosEstoqueController::class,
        ],
        [
            "property" => "produtos_imagens",
            "table" => "produtos_imagens",
            "model" => ProdutosImagensModel::class,
            "controller" => Controllers\ProdutosImagensController::class,
        ],
        [
            "property" => "produtos_kit",
            "table" => "produtos_kit",
            "model" => ProdutosKitsModel::class,
            "controller" => Controllers\ProdutosKitsController::class,
        ],
        [
            "property" => "produtos",
            "table" => "produtos",
            "model" => ProdutosModel::class,
            "controller" => Controllers\ProdutosController::class,
        ],
    ];

    public function __construct($id = null)
    {
        $this->conn = $this->openConnection();
        $this->getStructureTable($this->conn);

        if ($id) {
            $this->findById($id);
        }
    }

    public function __destruct()
    {
        $this->closeConnection();
    }

    abstract public function totalCount($filters = [], $dateRange = []);
    abstract public function findById($id);
    abstract public function find($filters = [], $limit = null, $offset = null, $order = [], $dateRange = []);
    abstract public function current();
    abstract public function insert($data);
    abstract public function update($data);
    abstract public function delete();
    abstract public function getTableName();

    protected function getStructureTable(PDO $pdo)
    {
        if ($this->structureTable === null) {
            $foreignKeyStmt = $this->getForeignKey($pdo);

            $stmt = $pdo->query("SELECT * FROM {$this->table} WHERE 1 = 0");

            $colunas = [];
            for ($i = 0; $i < $stmt->columnCount(); $i++) {
                $meta = $stmt->getColumnMeta($i);

                foreach ($foreignKeyStmt as $fk) {
                    if ($fk['foreign_key'] === $meta['name']) {
                        $this->relationConfig[] = $fk;
                        break;
                    }
                }

                $colunas[] = $meta;
            }

            $this->structureTable = $colunas;
        }

        return $this->structureTable;
    }

    public function verifyUniqueKey($key, $value)
    {
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM {$this->table} WHERE {$this->table}.{$key} = :value");
        $stmt->bindParam(':value', $value);
        $stmt->execute();

        $count = $stmt->fetchColumn();

        if ($count > 0) {
            throw new \Exception("O valor {$value} para {$key} já existe no sistema.");
            return false;
        }

        return true;
    }

    private function getForeignKey($pdo)
    {
        $stmt = $pdo->query("SELECT COLUMN_NAME,
                                REFERENCED_TABLE_NAME,
                                REFERENCED_COLUMN_NAME
                            FROM 
                                INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                            WHERE TABLE_SCHEMA = DATABASE() AND 
                                TABLE_NAME = '{$this->table}' AND
                                REFERENCED_TABLE_NAME IS NOT NULL");

        $foreignKeys = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

            $find = array_values(array_filter($this->structureDatabase, function ($item) use ($row) {
                return $item['table'] === $row['REFERENCED_TABLE_NAME'];
            }))[0];

            $foreignKeys[] = [
                'foreign_key' => $row['COLUMN_NAME'],
                'table' => $row['REFERENCED_TABLE_NAME'],
                'column' => $row['REFERENCED_COLUMN_NAME'],
                'model' => $find['model'],
                'property' => $find['property'],
                'controller' => $find['controller'],
                'ignore' => true
            ];
        }

        return $foreignKeys;
    }

    /**
     * Get the value of table
     */
    public function getTable()
    {
        return $this->table;
    }

    /**
     * Set the value of table
     *
     * @return  self
     */
    public function setTable($table)
    {
        $this->table = $table;

        return $this;
    }

    public function dataTable($params = [], $callback = null)
    {
        try {
            $joinQuery = new QueryBuilder($this->conn, $this->table);
            $countQuery = new QueryBuilder($this->conn, $this->table);

            $joins = [];

            $fields = array_filter(array_map(function ($column) use (&$joins) {
                if (isset($column['name']) && !empty($column['name'])) {
                    $data = [
                        "searchable" => $column['searchable'],
                        "orderable" => $column['orderable'],
                        "search" => $column['search'],
                    ];

                    if (strpos($column['name'], '.') !== false) {
                        $table = explode('.', $column['name'])[0];
                        $property = explode('.', $column['name'])[1];

                        $data["name"] = "{$table}.{$property} AS {$column['data']}";

                        $foreign_key = array_values(array_filter($this->relationConfig, function ($relation) use ($table) {
                            return isset($relation['table']) && $relation['table'] === $table;
                        }))[0];

                        if ($foreign_key) {
                            $joins[] = [
                                'table' => $table,
                                'on' => "{$this->table}.{$foreign_key['foreign_key']} = {$table}.{$foreign_key['column']}"
                            ];
                        }
                    } else {
                        $data["name"] = "{$this->table}.{$column['name']} AS {$column['data']}";
                    }

                    return $data;
                }
                return null;
            }, $params['columns']));

            $selectFields = implode(', ', array_map(function ($field) {
                return $field['name'];
            }, $fields));

            $searchableColumns = array_filter(array_map(function ($field) {
                return $field['searchable'] == true ? explode('AS ', $field['name'])[0] : null;
            }, $fields));

            $orderableColumns = array_filter(array_map(function ($field) {
                return $field['orderable'] ? explode('AS ', $field['name'])[0] : null;
            }, $fields, array_keys($fields)));

            $joinQuery->select($selectFields);

            foreach ($joins as $join) {
                $joinQuery->join($join['table'], $join['on']);
                $countQuery->join($join['table'], $join['on']);
            }

            // Processar busca por coluna (se houver)
            if (isset($params['columns']) && is_array($params['columns'])) {
                foreach ($searchableColumns as $columnName) {
                    $column = array_values(array_filter($fields, function ($field) use ($columnName) {
                        if (isset($field['name']) && !empty($field['name'])) {
                            return strpos($field['name'], $columnName) !== false;
                        }
                        return false;
                    }))[0];

                    if (!empty($column['search']['value'])) {
                        $searchValue = $column['search']['value'];

                        if (isset($searchValue['operator']) && isset($searchValue['value'])) {
                            $operator = strtoupper($searchValue['operator']);
                            $value = $searchValue['value'];
                            $joinQuery->where($columnName, $value, $operator);
                            $countQuery->where($columnName, $value, $operator);
                        } else if ($columnName) {
                            $joinQuery->where($columnName, $searchValue);
                            $countQuery->where($columnName, $searchValue);
                        }
                    }
                }
            }

            // Processar busca global
            if (!empty($params['search']['value'])) {
                $searchValue = $params['search']['value'];

                $searchConditions = [];
                foreach ($searchableColumns as $column) {
                    $searchConditions[] = [$column, $searchValue, 'LIKE'];
                }

                $joinQuery->multipleOrWhere($searchConditions, "AND");
            }

            // Processar ordenação
            if (isset($params['order']) && is_array($params['order'])) {
                foreach ($params['order'] as $order) {
                    $columnIndex = $order['column'] ?? 0;
                    $direction = $order['dir'] ?? 'asc';

                    if (isset($orderableColumns[$columnIndex])) {
                        $columnName = $orderableColumns[$columnIndex];
                        $joinQuery->orderBy($columnName, strtoupper($direction));
                    }
                }
            }

            // Aplicar limite e offset
            if (isset($params['start']) && isset($params['length'])) {
                $start = intval($params['start']);
                $length = intval($params['length']);

                if ($length > 0) {
                    $joinQuery->limit($length)->offset($start);
                }
            }

            // Ignorar registros deletados
            $joinQuery->where("{$this->table}.deletado", "N");
            $countQuery->where("{$this->table}.deletado", "N");

            //Pesquisando Registros
            $sql = $joinQuery->buildQuery();
            $stmt = $this->conn->prepare($sql);

            foreach ($joinQuery->getParams() as $key => $value) {
                $stmt->bindValue(":{$key}", $value);
            }

            $stmt->execute();
            $records = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            //Contando Registros
            $sql = $countQuery->buildQuery(true);
            $stmt = $this->conn->prepare($sql);

            foreach ($countQuery->getParams() as $key => $value) {
                $stmt->bindValue(":{$key}", $value);
            }

            $stmt->execute();
            $totalRecords = $stmt->fetch(\PDO::FETCH_ASSOC)['total'];

            $totalAtivo = (new QueryBuilder($this->conn, $this->table))
                ->select("COUNT(*) as total")
                ->where("status", "A")
                ->where("deletado", "N")
                ->execute(true);

            $totalInativos = (new QueryBuilder($this->conn, $this->table))
                ->select("COUNT(*) as total")
                ->where("status", "I")
                ->where("deletado", "N")
                ->execute(true);

            $total = (new QueryBuilder($this->conn, $this->table))
                ->select("COUNT(*) as total")
                ->where("deletado", "N")
                ->execute(true);

            return [
                'draw' => isset($params['draw']) ? intval($params['draw']) : 0,
                'recordsTotal' => $total,
                'recordsFiltered' => $totalRecords,
                'data' => $callback($records),
                'ativos' => $totalAtivo,
                'inativos' => $totalInativos,
                'total' => $total
            ];
        } catch (\PDOException $e) {
            throw new \PDOException("Erro ao buscar dados para DataTable: " . $e->getMessage());
        }
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

                if (!isset($record['status'])) {
                    $record['status'] = $statusMap[$record['status']];
                }

                $record['id'] = str_pad($record['id'], 6, '0', STR_PAD_LEFT);

                $formattedRecords[] = $record;
            }
            return $formattedRecords;
        } catch (\Exception $e) {
            throw new \Exception("Erro ao formatar dados: " . $e->getMessage());
        }
    }
}
