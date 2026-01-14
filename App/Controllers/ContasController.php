<?php

namespace App\Controllers;

use App\Models\ContasModel;
use DateTime;
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
      $data['id_conta'] = $_REQUEST['id_conta'];
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
        throw new \Exception("Conta não encontrada para atualização");
      }
    } catch (\Exception $e) {
      throw new \Exception($e->getMessage());
    }
  }

  public function update($data)
  {
    try {
      $currentData = $this->model->current();
      $result = $this->updateOnly($data);

      if ($result['situacao'] === 'PE' || $result['situacao'] === 'CA') {
        $formaPagamentoController = new FormasPagamentoController();
        $formaPagamento = $formaPagamentoController->findOnly([
          'filter' => [
            'id' => $result['id_forma']
          ],
          'limit' => 1
        ]);

        $conta = $this->findOnly([
          'filter' => [
            'id' => $result['id']
          ],
          "includes" => [
            'conta_pagamento' => [
              'includes' => [
                'mercado_pago_pagamentos' => true
              ]
            ]
          ],
          'limit' => 1
        ]);

        if ($conta && count($conta) > 0) {
          $conta = $conta[0];
        } else {
          throw new \Exception("Conta não encontrada para verificar forma de pagamento");
        }

        $formaBoleto = false;
        $boletoExistente = false;

        foreach ($conta['conta_pagamento'] as $pagamento) {
          if ($pagamento['mercado_pago_pagamentos']['tipo'] === 'B') {
            $boletoExistente = $pagamento['mercado_pago_pagamentos'];
          }
        }

        if ($formaPagamento && count($formaPagamento) > 0) {
          $formaPagamento = $formaPagamento[0];

          if ($formaPagamento['id_tipo'] === 11) {
            $formaBoleto = true;
          }
        }

        $erros = [];

        if (($currentData['vencimento'] !== $result['vencimento'] || $currentData['valor'] !== $result['valor']) && $result['situacao'] === 'PE') {
          if ($boletoExistente) {
            $mercadoPagoController = new MercadoPagoController();

            try {
              $mercadoPagoController->cancelarApenas($boletoExistente['id']);
            } catch (\Exception $e) {
              $erros[] = [
                "cancelar_boleto" => $e->getMessage()
              ];
            }
          }

          if ($formaBoleto) {
            $mercadoPagoController = new MercadoPagoController();
            try {
              $mercadoPagoController->gerarPagamentoPorConta($result['id']);
              $result = $this->findOnly([
                'filter' => [
                  'id' => $result['id']
                ],
                "includes" => [
                  "conta_pagamento" => [
                    "includes" => [
                      "mercado_pago_pagamentos" => true
                    ]
                  ]
                ],
                'limit' => 1
              ])[0];
            } catch (\Exception $e) {
              $erros[] = [
                "gerar_pagamento" => $e->getMessage()
              ];
            }
          }

          if ($result['origem'] === 'M') {
            $contasAssociadas = $this->findOnly([
              'filter' => [
                'token_unico' => $conta['token_unico'],
              ]
            ]);

            $contaCliente = null;

            if ($contasAssociadas) {
              foreach ($contasAssociadas as $contaAssociada) {
                if ($contaAssociada['natureza'] === "D") {
                  $contaCliente = $contaAssociada;
                }

                if ($contaAssociada['id'] !== $conta['id']) {
                  $contasControllerAssociada = new ContasController($contaAssociada['id']);
                  $contasControllerAssociada->updateOnly([
                    'vencimento' => $result['vencimento'],
                    'valor' => $result['valor'],
                  ]);
                }
              }
            }

            if ($contaCliente) {
              $contaUsuariosController = new ContasUsuariosController();
              $contaUsuario = $contaUsuariosController->findOnly([
                'filter' => [
                  'id' => $contaCliente['id_conta']
                ],
                'limit' => 1
              ]);

              if ($contaUsuario && count($contaUsuario) > 0) {
                $contaUsuario = $contaUsuario[0];
                $contaUsuariosControllerAssociada = new ContasUsuariosController($contaUsuario['id']);
                $contaUsuariosControllerAssociada->updateOnly([
                  'valor_mensal' => $result['valor'],
                  'vencimento' => $result['vencimento'],
                ]);
              }
            }
          }
        } else if ($result['situacao'] === 'CA') {
          if ($boletoExistente) {
            $mercadoPagoController = new MercadoPagoController();
            $mercadoPagoController->cancelarApenas($boletoExistente['id']);
          }

          $contasAssociadas = $this->findOnly([
            'filter' => [
              'token_unico' => $conta['token_unico'],
            ]
          ]);

          if ($contasAssociadas) {
            foreach ($contasAssociadas as $contaAssociada) {
              if ($contaAssociada['id'] !== $conta['id']) {
                $contasControllerAssociada = new ContasController($contaAssociada['id']);
                $contasControllerAssociada->updateOnly([
                  'situacao' => 'CA'
                ]);
              }
            }
          }
        }
      }

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

  public function pagamento($data)
  {
    try {
      if (!isset($data['id_conta']) || empty($data['id_conta'])) {
        throw new \Exception("O ID da conta é obrigatório");
      }

      $contaUsuariosController = new ContasUsuariosController();
      $formasPagamentosController = new FormasPagamentoController();
      $clientesController = new ClientesController();

      $conta = $contaUsuariosController->findOnly([
        'filter' => ['id' => $data['id_conta']],
        'limit' => 1,
        'includes' => [
          'empresas' => true,
          'usuarios' => true
        ]
      ]);

      if (count($conta) > 0) {
        $conta = $conta[0];
      } else {
        $conta = null;
      }

      if (empty($conta)) {
        throw new \Exception("Conta não encontrada");
      }

      $empresa = $conta['empresas'][0];
      $usuario = $conta['usuarios'][0];

      foreach ($conta['empresas'] as $key => $value) {
        if ($value['principal'] === 'S') {
          $empresa = $value;
        }
      }

      $contaController = new ContasController();
      $contaExistente = $contaController->findOnly([
        'filter' => [
          'id_conta' => $data['id_conta'],
          'origem' => 'M',
          'natureza' => 'D',
          'situacao' => 'PE'
        ],
        "includes" => [
          "conta_pagamento" => [
            "includes" => [
              "mercado_pago_pagamentos" => true
            ]
          ]
        ],
        'limit' => 1
      ]);

      $exiteBoleto = false;
      $exitePix = false;

      if ($contaExistente && count($contaExistente) > 0) {
        $contaExistente = $contaExistente[0];

        foreach ($contaExistente['conta_pagamento'] as $pagamento) {
          if ($pagamento['mercado_pago_pagamentos']['tipo'] === 'B') {
            $exiteBoleto = true;
          }
          if ($pagamento['mercado_pago_pagamentos']['tipo'] === 'P') {
            $exitePix = true;
          }
        }

        $dataVencimentoConta = DateTime::createFromFormat('Y-m-d', $contaExistente['vencimento']);
        $hoje = new DateTime('today');

        if ($dataVencimentoConta <= $hoje) {
          $novaDataVencimento = date('Y-m-d', strtotime('+1 day'));

          $novaContaController = new ContasController($contaExistente['id']);
          $contaExistente = $novaContaController->updateOnly([
            'vencimento' => $novaDataVencimento
          ]);
        }
      } else {
        $forma = $formasPagamentosController->findOnly([
          'filter' => [
            'id_conta' => $data['id_conta'],
            'id_tipo' => 11
          ],
          'limit' => 1
        ]);

        if ($forma && count($forma) > 0) {
          $forma = $forma[0];
        } else {
          $forma = null;
        }

        $contasAdms = $contaUsuariosController->findOnly([
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

        $vencimento = $conta['vencimento'] ?? date('Y-m-d', strtotime('+1 day'));

        $dataVencimentoConta = DateTime::createFromFormat('Y-m-d', $vencimento);
        $hoje = new DateTime('today');

        if ($dataVencimentoConta <= $hoje) {
          $vencimento = date('Y-m-d', strtotime('+1 day'));
        }

        $contasGeradas = [];

        if ($contasAdms) {
          foreach ($contasAdms as $contaAdm) {
            $forma = $formasPagamentosController->findOnly([
              "filter" => [
                "id_conta" => $contaAdm['id'],
                "id_tipo" => 11
              ],
            ]);

            if ($forma && isset($forma[0])) {
              $forma = $forma[0];
            }

            $cliente = $clientesController->findOnly([
              'filter' => [
                'documento' => $empresa['cnpj'],
                'id_conta' => $contaAdm['id']
              ],
              'limit' => 1
            ]);

            if ($cliente && count($cliente) > 0) {
              $cliente = $cliente[0];
            } else {
              $cliente = null;
            }

            $contasGeradas[] = $contaController->createOnly([
              'id_conta' => $contaAdm['id'],
              'id_empresa' => $contaAdm['empresas'][0]['id'] ?? null,
              'id_cliente' => $cliente ? $cliente['id'] : null,
              'id_forma' => $forma['id'] ?? null,
              'descricao'  => 'Assinatura mensal - ' . $conta['responsavel'],
              'valor' => $conta['valor_mensal'] ?? 0.00,
              'origem' => 'M',
              'natureza' => 'R',
              'condicao' => 'A',
              'vencimento' => $vencimento,
              'observacoes' => 'Geração automática de conta mensalidade',
              'situacao' => 'PE',
              'token_unico' => $tokenUnico,
            ]);
          }
        }

        $contaExistente = $this->createOnly([
          'id_conta' => $data['id_conta'],
          'id_empresa' => $empresa['id'],
          'id_conta' => $data['id_conta'],
          'id_forma' => $forma ? $forma['id'] : null,
          'descricao' => 'Mensalidade Sistema',
          'valor' => floatval($conta['valor_mensal']),
          'vencimento' => $vencimento,
          'observacoes' => 'Geração automática de conta para pagamento da mensalidade do sistema',
          'origem' => 'M',
          'natureza' => 'D',
          'situacao' => 'PE',
          'token_unico' => $tokenUnico,
        ]);
      }

      if (!$exitePix) {
        $mercadoPagoController = new MercadoPagoController();
        $pixGerado = $mercadoPagoController->gerarPixApenas([
          'valor' => floatval($contaExistente['valor'] ?? 0.00),
          'descricao' => 'Cobrança de Mensalidade do Sistema',
          'email' => $usuario['email'] ?? ($empresa['email'] ?? null),
          'nome' => $usuario['nome'] ?? 'Cliente',
          'cnpj' => $empresa['cnpj'] ?? null,
          'logradouro' => $empresa['logradouro'] ?? null,
          'numero' => $empresa['numero'] ?? null,
          'bairro' => $empresa['bairro'] ?? null,
          'cidade' => $empresa['cidade'] ?? null,
          'uf' => $empresa['uf'] ?? null,
          'cep' => $empresa['cep'] ?? null,
          'dataVencimento' => $contaExistente['vencimento'],
        ]);

        $contaExistenteController = new ContasController($contaExistente['id']);
        $contaExistente = $contaExistenteController->updateOnly([
          'descricao' => $contaExistente['descricao'],
          'conta_pagamento' => [
            'create' => [
              [
                "id_pagamento" => $pixGerado['id'],
              ]
            ]
          ]
        ]);

        $contasAssociadas = $contaController->findOnly([
          'filter' => [
            'token_unico' => $contaExistente['token_unico']
          ]
        ]);

        foreach ($contasAssociadas as $key => $contaAssociada) {
          if ($contaAssociada['id'] !== $contaExistente['id']) {
            $contaAssociadaController = new ContasController($contaAssociada['id']);
            $contaAssociada = $contaAssociadaController->updateOnly([
              'descricao' => $contaAssociada['descricao'],
              'conta_pagamento' => [
                'create' => [
                  [
                    "id_pagamento" => $pixGerado['id'],
                  ]
                ]
              ]
            ]);
          }
        }
      }

      if (!$exiteBoleto) {
        $mercadoPagoController = new MercadoPagoController();
        $boletoGerado = $mercadoPagoController->gerarBoletoApenas([
          'valor' => floatval($contaExistente['valor'] ?? 0.00),
          'descricao' => 'Cobrança de Mensalidade do Sistema',
          'email' => $usuario['email'] ?? ($empresa['email'] ?? null),
          'nome' => $usuario['nome'] ?? 'Cliente',
          'cnpj' => $empresa['cnpj'] ?? null,
          'logradouro' => $empresa['logradouro'] ?? null,
          'numero' => $empresa['numero'] ?? null,
          'bairro' => $empresa['bairro'] ?? null,
          'cidade' => $empresa['cidade'] ?? null,
          'uf' => $empresa['uf'] ?? null,
          'cep' => $empresa['cep'] ?? null,
          'dataVencimento' => $contaExistente['vencimento'],
        ]);

        $contaExistenteController = new ContasController($contaExistente['id']);
        $contaExistente = $contaExistenteController->updateOnly([
          'descricao' => $contaExistente['descricao'],
          'conta_pagamento' => [
            'create' => [
              [
                "id_pagamento" => $boletoGerado['id'],
              ]
            ]
          ]
        ]);

        $contasAssociadas = $contaController->findOnly([
          'filter' => [
            'token_unico' => $contaExistente['token_unico']
          ]
        ]);

        foreach ($contasAssociadas as $key => $contaAssociada) {
          if ($contaAssociada['id'] !== $contaExistente['id']) {
            $contaAssociadaController = new ContasController($contaAssociada['id']);
            $contaAssociada = $contaAssociadaController->updateOnly([
              'descricao' => $contaAssociada['descricao'],
              'conta_pagamento' => [
                'create' => [
                  [
                    "id_pagamento" => $boletoGerado['id'],
                  ]
                ]
              ]
            ]);
          }
        }
      }

      $contaRetornoController = new ContasController();
      $contaRetorno = $contaRetornoController->findOnly([
        'filter' => [
          'id' => $contaExistente['id']
        ],
        "includes" => [
          "conta_pagamento" => [
            "includes" => [
              "mercado_pago_pagamentos" => true
            ]
          ]
        ],
        'limit' => 1
      ]);

      if ($contaRetorno && count($contaRetorno) > 0) {
        $contaRetorno = $contaRetorno[0];
      } else {
        throw new \Exception("Erro ao gerar pagamento da conta");
      }

      http_response_code(200);
      echo json_encode($contaRetorno);
    } catch (\Exception $e) {
      http_response_code(500);
      echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
      ]);
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
