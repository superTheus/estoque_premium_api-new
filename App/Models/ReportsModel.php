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

    public function getSalesReports($data)
    {
        $sql = "
            SELECT V.id, COALESCE(C.nome, 'Consumidor Final') AS cliente, O.descricao AS operacao, E.nome_fantasia AS empresa, U.nome AS vendedor, V.total, V.`status`, V.dthr_registro FROM vendas V
            LEFT JOIN clientes C ON C.id = V.id_cliente
            INNER JOIN operacoes O ON O.id = V.id_operacao
            INNER JOIN empresas E ON E.id = V.id_empresa
            INNER JOIN usuarios U ON U.id = V.id_vendedor
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

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':idConta', $data['idConta']);
            $stmt->bindParam(':startDate', $data['startDate']);
            $stmt->bindParam(':endDate', $data['endDate']);
            if (isset($data['cliente']) && $data['cliente'] != 0) {
                $stmt->bindParam(':cliente', $data['cliente']);
            }
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new PDOException("Erro ao buscar relatórios fiscais: " . $e->getMessage());
        }
    }

    public function getEstoqueReport($data)
    {
        $sql = "
            SELECT P.descricao, P.unidade, P.ncm, M.descricao AS marca, C.descricao AS categoria, S.descricao AS subcategoria, F.nome AS fornecedor, 
            PE.estoque, PE.estoque_minimo, PE.preco_venda, PE.preco_custo, E.nome_fantasia AS  empresa FROM produtos P
            LEFT JOIN marcas M ON M.id = P.id_marca
            LEFT JOIN categorias C ON C.id = P.id_categoria
            LEFT JOIN subcategorias S ON S.id = P.id_subcategoria
            LEFT JOIN fornecedores F ON F.id = P.id_fornecedor
            LEFT JOIN produtos_estoque PE ON PE.id_produto = P.id
            LEFT JOIN empresas E ON E.id = PE.id_empresa
            WHERE PE.id_empresa = :empresa AND P.id_conta = :conta
        ";

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

        try {
            $stmt = $this->conn->prepare($sql);
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
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new PDOException("Erro ao buscar relatórios fiscais: " . $e->getMessage());
        }
    }
}
