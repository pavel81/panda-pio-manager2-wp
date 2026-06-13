<?php
namespace Panda\PIOManager;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

class Rest
{
    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route('pio/v1','/ping',[
            'methods'=>'GET',
            'permission_callback'=>'__return_true',
            'callback'=>[$this,'ping'],
        ]);

        register_rest_route('pio/v1','/check',[
            'methods'=>'POST',
            'permission_callback'=>[$this,'can_call_check'],
            'callback'=>[$this,'check_connection'],
            'args'=>[
                'payload'=>['required'=>false,'type'=>'object']
            ],
        ]);

        register_rest_route('pio/v1','/cron-run',[
            'methods'=>['GET','POST'],
            'permission_callback'=>[$this,'can_run_cron'],
            'callback'=>function(){
                (new Cron())->run();
                return new WP_REST_Response(['ok'=>true,'message'=>'Cron job vykonán','time'=>current_time('mysql')],200);
            },
        ]);

        // Sandbox: simulační test produkčních funkcí
        register_rest_route('pio/v1','/sandbox/test-prod-feature',[
            'methods'=>'POST',
            'permission_callback'=>function(){
                return current_user_can('manage_options') && Sandbox::is_test_enabled();
            },
            'callback'=>[$this,'sandbox_test_prod'],
        ]);
    }

    public function can_call_check(): bool
    {
        if (current_user_can('manage_options')) return true;
        return Security::verify_hmac_request(); // HMAC + Nonce
    }

    public function can_run_cron(): bool
    {
        if (current_user_can('manage_options')) return true;
        if (Security::verify_hmac_request()) return true;
        $token = isset($_GET['token']) ? (string) $_GET['token'] : '';
        return $token !== '' && hash_equals($token, (string) get_option('panda_pio_cron_token',''));
    }

    public function ping(WP_REST_Request $req): WP_REST_Response
    {
        return new WP_REST_Response([
            'ok'=>true,'plugin'=>'panda-pio-manager','version'=>PANDA_PIO_VERSION,'time'=>current_time('mysql'),
            'message'=>'Panda PIO Manager je aktivní.'
        ],200);
    }

    public function check_connection(WP_REST_Request $req): WP_REST_Response
    {
        $endpoint = (string) get_option('panda_pio_api_url','');
        if ($endpoint === '') {
            return new WP_REST_Response(['ok'=>false,'message'=>'PIO API URL není nastaveno.'],400);
        }

        $scheme = strtolower((string) parse_url($endpoint, PHP_URL_SCHEME));
        $is_https = ($scheme === 'https');

        if (!$is_https && !Sandbox::allow_http_for($endpoint)) {
            return new WP_REST_Response(['ok'=>false,'message'=>'PIO API URL musí být HTTPS (mimo Sandbox).'],400);
        }

        $payload = $req->get_param('payload');
        if (!is_array($payload)) $payload = ['ping'=>'true'];

        $client = new Client();
        $resp   = $client->send_request($payload);

        if (is_wp_error($resp)) {
            return new WP_REST_Response(['ok'=>false,'message'=>$resp->get_error_message(),'data'=>$resp->get_error_data()],502);
        }

        return new WP_REST_Response(['ok'=>true,'message'=>'Připojení OK','data'=>$resp],200);
    }

    public function sandbox_test_prod(WP_REST_Request $req): WP_REST_Response
    {
        $sim = [
            'sandbox'  => true,
            'note'     => 'Simulační výstup: výsledky jsou pouze orientační – v produkci platí přísnější pravidla.',
            'checks'   => [
                'https_required'      => ['status'=>'SIMULATED_OK','detail'=>'HTTPS v produkci POVINNÉ – sandbox může povolit HTTP pro dev hosty.'],
                'ssl_certificate'     => ['status'=>'SIMULATED_OK','detail'=>'Certifikát neověřen – sandbox test nevyžaduje platný cert.'],
                'hmac_signature_flow' => ['status'=>'SIMULATED_OK','detail'=>'HMAC tok ověřen v testu (nonce + timestamp).'],
                'cron_external_call'  => ['status'=>'SIMULATED_OK','detail'=>'Cron callback simulován (token/HMAC).'],
            ],
            'timestamp'=> time(),
        ];

        $response = new WP_REST_Response(['ok'=>true,'simulation'=>$sim],200);
        $response->header('X-PIO-Sandbox','1');
        $response->header('X-PIO-Sandbox-Test','1');
        return $response;
    }
}