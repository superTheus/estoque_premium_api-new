<?php

namespace App\Routers;

use App\Controllers\CategoriasController;
use App\Controllers\ClientesController;
use App\Controllers\ContasUsuariosController;
use App\Controllers\EmpresasController;
use App\Controllers\FiscalController;
use App\Controllers\FornecedoresController;
use App\Controllers\MarcasController;
use App\Controllers\OperacoesController;
use App\Controllers\ProdutosController;
use App\Controllers\RegrasFiscalController;
use App\Controllers\SubcategoriasController;
use App\Controllers\UsuariosController;
use App\Controllers\VendasController;
use App\Middlewares\AuthMiddleware;
use Bramus\Router\Router;

class Routers
{
    public static function execute($callback = null)
    {
        $router = new Router();

        $router->before('GET', '/.*', function () {
            header('X-Powered-By: bramus/router');
        });

        $router->before('GET|POST|PUT|DELETE|PATCH|OPTIONS', '/private(/.*)?', function () {
            AuthMiddleware::handle();
        });

        // $router->before('GET|POST|PUT|DELETE|PATCH|OPTIONS', '/root(/.*)?', function () {
        //     AuthMiddleware::handleRoot();
        // });

        // $router->before('GET|POST|PUT|DELETE|PATCH|OPTIONS', '/token_validation(/.*)?', function () {
        //     AuthMiddleware::handleToken();
        // });

        $router->get('/', function () {
            echo "Rota Pública: GET /";
        });

        $router->mount('/private', function () use ($router) {
            $router->get('/', function () {
                echo "Rota Privada: GET /private";
            });

            $router->mount('/usuarios', function () use ($router) {
                $router->post('/criar', function () {
                    $usuariosController = new UsuariosController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $usuariosController->create($data);
                });

                $router->post('/listar', function () {
                    $usuariosController = new UsuariosController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $usuariosController->find($data);
                });

                $router->put('/atualizar/{id}', function ($id) {
                    $usuariosController = new UsuariosController($id);
                    $data = json_decode(file_get_contents('php://input'), true);
                    $usuariosController->update($data);
                });

                $router->post('/tabela', function () {
                    $usuariosController = new UsuariosController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $usuariosController->searchDataTable($data);
                });
            });

            $router->mount('/empresas', function () use ($router) {
                $router->post('/criar', function () {
                    $empresasController = new EmpresasController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $empresasController->create($data);
                });

                $router->post('/listar', function () {
                    $empresasController = new EmpresasController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $empresasController->find($data);
                });

                $router->put('/atualizar/{id}', function ($id) {
                    $empresasController = new EmpresasController($id);
                    $data = json_decode(file_get_contents('php://input'), true);
                    $empresasController->update($data);
                });
            });

            $router->mount('/marcas', function () use ($router) {
                $router->post('/criar', function () {
                    $marcasController = new MarcasController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $marcasController->create($data);
                });

                $router->post('/listar', function () {
                    $marcasController = new MarcasController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $marcasController->find($data);
                });

                $router->put('/atualizar/{id}', function ($id) {
                    $marcasController = new MarcasController($id);
                    $data = json_decode(file_get_contents('php://input'), true);
                    $marcasController->update($data);
                });

                $router->post('/tabela', function () {
                    $marcasController = new MarcasController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $marcasController->searchDataTable($data);
                });
            });

            $router->mount('/fornecedores', function () use ($router) {
                $router->post('/criar', function () {
                    $fornecedoresController = new FornecedoresController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $fornecedoresController->create($data);
                });

                $router->post('/listar', function () {
                    $fornecedoresController = new FornecedoresController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $fornecedoresController->find($data);
                });

                $router->put('/atualizar/{id}', function ($id) {
                    $fornecedoresController = new FornecedoresController($id);
                    $data = json_decode(file_get_contents('php://input'), true);
                    $fornecedoresController->update($data);
                });

                $router->post('/tabela', function () {
                    $fornecedoresController = new FornecedoresController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $fornecedoresController->searchDataTable($data);
                });
            });

            $router->mount('/categorias', function () use ($router) {
                $router->post('/criar', function () {
                    $categoriasController = new CategoriasController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $categoriasController->create($data);
                });

                $router->post('/listar', function () {
                    $categoriasController = new CategoriasController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $categoriasController->find($data);
                });

                $router->put('/atualizar/{id}', function ($id) {
                    $categoriasController = new CategoriasController($id);
                    $data = json_decode(file_get_contents('php://input'), true);
                    $categoriasController->update($data);
                });

                $router->post('/tabela', function () {
                    $categoriasController = new CategoriasController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $categoriasController->searchDataTable($data);
                });
            });

            $router->mount('/subcategorias', function () use ($router) {
                $router->post('/criar', function () {
                    $subcategoriasController = new SubcategoriasController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $subcategoriasController->create($data);
                });

                $router->post('/listar', function () {
                    $subcategoriasController = new SubcategoriasController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $subcategoriasController->find($data);
                });

                $router->put('/atualizar/{id}', function ($id) {
                    $subcategoriasController = new SubcategoriasController($id);
                    $data = json_decode(file_get_contents('php://input'), true);
                    $subcategoriasController->update($data);
                });

                $router->post('/tabela', function () {
                    $subcategoriasController = new SubcategoriasController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $subcategoriasController->searchDataTable($data);
                });
            });

            $router->mount('/clientes', function () use ($router) {
                $router->post('/criar', function () {
                    $clientesController = new ClientesController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $clientesController->create($data);
                });

                $router->post('/listar', function () {
                    $clientesController = new ClientesController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $clientesController->find($data);
                });

                $router->put('/atualizar/{id}', function ($id) {
                    $clientesController = new ClientesController($id);
                    $data = json_decode(file_get_contents('php://input'), true);
                    $clientesController->update($data);
                });
            });

            $router->mount('/produtos', function () use ($router) {
                $router->post('/criar', function () {
                    $produtosController = new ProdutosController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $produtosController->create($data);
                });

                $router->post('/listar', function () {
                    $produtosController = new ProdutosController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $produtosController->find($data);
                });

                $router->put('/atualizar/{id}', function ($id) {
                    $produtosController = new ProdutosController($id);
                    $data = json_decode(file_get_contents('php://input'), true);
                    $produtosController->update($data);
                });
            });

            $router->mount('/operacoes', function () use ($router) {
                $router->post('/criar', function () {
                    $operacoesController = new OperacoesController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $operacoesController->create($data);
                });

                $router->post('/listar', function () {
                    $operacoesController = new OperacoesController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $operacoesController->find($data);
                });

                $router->put('/atualizar/{id}', function ($id) {
                    $operacoesController = new OperacoesController($id);
                    $data = json_decode(file_get_contents('php://input'), true);
                    $operacoesController->update($data);
                });
            });

            $router->mount('/vendas', function () use ($router) {
                $router->post('/criar', function () {
                    $vendasController = new VendasController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $vendasController->create($data);
                });

                $router->post('/listar', function () {
                    $vendasController = new VendasController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $vendasController->find($data);
                });

                $router->put('/atualizar/{id}', function ($id) {
                    $vendasController = new VendasController($id);
                    $data = json_decode(file_get_contents('php://input'), true);
                    $vendasController->update($data);
                });
            });

            $router->mount('/fiscal', function () use ($router) {
                $router->post('/cest', function () {
                    $fiscalController = new FiscalController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $fiscalController->listCest($data);
                });

                $router->post('/ibpt', function () {
                    $fiscalController = new FiscalController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $fiscalController->listIbpt($data);
                });

                $router->post('/ncm', function () {
                    $fiscalController = new FiscalController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $fiscalController->listNcm($data);
                });

                $router->post('/situacao', function () {
                    $fiscalController = new FiscalController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $fiscalController->listSituacao($data);
                });

                $router->post('/cfop', function () {
                    $fiscalController = new FiscalController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $fiscalController->listCFOP($data);
                });

                $router->post('/formas', function () {
                    $fiscalController = new FiscalController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $fiscalController->listFormas($data);
                });

                $router->post('/unidades', function () {
                    $fiscalController = new FiscalController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $fiscalController->listUnidades($data);
                });

                $router->post('/origem', function () {
                    $fiscalController = new FiscalController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $fiscalController->listOrigem($data);
                });

                $router->mount('/estados', function () use ($router) {
                    $router->post('/', function () {
                        $fiscalController = new FiscalController();
                        $data = json_decode(file_get_contents('php://input'), true);
                        $fiscalController->listEstados($data);
                    });

                    $router->get('/{uf}', function ($uf) {
                        $fiscalController = new FiscalController();
                        $fiscalController->listEstadosUnico($uf);
                    });
                });

                $router->mount('/cidades', function () use ($router) {
                    $router->post('/{uf}', function ($uf) {
                        $fiscalController = new FiscalController();
                        $fiscalController->listCidades($uf);
                    });

                    $router->get('/{cidade}', function ($cidade) {
                        $fiscalController = new FiscalController();
                        $fiscalController->listCidadesUnica($cidade);
                    });
                });
            });

            $router->mount('/regras-fiscais', function () use ($router) {
                $router->post('/criar', function () {
                    $regrasFiscalController = new RegrasFiscalController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $regrasFiscalController->create($data);
                });

                $router->post('/listar', function () {
                    $regrasFiscalController = new RegrasFiscalController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $regrasFiscalController->find($data);
                });

                $router->put('/atualizar/{id}', function ($id) {
                    $regrasFiscalController = new RegrasFiscalController($id);
                    $data = json_decode(file_get_contents('php://input'), true);
                    $regrasFiscalController->update($data);
                });
            });
        });

        $router->mount('/root', function () use ($router) {
            $router->mount('/contas-usuarios', function () use ($router) {
                $router->post('/criar', function () {
                    $contaUsuarioController = new ContasUsuariosController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $contaUsuarioController->create($data);
                });

                $router->post('/listar', function () {
                    $contaUsuarioController = new ContasUsuariosController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $contaUsuarioController->find($data);
                });

                $router->put('/atualizar/{id}', function ($id) {
                    $contaUsuarioController = new ContasUsuariosController($id);
                    $data = json_decode(file_get_contents('php://input'), true);
                    $contaUsuarioController->update($data);
                });

                $router->post('/tabela', function () {
                    $contaUsuarioController = new ContasUsuariosController();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $contaUsuarioController->searchDataTable($data);
                });
            });
        });

        $router->post('/login', function () {
            $usuarioController = new UsuariosController();
            $data = json_decode(file_get_contents('php://input'), true);
            $usuarioController->login($data);
        });

        $router->get('/files/{filename}', function ($filename) {
            $uploadsController = new \App\Controllers\UploadsController();
            $uploadsController->getFile($filename);
        });

        $router->set404(function () {
            header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
            echo '404, Rota não encontrada';
        });

        $router->run($callback);
    }
}
