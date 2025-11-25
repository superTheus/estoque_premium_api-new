<?php

namespace App\Controllers;

use App\Models\ContasModel;
use Dotenv\Dotenv;

class ContasController extends ControllerBase
{
  public function __construct($id = null)
  {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
    $dotenv->load();

    $this->model = new ContasModel($id ? $id : null);
  }

  public function search($data)
  {
    try {
      if (!isset($data['searchTerm'])) {
        throw new \Exception("O termo de busca é obrigatório");
      }

      $produtos = $this->model->search($data['searchTerm'], null, $data['limit'] ?? 10, $data['offset'] ?? 0);

      foreach ($produtos as $key => $item) {
        $empresasController = new EmpresasController();
        $item['empresas'] = $empresasController->findOnly([
          "filter" => [
            "id" => $item['id_empresa']
          ]
        ]);

        $clientesController = new ClientesController();
        $item['clientes'] = $clientesController->findOnly([
          "filter" => [
            "id" => $item['id_cliente']
          ]
        ]);

        $vendasController = new VendasController();
        $item['vendas'] = $vendasController->findOnly([
          "filter" => [
            "id" => $item['id_venda']
          ]
        ]);

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

  public function createOnly($data)
  {
    try {
      $this->validateRequiredFields($this->model, $data);

      return $this->model->insert($data);
    } catch (\Exception $e) {
      throw new \Exception($e->getMessage());
    }
  }

  public function create($data)
  {
    try {
      $result = $this->createOnly($data);

      http_response_code(200);
      echo json_encode($result);
    } catch (\Exception $e) {
      http_response_code(500);
      echo json_encode(["message" => $e->getMessage()]);
    }
  }

  public function updateOnly($data)
  {
    try {
      $currentData = $this->model->current();
      if ($currentData) {
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

        $result = $this->model->update($data);

        return $result;
      } else {
        throw new \Exception("User not found");
      }
    } catch (\Exception $e) {
      throw new \Exception($e->getMessage());
    }
  }

  public function update($data)
  {
    try {
      $result = $this->updateOnly($data);

      http_response_code(200);
      echo json_encode($result);
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
