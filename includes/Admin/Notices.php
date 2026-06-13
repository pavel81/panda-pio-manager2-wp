<?php
namespace Panda\PIOManager\Admin;

if (!defined('ABSPATH')) exit;

class Notices
{
    private const OPT_API_URL            = 'pio_api_url';
    private const OPT_SANDBOX            = 'pio_sandbox_mode';
    private const OPT_DISMISS_API_NOTICE = 'pio_dismiss_apiurl_notice';
    private const NONCE_ACTION_DISMISS   = 'pio_dismiss_notice_apiurl';

    /** Legacy názvy option, které jsme dříve používali (fallback/migrace). */
    private const LEGACY_API_OPTIONS = [
        'panda_pio_api_url',
        'panda_pio_manager_api_url',
        'pio_manager_api_url',
    ];

    public function __construct()
    {
        add_action('admin_notices', [$this, 'maybe_show_notices']);
        add_action('wp_ajax_pio_dismiss_apiurl_notice', [$this, 'ajax_dismiss_api_notice']);
    }

    /**
     * Zda je aktuální admin obrazovka relevantní pro zobrazování hlášek.
     */
    private function is_relevant_screen(): bool
    {
        // Umožni globálně vypnout přes filtr (např. v child theme / MU pluginu)
        if (!apply_filters('panda_pio_show_admin_notices', true)) {
            return false;
        }

        // Nouzový vypínač přes konstantu (např. v wp-config.php)
        if (defined('PIO_MANAGER_SUPPRESS_NOTICES') && PIO_MANAGER_SUPPRESS_NOTICES) {
            return false;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) return false;

        // Zobrazuj jen na přehledu pluginů a v našem nastavení
        $allowed = [
            'plugins',
            'toplevel_page_panda-pio-manager',
            'settings_page_panda-pio-manager',
        ];
        return in_array($screen->id, $allowed, true);
    }

    /**
     * Načtení API URL s fallbackem na legacy klíče, včetně okamžité migrace.
     */
    private function read_api_url(): string
    {
        $api = trim((string) get_option(self::OPT_API_URL, ''));
        if ($api !== '') return $api;

        foreach (self::LEGACY_API_OPTIONS as $legacy) {
            $val = trim((string) get_option($legacy, ''));
            if ($val !== '') {
                // Migruj hodnotu do nového klíče a dál používej už jen nový
                update_option(self::OPT_API_URL, $val, false);
                return $val;
            }
        }
        return '';
    }

    /**
     * Rozhodnutí, zda a které hlášky vykreslit.
     */
    public function maybe_show_notices(): void
    {
        if (!current_user_can('manage_options')) return;
        if (!$this->is_relevant_screen()) return;

        $api = $this->read_api_url();
        $dismissed = (bool) get_option(self::OPT_DISMISS_API_NOTICE, false);

        // Červená hláška – chybí API URL
        if ($api === '' && !$dismissed) {
            $this->render_api_missing_notice();
        }

        // Oranžová hláška – na HTTP mimo sandbox
        if (!is_ssl()) {
            $sandbox = (bool) get_option(self::OPT_SANDBOX, false);
            if (!$sandbox) {
                $this->render_http_warning_notice();
            }
        }
    }

    /**
     * AJAX: trvalé skrytí chybové hlášky o chybějícím API URL.
     */
    public function ajax_dismiss_api_notice(): void
    {
        check_ajax_referer(self::NONCE_ACTION_DISMISS, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }

        update_option(self::OPT_DISMISS_API_NOTICE, true, false);
        wp_send_json_success(['dismissed' => true]);
    }

    private function render_api_missing_notice(): void
    {
        $nonce = wp_create_nonce(self::NONCE_ACTION_DISMISS);
        $settings_url = admin_url('admin.php?page=panda-pio-manager');
        ?>
        <div class="notice notice-error is-dismissible pio-api-missing">
            <p>
                <strong>Panda PIO Manager:</strong>
                Není nastavena adresa <code>PIO API URL</code> – plugin nebude funkční.
            </p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url($settings_url); ?>">Otevřít nastavení</a>
                <button type="button" class="button" id="pio-dismiss-apiurl">Nezobrazovat znovu</button>
            </p>
        </div>
        <script>
        (function(){
            var btn = document.getElementById('pio-dismiss-apiurl');
            if(!btn) return;
            btn.addEventListener('click', function(){
                var fd = new FormData();
                fd.append('action','pio_dismiss_apiurl_notice');
                fd.append('nonce','<?php echo esc_js($nonce); ?>');
                fetch(ajaxurl, { method:'POST', body: fd, credentials:'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(){
                        var el = document.querySelector('.notice.pio-api-missing');
                        if(el) el.remove();
                    });
            });
        })();
        </script>
        <?php
    }

    private function render_http_warning_notice(): void
    {
        $settings_url = admin_url('admin.php?page=panda-pio-manager');
        ?>
        <div class="notice notice-warning">
            <p>
                <strong>Panda PIO Manager:</strong>
                Web běží přes <code>HTTP</code>. Doporučujeme aktivovat <strong>SSL certifikát</strong>
                nebo v nastavení dočasně povolit <em>Sandbox (HTTP)</em>.
            </p>
            <p><a class="button" href="<?php echo esc_url($settings_url); ?>">Otevřít nastavení</a></p>
        </div>
        <?php
    }
}