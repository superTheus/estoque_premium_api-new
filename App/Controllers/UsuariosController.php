<?php

namespace App\Controllers;

use Dotenv\Dotenv;

use App\Models\UsuariosModel;
use Firebase\JWT\JWT;

class UsuariosController extends ControllerBase
{
    public function __construct($id = null)
    {
        $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
        $dotenv->load();

        $this->model = new UsuariosModel($id ? $id : null);
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

            if (isset($data['foto']) && $data['foto']) {
                if (is_string($data['foto']) && (
                    strpos($data['foto'], 'base64,') !== false ||
                    preg_match('/^[a-zA-Z0-9+\/=]+$/', trim($data['foto']))
                )) {
                    $uploadsController = new UploadsController();
                    if (!empty($currentData['foto'])) {
                        try {
                            $uploadsController->deleteFile($currentData['foto']);
                        } catch (\Exception $e) {
                            error_log('Não foi possível excluir a foto anterior: ' . $e->getMessage());
                        }
                    }

                    $data['foto'] = $uploadsController->uploadFile($data['foto'], "user");
                }
            }

            if ($currentData) {

                if (isset($data['senha']) && $data['senha']) {
                    $hash = password_hash($data['senha'], PASSWORD_BCRYPT);
                    $data['senha'] = $hash;
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

    public function login($data)
    {
        try {
            $user = $this->findOnly([
                "filter" => [
                    "login" => $data['login']
                ],
                "includes" => [
                    "contas_usuarios" => true
                ]
            ]);

            if ($user && $user[0] && password_verify($data['senha'], $user[0]['senha'])) {
                $user = $user[0];

                $token = [
                    "erro" => false,
                    "iat" => time(),
                    "exp" => time() + 4200,
                    "data" => [
                        "id" => $user['id'],
                        "id_conta" => $user['id_conta'],
                        "perfil" => $user['perfil'],
                        "tipo" => $user['tipo'],
                        "conta_tipo" => $user['contas_usuarios']['tipo'],
                    ]
                ];

                $user['token'] = JWT::encode($token, $_ENV['SECRET_KEY'], 'HS256');

                http_response_code(200);
                echo json_encode([
                    "id" => $user['id'],
                    "id_conta" => $user['id_conta'],
                    "nome" => $user['nome'],
                    "tipo" => $user['tipo'],
                    "perfil" => $user['perfil'],
                    "token" => $user['token'],
                ]);
            } else {
                http_response_code(401);
                echo json_encode(["message" => "Unauthorized"]);
            }

            // }
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
