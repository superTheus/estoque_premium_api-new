<?php

namespace App\Controllers;

use App\Models\ApiModel;

class FiscalController extends ApiModel
{ 
  public function __construct()
  {
    parent::__construct();
  }

  public function createCompany($data = [])
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
}