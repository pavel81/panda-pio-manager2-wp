<?php
namespace Panda\PIOManager\Admin;

use Panda\PIOManager\Common\HttpClient;

if (!defined('ABSPATH')) exit;

class Ajax
{
    public function __construct()
    {
        add_action('wp_ajax_panda_pio_test_conn', [$this, 'testConnection']);
    }

    public function testConnection(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Nedostatečná oprávnění.'], 403);
        }

        check_ajax_referer('panda_pio_test_conn', 'nonce');

        $candidates = [
            '/wp-json/pio/v1/health',
            '/wp-json/pio/v1/ping',
            '/wp-json/wp/v2'
        ];

        $res = null;
        foreach ($candidates as $ep) {
            $res = HttpClient::get($ep);
            if (!empty($res['ok'])) break;
        }

        if (empty($res) || empty($res['ok'])) {
            $msg = !empty($res['error']) ? $res['error'] : (isset($res['code']) ? 'HTTP '.$res['code'] : 'Neznámá chyba');
            wp_send_json_error([
                'message' => $msg,
                'code'    => $res['code'] ?? null,
                'body'    => isset($res['body']) ? mb_substr((string)$res['body'], 0, 500) : null,
            ], 200);
        }

        wp_send_json_success([
            'message' => 'Spojení OK',
            'code'    => (int)$res['code'],
            'body'    => mb_substr((string)$res['body'], 0, 500),
        ], 200);
    }
}