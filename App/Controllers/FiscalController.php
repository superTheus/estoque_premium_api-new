<?php

namespace App\Controllers;

use App\Models\ApiModel;

class FiscalController extends ApiModel
{
  public function __construct()
  {
    parent::__construct();
  }

  /**
   * Valida todos os dados necessários para emissão de nota fiscal
   * 
   * @param array $empresa Dados da empresa
   * @param array $operacao Dados da operação
   * @param array|null $cliente Dados do cliente (opcional para NFCe)
   * @param array $produtos Produtos da venda
   * @param array $pagamentos Pagamentos da venda
   * @param string $tipo Tipo de nota (NFE ou NFCE)
   * @throws \Exception Se houver dados inválidos ou faltantes
   */
  private function validarDadosEmissao($empresa, $operacao, $cliente, $produtos, $pagamentos, $tipo)
  {
    $erros = [];

    // ========== VALIDAÇÃO DA EMPRESA ==========
    if (empty($empresa)) {
      $erros[] = 'Empresa não encontrada para esta venda.';
    } else {
      // CNPJ
      if (empty($empresa['cnpj'])) {
        $erros[] = 'CNPJ da empresa não informado.';
      }

      // Razão Social
      if (empty($empresa['razao_social'])) {
        $erros[] = 'Razão Social da empresa não informada.';
      }

      // Inscrição Estadual
      if (empty($empresa['inscricao_estadual'])) {
        $erros[] = 'Inscrição Estadual da empresa não informada.';
      }

      // Endereço da empresa
      if (empty($empresa['cep'])) {
        $erros[] = 'CEP da empresa não informado.';
      }
      if (empty($empresa['logradouro'])) {
        $erros[] = 'Logradouro da empresa não informado.';
      }
      if (empty($empresa['numero'])) {
        $erros[] = 'Número do endereço da empresa não informado.';
      }
      if (empty($empresa['bairro'])) {
        $erros[] = 'Bairro da empresa não informado.';
      }
      if (empty($empresa['cidade'])) {
        $erros[] = 'Cidade da empresa não informada.';
      }
      if (empty($empresa['uf'])) {
        $erros[] = 'UF da empresa não informada.';
      }

      // Certificado Digital
      if (empty($empresa['certificado'])) {
        $erros[] = 'Certificado digital da empresa não cadastrado.';
      }
      if (empty($empresa['senha'])) {
        $erros[] = 'Senha do certificado digital não informada.';
      }

      // CSC para NFCe
      if ($tipo === 'NFCE') {
        $isHomologacao = ($empresa['homologacao'] ?? 'N') === 'S';

        if ($isHomologacao) {
          if (empty($empresa['csc_homologacao'])) {
            $erros[] = 'CSC de homologação não informado para emissão de NFCe.';
          }
          if (empty($empresa['csc_id_homologacao'])) {
            $erros[] = 'ID do CSC de homologação não informado para emissão de NFCe.';
          }
        } else {
          if (empty($empresa['csc'])) {
            $erros[] = 'CSC de produção não informado para emissão de NFCe.';
          }
          if (empty($empresa['csc_id'])) {
            $erros[] = 'ID do CSC de produção não informado para emissão de NFCe.';
          }
        }
      }

      // CRT (Código de Regime Tributário)
      if (empty($empresa['crt'])) {
        $erros[] = 'Código de Regime Tributário (CRT) da empresa não informado.';
      }
    }

    // ========== VALIDAÇÃO DA OPERAÇÃO ==========
    if (empty($operacao)) {
      $erros[] = 'Operação fiscal não encontrada para esta venda.';
    } else {
      if (empty($operacao['cfop_estadual'])) {
        $erros[] = 'CFOP estadual da operação não informado.';
      }
      if (empty($operacao['descricao'])) {
        $erros[] = 'Descrição da operação não informada.';
      }
    }

    // ========== VALIDAÇÃO DO CLIENTE (obrigatório para NFE) ==========
    if ($tipo === 'NFE') {
      if (empty($cliente)) {
        $erros[] = 'NFe não pode ser emitida sem cliente. Para consumidor final, utilize NFCe.';
      } else {
        // Documento (CPF/CNPJ)
        if (empty($cliente['documento'])) {
          $erros[] = 'Documento (CPF/CNPJ) do cliente não informado. Obrigatório para NFe.';
        } else {
          $docLimpo = preg_replace('/\D/', '', $cliente['documento']);
          if (strlen($docLimpo) !== 11 && strlen($docLimpo) !== 14) {
            $erros[] = 'Documento do cliente inválido. Deve ser um CPF (11 dígitos) ou CNPJ (14 dígitos).';
          }
        }

        // Nome
        if (empty($cliente['nome'])) {
          $erros[] = 'Nome do cliente não informado';
        }

        // Endereço completo obrigatório para NFe
        if (empty($cliente['cep'])) {
          $erros[] = 'CEP do cliente não informado';
        }
        if (empty($cliente['logradouro'])) {
          $erros[] = 'Logradouro do cliente não informado';
        }
        if (empty($cliente['numero'])) {
          $erros[] = 'Número do endereço do cliente não informado';
        }
        if (empty($cliente['bairro'])) {
          $erros[] = 'Bairro do cliente não informado';
        }
        if (empty($cliente['cidade'])) {
          $erros[] = 'Cidade do cliente não informada';
        }
        if (empty($cliente['estado'])) {
          $erros[] = 'Estado do cliente não informado';
        }
      }
    }

    if ($tipo === 'NFCE' && !empty($cliente)) {
      if (!empty($cliente['documento'])) {
        $docLimpo = preg_replace('/\D/', '', $cliente['documento']);
        if (strlen($docLimpo) !== 11 && strlen($docLimpo) !== 14) {
          $erros[] = 'Documento do cliente inválido. Deve ser um CPF (11 dígitos) ou CNPJ (14 dígitos).';
        }
      }
    }

    if (empty($produtos) || count($produtos) === 0) {
      $erros[] = 'A venda não possui produtos para emissão da nota fiscal.';
    } else {
      foreach ($produtos as $index => $produto) {
        $numProduto = $index + 1;
        $descProduto = $produto['produtos']['descricao'] ?? "Produto #{$numProduto}";

        if (empty($produto['produtos'])) {
          $erros[] = "Dados do produto #{$numProduto} não encontrados.";
          continue;
        }

        // NCM
        if (empty($produto['produtos']['ncm'])) {
          $erros[] = "NCM não informado para o produto '{$descProduto}'.";
        } else {
          $ncmLimpo = preg_replace('/\D/', '', $produto['produtos']['ncm']);
          if (strlen($ncmLimpo) !== 8) {
            $erros[] = "NCM inválido para o produto '{$descProduto}'. Deve conter 8 dígitos.";
          }
        }

        // Unidade
        if (empty($produto['produtos']['unidade'])) {
          $erros[] = "Unidade de medida não informada para o produto '{$descProduto}'.";
        }

        // Descrição
        if (empty($produto['produtos']['descricao'])) {
          $erros[] = "Descrição não informada para o produto #{$numProduto}.";
        }

        // CFOP do item
        if (empty($produto['cfop'])) {
          $erros[] = "CFOP não informado para o produto '{$descProduto}'.";
        }

        // Quantidade e valor
        if (empty($produto['quantidade']) || $produto['quantidade'] <= 0) {
          $erros[] = "Quantidade inválida para o produto '{$descProduto}'.";
        }
        if (!isset($produto['preco']) || $produto['preco'] < 0) {
          $erros[] = "Preço inválido para o produto '{$descProduto}'.";
        }
      }
    }

    if (empty($pagamentos) || count($pagamentos) === 0) {
      $erros[] = 'A venda não possui pagamentos registrados para emissão da nota fiscal.';
    } else {
      foreach ($pagamentos as $index => $pagamento) {
        $numPagamento = $index + 1;

        if (empty($pagamento['formas_pagamento'])) {
          $erros[] = "Forma de pagamento #{$numPagamento} não encontrada.";
          continue;
        }

        if (
          empty($pagamento['formas_pagamento']['tipos_pagamento']) ||
          empty($pagamento['formas_pagamento']['tipos_pagamento'][0])
        ) {
          $erros[] = "Tipo de pagamento não configurado para a forma '{$pagamento['formas_pagamento']['descricao']}'.";
          continue;
        }

        $tipoPagamento = $pagamento['formas_pagamento']['tipos_pagamento'][0];
        if (!isset($tipoPagamento['codigo_sefaz']) || $tipoPagamento['codigo_sefaz'] === null) {
          $erros[] = "Código SEFAZ não configurado para o tipo de pagamento '{$tipoPagamento['descricao']}'.";
        }

        if (!isset($pagamento['valor']) || $pagamento['valor'] <= 0) {
          $erros[] = "Valor inválido para o pagamento #{$numPagamento}.";
        }
      }
    }

    if (count($erros) > 0) {
      throw new \Exception(json_encode([
        'error' => 'Validação falhou. Corrija os erros antes de emitir a nota fiscal.',
        'error_tags' => $erros
      ]));
    }
  }

