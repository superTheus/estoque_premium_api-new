<?php

namespace App\Controllers;

use App\Models\ApiModel;

class FiscalController extends ApiModel
{
  public function __construct()
  {
    parent::__construct();
  }

  public function emitirNFCE($idVenda)
  {
    $this->emitir($idVenda, 'NFCE');
  }

  public function emitirNFE($idVenda) {
    $this->emitir($idVenda, 'NFE');
  }

  public function emitir($idVenda, $tipo = 'NFCE')
  {
    try {
      $vendasController = new VendasController();
      $response = $vendasController->findOnly([
        "filter" => [
          "id" => $idVenda
        ],
        "includes" => [
          "empresas" => true,
          "operacoes" => true,
          "clientes" => true,
          "venda_pagamentos" => [
            "includes" => [
              "formas_pagamento" => [
                "includes" => [
                  "tipos_pagamento" => true
                ]
              ]
            ]
          ],
          "venda_produtos" => [
            "includes" => [
              "produtos" => true
            ]
          ],
        ]
      ]);

      if (!isset($response[0]) || empty($response[0])) {
        http_response_code(404);
        echo json_encode(['error' => 'Venda não encontrada.']);
        return;
      }

      $venda = $response[0];
      $empresa = $venda['empresas'][0];
      $operacao = $venda['operacoes'][0];
      $cliente = $venda['clientes'][0];
      $produtos = $venda['venda_produtos'];
      $pagamentos = $venda['venda_pagamentos'];

      $cidade = $this->cidadesUnico($cliente['cidade']);

      if (!$cidade) {
        throw new \Exception("Cidade do cliente não encontrada.");
      }

      $produtosNota = [];
      $pagamentosNota = [];

      foreach ($produtos as $key => $produto) {
        $produtosNota[] = [
          "acrescimo" => 0,
          "cfop" => $produto["cfop"],
          "codigo" => $produto["id_produto"],
          "desconto" => $produto["desconto_real"],
          "descricao" => $produto["produtos"]['descricao'],
          "ean" => "SEM GTIN",
          "frete" => 0,
          "ncm" => $produto["produtos"]["ncm"],
          "origem" => $produto["produtos"]["origem"],
          "quantidade" => $produto["quantidade"],
          "total" => $produto["total"],
          "unidade" => $produto["produtos"]['unidade'],
          "valor" => $produto["preco"]
        ];
      }

      foreach ($pagamentos as $key => $pagamento) {
        $pagamentosNota[] = [
          "codigo" => $pagamento["formas_pagamento"]["tipos_pagamento"][0]['codigo_sefaz'],
          "valorpago" => $pagamento["valor"]
        ];
      }

      $dadosEmissao = [
        "cnpj" => $empresa['cnpj'],
        "cfop" =>  $operacao['cfop_estadual'],
        "operacao" => $operacao['descricao'],
        "consumidor_final" => "S",
        "observacao" => $venda['observacao_nota'],
        "cliente" => [
          "documento" => $cliente["documento"],
          "nome" => $cliente["nome"],
          "tipo_documento" => strlen(preg_replace('/\D/', '', $cliente["documento"])) === 11 ? "CPF" : "CNPJ",
          "tipo_icms" => $cliente['icms'],
          "endereco" => [
            "bairro" => $cliente['bairro'],
            "codigo_municipio" => $cidade['codigo_ibge'],
            "logradouro" => $cliente["logradouro"],
            "municipio" =>  $cliente["cidade"],
            "numero" => $cliente["numero"],
            "uf" => $cliente["estado"],
            "cep" => $cliente["cep"]
          ],
          "inscricao_estadual" => $cliente["inscricao_estadual"]
        ],
        "modoEmissao" => 1,
        "total" => $venda['total'],
        "troco" => $venda['total_troco'],
        "total_pago" => $venda['total_pago'],
        "produtos" => $produtosNota,
        "pagamentos" => $pagamentosNota
      ];

      if ($tipo === 'NFCE') {
        $notaEmitida = $this->nfce($dadosEmissao);
      } else {
        $notaEmitida = $this->nfe($dadosEmissao);
      }

      $venda = $vendasController->updateOnly([
        "nota_emitida" => "S",
        "protocolo" => $notaEmitida['protocolo'],
        "chave" => $notaEmitida['chave'],
        "url" => $notaEmitida['link'],
        "pdf" => $notaEmitida['pdf'],
        "xml" => $notaEmitida['xml'],
        "status_nota" => "S",
      ]);

      http_response_code(200);
      echo json_encode($venda);
    } catch (\Exception $e) {
      $errors = json_decode($e->getMessage(), true);
      $messagemErro = $errors['error'];
      if(isset($errors['error_tags']) && is_array($errors['error_tags']) && count($errors['error_tags']) > 0) {
        $messagemErro = 'Erros: ' . implode(', ', $errors['error_tags']);
      }

      if ($errors) {
        $vendasController = new VendasController($idVenda);
        $vendasController->updateOnly([
          "nota_emitida" => "S",
          "status_nota" => "F",
          "messagem_error" => $messagemErro,
          "xml" => $errors['xml']
        ]);
      }

      http_response_code(401);
      echo json_encode($errors);
    }
  }

