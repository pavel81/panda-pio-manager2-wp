<?php
namespace Panda\PIOManager;

use WP_Error;

if (!defined('ABSPATH')) exit;

class SSL
{
    public static function check_endpoint_ssl(string $url): array
    {
        $url = trim($url);
        if ($url === '') return ['ok' => false, 'message' => 'URL není vyplněno.'];

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https') {
            return ['ok' => false, 'message' => 'URL nepoužívá HTTPS.'];
        }

        $resp = wp_remote_head($url, ['timeout'=>15, 'sslverify'=>true]);
        if (is_wp_error($resp)) return ['ok'=>false,'message'=>$resp->get_error_message()];

        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code >= 200 && $code < 400) return ['ok'=>true,'message'=>'Certifikát OK (HTTPS ověřeno).'];

        return ['ok'=>false,'message'=>'Neočekávaný HTTP kód: '.$code];
    }
}