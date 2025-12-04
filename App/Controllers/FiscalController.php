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

      if (!$idVenda) {
        throw new \Exception('ID da venda é obrigatório para emissão de nota fiscal.');
      }

      $vendasController = new VendasController($idVenda);
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

      // Validar cliente obrigatório apenas para NFe
      if (!isset($venda['clientes'][0]) && $tipo === 'NFE') {
        throw new \Exception(json_encode([
          "error" => "NFe não pode ser emitida sem cliente ou para consumidor final, nesse caso tente emitir uma NFCe."
        ]));
      }

      $empresa = $venda['empresas'][0];
      $operacao = $venda['operacoes'][0];
      $cliente = isset($venda['clientes'][0]) ? $venda['clientes'][0] : null;
      $produtos = $venda['venda_produtos'];
      $pagamentos = $venda['venda_pagamentos'];

      // Obter dados de cidade e endereço
      $cidade = null;
      $endereco = null;

      if ($cliente) {
        $cidade = $this->cidadesUnico($cliente['cidade']);
        $endereco = [
          "bairro" => $cliente['bairro'] ?? "",
          "codigo_municipio" => $cidade['codigo_ibge'] ?? "",
          "logradouro" => $cliente["logradouro"] ?? "",
          "municipio" => $cliente["cidade"] ?? "",
          "numero" => $cliente["numero"] ?? "S/N",
          "uf" => $cliente["estado"] ?? "",
          "cep" => $cliente["cep"] ?? ""
        ];
      } else {
        // Para consumidor final (NFCE), usar dados da empresa (se disponível)
        if (!empty($empresa['cidade'])) {
          $cidadeEmpresa = $this->cidadesUnico($empresa['cidade']);
          $endereco = [
            "bairro" => $empresa['bairro'] ?? "",
            "codigo_municipio" => $cidadeEmpresa['codigo_ibge'] ?? "",
            "logradouro" => $empresa["logradouro"] ?? "",
            "municipio" => $empresa["cidade"] ?? "",
            "numero" => $empresa["numero"] ?? "S/N",
            "uf" => $empresa["estado"] ?? "",
            "cep" => $empresa["cep"] ?? ""
          ];
          $cidade = $cidadeEmpresa;
        } else {
          // Se não tiver informação de cidade/endereço
          $endereco = [
            "bairro" => "",
            "codigo_municipio" => "",
            "logradouro" => "",
            "municipio" => "",
            "numero" => "S/N",
            "uf" => "",
            "cep" => ""
          ];
        }
      }

      // Validar obrigatoriedade de cidade apenas para NFe
      if (!$cidade && $tipo === 'NFE') {
        throw new \Exception(json_encode([
          "error" => "NFe requer informações de cidade/endereço. Certifique-se de que o cliente possui um endereço válido."
        ]));
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

      $consumidorFinal = $cliente ? "N" : "S";
      
      $clienteData = null;
      if ($cliente) {
        $clienteData = [
          "documento" => $cliente["documento"],
          "nome" => $cliente["nome"],
          "tipo_documento" => strlen(preg_replace('/\D/', '', $cliente["documento"])) === 11 ? "CPF" : "CNPJ",
          "tipo_icms" => $cliente['icms'],
          "endereco" => $endereco,
          "inscricao_estadual" => $cliente["inscricao_estadual"] ?? ""
        ];
      } else {
        $clienteData = [
          "documento" => "00000000000",
          "nome" => "CONSUMIDOR FINAL",
          "tipo_documento" => "CPF",
          "tipo_icms" => "RP",
          "endereco" => $endereco,
          "inscricao_estadual" => "ISENTO"
        ];
      }

      $dadosEmissao = [
        "cnpj" => $empresa['cnpj'],
        "cfop" =>  $operacao['cfop_estadual'],
        "operacao" => $operacao['descricao'],
        "consumidor_final" => $consumidorFinal,
        "observacao" => $venda['observacao_nota'],
        "cliente" => $clienteData,
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
        "messagem_error" => null
      ]);

      http_response_code(200);
      echo json_encode($venda);
    } catch (\Exception $e) {
      $rawMessage = $e->getMessage();
      error_log('[FiscalController::emitir] Erro ao emitir nota: ' . $rawMessage);

      // Tenta interpretar como JSON; se falhar, usa mensagem simples
      $errors = json_decode($rawMessage, true);
      if (json_last_error() !== JSON_ERROR_NONE || $errors === null) {
        $errors = [ 'error' => $rawMessage ];
      }

      // Monta mensagem amigável
      $messagemErro = $errors['error'] ?? ($errors['message'] ?? 'Erro ao emitir nota.');
      if (isset($errors['error_tags']) && is_array($errors['error_tags']) && count($errors['error_tags']) > 0) {
        $messagemErro = 'Erros: ' . implode(', ', $errors['error_tags']);
      }

      // Tenta registrar o erro na venda sem mascarar o erro original
      try {
        $vendasController = new VendasController($idVenda);
        $vendasController->updateOnly([
          'nota_emitida' => 'S',
          'status_nota' => 'F',
          'messagem_error' => $messagemErro,
          'xml' => $errors['xml'] ?? null
        ]);
      } catch (\Exception $inner) {
        error_log('[FiscalController::emitir] Falha ao persistir erro na venda: ' . $inner->getMessage());
      }

      http_response_code(400);
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
