<?php

namespace App\Controllers;

use App\Models\ContasModel;
use Dotenv\Dotenv;
use App\Models\MercadoPagoModel;
use App\Models\ContasUsuariosModel;
use DateTime;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Exceptions\MPApiException;

class MercadoPagoController extends ControllerBase
{
    private $accessToken;

    public function __construct($id = null)
    {
        $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
        $dotenv->load();

        $this->accessToken = $_ENV['MP_ACCESS_TOKEN'];
        $this->model = new MercadoPagoModel($id ? $id : null);

        // Configurar o SDK do Mercado Pago
        MercadoPagoConfig::setAccessToken($this->accessToken);
    }

    public function findOnly($data = [])
    {
        try {
            $filter = $data && isset($data['filter']) ? $data['filter'] : [];
            $limit = $data && isset($data['limit']) ? $data['limit'] : null;
            $offset = $data && isset($data['offset']) ? $data['offset'] : null;
            $order = $data && isset($data['order']) ? $data['order'] : [];
            $results = $this->model->find($filter, $limit, $offset, $order);

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

        try {
            http_response_code(200);
            echo json_encode([
                "total" => $this->model->totalCount($filter)['total'],
                "data" => $this->findOnly($data)
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["message" => $e->getMessage()]);
        }
    }

    public function create($data)
    {
        try {
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

                http_response_code(200);
                echo json_encode($result);
            } else {
                http_response_code(404);
                echo json_encode(["message" => "Registro não encontrado"]);
            }
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["message" => $e->getMessage()]);
        }
    }

