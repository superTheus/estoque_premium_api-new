<?php

namespace App\Controllers;

use App\Models\ReportsModel;
use Dotenv\Dotenv;

class ReportsController
{
    private $model;

    public function __construct($id = null)
    {
        $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
        $dotenv->load();

        $this->model = new ReportsModel($id ? $id : null);
    }

    public function getSalesReports($data = [])
    {   
        if (!isset($data['idConta']) || !isset($data['startDate']) || !isset($data['endDate'])) {
            http_response_code(400);
            echo json_encode(["message" => "Parâmetros insuficientes."]);
            return;
        }

        try {
            http_response_code(200);
            echo json_encode($this->model->getSalesReports($data));
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["message" => $e->getMessage()]);
        }
    }

    public function getEstoqueReports($data = [])
    {   
        if (!isset($data['conta']) || !isset($data['empresa'])) {
            http_response_code(400);
            echo json_encode(["message" => "Parâmetros insuficientes."]);
            return;
        }

        try {
            http_response_code(200);
            echo json_encode($this->model->getEstoqueReport($data));
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["message" => $e->getMessage()]);
        }
    }
}