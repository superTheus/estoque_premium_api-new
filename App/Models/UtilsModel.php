<?php

namespace App\Models;

use DateTime;
use Firebase\JWT\JWT;

class UtilsModel
{
    public static function generateToken($data)
    {
        $payload = [
            'iat' => time(),
            'exp' => time() + 1800,
            'data' => $data
        ];

        return JWT::encode($payload, $_ENV['SECRET_KEY'], 'HS256');
    }

    public static function decodeToken($token)
    {
        return JWT::decode($token, new \Firebase\JWT\Key($_ENV['SECRET_KEY'], 'HS256'));
    }

    public static function formatNumber(float $value)
    {
        return number_format($value, 2, ',', '.');
    }

    public static function formatDate(DateTime $date)
    {
        return $date->format('d/m/Y');
    }

    public static function formatPhone(string $phone)
    {
        $phone = preg_replace('/\D/', '', $phone);
        if (strlen($phone) === 11) {
            return preg_replace('/(\d{2})(\d{5})(\d{4})/', '($1) $2-$3', $phone);
        } elseif (strlen($phone) === 10) {
            return preg_replace('/(\d{2})(\d{4})(\d{4})/', '($1) $2-$3', $phone);
        }
        return $phone;
    }

    public static function formatCurrency(float $value)
    {
        return 'R$ ' . number_format($value, 2, ',', '.');
    }

    public static function formatDocument(string $document)
    {
        $document = preg_replace('/\D/', '', $document);
        if (strlen($document) === 11) {
            return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $document);
        } elseif (strlen($document) === 14) {
            return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $document);
        }
        return $document;
    }

    public static function capitalizeString(string $string)
    {
        return ucwords(strtolower($string));
    }

    public static function sanitizeString(string $string)
    {
        return filter_var($string, FILTER_SANITIZE_STRING);
    }

    public static function writeCurrentDate()
    {
        $formatter = new \IntlDateFormatter(
            'pt_BR',
            \IntlDateFormatter::LONG,
            \IntlDateFormatter::NONE,
            'America/Manaus',
            \IntlDateFormatter::GREGORIAN,
            "d 'de' MMMM 'de' yyyy"
        );
        return $formatter->format(new \DateTime());
    }

    /**
     * Consulta dados de CNPJ na API da ReceitaWS
     * 
     * @param string $cnpj CNPJ a ser consultado (com ou sem formatação)
     * @return array Dados da empresa ou erro
     */
    public static function consultarCNPJ($cnpj)
    {
        try {
            // Remover formatação do CNPJ (pontos, barras e hífens)
            $cnpjLimpo = preg_replace('/\D/', '', $cnpj);

            // Validar se tem 14 dígitos
            if (strlen($cnpjLimpo) !== 14) {
                throw new \Exception("CNPJ inválido. Deve conter 14 dígitos.");
            }

            // Fazer requisição GET para a API
            $url = "https://receitaws.com.br/v1/cnpj/{$cnpjLimpo}";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
                'User-Agent: Mozilla/5.0'
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                throw new \Exception("Erro na requisição: {$error}");
            }

            if ($httpCode !== 200) {
                throw new \Exception("Erro ao consultar CNPJ. Código HTTP: {$httpCode}");
            }

            $data = json_decode($response, true);

            // Verificar se houve erro na resposta da API
            if (isset($data['status']) && $data['status'] === 'ERROR') {
                throw new \Exception($data['message'] ?? 'Erro ao consultar CNPJ');
            }

            return [
                'success' => true,
                'data' => $data
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}
