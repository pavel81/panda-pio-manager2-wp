<?php
namespace Panda\PIOManager\Common;

if (!defined('ABSPATH')) exit;

class HttpClient
{
    protected static array $args = [
        'timeout'   => 20,
        'sslverify' => true,
        'headers'   => [],
    ];

    public static function configure(array $args): void
    {
        self::$args = array_merge(self::$args, $args);
    }

    public static function get(string $endpoint, array $args = []): array
    {
        $url = self::build_url($endpoint);
        $res = wp_remote_get($url, array_merge(self::$args, $args));
        return self::normalize($res);
    }

    public static function post(string $endpoint, array $body = [], array $args = []): array
    {
        $url = self::build_url($endpoint);
        $args = array_merge(self::$args, $args, [
            'headers' => array_merge(['Content-Type' => 'application/json'], self::$args['headers'] ?? []),
            'body'    => wp_json_encode($body),
        ]);
        $res = wp_remote_post($url, $args);
        return self::normalize($res);
    }

    protected static function build_url(string $endpoint): string
    {
        $base = defined('PANDA_PIO_BASE_URL') ? (string)PANDA_PIO_BASE_URL : '';
        return rtrim($base, '/') . '/' . ltrim($endpoint, '/');
    }

    protected static function normalize($res): array
    {
        if (is_wp_error($res)) {
            return [
                'ok'    => false,
                'error' => $res->get_error_message(),
                'code'  => $res->get_error_code(),
                'body'  => null,
            ];
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $body = (string) wp_remote_retrieve_body($res);
        return [
            'ok'   => $code >= 200 && $code < 300,
            'code' => $code,
            'body' => $body,
        ];
    }
}