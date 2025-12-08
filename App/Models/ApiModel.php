<?php

namespace App\Models;

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class ApiModel
{
  protected $baseurl;
  protected $client;

  public function __construct()
  {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
    $dotenv->load();
    $this->baseurl = $_ENV['API_URL'];
    $this->client = new Client([
      'base_uri' => $this->baseurl,
      'timeout'  => 10.0,
    ]);
  }

  private function request($url, $type, $data = [], $debug = false)
  {
    if ($debug) {
      die(json_encode([
        'url' => $this->baseurl .  $url,
        'type' => $type,
        'data' => $data
      ]));
    }

    try {
      $response = $this->client->request($type, $url, [
        'json' => $data,
        'headers' => [
          // 'Authorization' => 'Bearer token',
          'Accept' => 'application/json',
        ]
      ]);

      $statusCode = $response->getStatusCode();

      if ($statusCode !== 200) {
        throw new \Exception("Erro na requisição: " . $response->getReasonPhrase());
      }
      $body = $response->getBody();
      return json_decode($body, true);
    } catch (RequestException $e) {
      if ($e->hasResponse()) {
        $responseBody = $e->getResponse()->getBody()->getContents();
        $responseData = json_decode($responseBody, true);

        if (json_last_error() === JSON_ERROR_NONE && isset($responseData['message'])) {
          throw new \Exception($responseData['message']);
        } else {
          throw new \Exception($responseBody);
        }
      }

      throw new \Exception($e->getMessage());
    }
  }

  public function testeCertificado($data = [])
  {
    if (empty($data)) {
      throw new \Exception("Dados do certificado não fornecidos.");
    }

    if(!isset($data['certificado'])) {
      throw new \Exception("Certificado é obrigatório.");
    }

    if(!isset($data['senha'])) {
      throw new \Exception("Senha é obrigatória.");
    }
    
    return $this->request('fiscal/certicate/test', 'POST', $data);
  }

  public function testeCertificadoPorCnpj($cnpj)
  {
    if (empty($cnpj)) {
      throw new \Exception("CNPJ não fornecido.");
    }

    return $this->request('fiscal/certicate/test/' . $cnpj, 'GET');
  }

  public function listCompany($data = [])
  {
    return $this->request('company/list', 'POST', $data);
  }

  public function createCompany($data = [])
  {
    return $this->request('company/create', 'POST', $data);
  }

  public function updateCompany($id, $data = [])
  {
    return $this->request('company/update/' . $id, 'PUT', $data);
  }

  public function cest($data = [])
  {
    return $this->request('cest', 'POST', $data);
  }

  public function ibpt($data = [])
  {
    return $this->request('ibpt', 'POST', $data);
  }

  public function ncm($data = [])
  {
    return $this->request('ncm', 'POST', $data);
  }

  public function situacao($data = [])
  {
    return $this->request('situacao', 'POST', $data);
  }

  public function cfop($data = [])
  {
    return $this->request('cfop', 'POST', $data);
  }

  public function formas($data = [])
  {
    return $this->request('formas', 'POST', $data);
  }

  public function unidades($data = [])
  {
    return $this->request('unidades', 'POST', $data);
  }

  public function origem($data = [])
  {
    return $this->request('origem', 'POST', $data);
  }

  public function estados($data = [])
  {
    return $this->request('estados', 'POST', $data);
  }
  
  public function estadosUnico($uf)
  {
    return $this->request('estados/' . $uf, 'GET');
  }

  public function cidades($uf)
  {
    return $this->request('municipios/' . $uf, 'POST');
  }

  public function cidadesUnico($cidade)
  {
    return $this->request('municipios/' . $cidade, 'GET');
  }

  public function nfce($data)
  {
    return $this->request('fiscal/nfce', 'POST', $data);
  }

  public function nfe($data)
  {
    return $this->request('fiscal/nfe', 'POST', $data);
  }
  
  public function cancelarNfce($data)
  {
    return $this->request('fiscal/nfce/cancel', 'POST', $data);
  }

  public function cancelarNfe($data)
  {
    return $this->request('fiscal/nfe/cancel', 'POST', $data);
  }
}
