<?php
namespace Panda\PIOManager;

if (!defined('ABSPATH')) exit;

class Security
{
    public static function compute_signature(string $secret, string $method, string $path, string $timestamp, string $nonce, string $body = ''): string
    {
        $payload = strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', (string)$body);
        $raw = hash_hmac('sha256', $payload, $secret, true);
        return base64_encode($raw);
    }

    public static function verify_hmac_request(): bool
    {
        $key   = (string) get_option('panda_pio_app_key', '');
        $secret= (string) get_option('panda_pio_app_secret', '');

        $hKey  = (string) ($_SERVER['HTTP_X_PIO_KEY'] ?? '');
        $ts    = (string) ($_SERVER['HTTP_X_PIO_TIMESTAMP'] ?? '');
        $nonce = (string) ($_SERVER['HTTP_X_PIO_NONCE'] ?? '');
        $sig   = (string) ($_SERVER['HTTP_X_PIO_SIGNATURE'] ?? '');

        if ($key==='' || $secret==='') return false;
        if ($hKey==='' || $ts==='' || $nonce==='' || $sig==='') return false;
        if (!hash_equals($key, $hKey)) return false;

        // Time window ±10 min
        $now = time();
        if (abs($now - (int)$ts) > 600) return false;

        // Nonce replay guard – transient na 10 min
        $nonce_key = 'pio_nonce_' . md5($nonce);
        if (get_transient($nonce_key)) return false;
        set_transient($nonce_key, 1, 600);

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $body   = file_get_contents('php://input') ?: '';

        $expected = self::compute_signature($secret, $method, $path, $ts, $nonce, $body);
        return hash_equals($expected, $sig);
    }
}