    public function gerarPixApenas($data)
    {
        try {
            $client = new PaymentClient();

            $paymentData = [
                "transaction_amount" => $data['valor'],
                "description" => $data['descricao'],
                "payment_method_id" => "pix",
                "payer" => [
                    "email" => $data['email'],
                    "first_name" => $data['nome'],
                    "identification" => [
                        "type" => "CNPJ",
                        "number" => $data['cnpj']
                    ]
                ],
                "notification_url" => $_ENV['URL_WEBHOOKS']
            ];

            $payment = $client->create($paymentData);

            $pagamentoData = [
                'payment_id' => $payment->id,
                'status' => $payment->status,
                'valor' => $data['valor'],
                'qr_code' => $payment->point_of_interaction->transaction_data->qr_code ?? null,
                'qr_code_base64' => $payment->point_of_interaction->transaction_data->qr_code_base64 ?? null,
                'ticket_url' => $payment->point_of_interaction->transaction_data->ticket_url ?? null,
                'payment_data' => json_encode($payment)
            ];

            $resultado = $this->model->insert($pagamentoData);

            return $resultado;
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * Webhook para receber notificações do Mercado Pago
     */
    public function webhook()
    {
        try {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            error_log("Webhook Mercado Pago: " . $input);

            if (!isset($data['type']) || $data['type'] !== 'payment') {
                http_response_code(200);
                error_log("Notificação ignorada");
                echo json_encode(["message" => "Notificação ignorada"]);
                return;
            }

            $paymentId = $data['data']['id'] ?? null;

            if (!$paymentId) {
                error_log("Payment ID não encontrado na notificação");
                throw new \Exception("Payment ID não encontrado na notificação");
            }

            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => 'https://api.mercadopago.com/v1/payments/' . $paymentId,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => [
                    'accept: application/json',
                    'content-type: application/json',
                    'Authorization: Bearer ' . $_ENV['MP_ACCESS_TOKEN']
                ]
            ]);

            $response = curl_exec($curl);
            $payment = json_decode($response, true);

            die(json_encode($payment));

            $pagamento = $this->model->findByPaymentId($paymentId);

            if (!$pagamento) {
                error_log("Pagamento não encontrado no banco: " . $paymentId);
                http_response_code(404);
                echo json_encode(["message" => "Pagamento não encontrado"]);
                return;
            }

            $modelInstance = new MercadoPagoModel($pagamento['id']);
            $modelInstance->update([
                'status' => $payment['status'],
                'payment_data' => json_encode($payment)
            ]);

            
            if ($payment['status'] === 'approved') {
                $contasPagamentoController = new ContasPagamentoController();
                $contasPagamento = $contasPagamentoController->findOnly([
                    'filter' => [
                        'id_pagamento' => $pagamento['id']
                    ]
                ]);

                foreach ($contasPagamento as $contaPagamento) {
                    $contaPagamentoControllerInstance = new ContasController($contaPagamento['id_conta']);
                    $contaPagamentoControllerInstance->updateOnly([
                        'situacao' => 'PA'
                    ]);

                    $contaFinanceiroModel = new ContasModel($contaPagamento['id_conta']);
                    $contaFinanceiro = $contaFinanceiroModel->current();

                    $contaUsuarioModel = new ContasUsuariosModel($contaFinanceiro['id_conta']);
                    $contaDados = $contaUsuarioModel->current();

                    if($contaDados['tipo'] === 'C') {
                        $this->atualizarVencimentoConta($contaFinanceiro['id_conta']);
                    }
                }
            }

            http_response_code(200);
            echo json_encode([
                "success" => true,
                "message" => "Webhook processado com sucesso"
            ]);
        } catch (\Exception $e) {
            error_log("Erro no webhook: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                "success" => false,
                "message" => $e->getMessage()
            ]);
        }
    }

    private function atualizarVencimentoConta($idConta)
    {
        try {
            $contasUsuariosModel = new ContasUsuariosModel($idConta);
            $conta = $contasUsuariosModel->findOnly([
                'filter' => [
                    'id' => $idConta
                ],
                "includes" => [
                    "empresas" => true
                ],
                'limit' => 1
            ]);

            if ($conta && count($conta) > 0) {
                $conta = $conta[0];
            } else {
                $conta = null;
            }

            if (empty($conta)) {
                throw new \Exception("Conta não encontrada");
            }

            $empresa = $conta['empresas'][0] ?? null;
            $dataVencimento = $conta['vencimento'] ?? date('Y-m-d');

            if (strtotime($dataVencimento) < strtotime('today')) {
                $dataVencimento = date('Y-m-d');
            }

            $novaDataVencimento = date('Y-m-d', strtotime($dataVencimento . ' +1 month'));

            $contasUsuariosModel->update([
                'vencimento' => $novaDataVencimento,
                'status' => 'A'
            ]);

            $contaUsuariosController = new ContasUsuariosController();
            $formasPagamentosController = new FormasPagamentoController();
            $clientesController = new ClientesController();
            $contaController = new ContasController();

            $forma = $formasPagamentosController->findOnly([
                'filter' => [
                    'id_conta' => $conta['id_conta'],
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
                            'documento' => $contaAdm['empresas'][0]['cnpj'] ?? null,
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
                        'vencimento' => $novaDataVencimento,
                        'observacoes' => 'Geração automática de conta mensalidade',
                        'situacao' => 'PE',
                        'token_unico' => $tokenUnico,
                    ]);
                }
            }

            $contaController->createOnly([
                'id_conta' => $conta['id_conta'],
                'id_empresa' => $empresa['id'],
                'id_conta' => $conta['id_conta'],
                'id_forma' => $forma ? $forma['id'] : null,
                'descricao' => 'Mensalidade Sistema',
                'valor' => floatval($conta['valor_mensal']),
                'vencimento' => $novaDataVencimento,
                'observacoes' => 'Geração automática de conta para pagamento da mensalidade do sistema',
                'origem' => 'M',
                'natureza' => 'D',
                'situacao' => 'PE',
                'token_unico' => $tokenUnico,
            ]);

            error_log("Data de vencimento atualizada para conta {$idConta}: {$novaDataVencimento}");
        } catch (\Exception $e) {
            error_log("Erro ao atualizar vencimento da conta: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Consultar o status de um pagamento
     */
    public function consultarPagamento($data)
    {
        try {
            if (!isset($data['id']) || empty($data['id'])) {
                throw new \Exception("O ID do pagamento é obrigatório");
            }

            $pagamento = $this->model->find(['id' => $data['id']]);

            if (empty($pagamento)) {
                throw new \Exception("Pagamento não encontrado");
            }

            $pagamento = $pagamento[0];

            http_response_code(200);
            echo json_encode($pagamento);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                "success" => false,
                "message" => $e->getMessage()
            ]);
        }
    }

    public function gerarBoletoApenas($data)
    {
        try {
            $client = new PaymentClient();
            $payment = $client->create([
                "transaction_amount" => $data['valor'],
                "description" => $data['descricao'],
                "payment_method_id" => "bolbradesco",
                "payer" => [
                    "email" => $data['email'] ?? "pagamento@sistema.com",
                    "first_name" => $data['responsavel'] ?? "Cliente",
                    "identification" => [
                        "type" => "CNPJ",
                        "number" => $data['cnpj'] ?? "00000000000"
                    ],
                    "address" => [
                        "zip_code" => preg_replace('/\D/', '', $data['cep'] ?? ""),
                        "street_name" => $data['logradouro'] ?? "",
                        "street_number" => $data['numero'] ?? "S/N",
                        "neighborhood" => $data['bairro'] ?? "",
                        "city" => $data['cidade'] ?? "",
                        "federal_unit" => $data['uf'] ?? ""
                    ]
                ],
                "date_of_expiration" => $data['dataVencimento'] . "T23:59:59.000-04:00"
            ]);

            $pagamentoData = [
                'payment_id' => $payment->id,
                'status' => $payment->status,
                'valor' => $data['valor'],
                'tipo' => 'B',
                'qr_code' => null,
                'qr_code_base64' => null,
                'ticket_url' => $payment->transaction_details->external_resource_url ?? null,
                'payment_data' => json_encode($payment)
            ];

            $resultado = $this->model->insert($pagamentoData);

            return $resultado;
        } catch (MPApiException $e) {
            $apiResponse = $e->getApiResponse();
            $content = $apiResponse ? $apiResponse->getContent() : null;

            error_log("Erro MPApiException Boleto: " . json_encode([
                'message' => $e->getMessage(),
                'status_code' => $e->getStatusCode(),
                'content' => $content
            ]));

            throw new \Exception(json_encode([
                "success" => false,
                "message" => "Erro na API do Mercado Pago: " . $e->getMessage(),
                "status_code" => $e->getStatusCode(),
                "details" => $content
            ]));
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function gerarBoleto($data)
    {
        try {
            return $this->gerarBoletoApenas($data);
        } catch (\Exception $e) {
            error_log("Erro Exception Boleto: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                "success" => false,
                "message" => $e->getMessage()
            ]);
        }
    }

    private function cancelarApenas($id)
    {
        try {
            $mercadoPagamentoModel = new MercadoPagoModel($id);
            $pagamentoCurrent = $mercadoPagamentoModel->current();
            $client = new PaymentClient();
            $cancelledPayment = $client->cancel($pagamentoCurrent['payment_id']);

            $contaPagamentoController = new ContasController();
            $contasPagamento = $contaPagamentoController->findOnly([
                'filter' => [
                    'id_pagamento_mercado_pago' => $id
                ]
            ]);

            foreach ($contasPagamento as $contaPagamento) {
                $contaPagamentoControllerInstance = new ContasController($contaPagamento['id']);
                $contaPagamentoControllerInstance->update([
                    'situacao' => 'CA'
                ]);
            }

            return $mercadoPagamentoModel->update([
                'status' => $cancelledPayment->status,
                'payment_data' => json_encode($cancelledPayment)
            ]);
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function cancelarPagamento()
    {
        try {
            $pagamentoCurrent = $this->model->current();

            if (empty($pagamentoCurrent) || !isset($pagamentoCurrent['id'])) {
                throw new \Exception("Pagamento não encontrado");
            }

            if ($pagamentoCurrent['status'] === 'cancelled') {
                throw new \Exception("Este pagamento já foi cancelado");
            }

            if ($pagamentoCurrent['status'] === 'approved') {
                throw new \Exception("Não é possível cancelar um pagamento já aprovado");
            }

            $paymentId = $pagamentoCurrent['payment_id'];

            $client = new PaymentClient();
            $cancelledPayment = $client->cancel($paymentId);

            $this->model->update([
                'status' => $cancelledPayment->status,
                'payment_data' => json_encode($cancelledPayment)
            ]);

            $pagamentoAtualizado = $this->model->current();

            $contaPagamentoController = new ContasController();
            $contasPagamento = $contaPagamentoController->findOnly([
                'filter' => [
                    'id_pagamento_mercado_pago' => $pagamentoCurrent['id']
                ]
            ]);

            foreach ($contasPagamento as $contaPagamento) {
                $contaPagamentoControllerInstance = new ContasController($contaPagamento['id']);
                $contaPagamentoControllerInstance->update([
                    'situacao' => 'CA'
                ]);
            }

            http_response_code(200);
            echo json_encode([
                "success" => true,
                "message" => "Pagamento cancelado com sucesso",
                "data" => $pagamentoAtualizado
            ]);
        } catch (MPApiException $e) {
            $apiResponse = $e->getApiResponse();
            $content = $apiResponse ? $apiResponse->getContent() : null;

            error_log("Erro MPApiException Cancelamento: " . json_encode([
                'message' => $e->getMessage(),
                'status_code' => $e->getStatusCode(),
                'content' => $content
            ]));

            http_response_code(500);
            echo json_encode([
                "success" => false,
                "message" => "Erro na API do Mercado Pago: " . $e->getMessage(),
                "status_code" => $e->getStatusCode(),
                "details" => $content
            ]);
        } catch (\Exception $e) {
            error_log("Erro Exception Cancelamento: " . $e->getMessage());
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
}