  public function criarEmpresa($data = [])
  {
    try {
      echo json_encode($this->createCompany($data));
    } catch (\Exception $e) {
      http_response_code(500);
      echo json_encode(['error' => $e->getMessage()]);
    }
  }

  public function listCest($data = [])
  {
    try {
      echo json_encode($this->cest($data));
    } catch (\Exception $e) {
      http_response_code(500);
      echo json_encode(['error' => $e->getMessage()]);
    }
  }

  public function listIbpt($data = [])
  {
    try {
      echo json_encode($this->ibpt($data));
    } catch (\Exception $e) {
      http_response_code(500);
      echo json_encode(['error' => $e->getMessage()]);
    }
  }

  public function listNcm($data = [])
  {
    try {
      echo json_encode($this->ncm($data));
    } catch (\Exception $e) {
      http_response_code(500);
      echo json_encode(['error' => $e->getMessage()]);
    }
  }

  public function listSituacao($data = [])
  {
    try {
      echo json_encode($this->situacao($data));
    } catch (\Exception $e) {
      http_response_code(500);
      echo json_encode(['error' => $e->getMessage()]);
    }
  }

  public function listCFOP($data = [])
  {
    try {
      echo json_encode($this->cfop($data));
    } catch (\Exception $e) {
      http_response_code(500);
      echo json_encode(['error' => $e->getMessage()]);
    }
  }

  public function listFormas($data = [])
  {
    try {
      echo json_encode($this->formas($data));
    } catch (\Exception $e) {
      http_response_code(500);
      echo json_encode(['error' => $e->getMessage()]);
    }
  }

  public function listUnidades($data = [])
  {
    try {
      echo json_encode($this->unidades($data));
    } catch (\Exception $e) {
      http_response_code(500);
      echo json_encode(['error' => $e->getMessage()]);
    }
  }

  public function listOrigem($data = [])
  {
    try {
      echo json_encode($this->origem($data));
    } catch (\Exception $e) {
      http_response_code(500);
      echo json_encode(['error' => $e->getMessage()]);
    }
  }

  public function listEstados($data = [])
  {
    try {
      echo json_encode($this->estados($data));
    } catch (\Exception $e) {
      http_response_code(500);
      echo json_encode(['error' => $e->getMessage()]);
    }
  }

  public function listEstadosUnico($uf)
  {
    try {
      echo json_encode($this->estadosUnico($uf));
    } catch (\Exception $e) {
      http_response_code(500);
      echo json_encode(['error' => $e->getMessage()]);
    }
  }

  public function listCidades($uf)
  {
    try {
      echo json_encode($this->cidades($uf));
    } catch (\Exception $e) {
      http_response_code(500);
      echo json_encode(['error' => $e->getMessage()]);
    }
  }

  public function listCidadesUnica($cidade)
  {
    try {
      echo json_encode($this->cidadesUnico($cidade));
    } catch (\Exception $e) {
      http_response_code(500);
      echo json_encode(['error' => $e->getMessage()]);
    }
  }

  public function testarCertificado($data = [])
  {
    try {
      echo json_encode($this->testeCertificado($data));
    } catch (\Exception $e) {
      http_response_code(400);
      echo json_encode($e->getMessage());
    }
  }

  public function testarCertificadoPorCnpj($cnpj)
  {
    try {
      echo json_encode($this->testeCertificadoPorCnpj($cnpj));
    } catch (\Exception $e) {
      http_response_code(400);
      echo json_encode($e->getMessage());
    }
  }
}
