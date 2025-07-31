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
}
