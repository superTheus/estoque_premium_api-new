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
        [
            "property" => "cidade",
            "table" => "cidade",
            "model" => CidadesModel::class,
            "controller" => Controllers\CidadesController::class,
        ],
        [
            "property" => "estado",
            "table" => "estado",
            "model" => EstadosModel::class,
            "controller" => Controllers\EstadosController::class,
        ],
        [
            "property" => "menus",
            "table" => "menus",
            "model" => MenusModel::class,
            "controller" => Controllers\MenusController::class,
        ],
        [
            "property" => "submenus",
            "table" => "submenus",
            "model" => SubMenusModel::class,
            "controller" => Controllers\SubMenusController::class,
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

    abstract public function totalCount($filters = []);
    abstract public function findById($id);
    abstract public function find($filters = [], $limit = null, $offset = null, $order = []);
    abstract public function current();
    abstract public function insert($data);
    abstract public function update($data);
    abstract public function delete();
    abstract public function getTableName();

    protected function buildFilterConditions($filters, &$conditions, &$bindings, $parentKey = '', $level = 0)
    {
        foreach ($filters as $key => $value) {
            if ($key === 'AND' || $key === 'OR') {
                $this->handleLogicalOperator($key, $value, $conditions, $bindings, $parentKey, $level);
            } else {
                $this->handleFieldFilter($key, $value, $conditions, $bindings);
            }
        }
    }

    protected function handleLogicalOperator($operator, $value, &$conditions, &$bindings, $parentKey, $level)
    {
        $subConditions = [];

        if (is_array($value)) {
            foreach ($value as $subKey => $subValue) {
                if ($subKey == "AND" || $subKey == "OR") {
                    $this->handleLogicalOperator($subKey, $subValue, $subConditions, $bindings, $parentKey, $level + 1);
                } else {
                    $this->handleFieldFilter($subKey, $subValue, $subConditions, $bindings);
                }
            }
        } else {
            $this->handleFieldFilter($value, $value, $subConditions, $bindings);
        }

        if (!empty($subConditions)) {
            $conditions[] = '(' . implode(' ' . $operator . ' ', $subConditions) . ')';
        }
    }

    protected function handleFieldFilter($field, $value, &$conditions, &$bindings)
    {
        if (!is_array($value)) {
            $param = ':' . str_replace('.', '_', $field) . '_' . count($bindings);
            $conditions[] = "$field = $param";
            $bindings[$param] = $value;
            return;
        }

        if (isset($value['OPERATOR'])) {
            $this->handleOperatorFilter($field, $value, $conditions, $bindings);
        } elseif (isset($value['LIKE'])) {
            $this->handleLikeFilter($field, $value['LIKE'], $conditions, $bindings);
        } elseif (isset($value['IN'])) {
            $this->handleInFilter($field, $value['IN'], $conditions, $bindings);
        } elseif (isset($value['NOT IN'])) {
            $this->handleInFilter($field, $value['NOT IN'], $conditions, $bindings, true);
        } elseif (isset($value['BETWEEN'])) {
            $this->handleBetweenFilter($field, $value['BETWEEN'], $conditions, $bindings);
        } elseif (isset($value['IS NULL'])) {
            $conditions[] = "$field IS NULL";
        } elseif (isset($value['IS NOT NULL'])) {
            $conditions[] = "$field IS NOT NULL";
        } elseif (isset($value['OR'])) {
            $this->handleOrFilter($field, $value, $conditions, $bindings);
        } elseif (isset($value['AND'])) {
            $this->handleAndFilter($field, $value, $conditions, $bindings);
        }
    }

    protected function handleOperatorFilter($field, $value, &$conditions, &$bindings)
    {
        $operator = $value['OPERATOR'];
        $val = $value['VALUE'];

        $param = ':' . str_replace('.', '_', $field) . '_' . count($bindings);
        $bindings[$param] = $val;
        $conditions[] = "$field $operator $param";
    }

    protected function handleLikeFilter($field, $value, &$conditions, &$bindings)
    {
        if (empty($value)) return;

        $param = ':' . str_replace('.', '_', $field) . '_' . count($bindings);
        if (ctype_digit($value)) {
            $conditions[] = "CAST($field AS CHAR) LIKE $param";
            $bindings[$param] = '%' . $value . '%';
        } else {
            $conditions[] = "$field LIKE $param";
            $bindings[$param] = '%' . $value . '%';
        }
    }

    protected function handleInFilter($field, $values, &$conditions, &$bindings, $notIn = false)
    {
        if (empty($values)) return;

        $params = [];
        foreach ($values as $val) {
            $param = ':' . str_replace('.', '_', $field) . '_in_' . count($bindings);
            $params[] = $param;
            $bindings[$param] = $val;
        }

        $operator = $notIn ? 'NOT IN' : 'IN';
        $conditions[] = "$field $operator (" . implode(', ', $params) . ")";
    }

    protected function handleBetweenFilter($field, $values, &$conditions, &$bindings)
    {
        if (count($values) != 2) return;

        $param1 = ':' . str_replace('.', '_', $field) . '_between_1';
        $param2 = ':' . str_replace('.', '_', $field) . '_between_2';

        $bindings[$param1] = $values[0];
        $bindings[$param2] = $values[1];

        $conditions[] = "$field BETWEEN $param1 AND $param2";
    }

    protected function handleOrFilter($field, $value, &$conditions, &$bindings)
    {
        $orValues = $value['OR'] ?? [];

        if (empty($orValues)) return;

        $orConditions = [];
        foreach ($orValues as $val) {
            $param = ':' . str_replace('.', '_', $field) . '_or_' . count($bindings);
            $orConditions[] = "$field = $param";
            $bindings[$param] = $val;
        }

        if (!empty($orConditions)) {
            $prefix = !empty($conditions) ? 'AND ' : '';
            $conditions[] = $prefix . '(' . implode(' OR ', $orConditions) . ')';
        }
    }

    protected function handleAndFilter($field, $value, &$conditions, &$bindings)
    {
        $andValues = $value['AND'] ?? [];

        if (empty($andValues)) return;

        $andConditions = [];
        foreach ($andValues as $val) {
            $param = ':' . str_replace('.', '_', $field) . '_and_' . count($bindings);
            $andConditions[] = "$field = $param";
            $bindings[$param] = $val;
        }

        if (!empty($andConditions)) {
            $prefix = !empty($conditions) ? 'AND ' : '';
            $conditions[] = $prefix . '(' . implode(' AND ', $andConditions) . ')';
        }
    }

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
                        $table = "axpem_" . explode('.', $column['name'])[0];
                        $property = explode('.', $column['name'])[1];

                        $data["name"] = "{$table}.{$property} AS {$column['data']}";

                        $foreign_key = array_values(array_filter($this->relationConfig, function ($relation) use ($table) {
                            return isset($relation['table']) && $relation['table'] === $table;
                        }))[0];

                        if ($foreign_key) {
                            if (in_array($table, array_column($joins, 'table'))) {
                                $joins[array_search($table, array_column($joins, 'table'))]['on'] .= " AND {$this->table}.{$foreign_key['foreign_key']} = {$table}.{$foreign_key['column']}";
                            } else {
                                $joins[] = [
                                    'table' => $table,
                                    'on' => "{$this->table}.{$foreign_key['foreign_key']} = {$table}.{$foreign_key['column']}"
                                ];
                            }
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
                foreach ($searchableColumns as $columnIndex => $columnName) {
                    $column = array_values(array_filter($fields, function ($field) use ($columnName) {
                        if (isset($field['name']) && !empty($field['name'])) {
                            return strpos($field['name'], $columnName) !== false;
                        }
                        return false;
                    }))[0] ?? null;

                    if ($column && !empty($params['columns'][$columnIndex]['search']['value'])) {
                        $searchValue = $params['columns'][$columnIndex]['search']['value'];

                        if (isset($searchValue['operator']) && isset($searchValue['value'])) {
                            $operator = strtoupper($searchValue['operator']);
                            $value = $searchValue['value'];

                            if ($operator === 'BETWEEN' && is_array($value) && count($value) === 2) {
                                $joinQuery->whereBetween($columnName, $value[0], $value[1]);
                                $countQuery->whereBetween($columnName, $value[0], $value[1]);
                            } else {
                                $joinQuery->where($columnName, $value, $operator);
                                $countQuery->where($columnName, $value, $operator);
                            }
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