  /**
   * Determina o CFOP da nota baseado no estado do cliente vs empresa
   * 
   * @param array $empresa Dados da empresa emitente
   * @param array|null $cliente Dados do cliente (null para consumidor final)
   * @param array $operacao Dados da operação fiscal
   * @return string CFOP a ser utilizado na nota
   */
  private function determinarCFOP($empresa, $cliente, $operacao)
  {
    // Se não tem cliente (consumidor final), usa CFOP estadual
    if (empty($cliente)) {
      return $operacao['cfop_estadual'];
    }

    // Normalizar UFs para comparação (maiúsculas e sem espaços)
    $ufEmpresa = strtoupper(trim($empresa['uf'] ?? ''));
    $ufCliente = strtoupper(trim($cliente['estado'] ?? ''));

    // Se cliente não tem estado informado, assume estadual
    if (empty($ufCliente)) {
      return $operacao['cfop_estadual'];
    }

    // Se mesmo estado = CFOP estadual (5XXX)
    // Se estado diferente = CFOP interestadual (6XXX)
    if ($ufEmpresa === $ufCliente) {
      return $operacao['cfop_estadual'];
    } else {
      return $operacao['cfop_internacional'];
    }
  }

  /**
   * Determina o CFOP do produto baseado no estado do cliente vs empresa
   * Converte o CFOP do produto para estadual ou interestadual conforme necessário
   * 
   * @param array $empresa Dados da empresa emitente
   * @param array|null $cliente Dados do cliente (null para consumidor final)
   * @param array $produto Dados do produto da venda
   * @return string CFOP a ser utilizado no produto
   */
  private function determinarCFOPProduto($empresa, $cliente, $produto)
  {
    $cfopOriginal = $produto['cfop'] ?? '';

    // Se não tem CFOP definido no produto, retorna vazio
    if (empty($cfopOriginal)) {
      return $cfopOriginal;
    }

    // Se não tem cliente (consumidor final), mantém o CFOP original
    if (empty($cliente)) {
      return $cfopOriginal;
    }

    // Normalizar UFs para comparação
    $ufEmpresa = strtoupper(trim($empresa['uf'] ?? ''));
    $ufCliente = strtoupper(trim($cliente['estado'] ?? ''));

    // Se cliente não tem estado informado, mantém CFOP original
    if (empty($ufCliente)) {
      return $cfopOriginal;
    }

    $primeiroDigito = substr($cfopOriginal, 0, 1);
    $restoCfop = substr($cfopOriginal, 1);

    if ($ufEmpresa === $ufCliente) {
      if ($primeiroDigito === '6') {
        return '5' . $restoCfop;
      }
      return $cfopOriginal;
    } else {
      if ($primeiroDigito === '5') {
        return '6' . $restoCfop;
      }
      return $cfopOriginal;
    }
  }

