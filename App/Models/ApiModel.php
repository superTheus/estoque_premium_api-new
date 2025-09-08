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

  private function request($url, $type, $data = [])
  {
    try {
      $response = $this->client->request($type, $url, [
        'json' => $data,
        'headers' => [
          // 'Authorization' => 'Bearer token',
          'Accept' => 'application/json',
        ]
      ]);

      $statusCode = $response->getStatusCode();
      $body = $response->getBody();
      return json_decode($body, true);
    } catch (RequestException $e) {
      throw new \Exception("Erro na requisição: " . $e->getMessage());
    }
  }

  public function listCompany($data = [])
  {
    return $this->request('/company/list', 'POST', $data);
  }

  public function createCompany($data = [])
  {
    return $this->request('/company/create', 'POST', $data);
  }

  public function updateCompany($id, $data = [])
  {
    return $this->request('/company/update/' . $id, 'PUT', $data);
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
}
