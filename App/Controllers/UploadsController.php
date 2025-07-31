<?php

namespace App\Controllers;

class UploadsController {
  public function uploadFile($fileOrBase64, $prefix) {
    $uploadDir = __DIR__ . '/../uploads/';

    if (!is_dir($uploadDir)) {
      if (!mkdir($uploadDir, 0777, true)) {
        throw new \Exception('Falha ao criar diretório de upload.');
      }
    }

    if (is_array($fileOrBase64) && isset($fileOrBase64['tmp_name'])) {
      if (!isset($fileOrBase64['error']) || $fileOrBase64['error'] !== UPLOAD_ERR_OK) {
        throw new \Exception('Erro no upload do arquivo.');
      }

      $finfo = new \finfo(FILEINFO_MIME_TYPE);
      $mime = $finfo->file($fileOrBase64['tmp_name']);
      $ext = self::mimeToExtension($mime);

      if (!$ext) {
        throw new \Exception('Tipo de arquivo não suportado.');
      }

      $uniqueId = uniqid();
      $filename = $prefix . '_' . $uniqueId . '.' . $ext;
      $destPath = $uploadDir . $filename;

      if (!move_uploaded_file($fileOrBase64['tmp_name'], $destPath)) {
        throw new \Exception('Falha ao mover o arquivo.');
      }

      return $filename;
    }

    if (is_string($fileOrBase64)) {
      $base64Data = null;
      $mime = null;

      if (strpos($fileOrBase64, 'base64,') !== false) {
        $parts = explode('base64,', $fileOrBase64);
        $base64Data = $parts[1] ?? null;

        if (preg_match('/data:(.*);/', $parts[0], $matches)) {
          $mime = strtolower(trim($matches[1]));
        }
      }
      else if (preg_match('/^[a-zA-Z0-9+\/=]+$/', trim($fileOrBase64))) {
        $base64Data = $fileOrBase64;
      }

      if ($base64Data) {
        $fileData = base64_decode($base64Data);
        if ($fileData === false) {
          throw new \Exception('Falha ao decodificar base64.');
        }

        if (!$mime) {
          $mime = self::detectMimeFromContent($fileData);
        }

        $ext = self::mimeToExtension($mime);
        if (!$ext) {
          throw new \Exception('Tipo de arquivo não suportado: ' . $mime);
        }

        $uniqueId = uniqid();
        $filename = $prefix . '_' . $uniqueId . '.' . $ext;
        $destPath = $uploadDir . $filename;

        if (file_put_contents($destPath, $fileData) === false) {
          throw new \Exception('Falha ao salvar o arquivo.');
        }

        return $filename;
      }
    }

    throw new \Exception('Formato de arquivo não suportado ou inválido.');
  }

  private static function detectMimeFromContent($content) {
    $signatures = [
      "\xFF\xD8\xFF" => 'image/jpeg',
      "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A" => 'image/png',
      "GIF8" => 'image/gif',
      "%PDF" => 'application/pdf',
      "PK\x03\x04" => 'application/zip',
      "RIFF" => 'image/webp',
    ];

    foreach ($signatures as $signature => $mime) {
      if (strncmp($content, $signature, strlen($signature)) === 0) {
        return $mime;
      }
    }

    return 'image/jpeg';
  }

  private static function mimeToExtension($mime) {
    $map = [
      'image/jpeg' => 'jpg',
      'image/png' => 'png',
      'image/gif' => 'gif',
      'image/bmp' => 'bmp',
      'image/svg+xml' => 'svg',
      'image/tiff' => 'tiff',
      'image/vnd.microsoft.icon' => 'ico',
      'image/x-icon' => 'ico',
      'image/x-ms-bmp' => 'bmp',
      'image/x-icon' => 'ico',
      'image/x-png' => 'png',
      'application/pdf' => 'pdf',
      'application/zip' => 'zip',
      'application/msword' => 'doc',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
      'application/vnd.ms-excel' => 'xls',
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
      'text/plain' => 'txt',
      'image/webp' => 'webp',
    ];
    return $map[$mime] ?? false;
  }

  public function deleteFile($filename) {
    $uploadDir = __DIR__ . '/../uploads/';
    $filePath = $uploadDir . $filename;

    if (!file_exists($filePath)) {
      throw new \Exception('Arquivo não encontrado.');
    }

    if (!unlink($filePath)) {
      throw new \Exception('Não foi possível deletar o arquivo.');
    }

    return true;
  }

  public function getFile($filename)
  {
    $path = dirname(__DIR__) . '/uploads/' . $filename;

    if (file_exists($path)) {
      $mimeType = mime_content_type($path);
      header('Content-Type: ' . $mimeType);
      readfile($path);
      exit;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Arquivo não encontrado']);
    exit;
  }
}
