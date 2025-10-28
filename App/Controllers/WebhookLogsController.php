<?php

namespace App\Controllers;

use Dotenv\Dotenv;
use App\Models\WebhookLogsModel;

class WebhookLogsController extends ControllerBase
{
    public function __construct($id = null)
    {
        $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
        $dotenv->load();

        $this->model = new WebhookLogsModel($id ? $id : null);
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
     * Recebe qualquer webhook e salva o body como string no banco de dados
     */
    public function receberWebhook($origem = 'generico')
    {
        try {
            // Capturar o corpo da requisição
            $body = file_get_contents('php://input');
            
            // Se o body estiver vazio, tentar pegar do POST
            if (empty($body)) {
                $body = json_encode($_POST);
            }

            // Capturar informações da requisição
            $ipOrigem = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $method = $_SERVER['REQUEST_METHOD'] ?? 'POST';
            $url = $_SERVER['REQUEST_URI'] ?? null;

            // Capturar headers
            $headers = [];
            foreach ($_SERVER as $key => $value) {
                if (strpos($key, 'HTTP_') === 0) {
                    $headerName = str_replace('HTTP_', '', $key);
                    $headerName = str_replace('_', '-', $headerName);
                    $headers[$headerName] = $value;
                }
            }

            // Preparar dados para salvar
            $logData = [
                'origem' => $origem,
                'body' => $body,
                'ip_origem' => $ipOrigem,
                'user_agent' => $userAgent,
                'headers' => json_encode($headers),
                'method' => $method,
                'url' => $url
            ];

            // Salvar no banco de dados
            $resultado = $this->model->insert($logData);

            // Log para debug
            error_log("Webhook recebido [{$origem}] - ID: {$resultado['id']} - IP: {$ipOrigem}");

            http_response_code(200);
            echo json_encode([
                "success" => true,
                "message" => "Webhook recebido e registrado com sucesso",
                "log_id" => $resultado['id']
            ]);
        } catch (\Exception $e) {
            error_log("Erro ao processar webhook [{$origem}]: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                "success" => false,
                "message" => "Erro ao processar webhook: " . $e->getMessage()
            ]);
        }
    }

    /**
     * Buscar logs por origem
     */
    public function buscarPorOrigem($data)
    {
        try {
            if (!isset($data['origem']) || empty($data['origem'])) {
                throw new \Exception("A origem é obrigatória");
            }

            $filter = ['origem' => $data['origem']];
            $limit = $data['limit'] ?? 50;
            $offset = $data['offset'] ?? 0;
            $order = $data['order'] ?? ['cols' => ['id'], 'direction' => 'DESC'];

            $results = $this->model->find($filter, $limit, $offset, $order);

            http_response_code(200);
            echo json_encode([
                "total" => $this->model->totalCount($filter)['total'],
                "data" => $results
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
     * Limpar logs antigos (mais de X dias)
     */
    public function limparLogsAntigos($dias = 30)
    {
        try {
            $dataLimite = date('Y-m-d H:i:s', strtotime("-{$dias} days"));
            
            $sql = "DELETE FROM {$this->model->getTableName()} WHERE dthr_registro < :data_limite";
            
            $conn = $this->model->conn;
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':data_limite', $dataLimite);
            $stmt->execute();
            
            $deletados = $stmt->rowCount();

            http_response_code(200);
            echo json_encode([
                "success" => true,
                "message" => "Logs antigos removidos com sucesso",
                "registros_deletados" => $deletados
            ]);
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
}