  /**
   * Determina se a operação é com consumidor final
   * 
   * Regras SEFAZ:
   * - Pessoa Física (CPF) = Sempre consumidor final
   * - Pessoa Jurídica (CNPJ) sem Inscrição Estadual = Consumidor final (não contribuinte)
   * - Pessoa Jurídica (CNPJ) com Inscrição Estadual válida = Não é consumidor final (contribuinte)
   * - Campo consumidor_final no cadastro do cliente = "S" = Consumidor final
   * 
   * @param array|null $cliente Dados do cliente
   * @return string "S" para consumidor final, "N" caso contrário
   */
  private function determinarConsumidorFinal($cliente)
  {
    // Se não tem cliente, é consumidor final
    if (empty($cliente)) {
      return "S";
    }

    // Se o cliente está marcado como consumidor final no cadastro
    if (isset($cliente['consumidor_final']) && strtoupper($cliente['consumidor_final']) === 'S') {
      return "S";
    }

    // Verificar tipo de documento
    $documento = preg_replace('/\D/', '', $cliente['documento'] ?? '');

    // CPF (11 dígitos) = Pessoa Física = Consumidor Final
    if (strlen($documento) === 11) {
      return "S";
    }

    // CNPJ (14 dígitos) = Verificar se tem Inscrição Estadual
    if (strlen($documento) === 14) {
      $inscricaoEstadual = trim($cliente['inscricao_estadual'] ?? '');

      // Se não tem IE ou é ISENTO = Não contribuinte = Consumidor Final
      if (empty($inscricaoEstadual) || strtoupper($inscricaoEstadual) === 'ISENTO') {
        return "S";
      }

      // Tem CNPJ e tem IE válida = Contribuinte = Não é consumidor final
      return "N";
    }

    // Documento inválido ou vazio = Consumidor final por segurança
    return "S";
  }

