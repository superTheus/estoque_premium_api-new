<?php

namespace App\Models;

use PDO;
use PDOException;

class ReportsModel extends Connection
{
    private $conn;

    public function __construct()
    {
        $this->conn = $this->openConnection();
    }

    private function buildProductDescriptionPattern($operator, $value)
    {
        $escapedValue = addcslashes(trim($value), "\\%_");

        switch ($operator) {
            case 'starts_with':
            case 'not_starts_with':
                return $escapedValue . '%';
            case 'ends_with':
            case 'not_ends_with':
                return '%' . $escapedValue;
            case 'contains':
            case 'not_contains':
            default:
                return '%' . $escapedValue . '%';
        }
    }

    private function buildSalesOrderBy($field, $direction)
    {
        $direction = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';
        $orderFields = [
            'subtotal' => 'V.total',
            'produto' => "(SELECT MIN(PORD.descricao) FROM venda_produtos VPORD INNER JOIN produtos PORD ON PORD.id = VPORD.id_produto WHERE VPORD.id_venda = V.id)",
            'cliente' => "COALESCE(C.nome, 'Consumidor Final')",
            'quantidade' => "(SELECT COALESCE(SUM(VPQ.quantidade), 0) FROM venda_produtos VPQ WHERE VPQ.id_venda = V.id)",
            'estoque' => "(SELECT COALESCE(SUM(PEORD.estoque), 0) FROM venda_produtos VPEORD LEFT JOIN produtos_estoque PEORD ON PEORD.id_produto = VPEORD.id_produto AND PEORD.id_empresa = V.id_empresa WHERE VPEORD.id_venda = V.id)",
            'usuario' => 'U.nome',
            'vendedor' => 'VE.nome',
            'data' => 'V.dthr_registro',
            'os' => 'V.id',
        ];

        $orderField = $orderFields[$field] ?? $orderFields['subtotal'];

        return "{$orderField} {$direction}, V.id DESC";
    }

    private function buildCustomerSalesOrderBy($field, $direction)
    {
        $direction = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';
        $orderFields = [
            'subtotal' => 'total',
            'cliente' => 'cliente',
            'quantidade' => 'vendas',
            'data' => 'ultima_venda',
        ];

        $orderField = $orderFields[$field] ?? $orderFields['subtotal'];

        return "{$orderField} {$direction}, cliente ASC";
    }

    private function buildProductSalesOrderBy($field, $direction)
    {
        $direction = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';
        $orderFields = [
            'subtotal' => 'total',
            'produto' => 'produto',
            'quantidade' => 'quantidade',
            'estoque' => 'estoque',
            'data' => 'ultima_venda',
        ];

        $orderField = $orderFields[$field] ?? $orderFields['subtotal'];

        return "{$orderField} {$direction}, produto ASC";
    }

    private function buildStockOrderBy($field, $direction)
    {
        $direction = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';
        $orderFields = [
            'descricao' => 'P.descricao',
            'estoque' => 'COALESCE(PE.estoque, 0)',
            'preco_custo' => 'COALESCE(PE.preco_custo, 0)',
            'preco_venda' => 'COALESCE(PE.preco_venda, 0)',
            'categoria' => 'C.descricao',
        ];

        $orderField = $orderFields[$field] ?? $orderFields['estoque'];

        return "{$orderField} {$direction}, P.descricao ASC";
    }

