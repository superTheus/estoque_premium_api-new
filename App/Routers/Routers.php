<?php

namespace App\Routers;

use App\Controllers\CategoriasController;
use App\Controllers\ClientesController;
use App\Controllers\ContasUsuariosController;
use App\Controllers\FornecedoresController;
use App\Controllers\MarcasController;
use App\Controllers\ProdutosController;
use App\Controllers\SubcategoriasController;
use App\Controllers\UsuariosController;
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
