<?php

namespace App\Controllers;

use Dotenv\Dotenv;
use App\Models\MercadoPagoModel;
use App\Models\ContasUsuariosModel;
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

    /**
     * Gera um pagamento PIX para uma conta de usuário
     * Recebe: { "id_conta": 123 }
     */
    public function gerarPix($data)
    {
        try {
            if (!isset($data['id_conta']) || empty($data['id_conta'])) {
                throw new \Exception("O ID da conta é obrigatório");
            }
            $contaController = new ContasUsuariosController();
            $conta = $contaController->findOnly([
                'filter' => ['id' => $data['id_conta']],
                'limit' => 1,
                'includes' => [
                    'empresas' => true
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

            if (!isset($conta['valor_mensal']) || empty($conta['valor_mensal'])) {
                throw new \Exception("Valor mensal não definido para esta conta");
            }

            $valorMensal = floatval($conta['valor_mensal']);

            if ($valorMensal <= 0) {
                throw new \Exception("Valor mensal inválido");
            }

            $empresa = $conta['empresas'][0];

            // Criar o pagamento PIX no Mercado Pago
            $client = new PaymentClient();

            $paymentData = [
                "transaction_amount" => $valorMensal,
                "description" => "Assinatura mensal - " . ($conta['responsavel'] ?? 'Sistema'),
                "payment_method_id" => "pix",
                "payer" => [
                    "email" => $conta['email'] ?? "pagamento@sistema.com",
                    "first_name" => $conta['responsavel'] ?? "Cliente",
                    "identification" => [
                        "type" => "CNPJ",
                        "number" => $empresa['cnpj'] ?? "00000000000"
                    ]
                ],
                "notification_url" => $_ENV['URL_WEBHOOKS']
            ];

            $payment = $client->create($paymentData);

            // Salvar o pagamento no banco de dados
            $pagamentoData = [
                'id_conta' => $data['id_conta'],
                'payment_id' => $payment->id,
                'status' => $payment->status,
                'valor' => $valorMensal,
                'qr_code' => $payment->point_of_interaction->transaction_data->qr_code ?? null,
                'qr_code_base64' => $payment->point_of_interaction->transaction_data->qr_code_base64 ?? null,
                'ticket_url' => $payment->point_of_interaction->transaction_data->ticket_url ?? null,
                'payment_data' => json_encode($payment)
            ];

            $resultado = $this->model->insert($pagamentoData);

            http_response_code(200);
            echo json_encode([
                "success" => true,
                "message" => "PIX gerado com sucesso",
                "data" => [
                    "id" => $resultado['id'],
                    "payment_id" => $payment->id,
                    "status" => $payment->status,
                    "valor" => $valorMensal,
                    "qr_code" => $payment->point_of_interaction->transaction_data->qr_code ?? null,
                    "qr_code_base64" => $payment->point_of_interaction->transaction_data->qr_code_base64 ?? null,
                    "ticket_url" => $payment->point_of_interaction->transaction_data->ticket_url ?? null,
                    "expiration_date" => $payment->date_of_expiration ?? null
                ]
            ]);
        } catch (MPApiException $e) {
            $apiResponse = $e->getApiResponse();
            $content = $apiResponse ? $apiResponse->getContent() : null;
            
            error_log("Erro MPApiException PIX: " . json_encode([
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
            error_log("Erro Exception PIX: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                "success" => false,
                "message" => $e->getMessage()
            ]);
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
                echo json_encode(["message" => "Notificação ignorada"]);
                return;
            }

            $paymentId = $data['data']['id'] ?? null;

            if (!$paymentId) {
                throw new \Exception("Payment ID não encontrado na notificação");
            }

            $client = new PaymentClient();
            $payment = $client->get($paymentId);

            $pagamento = $this->model->findByPaymentId($paymentId);

            if (!$pagamento) {
                error_log("Pagamento não encontrado no banco: " . $paymentId);
                http_response_code(404);
                echo json_encode(["message" => "Pagamento não encontrado"]);
                return;
            }

            $modelInstance = new MercadoPagoModel($pagamento['id']);
            $modelInstance->update([
                'status' => $payment->status,
                'payment_data' => json_encode($payment)
            ]);

            if ($payment->status === 'approved') {
                $this->atualizarVencimentoConta($pagamento['id_conta']);
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

    /**
     * Atualiza a data de vencimento da conta adicionando um mês
     */
    private function atualizarVencimentoConta($idConta)
    {
        try {
            $contasUsuariosModel = new ContasUsuariosModel($idConta);
            $conta = $contasUsuariosModel->current();

            if (empty($conta)) {
                throw new \Exception("Conta não encontrada");
            }

            // Obter a data de vencimento atual ou usar a data atual
            $dataVencimento = $conta['vencimento'] ?? date('Y-m-d');

            // Se a data de vencimento já passou, usar a data atual
            if (strtotime($dataVencimento) < strtotime('today')) {
                $dataVencimento = date('Y-m-d');
            }

            // Adicionar um mês
            $novaDataVencimento = date('Y-m-d', strtotime($dataVencimento . ' +1 month'));

            // Atualizar a conta
            $contasUsuariosModel->update([
                'vencimento' => $novaDataVencimento,
                'status' => 'A' // Ativar a conta caso esteja inativa
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

            // Consultar o status atualizado na API do Mercado Pago
            $client = new PaymentClient();
            $payment = $client->get($pagamento['payment_id']);

            // Atualizar o status no banco de dados
            $modelInstance = new MercadoPagoModel($pagamento['id']);
            $modelInstance->update([
                'status' => $payment->status,
                'payment_data' => json_encode($payment)
            ]);

            // Se foi aprovado e ainda não foi processado, atualizar a conta
            if ($payment->status === 'approved' && $pagamento['status'] !== 'approved') {
                $this->atualizarVencimentoConta($pagamento['id_conta']);
            }

            http_response_code(200);
            echo json_encode([
                "success" => true,
                "data" => [
                    "id" => $pagamento['id'],
                    "payment_id" => $pagamento['payment_id'],
                    "status" => $payment->status,
                    "status_detail" => $payment->status_detail ?? null,
                    "valor" => $pagamento['valor'],
                    "qr_code" => $pagamento['qr_code'],
                    "qr_code_base64" => $pagamento['qr_code_base64']
                ]
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                "success" => false,
                "message" => $e->getMessage()
            ]);
        }
    }

    /**
     * Gera um pagamento via Boleto para uma conta de usuário
     * Recebe: { "id_conta": 123 }
     */
    public function gerarBoleto($data)
    {
        try {
            if (!isset($data['id_conta']) || empty($data['id_conta'])) {
                throw new \Exception("O ID da conta é obrigatório");
            }

            $contaController = new ContasUsuariosController();
            $conta = $contaController->findOnly([
                'filter' => ['id' => $data['id_conta']],
                'limit' => 1,
                'includes' => [
                    'empresas' => true
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

            if (!isset($conta['valor_mensal']) || empty($conta['valor_mensal'])) {
                throw new \Exception("Valor mensal não definido para esta conta");
            }

            $valorMensal = floatval($conta['valor_mensal']);

            if ($valorMensal <= 0) {
                throw new \Exception("Valor mensal inválido");
            }

            $empresa = $conta['empresas'][0];

            // Criar o pagamento Boleto no Mercado Pago
            $client = new PaymentClient();

            // Data de vencimento do boleto (7 dias a partir de hoje)
            $dataVencimento = date('Y-m-d', strtotime('+7 days'));

            $paymentData = [
                "transaction_amount" => $valorMensal,
                "description" => "Assinatura mensal - " . ($conta['responsavel'] ?? 'Sistema'),
                "payment_method_id" => "bolbradesco", // Boleto Bradesco
                "payer" => [
                    "email" => $conta['email'] ?? "pagamento@sistema.com",
                    "first_name" => $conta['responsavel'] ?? "Cliente",
                    "identification" => [
                        "type" => "CNPJ",
                        "number" => $empresa['cnpj'] ?? "00000000000"
                    ],
                    "address" => [
                        "zip_code" => $empresa['cep'] ?? "",
                        "street_name" => $empresa['logradouro'] ?? "",
                        "street_number" => $empresa['numero'] ?? "S/N",
                        "neighborhood" => $empresa['bairro'] ?? "",
                        "city" => $empresa['cidade'] ?? "",
                        "federal_unit" => $empresa['uf'] ?? ""
                    ]
                ],
                "date_of_expiration" => $dataVencimento . "T23:59:59.000-04:00",
                "notification_url" => $_ENV['URL_WEBHOOKS']
            ];

            $payment = $client->create($paymentData);

            // Salvar o pagamento no banco de dados
            $pagamentoData = [
                'id_conta' => $data['id_conta'],
                'payment_id' => $payment->id,
                'status' => $payment->status,
                'valor' => $valorMensal,
                'qr_code' => null,
                'qr_code_base64' => null,
                'ticket_url' => $payment->transaction_details->external_resource_url ?? null,
                'payment_data' => json_encode($payment)
            ];

            $resultado = $this->model->insert($pagamentoData);

            http_response_code(200);
            echo json_encode([
                "success" => true,
                "message" => "Boleto gerado com sucesso",
                "data" => [
                    "id" => $resultado['id'],
                    "payment_id" => $payment->id,
                    "status" => $payment->status,
                    "valor" => $valorMensal,
                    "boleto_url" => $payment->transaction_details->external_resource_url ?? null,
                    "barcode" => $payment->barcode->content ?? null,
                    "data_vencimento" => $dataVencimento,
                    "expiration_date" => $payment->date_of_expiration ?? null
                ]
            ]);
        } catch (MPApiException $e) {
            $apiResponse = $e->getApiResponse();
            $content = $apiResponse ? $apiResponse->getContent() : null;
            
            error_log("Erro MPApiException Boleto: " . json_encode([
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
            error_log("Erro Exception Boleto: " . $e->getMessage());
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
