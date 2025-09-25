<?php

namespace App\Controllers;

use App\Models\ProdutosModel;
use Dotenv\Dotenv;

class ProdutosController extends ControllerBase
{
    public function __construct($id = null)
    {
        $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
        $dotenv->load();

        $this->model = new ProdutosModel($id ? $id : null);
    }

    public function search($data)
    {
        try {
            if (!isset($data['searchTerm']) || empty($data['searchTerm'])) {
                throw new \Exception("O termo de busca é obrigatório");
            }

            $produtos = $this->model->search($data['searchTerm'], $_REQUEST['id_conta'], isset($data['id_empresa']) ? $data['id_empresa'] : null, $data['limit'] ?? 10, $data['offset'] ?? 0);

            foreach ($produtos as $key => $item) {
                $marcasController = new MarcasController($item['id_marca']);
                $item['marcas'][] = $marcasController->findUnique();

                $categoriasController = new CategoriasController($item['id_categoria']);
                $item['categorias'][] = $categoriasController->findUnique();

                $subcategoriasController = new SubcategoriasController($item['id_subcategoria']);
                $item['subcategorias'][] = $subcategoriasController->findUnique();

                $fornecedoresController = new FornecedoresController($item['id_fornecedor']);
                $item['fornecedores'][] = $fornecedoresController->findUnique();

                $produtosImagensController = new ProdutosImagensController();
                $item['produtos_imagens'] = $produtosImagensController->findOnly([
                    "filter" => [
                        "id_produto" => $item['id']
                    ]
                ]) ?? [];

                $filter = [
                    "id_produto" => $item['id'],
                ];

                if (isset($data['id_empresa']) && !empty($data['id_empresa'])) {
                    $filter['id_empresa'] = $data['id_empresa'];
                }

                $produtosEstoqueController = new ProdutosEstoqueController();
                $item['produtos_estoque'] = $produtosEstoqueController->findOnly([
                    "filter" => $filter
                ]) ?? [];

                $produtos[$key] = $item;
            }

            http_response_code(200);
            echo json_encode($produtos);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["message" => $e->getMessage()]);
        }
    }

    public function findOnly($data = [])
    {
        try {
            $filter = $data && isset($data['filter']) ? $data['filter'] : [];
            $limit = $data && isset($data['limit']) ? $data['limit'] : null;
            $offset = $data && isset($data['offset']) ? $data['offset'] : null;
            $order = $data && isset($data['order']) ? $data['order'] : [];
            $dateRange = $data && isset($data['date_ranger']) ? $data['date_ranger'] : [];
            $results = $this->model->find(array_merge($filter, ["deletado" => "N"]), $limit, $offset, $order, $dateRange);

            if (isset($data['includes'])) {
                $this->processIncludes($results, $data['includes']);
            }

            return $results;
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function find($data = [])
    {
        $filter = $data && isset($data['filter']) ? $data['filter'] : [];
        $dateRange = $data && isset($data['date_ranger']) ? $data['date_ranger'] : [];

        try {
            http_response_code(200);
            echo json_encode([
                "total" => $this->model->totalCount(array_merge($filter, ["deletado" => "N"]), $dateRange)['total'],
                "data" => $this->findOnly($data)
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["message" => $e->getMessage()]);
        }
    }

    public function findUnique()
    {
        return $this->model->current();
    }

    public function create($data)
    {
        try {
            $data['id_conta'] = $_REQUEST['id_conta'];
            $this->validateRequiredFields($this->model, $data);
            $result = $this->model->insert($data);

            http_response_code(200);
            echo json_encode($result);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["message" => $e->getMessage()]);
        }
    }

    public function update($data)
    {
        try {
            $currentData = $this->model->current();

            if ($currentData) {

                $result = $this->model->update($data);

                foreach ($this->model->relationConfig as $relation) {
                    if (isset($data[$relation['property']])) {
                        $delete = $data[$relation['property']]['delete'] ?? [];
                        $create = $data[$relation['property']]['create'] ?? [];
                        $update = $data[$relation['property']]['update'] ?? [];

                        foreach ($delete as $item) {
                            $model = new $relation['model']();
                            $model->__set($relation['foreign_key'], $currentData['id']);
                            $model->__set($relation['key'], $item[$relation['key']]);

                            $currentDataItem = $model->find([
                                $relation['foreign_key'] => $currentData['id'],
                                $relation['key'] => $item[$relation['key']]
                            ])[0] ?? null;

                            $model = new $relation['model']($currentDataItem['id']);

                            if (!$currentDataItem) {
                                throw new \Exception("Item com ID {$item['id']} não foi encontrado em relação {$relation['property']}");
                            }

                            if ($currentDataItem[$relation['foreign_key']] !== $currentData['id']) {
                                throw new \Exception("Item com ID {$item['id']} não pertence ao cliente atual");
                            }

                            if ($this->model->verifyMinimum($relation)) {
                                throw new \Exception("É necessário ter pelo menos {$relation['min_count']} itens em {$relation['property']}");
                            } else {
                                $model->delete();
                            }
                        }

                        foreach ($create as $item) {
                            $model = new $relation['model']();
                            $this->validateRequiredFields($model, $item, [$relation['foreign_key']]);
                            $item[$relation['foreign_key']] = $currentData['id'];
                            
                            $result = $model->insert($item);
                        }

                        foreach ($update as $item) {
                            $model = new $relation['model']($item['id']);
                            $currentDataItem = $model->current();
                            if (!$currentDataItem) {
                                throw new \Exception("Item com ID {$item['id']} não foi encontrado em relação {$relation['property']}");
                            }
                            if ($currentDataItem[$relation['foreign_key']] !== $currentData['id']) {
                                throw new \Exception("Item com ID {$item['id']} não pertence ao cliente atual");
                            }
                            $this->validateUpdateFields($model, $item, $currentDataItem);
                            $model->update($item);
                        }
                    }
                }

                http_response_code(200);
                echo json_encode($result);
            } else {
                http_response_code(404);
                echo json_encode(["message" => "User not found"]);
            }
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["message" => $e->getMessage()]);
        }
    }

    public function delete(int $id)
    {
        try {
            $result = $this->model->delete($id);

            http_response_code(200);
            echo json_encode($result);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["message" => $e->getMessage()]);
        }
    }

    public function _destruct()
    {
        $this->model = null;
    }

    public function searchDataTable($data)
    {
        try {
            http_response_code(200);
            echo json_encode($this->model->dataTable($data, [$this->model, 'formatData']));
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["message" => $e->getMessage()]);
        }
    }
}