    public function getSalesReports($data)
    {
        $tipoRelatorio = $data['tipoRelatorio'] ?? 'vendas';

        if ($tipoRelatorio === 'clientes') {
            return $this->getSalesCustomersReport($data);
        }

        if ($tipoRelatorio === 'produtos') {
            return $this->getSalesProductsReport($data);
        }

        $produtoDescricaoPattern = null;
        $produtoDescricaoNegadaPattern = null;

        $sql = "
            SELECT
                V.id,
                COALESCE(C.nome, 'Consumidor Final') AS cliente,
                O.descricao AS operacao,
                E.nome_fantasia AS empresa,
                U.nome AS usuario,
                VE.nome AS vendedor,
                V.total,
                V.`status`,
                V.dthr_registro
            FROM vendas V
            LEFT JOIN clientes C ON C.id = V.id_cliente
            INNER JOIN operacoes O ON O.id = V.id_operacao
            INNER JOIN empresas E ON E.id = V.id_empresa
            LEFT JOIN usuarios U ON U.id = V.id_usuario
            LEFT JOIN vendedores VE ON VE.id = V.id_vendedor
            WHERE V.id_conta = :idConta AND DATE(V.dthr_registro) BETWEEN :startDate AND :endDate
        ";

        if (isset($data['cliente'])) {
            if ($data['cliente'] == 0) {
                $sql .= " AND V.id_cliente IS NULL";
                unset($data['cliente']);
            } else {
                $sql .= " AND V.id_cliente = :cliente";
            }
        }

        if (isset($data['empresa'])) {
            $sql .= " AND V.id_empresa = :empresa";
        }

        if (isset($data['operacao'])) {
            $sql .= " AND V.id_operacao = :operacao";
        }

        if (isset($data['usuario'])) {
            $sql .= " AND V.id_usuario = :usuario";
        }

        if (isset($data['vendedor'])) {
            $sql .= " AND V.id_vendedor = :vendedor";
        }

        if (isset($data['produto'])) {
            $sql .= " AND EXISTS (
                SELECT 1
                FROM venda_produtos VP
                WHERE VP.id_venda = V.id
                    AND VP.id_produto = :produto
            )";
        }

