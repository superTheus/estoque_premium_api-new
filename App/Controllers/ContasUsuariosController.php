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
      if (!isset($data['searchTerm'])) {
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

      $contasGeradas = [];
      $geraFinanceiro = false;

      if ($result) {
        $clienteController = new ClientesController();
        $contasController = new ContasController();
        $contasUsuariosController = new ContasUsuariosController();

        $contaNova = $contasUsuariosController->findOnly([
          'filter' => [
            'id' => $result['id']
          ],
          'includes' => [
            'empresas' => true,
            'usuarios' => true
          ]
        ]);

        if (!$contaNova || !isset($contaNova[0])) {
          throw new \Exception("Erro ao recuperar a conta recém criada.");
        }

        $contaNova = $contaNova[0];
        $empresa = $contaNova['empresas'][0] ?? null;
        $usuario = $contaNova['usuarios'][0] ?? null;

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

        $boleto = $formaPagamentoController->createOnly([
          "id_conta" => $result['id'],
          "id_tipo" => 11,
          "descricao" => "Boleto",
        ]);

        $formas[] = $boleto;
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

        if (
          isset($data['cnpj']) && $data['cnpj'] &&
          isset($data['uf']) && $data['uf'] &&
          isset($data['cidade']) && $data['cidade'] &&
          isset($data['logradouro']) && $data['logradouro'] &&
          isset($data['numero']) && $data['numero'] &&
          isset($data['cep']) && $data['cep'] &&
          isset($data['bairro']) && $data['bairro']
        ) {
          $geraFinanceiro = true;
        }

        $contasAdms = $contasUsuariosController->findOnly([
          'filter' => [
            'tipo' => 'A',
            'deletado' => 'N',
            'status' => 'A',
          ],
          'includes' => [
            'empresas' => true,
            'usuarios' => true
          ]
        ]);

        $tokenUnico = uniqid(date('YmdHis'));

        if ($contasAdms) {
          foreach ($contasAdms as $contaAdm) {
            $forma = $formaPagamentoController->findOnly([
              "filter" => [
                "id_conta" => $contaAdm['id'],
                "id_tipo" => 11
              ],
            ]);

            if ($forma && isset($forma[0])) {
              $forma = $forma[0];
            }

            $cliente = $clienteController->createOnly([
              'tipo_cliente' => 'PJ',
              'nome' => $data['responsavel'] . ' ' . $data['empresas'][0]['razao_social'] ?? 'Cliente Principal',
              'apelido' => $data['responsavel'] . ' ' . $data['empresas'][0]['razao_social'] ?? 'Cliente Principal',
              'documento' => $data['empresas'][0]['cnpj'] ?? null,
              'razao_social' => $data['empresas'][0]['razao_social'] ?? null,
              'email' => $data['empresas'][0]['email'] ?? null,
              'cep' => $data['empresas'][0]['cep'] ?? null,
              'logradouro' => $data['empresas'][0]['logradouro'] ?? null,
              'numero' => $data['empresas'][0]['numero'] ?? null,
              'bairro' => $data['empresas'][0]['bairro'] ?? null,
              'cidade' => $data['empresas'][0]['cidade'] ?? null,
              'estado' => $data['empresas'][0]['uf'] ?? null,
              'id_conta' => $contaAdm['id'],
            ]);

            if ($geraFinanceiro) {
              $contasGeradas[] = $contasController->createOnly([
                'id_conta' => $contaAdm['id'],
                'id_empresa' => $contaAdm['empresas'][0]['id'] ?? null,
                'id_cliente' => $cliente['id'],
                'id_forma' => $forma['id'] ?? null,
                'descricao'  => 'Assinatura mensal - ' . $data['responsavel'],
                'valor' => $data['valor_mensal'] ?? 0.00,
                'origem' => 'M',
                'natureza' => 'R',
                'condicao' => 'A',
                'vencimento' => $data['vencimento'],
                'observacoes' => 'Geração automática de conta mensalidade',
                'situacao' => 'PE',
                'token_unico' => $tokenUnico,
              ]);
            }
          }
        }

        if ($geraFinanceiro) {
          $contasGeradas[] = $contasController->createOnly([
            'id_conta' => $result['id'],
            'id_empresa' => $empresa['id'] ?? null,
            'id_cliente' => null,
            'id_forma' => $boleto['id'] ?? null,
            'descricao'  => 'Mensalidade do Sistema',
            'valor' => $data['valor_mensal'] ?? 0.00,
            'origem' => 'M',
            'natureza' => 'D',
            'condicao' => 'A',
            'vencimento' => $data['vencimento'],
            'observacoes' => 'Geração automática de conta de mensalidade do sistema',
            'situacao' => 'PE',
            'token_unico' => $tokenUnico,
          ]);

          $dias = $this->diasFaltantes($data['vencimento']);

          if ($dias < 28) {
            $mercadoPagoController = new MercadoPagoController();
            $pagamentoBoleto = $mercadoPagoController->gerarBoletoApenas([
              'valor' => floatval($data['valor_mensal'] ?? 0.00),
              'descricao' => 'Mensalidade do Sistema',
              'email' => $usuario['email'] ?? ($empresa['email'] ?? null),
              'responsavel' => $usuario['nome'] ?? 'Cliente',
              'cnpj' => $empresa['cnpj'] ?? null,
              'logradouro' => $empresa['logradouro'] ?? null,
              'numero' => $empresa['numero'] ?? null,
              'bairro' => $empresa['bairro'] ?? null,
              'cidade' => $empresa['cidade'] ?? null,
              'uf' => $empresa['uf'] ?? null,
              'cep' => $empresa['cep'] ?? null,
              'dataVencimento' => $data['vencimento'],
            ]);

            foreach ($contasGeradas as $key => $contaGerada) {
              $novasContasController = new ContasController($contaGerada['id']);
              $contasGeradas[$key] = $novasContasController->updateOnly([
                'descricao' => $contaGerada['descricao'],
                'conta_pagamento' => [
                  'create' => [
                    [
                      'id_pagamento' => $pagamentoBoleto['id'],
                    ]
                  ]
                ]
              ]);
            }
          }
        }

        $result['formas_pagamento'] = $formas;
        if ($geraFinanceiro) {
          $result['mensalidades_geradas'] = $contasGeradas;
        }
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

  /**
   * Calcula quantos dias faltam para uma determinada data
   * 
   * @param string $data Data no formato YYYY-MM-DD
   * @return int Número de dias faltantes (negativo se a data já passou)
   */
  private function diasFaltantes($data)
  {
    try {
      $dataFutura = new \DateTime($data);
      $dataAtual = new \DateTime();

      $dataFutura->setTime(0, 0, 0);
      $dataAtual->setTime(0, 0, 0);

      $diferenca = $dataAtual->diff($dataFutura);

      if ($dataFutura < $dataAtual) {
        return -$diferenca->days;
      }

      return $diferenca->days;
    } catch (\Exception $e) {
      throw new \Exception("Data inválida: " . $e->getMessage());
    }
  }
}
