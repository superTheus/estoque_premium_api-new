<?php

namespace App\Middlewares;

use App\Controllers\EmailsController;
use App\Models\UtilsModel;
use Dotenv\Dotenv;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;

class AuthMiddleware
{
  /**
   * Função auxiliar para obter o cabeçalho Authorization de várias fontes possíveis
   */
  private static function getAuthorizationHeader()
  {
    $auth = null;

    // Método 1: Usando getallheaders()
    if (function_exists('getallheaders')) {
      $headers = getallheaders();
      // Verificação case-insensitive
      foreach ($headers as $name => $value) {
        if (strtolower($name) === 'authorization') {
          $auth = $value;
          break;
        }
      }
    }

    // Método 2: Verificar variáveis específicas do servidor
    if (empty($auth)) {
      if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'];
      } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
      } elseif (isset($_SERVER['PHP_AUTH_USER'])) {
        // Basic auth
        $auth = 'Basic ' . base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . ($_SERVER['PHP_AUTH_PW'] ?? ''));
      }
    }

    // Método 3: Obter da entrada apache
    if (empty($auth) && function_exists('apache_request_headers')) {
      $headers = apache_request_headers();
      foreach ($headers as $name => $value) {
        if (strtolower($name) === 'authorization') {
          $auth = $value;
          break;
        }
      }
    }

    return $auth;
  }

  public static function handle()
  {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
    $dotenv->load();

    $auth = self::getAuthorizationHeader();

    if (empty($auth)) {
      http_response_code(401);
      echo json_encode(['message' => 'Token não fornecido']);
      exit;
    }

    if (!preg_match('/Bearer\s(\S+)/', $auth, $matches)) {
      http_response_code(401);
      echo json_encode(['message' => 'Formato de token inválido']);
      exit;
    }

    $token = $matches[1];

    try {
      $decoded = JWT::decode($token, new Key($_ENV['SECRET_KEY'], 'HS256'));

      $_REQUEST['user'] = $decoded->data;
      $_REQUEST['id_conta'] = $decoded->data->id_conta;

      return true;
    } catch (ExpiredException $e) {
      http_response_code(401);
      echo json_encode(['message' => 'Token expirado']);
      exit;
    } catch (\Exception $e) {
      http_response_code(401);
      echo json_encode(['message' => 'Token inválido: ' . $e->getMessage()]);
      exit;
    }
  }

  public static function handleRoot()
  {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
    $dotenv->load();

    $auth = self::getAuthorizationHeader();

    if (empty($auth)) {
      http_response_code(401);
      echo json_encode(['message' => 'Token não fornecido']);
      exit;
    }

    if (!preg_match('/Bearer\s(\S+)/', $auth, $matches)) {
      http_response_code(401);
      echo json_encode(['message' => 'Formato de token inválido']);
      exit;
    }

    $token = $matches[1];

    try {
      $decoded = JWT::decode($token, new Key($_ENV['SECRET_KEY'], 'HS256'));

      $_REQUEST['user'] = $decoded->data;
      return true;
    } catch (ExpiredException $e) {
      http_response_code(401);
      echo json_encode(['message' => 'Token expirado']);
      exit;
    } catch (\Exception $e) {
      http_response_code(401);
      echo json_encode(['message' => 'Token inválido: ' . $e->getMessage()]);
      exit;
    }
  }

  public static function handleToken()
  {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
    $dotenv->load();

    $headers = getallheaders();

    if (!isset($headers['Authorization'])) {
      http_response_code(401);
      echo json_encode(['message' => 'Token não fornecido']);
      exit;
    }

    $auth = $headers['Authorization'];
    if (!preg_match('/Bearer\s(\S+)/', $auth, $matches)) {
      http_response_code(401);
      echo json_encode(['message' => 'Formato de token inválido']);
      exit;
    }

    $token = $matches[1];

    try {
      $decoded = JWT::decode($token, new Key($_ENV['SECRET_KEY'], 'HS256'));

      if (!isset($decoded->data->id) || !isset($decoded->data->nome) || !isset($decoded->data->email)) {
        http_response_code(401);
        echo json_encode(['message' => 'Token inválido - estrutura incorreta']);
        exit;
      }

      $_REQUEST['user'] = [
        "id" => $decoded->data->id,
        "email" => $decoded->data->email,
        "nome" => $decoded->data->nome,
      ];

      return true;
    } catch (ExpiredException $e) {
      try {
        $payload = json_decode(base64_decode(str_replace('_', '/', str_replace('-', '+', explode('.', $token)[1]))));

        if (isset($payload->data->mode) && $payload->data->mode == "activate_user") {
          $activationLink = $_ENV['URL_SISTEMA'] . 'login.php?token_activation=' . UtilsModel::generateToken([
            "id" => $payload->data->id,
            "email" => $payload->data->email,
            "nome" => $payload->data->nome,
            "mode" => "activate_user"
          ]);

          $message = '
                        <h3>Olá, ' . $payload->data->nome . '!</h3>

                        <p>Bem-vindo ao ' . $_ENV['NOME_SISTEMA'] . '!</p>

                        <p>Seu link de ativação expirou. Clique no novo link abaixo para ativar sua conta:</p>

                        <p><a href="' . $activationLink . '" style="color: #fff; background-color: #28a745; padding: 10px 15px; text-decoration: none; border-radius: 5px;">Ativar Conta</a></p>

                        <p>Atenciosamente,<br>
                        Equipe Axpem</p>
                    ';

          $emailController = new EmailsController($payload->data->email, "Novo Link de Ativação - " . $_ENV['NOME_SISTEMA'], $_ENV['NOME_SISTEMA']);
          $emailController->setMessage($emailController->templateEmail($message));
          $send = $emailController->send();

          if ($send) {
            http_response_code(401);
            echo json_encode(['message' => 'Token expirado. Um novo link de ativação foi enviado para o seu e-mail.']);
            exit;
          }
        }
      } catch (\Exception $decodeError) {
      }

      http_response_code(401);
      echo json_encode(['message' => 'Token expirado.']);
      exit;
    } catch (\Exception $e) {
      http_response_code(401);
      echo json_encode(['message' => 'Token inválido: ' . $e->getMessage()]);
      exit;
    }
  }
}
