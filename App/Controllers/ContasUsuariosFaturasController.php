<?php

namespace App\Controllers;

use App\Models\ContasUsuariosFaturasModel;
use App\Models\UtilsModel;
use Dotenv\Dotenv;

class ContasUsuariosFaturasController extends ControllerBase
{
    public function __construct($id = null)
    {
        $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
        $dotenv->load();

        $this->model = new ContasUsuariosFaturasModel($id ? $id : null);
    }

    public function findOnly($data = [])
    {
        try {
            $filter = $data && isset($data['filter']) ? $data['filter'] : [];
            $limit = $data && isset($data['limit']) ? $data['limit'] : null;
            $offset = $data && isset($data['offset']) ? $data['offset'] : null;
            $order = $data && isset($data['order']) ? $data['order'] : [];
            $results = $this->model->find(array_merge($filter, ["deletado" => "N"]), $limit, $offset, $order);

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
                "total" => $this->model->totalCount(array_merge($filter, ["deletado" => "N"]))['total'],
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
            $result = $this->model->insert($data);

            if ($result) {
                try {
                    $this->gerarFinanceiro($result['id']);
                } catch (\Exception $e) {
                    error_log("Erro ao gerar financeiro: " . $e->getMessage());
                }
            }

            return $result;
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
            if (!$currentData) {
                throw new \Exception("User not found");
            }

            $result = $this->model->update($data);

            foreach ($this->model->relationConfig as $relation) {
                if (isset($data[$relation['property']])) {
                    foreach ($data[$relation['property']] as $item) {
                        $this->validateRequiredFields(new $relation['model'](), $item, [$relation['foreign_key']]);
                        $item[$relation['foreign_key']] = $currentData['id'];
                        $relatedModel = new $relation['model']();
                        $relatedModel->insert($item);
                    }
                }
            }

            if($result && $currentData['status'] !== $result['status'] && $result['status'] === 'CA') {
                $mercadoPagoController = new MercadoPagoController();
                $mercadoPagoController->cancelarPorFatura($result['id']);
            }

            return $result;
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
            $result = $this->model->delete();

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

    public function gerarFinanceiro($id)
    {
        try {
            $contasUsuariosFaturasController = new ContasUsuariosFaturasController($id);
            $contasUsuarios = $contasUsuariosFaturasController->findUnique();

            if (!$contasUsuarios) {
                throw new \Exception("Fatura não encontrada");
            }

            $contasUsuarionsController = new ContasUsuariosController($contasUsuarios['id_conta_usuario']);
            $contaUsuario = $contasUsuarionsController->findOnly([
                "filter" => [
                    "id" => $contasUsuarios['id_conta_usuario']
                ],
                "includes" => [
                    "empresas" => true,
                    "usuarios" => true
                ]
            ])[0] ?? null;

            if (!$contaUsuario) {
                throw new \Exception("Conta de usuário não encontrada");
            }

            $empresa = $contaUsuario['empresas'][0] ?? null;
            $usuario = $contaUsuario['usuarios'][0] ?? null;

            if (!$empresa || !$usuario) {
                throw new \Exception("Empresa ou usuário não encontrado");
            }

            if (
                isset($empresa['cnpj']) && $empresa['cnpj'] &&
                isset($empresa['uf']) && $empresa['uf'] &&
                isset($empresa['cidade']) && $empresa['cidade'] &&
                isset($empresa['logradouro']) && $empresa['logradouro'] &&
                isset($empresa['numero']) && $empresa['numero'] &&
                isset($empresa['cep']) && $empresa['cep'] &&
                isset($empresa['bairro']) && $empresa['bairro']
            ) {
                if (UtilsModel::diasFaltantes($contaUsuario['vencimento']) < 28) {
                    $mercadoPagoController = new MercadoPagoController();

                    $dataPayment = [
                        'id_fatura' => $id,
                        'nome' => $usuario['nome'] ?? 'Cliente',
                        'valor' => floatval($contaUsuario['valor_mensal'] ?? 0.00),
                        'descricao' => "Fatura mensal - Conta: {$contaUsuario['id']}",
                        'email' => $usuario['email'] ?? ($empresa['email'] ?? null),
                        'responsavel' => $usuario['nome'] ?? 'Cliente',
                        'cnpj' => $empresa['cnpj'] ?? null,
                        'logradouro' => $empresa['logradouro'] ?? null,
                        'numero' => $empresa['numero'] ?? null,
                        'bairro' => $empresa['bairro'] ?? null,
                        'cidade' => $empresa['cidade'] ?? null,
                        'uf' => $empresa['uf'] ?? null,
                        'cep' => $empresa['cep'] ?? null,
                        'dataVencimento' => $contaUsuario['vencimento'],
                    ];

                    $pagamentoBoleto = $mercadoPagoController->gerarBoletoApenas($dataPayment);
                    $pagamentoPix = $mercadoPagoController->gerarPixApenas($dataPayment);

                    return [
                        'boleto' => $pagamentoBoleto,
                        'pix' => $pagamentoPix
                    ];
                } else {
                    throw new \Exception("Fatura ainda não está próxima do vencimento. Faltam " . UtilsModel::diasFaltantes($contaUsuario['vencimento']) . " dias para o vencimento.");
                }
            }
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function atualizarInfoFaturas($id)
    {
        try {
            $contasUsuariosController = new ContasUsuariosController($id);
            $contaUsuario = $contasUsuariosController->findOnly([
                "filter" => [
                    "id" => $id
                ],
                "includes" => [
                    "empresas" => true,
                    "usuarios" => true
                ]
            ])[0] ?? null;

            if ($contaUsuario) {
                $empresa = $contaUsuario['empresas'][0] ?? null;
                $usuario = $contaUsuario['usuarios'][0] ?? null;

                $contasUsuariosFaturas = $this->findOnly([
                    "filter" => [
                        "id_conta_usuario" => $id,
                        "status" => "PE",
                        "deletado" => "N"
                    ]
                ]);

                if ($contasUsuariosFaturas) {
                    foreach ($contasUsuariosFaturas as $fatura) {
                        $updateController = new ContasUsuariosFaturasController($fatura['id']);
                        $updateController->updateOnly([
                            "status" => "CA",
                        ]);
                    }
                }

                $result = $this->createOnly([
                    "vencimento" => $contaUsuario['vencimento'],
                    "valor" => $contaUsuario['valor_mensal'],
                    "descricao" => "Mensalidade do Sistema para " . ($empresa['razao_social'] ?? $usuario['nome'] ?? 'Cliente'),
                    "id_conta_usuario" => $id
                ]);
            }
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }
}