        if (!empty($data['produtoDescricao'])) {
            $produtoDescricaoPattern = $this->buildProductDescriptionPattern(
                $data['produtoDescricaoOperador'] ?? 'contains',
                $data['produtoDescricao']
            );
            $sql .= " AND EXISTS (
                SELECT 1
                FROM venda_produtos VPD
                INNER JOIN produtos PD ON PD.id = VPD.id_produto
                WHERE VPD.id_venda = V.id
                    AND PD.descricao LIKE :produtoDescricao ESCAPE '\\\\'
            )";
        }

        if (!empty($data['produtoDescricaoNegada'])) {
            $produtoDescricaoNegadaPattern = $this->buildProductDescriptionPattern(
                $data['produtoDescricaoNegadaOperador'] ?? 'not_contains',
                $data['produtoDescricaoNegada']
            );
            $sql .= " AND NOT EXISTS (
                SELECT 1
                FROM venda_produtos VPND
                INNER JOIN produtos PND ON PND.id = VPND.id_produto
                WHERE VPND.id_venda = V.id
                    AND PND.descricao LIKE :produtoDescricaoNegada ESCAPE '\\\\'
            )";
        }

        $sql .= " ORDER BY " . $this->buildSalesOrderBy(
            $data['ordenacaoCampo'] ?? 'subtotal',
            $data['ordenacaoDirecao'] ?? 'desc'
        );

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':idConta', $data['idConta']);
            $stmt->bindParam(':startDate', $data['startDate']);
            $stmt->bindParam(':endDate', $data['endDate']);
            if (isset($data['cliente']) && $data['cliente'] != 0) {
                $stmt->bindParam(':cliente', $data['cliente']);
            }
            if (isset($data['empresa'])) {
                $stmt->bindParam(':empresa', $data['empresa']);
            }
            if (isset($data['operacao'])) {
                $stmt->bindParam(':operacao', $data['operacao']);
            }
            if (isset($data['usuario'])) {
                $stmt->bindParam(':usuario', $data['usuario']);
            }
            if (isset($data['vendedor'])) {
                $stmt->bindParam(':vendedor', $data['vendedor']);
            }
            if (isset($data['produto'])) {
                $stmt->bindParam(':produto', $data['produto']);
            }
            if ($produtoDescricaoPattern !== null) {
                $stmt->bindParam(':produtoDescricao', $produtoDescricaoPattern);
            }
            if ($produtoDescricaoNegadaPattern !== null) {
                $stmt->bindParam(':produtoDescricaoNegada', $produtoDescricaoNegadaPattern);
            }
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new PDOException("Erro ao buscar relatórios fiscais: " . $e->getMessage());
        }
    }

    private function getSalesCustomersReport($data)
    {
        $produtoDescricaoPattern = null;
        $produtoDescricaoNegadaPattern = null;

        $sql = "
            SELECT
                COALESCE(C.id, 0) AS cliente_id,
                COALESCE(C.nome, 'Consumidor Final') AS cliente,
                COALESCE(C.documento, '') AS documento,
                COUNT(DISTINCT V.id) AS vendas,
                SUM(V.total) AS total,
                AVG(V.total) AS ticket_medio,
                MAX(V.dthr_registro) AS ultima_venda
            FROM vendas V
            LEFT JOIN clientes C ON C.id = V.id_cliente
            INNER JOIN operacoes O ON O.id = V.id_operacao
            INNER JOIN empresas E ON E.id = V.id_empresa
            LEFT JOIN usuarios U ON U.id = V.id_usuario
            LEFT JOIN vendedores VE ON VE.id = V.id_vendedor
            WHERE V.id_conta = :idConta AND DATE(V.dthr_registro) BETWEEN :startDate AND :endDate
        ";

        if (isset($data['cliente'])) {
            if ($data['cliente'] == 0) {
                $sql .= " AND V.id_cliente IS NULL";
                unset($data['cliente']);
            } else {
                $sql .= " AND V.id_cliente = :cliente";
            }
        }

        if (isset($data['empresa'])) {
            $sql .= " AND V.id_empresa = :empresa";
        }

        if (isset($data['operacao'])) {
            $sql .= " AND V.id_operacao = :operacao";
        }

        if (isset($data['usuario'])) {
            $sql .= " AND V.id_usuario = :usuario";
        }

        if (isset($data['vendedor'])) {
            $sql .= " AND V.id_vendedor = :vendedor";
        }

        if (isset($data['produto'])) {
            $sql .= " AND EXISTS (
                SELECT 1
                FROM venda_produtos VP
                WHERE VP.id_venda = V.id
                    AND VP.id_produto = :produto
            )";
        }

        if (!empty($data['produtoDescricao'])) {
            $produtoDescricaoPattern = $this->buildProductDescriptionPattern(
                $data['produtoDescricaoOperador'] ?? 'contains',
                $data['produtoDescricao']
            );
            $sql .= " AND EXISTS (
                SELECT 1
                FROM venda_produtos VPD
                INNER JOIN produtos PD ON PD.id = VPD.id_produto
                WHERE VPD.id_venda = V.id
                    AND PD.descricao LIKE :produtoDescricao ESCAPE '\\\\'
            )";
        }

        if (!empty($data['produtoDescricaoNegada'])) {
            $produtoDescricaoNegadaPattern = $this->buildProductDescriptionPattern(
                $data['produtoDescricaoNegadaOperador'] ?? 'not_contains',
                $data['produtoDescricaoNegada']
            );
            $sql .= " AND NOT EXISTS (
                SELECT 1
                FROM venda_produtos VPND
                INNER JOIN produtos PND ON PND.id = VPND.id_produto
                WHERE VPND.id_venda = V.id
                    AND PND.descricao LIKE :produtoDescricaoNegada ESCAPE '\\\\'
            )";
        }

        $sql .= "
            GROUP BY
                COALESCE(C.id, 0),
                COALESCE(C.nome, 'Consumidor Final'),
                COALESCE(C.documento, '')
            ORDER BY " . $this->buildCustomerSalesOrderBy(
                $data['ordenacaoCampo'] ?? 'subtotal',
                $data['ordenacaoDirecao'] ?? 'desc'
            );

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':idConta', $data['idConta']);
            $stmt->bindParam(':startDate', $data['startDate']);
            $stmt->bindParam(':endDate', $data['endDate']);

            if (isset($data['cliente']) && $data['cliente'] != 0) {
                $stmt->bindParam(':cliente', $data['cliente']);
            }
            if (isset($data['empresa'])) {
                $stmt->bindParam(':empresa', $data['empresa']);
            }
            if (isset($data['operacao'])) {
                $stmt->bindParam(':operacao', $data['operacao']);
            }
            if (isset($data['usuario'])) {
                $stmt->bindParam(':usuario', $data['usuario']);
            }
            if (isset($data['vendedor'])) {
                $stmt->bindParam(':vendedor', $data['vendedor']);
            }
            if (isset($data['produto'])) {
                $stmt->bindParam(':produto', $data['produto']);
            }
            if ($produtoDescricaoPattern !== null) {
                $stmt->bindParam(':produtoDescricao', $produtoDescricaoPattern);
            }
            if ($produtoDescricaoNegadaPattern !== null) {
                $stmt->bindParam(':produtoDescricaoNegada', $produtoDescricaoNegadaPattern);
            }

            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new PDOException("Erro ao buscar relatorio de clientes: " . $e->getMessage());
        }
    }

    private function getSalesProductsReport($data)
    {
        $produtoDescricaoPattern = null;
        $produtoDescricaoNegadaPattern = null;

        $sql = "
            SELECT
                P.id AS produto_id,
                P.descricao AS produto,
                P.unidade,
                COUNT(DISTINCT V.id) AS vendas,
                SUM(VP.quantidade) AS quantidade,
                SUM((COALESCE(VP.preco, 0) * COALESCE(VP.quantidade, 0)) - COALESCE(VP.desconto_real, 0)) AS total,
                COALESCE(MAX(PE.estoque), 0) AS estoque,
                MAX(V.dthr_registro) AS ultima_venda
            FROM vendas V
            INNER JOIN venda_produtos VP ON VP.id_venda = V.id
            INNER JOIN produtos P ON P.id = VP.id_produto
            LEFT JOIN produtos_estoque PE ON PE.id_produto = P.id AND PE.id_empresa = V.id_empresa
            INNER JOIN operacoes O ON O.id = V.id_operacao
            INNER JOIN empresas E ON E.id = V.id_empresa
            LEFT JOIN usuarios U ON U.id = V.id_usuario
            LEFT JOIN vendedores VE ON VE.id = V.id_vendedor
            WHERE V.id_conta = :idConta AND DATE(V.dthr_registro) BETWEEN :startDate AND :endDate
        ";

        if (isset($data['cliente'])) {
            if ($data['cliente'] == 0) {
                $sql .= " AND V.id_cliente IS NULL";
                unset($data['cliente']);
            } else {
                $sql .= " AND V.id_cliente = :cliente";
            }
        }

        if (isset($data['empresa'])) {
            $sql .= " AND V.id_empresa = :empresa";
        }

        if (isset($data['operacao'])) {
            $sql .= " AND V.id_operacao = :operacao";
        }

        if (isset($data['usuario'])) {
            $sql .= " AND V.id_usuario = :usuario";
        }

        if (isset($data['vendedor'])) {
            $sql .= " AND V.id_vendedor = :vendedor";
        }

        if (isset($data['produto'])) {
            $sql .= " AND VP.id_produto = :produto";
        }

        if (!empty($data['produtoDescricao'])) {
            $produtoDescricaoPattern = $this->buildProductDescriptionPattern(
                $data['produtoDescricaoOperador'] ?? 'contains',
                $data['produtoDescricao']
            );
            $sql .= " AND P.descricao LIKE :produtoDescricao ESCAPE '\\\\'";
        }

        if (!empty($data['produtoDescricaoNegada'])) {
            $produtoDescricaoNegadaPattern = $this->buildProductDescriptionPattern(
                $data['produtoDescricaoNegadaOperador'] ?? 'not_contains',
                $data['produtoDescricaoNegada']
            );
            $sql .= " AND P.descricao NOT LIKE :produtoDescricaoNegada ESCAPE '\\\\'";
        }

        $sql .= "
            GROUP BY
                P.id,
                P.descricao,
                P.unidade
            ORDER BY " . $this->buildProductSalesOrderBy(
                $data['ordenacaoCampo'] ?? 'subtotal',
                $data['ordenacaoDirecao'] ?? 'desc'
            );

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':idConta', $data['idConta']);
            $stmt->bindParam(':startDate', $data['startDate']);
            $stmt->bindParam(':endDate', $data['endDate']);

            if (isset($data['cliente']) && $data['cliente'] != 0) {
                $stmt->bindParam(':cliente', $data['cliente']);
            }
            if (isset($data['empresa'])) {
                $stmt->bindParam(':empresa', $data['empresa']);
            }
            if (isset($data['operacao'])) {
                $stmt->bindParam(':operacao', $data['operacao']);
            }
            if (isset($data['usuario'])) {
                $stmt->bindParam(':usuario', $data['usuario']);
            }
            if (isset($data['vendedor'])) {
                $stmt->bindParam(':vendedor', $data['vendedor']);
            }
            if (isset($data['produto'])) {
                $stmt->bindParam(':produto', $data['produto']);
            }
            if ($produtoDescricaoPattern !== null) {
                $stmt->bindParam(':produtoDescricao', $produtoDescricaoPattern);
            }
            if ($produtoDescricaoNegadaPattern !== null) {
                $stmt->bindParam(':produtoDescricaoNegada', $produtoDescricaoNegadaPattern);
            }

            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new PDOException("Erro ao buscar relatorio de produtos: " . $e->getMessage());
        }
    }

    public function getEstoqueReport($data)
    {
        $produtoDescricaoPattern = null;
        $produtoDescricaoNegadaPattern = null;

        $sql = "
            SELECT
                P.descricao,
                P.unidade,
                P.ncm,
                M.descricao AS marca,
                C.descricao AS categoria,
                S.descricao AS subcategoria,
                F.nome AS fornecedor,
                COALESCE(PE.estoque, 0) AS estoque,
                COALESCE(PE.estoque_minimo, 0) AS estoque_minimo,
                COALESCE(PE.preco_venda, 0) AS preco_venda,
                COALESCE(PE.preco_custo, 0) AS preco_custo,
                E.nome_fantasia AS empresa
            FROM produtos P
            LEFT JOIN marcas M ON M.id = P.id_marca
            LEFT JOIN categorias C ON C.id = P.id_categoria
            LEFT JOIN subcategorias S ON S.id = P.id_subcategoria
            LEFT JOIN fornecedores F ON F.id = P.id_fornecedor
            LEFT JOIN produtos_estoque PE ON PE.id_produto = P.id AND PE.id_empresa = :empresaEstoque
            LEFT JOIN empresas E ON E.id = :empresa
            WHERE P.id_conta = :conta
        ";

        $tipoEstoque = $data['tipoEstoque'] ?? null;

        if ($tipoEstoque === 'com_estoque') {
            $sql .= " AND COALESCE(PE.estoque, 0) > 0";
        } elseif ($tipoEstoque === 'sem_estoque') {
            $sql .= " AND COALESCE(PE.estoque, 0) <= 0";
        } elseif ($tipoEstoque !== 'todos') {
            $sql .= " AND PE.id_empresa IS NOT NULL";
        }

        if (isset($data['marca'])) {
            $sql .= " AND P.id_marca = :marca";
        }

        if (isset($data['categoria'])) {
            $sql .= " AND P.id_categoria = :categoria";
        }

        if (isset($data['subcategoria'])) {
            $sql .= " AND P.id_subcategoria = :subcategoria";
        }

        if (isset($data['fornecedor'])) {
            $sql .= " AND P.id_fornecedor = :fornecedor";
        }

        if (!empty($data['produtoDescricao'])) {
            $produtoDescricaoPattern = $this->buildProductDescriptionPattern(
                $data['produtoDescricaoOperador'] ?? 'contains',
                $data['produtoDescricao']
            );
            $sql .= " AND P.descricao LIKE :produtoDescricao ESCAPE '\\\\'";
        }

        if (!empty($data['produtoDescricaoNegada'])) {
            $produtoDescricaoNegadaPattern = $this->buildProductDescriptionPattern(
                $data['produtoDescricaoNegadaOperador'] ?? 'not_contains',
                $data['produtoDescricaoNegada']
            );
            $sql .= " AND P.descricao NOT LIKE :produtoDescricaoNegada ESCAPE '\\\\'";
        }

        $sql .= " ORDER BY " . $this->buildStockOrderBy(
            $data['ordenacaoCampo'] ?? 'estoque',
            $data['ordenacaoDirecao'] ?? 'desc'
        );

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':empresaEstoque', $data['empresa']);
            $stmt->bindParam(':empresa', $data['empresa']);
            $stmt->bindParam(':conta', $data['conta']);
            if (isset($data['marca'])) {
                $stmt->bindParam(':marca', $data['marca']);
            }
            if (isset($data['categoria'])) {
                $stmt->bindParam(':categoria', $data['categoria']);
            }
            if (isset($data['subcategoria'])) {
                $stmt->bindParam(':subcategoria', $data['subcategoria']);
            }
            if (isset($data['fornecedor'])) {
                $stmt->bindParam(':fornecedor', $data['fornecedor']);
            }
            if ($produtoDescricaoPattern !== null) {
                $stmt->bindParam(':produtoDescricao', $produtoDescricaoPattern);
            }
            if ($produtoDescricaoNegadaPattern !== null) {
                $stmt->bindParam(':produtoDescricaoNegada', $produtoDescricaoNegadaPattern);
            }
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new PDOException("Erro ao buscar relatorio de estoque: " . $e->getMessage());
        }
    }

    public function getComissoesReport($data)
    {
        $tipo = $data['tipo'] ?? 'vendedores';
        $isMotorista = $tipo === 'motoristas';
        $pessoaTable = $isMotorista ? 'motoristas' : 'vendedores';
        $pessoaKey = $isMotorista ? 'id_motorista' : 'id_vendedor';

        $baseComissao = "GREATEST(SUM((COALESCE(VP.preco, 0) * COALESCE(VP.quantidade, 0)) - COALESCE(VP.desconto_real, 0)), 0)";

        $sql = "
            SELECT
                V.id AS venda_id,
                V.dthr_registro,
                V.status,
                V.id_empresa,
                E.nome_fantasia AS empresa,
                P.id AS pessoa_id,
                P.nome AS pessoa,
                P.tipo_comissao,
                COALESCE(P.comissao_percentual, 0) AS comissao_percentual,
                COALESCE(P.comissao_valor, 0) AS comissao_valor,
                {$baseComissao} AS base_comissao,
                CASE
                    WHEN P.tipo_comissao = 'V' THEN COALESCE(P.comissao_valor, 0)
                    ELSE ROUND({$baseComissao} * (COALESCE(P.comissao_percentual, 0) / 100), 2)
                END AS valor_comissao
            FROM vendas V
            INNER JOIN venda_produtos VP ON VP.id_venda = V.id
            INNER JOIN {$pessoaTable} P ON P.id = V.{$pessoaKey}
            INNER JOIN empresas E ON E.id = V.id_empresa
            WHERE V.id_conta = :idConta
                AND V.deletado = 'N'
                AND DATE(V.dthr_registro) BETWEEN :startDate AND :endDate
        ";

        if ($isMotorista) {
            $sql .= " AND V.id_motorista IS NOT NULL AND V.id_motorista > 0";
        }

        if (!empty($data['empresa']) && intval($data['empresa']) > 0) {
            $sql .= " AND V.id_empresa = :empresa";
        }

        if (!empty($data['pessoa']) && intval($data['pessoa']) > 0) {
            $sql .= " AND P.id = :pessoa";
        }

        if (!empty($data['status']) && $data['status'] !== 'TD') {
            $sql .= " AND V.status = :status";
        }

        $sql .= "
            GROUP BY
                V.id,
                V.dthr_registro,
                V.status,
                V.id_empresa,
                E.nome_fantasia,
                P.id,
                P.nome,
                P.tipo_comissao,
                P.comissao_percentual,
                P.comissao_valor
            ORDER BY P.nome ASC, V.dthr_registro ASC
        ";

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':idConta', $data['idConta']);
            $stmt->bindParam(':startDate', $data['startDate']);
            $stmt->bindParam(':endDate', $data['endDate']);

            if (!empty($data['empresa']) && intval($data['empresa']) > 0) {
                $stmt->bindParam(':empresa', $data['empresa']);
            }

            if (!empty($data['pessoa']) && intval($data['pessoa']) > 0) {
                $stmt->bindParam(':pessoa', $data['pessoa']);
            }

            if (!empty($data['status']) && $data['status'] !== 'TD') {
                $stmt->bindParam(':status', $data['status']);
            }

            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new PDOException("Erro ao buscar relatorio de comissoes: " . $e->getMessage());
        }
    }
}
