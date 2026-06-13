<?php
namespace Panda\PIOManager;

use WP_Error;

if (!defined('ABSPATH')) exit;

class Client
{
    private string $endpoint;

    public function __construct()
    {
        $this->endpoint = (string) get_option('panda_pio_api_url', '');
    }

    public function send_request(array $data = []): array|WP_Error
    {
        if ($this->endpoint === '') {
            return new WP_Error('missing_endpoint', 'Endpoint není nastaven.');
        }

        $scheme = strtolower((string) parse_url($this->endpoint, PHP_URL_SCHEME));
        $is_https = ($scheme === 'https');
        $sandbox_http_ok = (!$is_https && Sandbox::allow_http_for($this->endpoint));

        if (!$is_https && !$sandbox_http_ok) {
            return new WP_Error('insecure_endpoint', 'Endpoint musí používat HTTPS (SSL). V HTTP režimu jen v Sandboxu (localhost/dev).');
        }

        $sslverify = $is_https ? (bool) apply_filters('panda_pio/sslverify', true) : false;

        if ($sandbox_http_ok && class_exists(Logger::class)) {
            Logger::log('Sandbox HTTP request to ' . $this->endpoint, 'WARN');
        }

        $headers = ['Content-Type' => 'application/json; charset=utf-8'];
        $headers = Sandbox::add_sandbox_headers($headers); // X-PIO-Sandbox(+ -Test)

        $args = [
            'timeout'   => 20,
            'headers'   => $headers,
            'body'      => wp_json_encode($data),
            'sslverify' => $sslverify,
        ];

        $response = wp_remote_post($this->endpoint, $args);

        if (is_wp_error($response)) {
            if (class_exists(Logger::class)) Logger::log('Connection error: ' . $response->get_error_message(), 'ERROR');
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = (string) wp_remote_retrieve_body($response);
        if (class_exists(Logger::class)) Logger::log("Response ($code): " . mb_substr($raw, 0, 500), 'INFO');

        $decoded = json_decode($raw, true);
        if ($code < 200 || $code >= 300 || !is_array($decoded)) {
            return new WP_Error('bad_http_response', 'Neplatná odpověď API', [
                'code' => $code,
                'raw'  => mb_substr($raw, 0, 300),
            ]);
        }

        return $decoded;
    }
}