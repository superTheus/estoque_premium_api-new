<?php

namespace App\Controllers;

use Dotenv\Dotenv;
use App\Models\EmpresasModel;

class EmpresasController extends ControllerBase
{
  public function __construct($id = null)
  {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
    $dotenv->load();

    $this->model = new EmpresasModel($id ? $id : null);
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

      if (isset($data['cnpj'])) {
        $existing = $this->model->find(['cnpj' => $data['cnpj'], 'deletado' => 'N']);
        if (count($existing) > 0) {
          throw new \Exception("CNPJ já cadastrado");
        }
      }

      if (isset($data['emite_nota']) && $data['emite_nota'] === 'S') {
        if (!isset($data['situacao_tributaria']) || !isset($data['csc']) || !isset($data['csc_id']) || !isset($data['certificado']) || !isset($data['senha'])) {
          throw new \Exception("Preencha todos os campos obrigatórios de tributação");
        }

        if (isset($data['homologacao']) && $data['homologacao'] === 'S') {
          if (!isset($data['serie_homologacao']) || !isset($data['numero_nota_homologacao'])) {
            throw new \Exception("Série e número de homologação são obrigatórios");
          }
        } else {
          if (!isset($data['serie']) || !isset($data['numero_nota'])) {
            throw new \Exception("Série e número da nota são obrigatórios");
          }
        }

        $fiscalController = new FiscalController();
        $certTest = $fiscalController->testeCertificado([
          'certificado' => $data['certificado'],
          'senha' => $data['senha']
        ]);

        if ($data['cnpj'] !== $certTest['cnpj']) {
          throw new \Exception("CNPJ do certificado não corresponde ao CNPJ da empresa");
        }

        $companys = $fiscalController->listCompany([
          "filter" => [
            'cnpj' => preg_replace('/\D/', '', $data['cnpj'])
          ]
        ]);

        if ($companys && count($companys) > 0) {
          $company = $companys[0];
          $updateCompany = $fiscalController->updateCompany($company['id'], [
            "razao_social" => $data['razao_social'] ?? $company['razao_social'],
            "nome_fantasia" => $data['nome_fantasia'] ?? $company['nome_fantasia'],
            "telefone" => $data['telefone'] ?? $company['telefone'],
            "email" => $data['email'] ?? $company['email'],
            "cep" => $data['cep'] ?? $company['cep'],
            "logradouro" => $data['logradouro'] ?? $company['logradouro'],
            "numero" => $data['numero'] ?? "15",
            "bairro" => $data['bairro'] ?? "Compensa",
            "cidade" => $data['cidade'] ?? "Manaus",
            "inscricao_estadual" => $data['inscricao_estadual'] ?? "054142563",
            "uf" => $data['uf'] ?? "AM",
            "serie_nfce" => 3,
            "numero_nfce" => 100,
            "serie_nfe" => 2,
            "numero_nfe" => 100,
            "situacao_tributaria" => "102",
            "codigo_municipio" => "1302603",
            "codigo_uf" => "13",
            "tpamb" => "2",
            "csc" => "925622871150be55",
            "csc_id" => "000001"
          ]);
        }
      }

      // $result = $this->model->insert($data);

      http_response_code(200);
      echo json_encode([]);
    } catch (\Exception $e) {
      http_response_code(400);
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
