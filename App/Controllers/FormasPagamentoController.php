<?php

namespace App\Controllers;

use App\Models\ContasModel;
use App\Models\ContasUsuariosModel;
use App\Models\FormasPagamentoModel;
use Dotenv\Dotenv;

class FormasPagamentoController extends ControllerBase
{
    public function __construct($id = null)
    {
        $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
        $dotenv->load();

        $this->model = new FormasPagamentoModel($id ? $id : null);
    }

    public function findOnly($data = [])
    {
        try {
            $filter = $data && isset($data['filter']) ? $data['filter'] : [];
            $limit = $data && isset($data['limit']) ? $data['limit'] : null;
            $offset = $data && isset($data['offset']) ? $data['offset'] : null;
            $order = $data && isset($data['order']) ? $data['order'] : [];
            $dateRange = $data && isset($data['date_ranger']) ? $data['date_ranger'] : [];
            $results = $this->model->find($filter, $limit, $offset, $order, $dateRange);

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
                "total" => $this->model->totalCount($filter, $dateRange)['total'],
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

    public function validaCriacaoConta($data)
    {
        $this->validateRequiredFields($this->model, $data);

        $this->validateRequiredFields($this->model, $data);

        $contaModel = new ContasUsuariosModel($data['id_conta']);
        $contaData = $contaModel->current();

        if (!$contaData) {
            throw new \Exception("Conta com ID {$data['id_conta']} não encontrada.");
        }

        if ($contaData['status'] !== 'A') {
            throw new \Exception("Não é possível adicionar forma de pagamento a uma conta inativa.");
        }

        $result = null;

        if ($contaData['tipo'] === 'A') {
            $contasUsuariosModel = new ContasUsuariosModel();
            $usuariosAdms = $contasUsuariosModel->find([
                'tipo' => 'A',
                'status' => 'A',
            ]);

            $uniqueId = uniqid("conta_", true);

            foreach ($usuariosAdms as $usuarioAdm) {
                $contaDados = $data;
                $contaDados['id_conta'] = $usuarioAdm['id'];
                $contaDados['token_unico'] = $uniqueId;

                $r = $this->createOnly($contaDados);

                if($r['id_conta'] === $data['id_conta']) {
                    $result = $r;
                }
            }
        } else {
            $result = $this->createOnly($data);
        }

        return $result;
    }

    public function createOnly($data)
    {
        try {
            $result = $this->model->insert($data);
            return $result;
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function create($data)
    {
        try {
            $data['id_conta'] = $_REQUEST['id_conta'];
            return $this->createOnly($data);
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

                        foreach ($create as $item) {
                            $model = new $relation['model']();
                            $this->validateRequiredFields($model, $item, [$relation['foreign_key']]);
                            $item[$relation['foreign_key']] = $currentData['id'];
                            $model->insert($item);
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

                        foreach ($delete as $item) {
                            $model = new $relation['model']();
                            $model->__set($relation['foreign_key'], $currentData['id']);
                            $model->__set($relation['key'], $item['id']);

                            $currentDataItem = $model->find([
                                $relation['foreign_key'] => $currentData['id'],
                                $relation['key'] => $item['id']
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
