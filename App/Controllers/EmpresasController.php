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
        if (!isset($data['inscricao_estadual'])) {
          throw new \Exception("Inscrição estadual é obrigatória para emissão de nota");
        }

        if (!isset($data['certificado']) || !isset($data['certificado_nome']) || !isset($data['senha']) || !isset($data['crt'])) {
          throw new \Exception("Preencha todos os campos obrigatórios de tributação");
        }

        if (isset($data['homologacao']) && $data['homologacao'] === 'S') {
          if (!isset($data['serie_nfce_homologacao']) && !isset($data['numero_nfce_homologacao']) && !isset($data['serie_nfe_homologacao']) && !isset($data['numero_nfe_homologacao'])) {
            throw new \Exception("Série e número de homologação são obrigatórios");
          }

          if(!isset($data['csc_homologacao']) || !isset($data['csc_id_homologacao'])) {
            throw new \Exception("CSCs e IDs de homologação são obrigatórios");
          }
        } else {
          if (!isset($data['serie_nfe']) && !isset($data['numero_nfe']) && !isset($data['serie_nfce']) && !isset($data['numero_nfce'])) {
            throw new \Exception("Série e número da nota são obrigatórios");
          }

          if(!isset($data['csc']) || !isset($data['csc_id'])) {
            throw new \Exception("CSCs e IDs são obrigatórios");
          }
        }

        if (!isset($data['cep']) || !isset($data['logradouro']) || !isset($data['numero']) || !isset($data['bairro']) || !isset($data['cidade']) || !isset($data['uf'])) {
          throw new \Exception("Campos de endereço são obrigatórios para emissão de nota");
        }

        $fiscalController = new FiscalController();
        $certTest = $fiscalController->testeCertificado([
          'certificado' => $data['certificado'],
          'senha' => $data['senha']
        ]);

        if (preg_replace('/\D/', '', $data['cnpj']) !== $certTest['cnpj']) {
          throw new \Exception("CNPJ do certificado não corresponde ao CNPJ da empresa");
        }

        $companys = $fiscalController->listCompany([
          "filter" => [
            'cnpj' => preg_replace('/\D/', '', $data['cnpj'])
          ]
        ]);

        $estado = $fiscalController->estadosUnico($data['uf']);
        $cidade = $fiscalController->cidadesUnico($data['cidade']);

        $data['codigo_uf'] = $estado ? $estado['codigo_ibge'] : null;
        $data['codigo_municipio'] = $cidade ? $cidade['codigo_ibge'] : null;

        if ($companys && count($companys) > 0) {
          $company = $companys[0];
          $fiscalController->updateCompany($company['id'], [
            "cnpj" => preg_replace('/\D/', '', $data['cnpj']),
            "razao_social" => $data['razao_social'] ?? $company['razao_social'],
            "nome_fantasia" => $data['nome_fantasia'] ?? $company['nome_fantasia'],
            "telefone" => $data['telefone'] ?? $company['telefone'],
            "email" => $data['email'] ?? $company['email'],
            "cep" => $data['cep'] ?? $company['cep'],
            "logradouro" => $data['logradouro'] ?? $company['logradouro'],
            "numero" => $data['numero'] ?? $company['numero'],
            "bairro" => $data['bairro'] ?? $company['bairro'],
            "cidade" => $data['cidade'] ?? $company['cidade'],
            "codigo_municipio" => $data['codigo_municipio'] ?? $company['codigo_municipio'],
            "uf" => $data['uf'] ?? $company['uf'],
            "codigo_uf" => $data['codigo_uf'] ?? $company['codigo_uf'],
            "inscricao_estadual" => $data['inscricao_estadual'] ?? $company['inscricao_estadual'],
            "inscricao_municipal" => $data['inscricao_municipal'] ?? $company['inscricao_municipal'],
            "certificado" => $data['certificado'] ?? $company['certificado'],
            "senha" => $data['senha'] ?? $company['senha'],
            "senha" => $data['senha'] ?? $company['senha'],
            "csc" => $data['csc'] ?? $company['csc'],
            "csc_id" => $data['csc_id'] ?? $company['csc_id'],
            "tpamb" => isset($data['homologacao']) && $data['homologacao'] === 'S' ? 2 : 1,
            "serie_nfce" => $data['serie_nfce'] ?? $company['serie_nfce'],
            "numero_nfce" => $data['numero_nfce'] ?? $company['numero_nfce'],
            "serie_nfe" => $data['serie_nfe'] ?? $company['serie_nfe'],
            "numero_nfe" => $data['numero_nfe'] ?? $company['numero_nfe'],
            "situacao_tributaria" => $data['situacao_tributaria'] ?? $company['situacao_tributaria'],
            "csc_homologacao" => $data['csc_homologacao'] ?? $company['csc_homologacao'],
            "csc_id_homologacao" => $data['csc_id_homologacao'] ?? $company['csc_id_homologacao'],
            "serie_nfce_homologacao" => $data['serie_nfce_homologacao'] ?? $company['serie_nfce_homologacao'],
            "numero_nfce_homologacao" => $data['numero_nfce_homologacao'] ?? $company['numero_nfce_homologacao'],
            "serie_nfe_homologacao" => $data['serie_nfe_homologacao'] ?? $company['serie_nfe_homologacao'],
            "numero_nfe_homologacao" => $data['numero_nfe_homologacao'] ?? $company['numero_nfe_homologacao'],
            "crt" => $data['crt'] ?? $company['crt']
          ]);
        } else {
          $create = $fiscalController->createCompany([
            "cnpj" => preg_replace('/\D/', '', $data['cnpj']),
            "razao_social" => $data['razao_social'],
            "nome_fantasia" => $data['nome_fantasia'],
            "telefone" => $data['telefone'],
            "email" => $data['email'],
            "cep" => $data['cep'],
            "logradouro" => $data['logradouro'],
            "numero" => $data['numero'],
            "bairro" => $data['bairro'],
            "cidade" => $data['cidade'],
            "codigo_municipio" => $data['codigo_municipio'],
            "uf" => $data['uf'],
            "codigo_uf" => $data['codigo_uf'],
            "inscricao_estadual" => $data['inscricao_estadual'],
            "inscricao_municipal" => $data['inscricao_municipal'] ?? null,
            "certificado" => $data['certificado'] ?? null,
            "senha" => $data['senha'] ?? null,
            "senha" => $data['senha'] ?? null,
            "csc" => $data['csc'] ?? null,
            "csc_id" => $data['csc_id'] ?? null,
            "tpamb" => isset($data['homologacao']) && $data['homologacao'] === 'S' ? 2 : 1,
            "serie_nfce" => $data['serie_nfce'] ?? null,
            "numero_nfce" => $data['numero_nfce'] ?? null,
            "serie_nfe" => $data['serie_nfe'] ?? null,
            "numero_nfe" => $data['numero_nfe'] ?? null,
            "situacao_tributaria" => $data['situacao_tributaria'] ?? null,
            "csc_homologacao" => $data['csc_homologacao'] ?? null,
            "csc_id_homologacao" => $data['csc_id_homologacao'] ?? null,
            "serie_nfce_homologacao" => $data['serie_nfce_homologacao'] ?? null,
            "numero_nfce_homologacao" => $data['numero_nfce_homologacao'] ?? null,
            "serie_nfe_homologacao" => $data['serie_nfe_homologacao'] ?? null,
            "numero_nfe_homologacao" => $data['numero_nfe_homologacao'] ?? null,
            "crt" => $data['crt'],
          ]);
        }
      }

      $result = $this->model->insert($data);

      http_response_code(200);
      echo json_encode($result);
    } catch (\Exception $e) {
      http_response_code(400);
      echo json_encode($e->getMessage());
    }
  }

  public function update($data)
  {
    try {
      $currentData = $this->model->current();
      
      if (isset($data['emite_nota']) && $data['emite_nota'] === 'S') {
        if (!isset($data['inscricao_estadual'])) {
          $data['inscricao_estadual'] = $currentData['inscricao_estadual'];
        }

        if (!isset($data['situacao_tributaria'])) {
          $data['situacao_tributaria'] = $currentData['situacao_tributaria'];
        }

        if (!isset($data['csc'])) {
          $data['csc'] = $currentData['csc'];
        }

        if (!isset($data['csc_id'])) {
          $data['csc_id'] = $currentData['csc_id'];
        }

        if (!isset($data['certificado'])) {
          $data['certificado'] = $currentData['certificado'];
        }

        if (!isset($data['certificado_nome'])) {
          $data['certificado_nome'] = $currentData['certificado_nome'];
        }

        if (!isset($data['senha'])) {
          $data['senha'] = $currentData['senha'];
        }

        if (!isset($data['crt'])) {
          $data['crt'] = $currentData['crt'];
        }

        if(!isset($data['serie_nfce_homologacao'])) {
          $data['serie_nfce_homologacao'] = $currentData['serie_nfce_homologacao'];
        }

        if(!isset($data['numero_nfce_homologacao'])) {
          $data['numero_nfce_homologacao'] = $currentData['numero_nfce_homologacao'];
        }

        if(!isset($data['serie_nfe_homologacao'])) {
          $data['serie_nfe_homologacao'] = $currentData['serie_nfe_homologacao'];
        }

        if(!isset($data['numero_nfe_homologacao'])) {
          $data['numero_nfe_homologacao'] = $currentData['numero_nfe_homologacao'];
        }

        if(!isset($data['cep'])) {
          $data['cep'] = $currentData['cep'];
        }

        if(!isset($data['logradouro'])) {
          $data['logradouro'] = $currentData['logradouro'];
        }

        if(!isset($data['numero'])) {
          $data['numero'] = $currentData['numero'];
        }

        if(!isset($data['bairro'])) {
          $data['bairro'] = $currentData['bairro'];
        }

        if(!isset($data['cidade'])) {
          $data['cidade'] = $currentData['cidade'];
        }

        if(!isset($data['uf'])) {
          $data['uf'] = $currentData['uf'];
        }

        if (!isset($data['inscricao_estadual']) && !isset($currentData['inscricao_estadual'])) {
          throw new \Exception("Inscrição estadual é obrigatória para emissão de nota");
        }

        if (
          !isset($data['csc']) ||
          !isset($data['csc_id']) ||
          !isset($data['certificado']) ||
          !isset($data['certificado_nome']) ||
          !isset($data['senha']) ||
          !isset($data['crt']) 
        ) {
          throw new \Exception("Preencha todos os campos obrigatórios de tributação");
        }

        if (
          isset($data['homologacao']) && $data['homologacao'] === 'S'
        ) {
          if (
            !isset($data['serie_nfce_homologacao']) &&
            !isset($data['numero_nfce_homologacao']) &&
            !isset($data['serie_nfe_homologacao']) &&
            !isset($data['numero_nfe_homologacao'])
          ) {
            throw new \Exception("Série e número de homologação são obrigatórios");
          }
        } else {
          if (
            !isset($data['serie_nfe']) &&
            !isset($data['numero_nfe']) &&
            !isset($data['serie_nfce']) &&
            !isset($data['numero_nfce']) &&
            !$currentData['serie_nfe'] &&
            !$currentData['numero_nfe'] &&
            !$currentData['serie_nfce'] &&
            !$currentData['numero_nfce']
          ) {
            throw new \Exception("Série e número da nota são obrigatórios");
          }
        }

        if (
          !isset($data['cep']) ||
          !isset($data['logradouro']) ||
          !isset($data['numero']) ||
          !isset($data['bairro']) ||
          !isset($data['cidade']) ||
          !isset($data['uf'])
        ) {
          throw new \Exception("Campos de endereço são obrigatórios para emissão de nota");
        }

        if (isset($data['certificado']) && isset($data['senha'])) {
          $fiscalController = new FiscalController();
          $certTest = $fiscalController->testeCertificado([
            'certificado' => $data['certificado'],
            'senha' => $data['senha']
          ]);

          if (!isset($data['cnpj'])) {
            $data['cnpj'] = $currentData['cnpj'];
          }

          if ($data['cnpj'] !== $certTest['cnpj']) {
            throw new \Exception("CNPJ do certificado não corresponde ao CNPJ da empresa");
          }

          $companys = $fiscalController->listCompany([
            "filter" => [
              'cnpj' => preg_replace('/\D/', '', $data['cnpj'])
            ]
          ]);

          $estado = $fiscalController->estadosUnico($data['uf']);
          $cidade = $fiscalController->cidadesUnico($data['cidade']);

          $data['codigo_uf'] = $estado ? $estado['codigo_ibge'] : null;
          $data['codigo_municipio'] = $cidade ? $cidade['codigo_ibge'] : null;

          if ($companys && count($companys) > 0) {
            $company = $companys[0];
            $fiscalController->updateCompany($company['id'], [
              "cnpj" => preg_replace('/\D/', '', $data['cnpj']),
              "razao_social" => $data['razao_social'] ?? $company['razao_social'],
              "nome_fantasia" => $data['nome_fantasia'] ?? $company['nome_fantasia'],
              "telefone" => $data['telefone'] ?? $company['telefone'],
              "email" => $data['email'] ?? $company['email'],
              "cep" => $data['cep'] ?? $company['cep'],
              "logradouro" => $data['logradouro'] ?? $company['logradouro'],
              "numero" => $data['numero'] ?? $company['numero'],
              "bairro" => $data['bairro'] ?? $company['bairro'],
              "cidade" => $data['cidade'] ?? $company['cidade'],
              "codigo_municipio" => $data['codigo_municipio'] ?? $company['codigo_municipio'],
              "uf" => $data['uf'] ?? $company['uf'],
              "codigo_uf" => $data['codigo_uf'] ?? $company['codigo_uf'],
              "inscricao_estadual" => $data['inscricao_estadual'] ?? $company['inscricao_estadual'],
              "inscricao_municipal" => $data['inscricao_municipal'] ?? $company['inscricao_municipal'],
              "certificado" => $data['certificado'] ?? $company['certificado'],
              "senha" => $data['senha'] ?? $company['senha'],
              "senha" => $data['senha'] ?? $company['senha'],
              "csc" => $data['csc'] ?? $company['csc'],
              "csc_id" => $data['csc_id'] ?? $company['csc_id'],
              "tpamb" => isset($data['homologacao']) && $data['homologacao'] === 'S' ? 2 : 1,
              "serie_nfce" => $data['serie_nfce'] ?? $company['serie_nfce'],
              "numero_nfce" => $data['numero_nfce'] ?? $company['numero_nfce'],
              "serie_nfe" => $data['serie_nfe'] ?? $company['serie_nfe'],
              "numero_nfe" => $data['numero_nfe'] ?? $company['numero_nfe'],
              "situacao_tributaria" => $data['situacao_tributaria'] ?? $company['situacao_tributaria'],
              "csc_homologacao" => $data['csc_homologacao'] ?? $company['csc_homologacao'],
              "csc_id_homologacao" => $data['csc_id_homologacao'] ?? $company['csc_id_homologacao'],
              "serie_nfce_homologacao" => $data['serie_nfce_homologacao'] ?? $company['serie_nfce_homologacao'],
              "numero_nfce_homologacao" => $data['numero_nfce_homologacao'] ?? $company['numero_nfce_homologacao'],
              "serie_nfe_homologacao" => $data['serie_nfe_homologacao'] ?? $company['serie_nfe_homologacao'],
              "numero_nfe_homologacao" => $data['numero_nfe_homologacao'] ?? $company['numero_nfe_homologacao'],
              "crt" => $data['crt'] ?? $company['crt']
            ]);
            
          } else {
            $fiscalController->createCompany([
              "cnpj" => preg_replace('/\D/', '', $data['cnpj']),
              "razao_social" => $data['razao_social'] ?? $currentData['razao_social'],
              "nome_fantasia" => $data['nome_fantasia'] ?? $currentData['nome_fantasia'],
              "telefone" => $data['telefone'] ?? $currentData['telefone'],
              "email" => $data['email'] ?? $currentData['email'],
              "cep" => $data['cep'] ?? $currentData['cep'],
              "logradouro" => $data['logradouro'] ?? $currentData['logradouro'],
              "numero" => $data['numero'] ?? $currentData['numero'],
              "bairro" => $data['bairro'] ?? $currentData['bairro'],
              "cidade" => $data['cidade'] ?? $currentData['cidade'],
              "codigo_municipio" => $data['codigo_municipio'],
              "uf" => $data['uf'] ?? $currentData['uf'],
              "codigo_uf" => $data['codigo_uf'] ?? $currentData['codigo_uf'],
              "inscricao_estadual" => $data['inscricao_estadual'] ?? $currentData['inscricao_estadual'],
              "inscricao_municipal" => $data['inscricao_municipal'] ?? '',
              "certificado" => $data['certificado'],
              "senha" => $data['senha'],
              "senha" => $data['senha'],
              "csc" => $data['csc'],
              "csc_id" => $data['csc_id'],
              "tpamb" => isset($data['homologacao']) && $data['homologacao'] === 'S' ? 2 : 1,
              "serie_nfce" => $data['serie_nfce'] ?? null,
              "numero_nfce" => $data['numero_nfce'] ?? null,
              "serie_nfe" => $data['serie_nfe'] ?? null,
              "numero_nfe" => $data['numero_nfe'] ?? null,
              "situacao_tributaria" => $data['situacao_tributaria'] ?? null,
              "csc_homologacao" => $data['csc_homologacao'] ?? null,
              "csc_id_homologacao" => $data['csc_id_homologacao'] ?? null,
              "serie_nfce_homologacao" => $data['serie_nfce_homologacao'] ?? null,
              "numero_nfce_homologacao" => $data['numero_nfce_homologacao'] ?? null,
              "serie_nfe_homologacao" => $data['serie_nfe_homologacao'] ?? null,
              "numero_nfe_homologacao" => $data['numero_nfe_homologacao'] ?? null,
              "crt" => $data['crt'],
            ]);
          }
        }
      }

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
