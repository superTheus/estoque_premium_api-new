<?php

namespace App\Controllers;

use App\Models\ContasModel;
use App\Models\VendaPagamentosModel;
use App\Models\VendasModel;
use Dotenv\Dotenv;

class VendasController extends ControllerBase
{
    public function __construct($id = null)
    {
        $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
        $dotenv->load();

        $this->model = new VendasModel($id ? $id : null);
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

    public function duplicate($data)
    {
        try {
            $data['id_conta'] = $_REQUEST['id_conta'];

            if (!isset($data['venda']) || empty($data['venda'])) {
                throw new \Exception("Venda a ser duplicada não foi informada.");
            }

            if (!isset($data['operacao']) || empty($data['operacao'])) {
                throw new \Exception("Operação não foi informada.");
            }

            $operacao = new OperacoesController();
            $operacaoData = $operacao->findOnly([
                'filter' => ['id' => $data['operacao']]
            ])[0] ?? null;

            if (!$operacaoData) {
                throw new \Exception("Operação informada não foi encontrada.");
            }

            $vendaController = new VendasController();
            $vendaData = $vendaController->findOnly([
                'filter' => ['id' => $data['venda']],
                'includes' => [
                    'venda_produtos' => true
                ]
            ])[0] ?? null;

            if (!$vendaData) {
                throw new \Exception("Venda informada não foi encontrada.");
            }

            $this->create([
                "id_conta" => $vendaData['id_conta'],
                "id_empresa" => $vendaData['id_empresa'],
                "id_operacao" => $operacaoData['id'],
                "id_cliente" => $vendaData['id_cliente'],
                "id_vendedor" => $vendaData['id_vendedor'],
                "total" => $vendaData['total'],
                "total_pago" => $vendaData['total_pago'],
                "total_desconto" => $vendaData['total_desconto'],
                "total_troco" => $vendaData['total_troco'],
                "status" => 'AB',
                "observacao_nota" => $vendaData['observacao_nota'],
                "venda_produtos" => $vendaData['venda_produtos'],
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["message" => $e->getMessage()]);
        }
    }

    public function reopen($data)
    {
        try {
            if (!isset($data['venda']) || empty($data['venda'])) {
                throw new \Exception("Venda a ser aberta não foi informada.");
            }

            $vendaController = new VendasController();
            $vendaData = $vendaController->findOnly([
                'filter' => ['id' => $data['venda']],
                'includes' => [
                    'venda_produtos' => true,
                    'venda_pagamentos' => true,
                    'contas' => true
                ]
            ])[0] ?? null;

            if (!$vendaData) {
                throw new \Exception("Venda informada não foi encontrada.");
            }

            if ($vendaData['status'] !== 'FE') {
                throw new \Exception("Apenas vendas finalizadas podem ser reabertas.");
            }

            if ($vendaData['contas']) {
                foreach ($vendaData['contas'] as $conta) {
                    $contaModel = new ContasModel($conta['id']);
                    $contaModel->delete();
                }
            }

            if ($vendaData['venda_pagamentos']) {
                foreach ($vendaData['venda_pagamentos'] as $pagamento) {
                    $vendasModel = new VendaPagamentosModel($pagamento['id']);
                    $vendasModel->delete();
                }
            }

            $vendaController = new VendasController($vendaData['id']);
            $vendaController->update([
                "status" => 'AB',
                "total_pago" => 0,
                "total_desconto" => 0,
                "total_troco" => 0
            ]);
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
                    }
                }

                if (isset($data['status']) && $currentData['status'] !== $data['status'] && $data['status'] === 'FE') {
                    $operacoesController = new OperacoesController();
                    $vendaProdutosController = new VendaProdutosController();
                    $formasPagamentoController = new VendaPagamentosController();
                    $formaPagamentoController = new FormasPagamentoController();
                    $produtosController = new ProdutosController();
                    $produtoEstoqueController = new ProdutosEstoqueController();
                    $contasController = new ContasController();

                    $operacao = $operacoesController->findOnly([
                        'filter' => ['id' => $currentData['id_operacao']]
                    ])[0] ?? null;

                    if ($operacao && ($operacao['mov_estoque'] === 'S' || $operacao['mov_estoque'] === 'E')) {
                        $itens = $vendaProdutosController->findOnly([
                            'filter' => ['id_venda' => $currentData['id']]
                        ]);

                        foreach ($itens as $item) {
                            $produto = $produtosController->findOnly([
                                'filter' => ['id' => $item['id_produto']]
                            ])[0] ?? null;

                            if ($produto && $produto['controla_estoque'] === 'S') {
                                $estoque = $produtoEstoqueController->findOnly([
                                    'filter' => [
                                        'id_produto' => $item['id_produto'],
                                        'id_empresa' => $currentData['id_empresa']
                                    ]
                                ])[0] ?? null;

                                if ($estoque) {
                                    if ($operacao['mov_estoque'] === 'S') {
                                        $newQuantidade = $estoque['estoque'] - $item['quantidade'];
                                    } else {
                                        $newQuantidade = $estoque['estoque'] + $item['quantidade'];
                                    }

                                    $produtoEstoqueController = new ProdutosEstoqueController($estoque['id']);
                                    $produtoEstoqueController->updateOnly(['estoque' => $newQuantidade]);

                                    $produtoMovimentacaoController = new ProdutosMovimentacaoController();
                                    $produtoMovimentacaoController->createOnly([
                                        'id_conta' => $currentData['id_conta'],
                                        'id_empresa' => $currentData['id_empresa'],
                                        'id_produto' => $item['id_produto'],
                                        'id_venda' => $currentData['id'],
                                        'descricao' => ($operacao['mov_estoque'] === 'S' ? 'Saída' : 'Entrada') . ' via venda #' . str_pad($currentData['id'], 5, '0', STR_PAD_LEFT),
                                        'estoque_anterior' => $estoque['estoque'],
                                        'estoque_atual' => $newQuantidade,
                                        'estoque_movimentado' => $item['quantidade'],
                                        'tipo' => $operacao['mov_estoque'] === 'S' ? 'S' : 'E',
                                    ]);
                                }
                            }
                        }
                    }

                    $formasPagamento = $formasPagamentoController->findOnly([
                        'filter' => ['id_venda' => $currentData['id']]
                    ]);

                    foreach ($formasPagamento as $pagamento) {
                        $forma = $formaPagamentoController->findOnly([
                            'filter' => ['id' => $pagamento['id_forma']],
                            "includes" => [
                                "tipos_pagamento" => true
                            ]
                        ])[0] ?? null;

                        $conta = [
                            "id_conta" => $currentData['id_conta'],
                            "id_empresa" => $currentData['id_empresa'],
                            "id_cliente" => $currentData['id_cliente'] ?? null,
                            "id_venda" => $currentData['id'],
                            "descricao" => "Recebimento de " . $forma['descricao'] . " da venda #" . str_pad($currentData['id'], 5, '0', STR_PAD_LEFT),
                            "valor" => $pagamento['valor'],
                            "natureza" => 'R',
                            "vencimento" => date('Y-m-d'),
                            "data_pagamento" => $pagamento['data'],
                            "situacao" => $pagamento['status']
                        ];

                        $contasController->createOnly($conta);
                    }
                }

                if (isset($data['status']) && $currentData['status'] !== $data['status'] && $data['status'] === 'CA') {
                    $produtoMovimentacaoController = new ProdutosMovimentacaoController();
                    $movimentacoes = $produtoMovimentacaoController->findOnly([
                        'filter' => ['id_venda' => $currentData['id']]
                    ]);

                    if($movimentacoes) {
                        foreach ($movimentacoes as $movimentacao) {
                            $produtoEstoqueController = new ProdutosEstoqueController();
                            $estoque = $produtoEstoqueController->findOnly([
                                'filter' => [
                                    'id_produto' => $movimentacao['id_produto'],
                                    'id_empresa' => $currentData['id_empresa']
                                ]
                            ])[0] ?? null;

                            if ($estoque) {
                                if ($movimentacao['tipo'] === 'E') {
                                    $newQuantidade = $estoque['estoque'] - $movimentacao['estoque_movimentado'];
                                } else {
                                    $newQuantidade = $estoque['estoque'] + $movimentacao['estoque_movimentado'];
                                }

                                $produtoEstoqueController = new ProdutosEstoqueController($estoque['id']);
                                $produtoEstoqueController->updateOnly(['estoque' => $newQuantidade]);

                                $produtoMovimentacaoController = new ProdutosMovimentacaoController();
                                $produtoMovimentacaoController->createOnly([
                                    'id_conta' => $currentData['id_conta'],
                                    'id_empresa' => $currentData['id_empresa'],
                                    'id_produto' => $movimentacao['id_produto'],
                                    'id_venda' => $currentData['id'],
                                    'descricao' => ($movimentacao['tipo'] === 'E' ? 'Saída' : 'Entrada') . ' de cancelamento via venda #' . str_pad($currentData['id'], 5, '0', STR_PAD_LEFT),
                                    'estoque_anterior' => $estoque['estoque'],
                                    'estoque_atual' => $newQuantidade,
                                    'estoque_movimentado' => $movimentacao['estoque_movimentado'],
                                    'tipo' => $movimentacao['tipo'] === 'E' ? 'S' : 'E',
                                ]);
                            }
                        }
                    }

                    $contasController = new ContasController();
                    $contas = $contasController->findOnly([
                        'filter' => ['id_venda' => $currentData['id']]
                    ]);

                    foreach ($contas as $conta) {
                        $contasController = new ContasController($conta['id']);
                        $contasController->updateOnly([
                            "situacao" => "CA",
                            "obervacoes" => ($conta['obervacoes'] ? $conta['obervacoes'] . " | " : "") . "Conta cancelada devido ao cancelamento da venda #" . str_pad($currentData['id'], 5, '0', STR_PAD_LEFT)
                        ]);
                    }

                    if ($currentData['nota_emitida'] === 'S') {
                        $emissao = new \DateTime($currentData['dthr_emissao']);
                        $agora = new \DateTime();
                        $intervalo = $emissao->diff($agora);
                        $minutosDecorridos = ($intervalo->days * 24 * 60) + ($intervalo->h * 60) + $intervalo->i;

                        $podeCancelarNota = true;

                        if($currentData['tipo'] == 'NFCE' && $minutosDecorridos > 30) {
                            $this->model->update([
                                'messagem_error' => 'Prazo para cancelamento excedido (30 minutos). Favor realizar uma carta de correção.',
                            ]);

                            $podeCancelarNota = false;
                        } else if($currentData['tipo'] == 'NFE' && $minutosDecorridos > 1440) {
                            $this->model->update([
                                'messagem_error' => 'Prazo para cancelamento excedido (24 horas). Favor realizar uma carta de correção.',
                            ]);

                            $podeCancelarNota = false;
                        }

                        if($podeCancelarNota) {
                            $fiscalController = new FiscalController();
                            $fiscalController->cancelarNotaSomente($currentData['id']);
                        }
                    }
                }

                return $result;
            } else {
                throw new \Exception("Venda não encontrada.");
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
