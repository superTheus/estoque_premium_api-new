<?php

namespace App\Controllers;

use Dotenv\Dotenv;
use App\Models\ContasUsuariosModel;

class ContasUsuariosController extends ControllerBase
{
  public function __construct($id = null)
  {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
    $dotenv->load();

    $this->model = new ContasUsuariosModel($id ? $id : null);
  }

  public function search($data)
  {
    try {
      if (!isset($data['searchTerm']) || empty($data['searchTerm'])) {
        throw new \Exception("O termo de busca é obrigatório");
      }

      $produtos = $this->model->search($data['searchTerm'], null, $data['limit'] ?? 10, $data['offset'] ?? 0);

      foreach ($produtos as $key => $item) {
        $empresasController = new EmpresasController();
        $item['empresas'] = $empresasController->findOnly([
          "filter" => [
            "id_conta" => $item['id']
          ]
        ]);

        $usuariosController = new UsuariosController();
        $item['usuarios'] = $usuariosController->findOnly([
          "filter" => [
            "id_conta" => $item['id']
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

  public function create($data)
  {
    try {
      $this->validateRequiredFields($this->model, $data);

      $result = $this->model->insert($data);
      $formas = [];
      if($result) {
        $formaPagamentoController = new FormasPagamentoController();
        $formas[] = $formaPagamentoController->createOnly([
          "id_conta" => $result['id'],
          "id_tipo" => 1,
          "descricao" => "Dinheiro",
        ]);
        $formas[] = $formaPagamentoController->createOnly([
          "id_conta" => $result['id'],
          "id_tipo" => 3,
          "descricao" => "Cartão de Crédito",
        ]);
        $formas[] = $formaPagamentoController->createOnly([
          "id_conta" => $result['id'],
          "id_tipo" => 4,
          "descricao" => "Cartão de Débito",
        ]);
        $formas[] = $formaPagamentoController->createOnly([
          "id_conta" => $result['id'],
          "id_tipo" => 13,
          "descricao" => "Pix",
        ]);
        $formas[] = $formaPagamentoController->createOnly([
          "id_conta" => $result['id'],
          "id_tipo" => 11,
          "descricao" => "Boleto",
        ]);
        $formas[] = $formaPagamentoController->createOnly([
          "id_conta" => $result['id'],
          "id_tipo" => 5,
          "descricao" => "Crediário",
        ]);

        $operacoesController = new OperacoesController();
        $operacoesController->createOnly([
          "id_conta" => $result['id'],
          "cfop_internacional" => "6102",
          "cfop_estadual" => "5102",
          "natureza_operacao" => "V",
          "tipo" => "R",
          "mov_estoque" => "S",
          "descricao" => "Venda de Mercadoria", 
        ]);

        $operacoesController->createOnly([
          "id_conta" => $result['id'],
          "cfop_internacional" => "2102",
          "cfop_estadual" => "1102",
          "natureza_operacao" => "E",
          "tipo" => "D",
          "mov_estoque" => "E",
          "descricao" => "Entrada de Mercadoria",
        ]);
        
        $newCliente = [
          'tipo_cliente' => 'PJ',
          'nome' => $data['empresas'][0]['razao_social'] ?? 'Cliente Principal',
          'apelido' => $data['empresas'][0]['razao_social'] ?? 'Cliente Principal',
          'documento' => $data['empresas'][0]['cnpj'] ?? null,
          'razao_social' => $data['empresas'][0]['razao_social'] ?? null,
          'email' => $data['empresas'][0]['email'] ?? null,
          'cep' => $data['empresas'][0]['cep'] ?? null,
          'logradouro' => $data['empresas'][0]['logradouro'] ?? null,
          'numero' => $data['empresas'][0]['numero'] ?? null,
          'bairro' => $data['empresas'][0]['bairro'] ?? null,
          'cidade' => $data['empresas'][0]['cidade'] ?? null,
          'estado' => $data['empresas'][0]['uf'] ?? null,
        ];

        $mercadoPagoController = new MercadoPagoController();
        $mercadoPagoController->gerarBoletoApenas([
          'id_conta' => $result['id']
        ], $newCliente);
      }
      
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