  public function emitirNFCE($idVenda)
  {
    $this->emitir($idVenda, 'NFCE');
  }

  public function emitirNFE($idVenda)
  {
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

      // Validar dados antes de emitir a nota
      $this->validarDadosEmissao($empresa, $operacao, $cliente, $produtos, $pagamentos, $tipo);

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

      if (!$cidade && $tipo === 'NFE') {
        throw new \Exception(json_encode([
          "error" => "NFe requer informações de cidade/endereço. Certifique-se de que o cliente possui um endereço válido."
        ]));
      }

      // Determinar CFOP baseado no estado do cliente vs empresa
      // Se cliente do mesmo estado da empresa = CFOP estadual (ex: 5102)
      // Se cliente de outro estado = CFOP interestadual (ex: 6102)
      $cfopNota = $this->determinarCFOP($empresa, $cliente, $operacao);

      $produtosNota = [];
      $pagamentosNota = [];

      foreach ($produtos as $key => $produto) {
        $cfopProduto = $this->determinarCFOPProduto($empresa, $cliente, $produto);

        $produtosNota[] = [
          "acrescimo" => 0,
          "cfop" => $cfopProduto,
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

      // Determinar se é consumidor final
      // Consumidor final = Pessoa Física (CPF) OU Pessoa Jurídica sem Inscrição Estadual válida
      // Não contribuinte = sempre consumidor final
      $consumidorFinal = $this->determinarConsumidorFinal($cliente);
      $consumidorFinalFlag = $consumidorFinal === "S" ? 1 : 0;

      $clienteData = null;
      if ($cliente) {
        $documentoLimpo = preg_replace('/\D/', '', $cliente["documento"] ?? '');
        $tipoDocumento = strlen($documentoLimpo) === 11 ? "CPF" : "CNPJ";
        $inscricaoEstadual = trim($cliente["inscricao_estadual"] ?? "");
        $temInscricaoValida = !empty($inscricaoEstadual) && strtoupper($inscricaoEstadual) !== "ISENTO";

        // Força consumidor final para CPF ou ausência de inscrição estadual
        if ($tipoDocumento === "CPF" || !$temInscricaoValida) {
          $consumidorFinal = "S";
          $consumidorFinalFlag = 1;
        }

        $tipoIcms = ($tipoDocumento === "CNPJ" && $temInscricaoValida && $consumidorFinal === "N") ? "1" : "9";

        if ($tipoIcms === "9") {
          $inscricaoEstadual = "ISENTO";
        }

        $clienteData = [
          "documento" => $cliente["documento"],
          "nome" => $cliente["nome"],
          "tipo_documento" => $tipoDocumento,
          "tipo_icms" => $tipoIcms,
          "endereco" => $endereco,
          "inscricao_estadual" => $inscricaoEstadual,
          "dthr_emissao" => date('Y-m-d H:i:s')
        ];
      } else {
        $clienteData = [
          "documento" => "00000000000",
          "nome" => "CONSUMIDOR FINAL",
          "tipo_documento" => "CPF",
          "tipo_icms" => "9", // 9 = Não contribuinte
          "endereco" => $endereco,
          "inscricao_estadual" => "ISENTO"
        ];
      }

      $dadosEmissao = [
        "cnpj" => $empresa['cnpj'],
        "cfop" => $cfopNota,
        "operacao" => $operacao['descricao'],
        "consumidor_final" => $consumidorFinal,
        "ind_final" => $consumidorFinalFlag,
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

      if($notaEmitida) {
        $venda = $vendasController->updateOnly([
          "nota_emitida" => "S",
          "protocolo" => $notaEmitida['protocolo'],
          "chave" => $notaEmitida['chave'],
          "url" => $notaEmitida['link'],
          "pdf" => $notaEmitida['pdf'],
          "xml" => $notaEmitida['xml'],
          'tipo' => $tipo,
          "status_nota" => "S",
          "messagem_error" => ""
        ]);
      } else {
        throw new \Exception(json_encode([
          "error" => "Erro desconhecido ao emitir a nota fiscal."
        ]));
      }

      http_response_code(200);
      echo json_encode($venda);
    } catch (\Exception $e) {
      $rawMessage = $e->getMessage();
      error_log('[FiscalController::emitir] Erro ao emitir nota: ' . $rawMessage);

      // Tenta interpretar como JSON; se falhar, usa mensagem simples
      $errors = json_decode($rawMessage, true);
      if (json_last_error() !== JSON_ERROR_NONE || $errors === null) {
        $errors = ['error' => $rawMessage];
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

  public function cancelarNotaSomente($idVenda)
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
          "empresas" => true
        ]
      ]);

      if (!isset($response[0]) || empty($response[0])) {
        throw new \Exception('Venda não encontrada.');
        return;
      }

      $venda = $response[0];
      $empresa = $venda['empresas'][0];

      $dadosCancelamento = [
        "cnpj" => $empresa['cnpj'],
        "chave" => $venda['chave'],
        "justificativa" => 'Cancelamento solicitado pelo contribuinte.'
      ];

      if ($venda['tipo'] === 'NFCE') {
        $cancelamento = $this->cancelarNfce($dadosCancelamento);
      } else {
        $cancelamento = $this->cancelarNfe($dadosCancelamento);
      }

      if($venda['status'] !== 'CA') {
        $venda = $vendasController->updateOnly([
          "status" => "CA"
        ]);
      }

      return $cancelamento;
    } catch (\Exception $e) {
      $rawMessage = $e->getMessage();

      throw new \Exception($rawMessage);
    }
  }

  public function cancelarNota($idVenda)
  {
    try {
      $cancelamento = $this->cancelarNotaSomente($idVenda);

      http_response_code(200);
      echo json_encode($cancelamento);
    } catch (\Exception $e) {
      $rawMessage = $e->getMessage();
      error_log('[FiscalController::emitir] Erro ao emitir nota: ' . $rawMessage);

      $errors = json_decode($rawMessage, true);
      if (json_last_error() !== JSON_ERROR_NONE || $errors === null) {
        $errors = ['error' => $rawMessage];
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
