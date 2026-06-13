<?php
namespace Panda\PIOManager\Admin;

if (!defined('ABSPATH')) exit;

class Dashboard
{
    public function __construct()
    {
        add_action('wp_dashboard_setup', [$this, 'add_widget']);
    }

    public function add_widget(): void
    {
        wp_add_dashboard_widget(
            'panda_pio_status_widget',
            'Panda PIO – Stav připojení',
            [$this, 'render_widget']
        );
    }

    public function render_widget(): void
    {
        $opts     = get_option('panda_pio_settings', []);
        $sandbox  = !empty($opts['sandbox_mode']);
        $api      = isset($opts['api_url']) ? trim((string)$opts['api_url']) : '';
        $has_url  = $api !== '';

        $display = $has_url ? esc_html(mb_strimwidth($api, 0, 64, '…')) : '— nenastaveno —';

        if (!$has_url) {
            $badge = '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#fdeaea;border:1px solid #d63638;color:#a60000;font-weight:600">🔴 Bez API URL</span>';
        } elseif ($sandbox) {
            $badge = '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#fff7e6;border:1px solid #ff9800;color:#8a5a00;font-weight:600">🟠 Sandbox (HTTP)</span>';
        } else {
            $badge = '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#e7f7ee;border:1px solid #46b450;color:#1e7e34;font-weight:600">🟢 Produkce (HTTPS)</span>';
        }

        $nonce        = wp_create_nonce('panda_pio_test_conn');
        $ajax         = admin_url('admin-ajax.php');
        $settings_url = admin_url('admin.php?page=panda-pio-manager');

        echo '<div class="panda-pio-widget">';
        echo '<p style="margin:0 0 8px">'.$badge.'</p>';
        echo '<p style="margin:0 0 12px"><strong>API URL:</strong> <code>'.$display.'</code></p>';

        echo '<p style="margin:0 0 8px">';
        echo '<a href="'.esc_url($settings_url).'" class="button">Otevřít nastavení</a> ';
        if (current_user_can('manage_options')) {
            echo '<button id="panda-pio-dash-test" class="button button-primary">Otestovat připojení</button>';
        }
        echo '</p>';

        echo '<pre id="panda-pio-dash-result" style="display:none;margin-top:10px;max-height:220px;overflow:auto;padding:10px;border-radius:4px;background:#f6f7f7;border:1px solid #dcdcde;white-space:pre-wrap"></pre>';
        echo '</div>';
        ?>
        <script>
        (function(){
          var btn = document.getElementById('panda-pio-dash-test');
          if(!btn) return;
          var out = document.getElementById('panda-pio-dash-result');
          btn.addEventListener('click', function(e){
            e.preventDefault();
            out.style.display = 'block';
            out.textContent = 'Testuji připojení...';
            var data = new FormData();
            data.append('action', 'panda_pio_test_conn');
            data.append('nonce', '<?php echo esc_js($nonce); ?>');
            fetch('<?php echo esc_url($ajax); ?>', {
              method: 'POST',
              credentials: 'same-origin',
              body: data
            })
            .then(function(r){ return r.json(); })
            .then(function(resp){
              if(resp && resp.success){
                var code = resp.data && resp.data.code ? ' (HTTP '+resp.data.code+')' : '';
                out.style.background = '#e7f7ee';
                out.style.borderColor = '#8bd2ad';
                out.textContent = '✅ OK: ' + (resp.data.message || 'Připojeno') + code;
              } else {
                var msg  = (resp && resp.data && resp.data.message) ? resp.data.message : 'Neznámá chyba';
                var http = (resp && resp.data && resp.data.code) ? ' (HTTP '+resp.data.code+')' : '';
                out.style.background = '#fdeaea';
                out.style.borderColor = '#e19a9a';
                out.textContent = '❌ Chyba: ' + msg + http;
              }
            })
            .catch(function(){
              out.style.background = '#fdeaea';
              out.style.borderColor = '#e19a9a';
              out.textContent = '❌ Chyba požadavku (AJAX/XHR).';
            });
          });
        })();
        </script>
        <?php
    }
}