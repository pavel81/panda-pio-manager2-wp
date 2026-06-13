<?php
/**
 * Panda PIO Manager – Admin (Cron + Log + SSL + Secure AJAX + Sandbox HTTP + Sandbox Testy + Log protection notice)
 */
namespace Panda\PIOManager;

if (!defined('ABSPATH')) exit;

class Admin
{
    public function __construct()
    {
        add_action('admin_menu',               [$this, 'menu']);
        add_action('admin_init',               [$this, 'register_settings']);
        add_action('admin_enqueue_scripts',    [$this, 'enqueue_assets']);

        // Notices
        add_action('admin_notices',            [$this, 'notice_insecure_endpoint']);
        add_action('admin_notices',            [$this, 'notice_sandbox_enabled']);
        add_action('admin_notices',            [$this, 'notice_logs_protection']); // 🆕 kontrola ochrany logů

        // AJAX
        add_action('wp_ajax_panda_pio_test',             [$this, 'ajax_test']);
        add_action('wp_ajax_panda_pio_secure_check',     [$this, 'ajax_secure_check']);
        add_action('wp_ajax_panda_pio_log_tail',         [$this, 'ajax_log_tail']);
        add_action('wp_ajax_panda_pio_log_clear',        [$this, 'ajax_log_clear']);
        add_action('wp_ajax_panda_pio_check_ssl',        [$this, 'ajax_check_ssl']);
        add_action('wp_ajax_panda_pio_toggle_sandbox',   [$this, 'ajax_toggle_sandbox']);
        add_action('wp_ajax_panda_pio_sandbox_prodtest', [$this, 'ajax_sandbox_prodtest']);

        // Cron reschedule
        add_action('update_option_panda_pio_cron_enabled',       [$this, 'maybe_reschedule'], 10, 2);
        add_action('update_option_panda_pio_cron_interval',      [$this, 'maybe_reschedule'], 10, 2);
        add_action('update_option_panda_pio_cron_every_minutes', [$this, 'maybe_reschedule'], 10, 2);
    }

    public function register_settings(): void
    {
        // Endpoint (produkce vyžaduje HTTPS – uložení HTTP jen pokud Sandbox povoluje dev hosta)
        register_setting('panda_pio_group', 'panda_pio_api_url', [
            'type' => 'string',
            'sanitize_callback' => function ($url) {
                $url = esc_url_raw($url);
                if (!$url) return '';
                $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
                if ($scheme !== 'https' && !Sandbox::allow_http_for($url)) {
                    add_settings_error('panda_pio_api_url','panda_pio_https_required',
                        'PIO API URL musí používat HTTPS (SSL). Pro HTTP použij sandbox a povolené dev hosty.', 'error');
                    return '';
                }
                return $url;
            }
        ]);

        register_setting('panda_pio_group', 'panda_pio_app_key', [
            'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'
        ]);
        register_setting('panda_pio_group', 'panda_pio_app_secret', [
            'type' => 'string', 'sanitize_callback' => [$this, 'sanitize_secret']
        ]);

        // Cron
        register_setting('panda_pio_group', 'panda_pio_cron_enabled', [
            'type' => 'string', 'sanitize_callback' => [$this, 'bool_to_string']
        ]);
        register_setting('panda_pio_group', 'panda_pio_cron_interval', [
            'type' => 'string', 'sanitize_callback' => [$this, 'sanitize_interval']
        ]);
        register_setting('panda_pio_group', 'panda_pio_cron_every_minutes', [
            'type' => 'integer', 'sanitize_callback' => 'absint'
        ]);
        register_setting('panda_pio_group', 'panda_pio_cron_token', [
            'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'
        ]);

        // Sandbox
        register_setting('panda_pio_group', 'panda_pio_sandbox_hours', [
            'type'=>'integer', 'sanitize_callback'=>'absint', 'default'=>6
        ]);
        register_setting('panda_pio_group', 'panda_pio_sandbox_tests', [
            'type'=>'string', 'sanitize_callback'=>[$this,'bool_to_string'], 'default'=>'0'
        ]);
    }

    public function sanitize_secret($v){ return trim((string)$v); }
    public function bool_to_string($v){ return $v ? '1' : '0'; }
    public function sanitize_interval($v){
        $allowed = ['hourly','twicedaily','daily','custom'];
        return in_array($v,$allowed,true) ? $v : 'daily';
    }

    public function menu(): void
    {
        add_menu_page('Panda PIO Manager','Panda PIO','manage_options','panda-pio-manager',[$this,'dashboard'],'dashicons-rest-api');
    }

    public function enqueue_assets(string $hook): void
    {
        if (strpos($hook, 'panda-pio-manager') === false) return;

        $nonce        = wp_create_nonce('panda_pio_nonce');
        $nonce_secure = wp_create_nonce('panda_pio_secure_nonce');

        wp_register_script('panda-pio-dashboard', false, ['jquery'], '1.4.0', true);
        wp_enqueue_script('panda-pio-dashboard');

        wp_add_inline_script('panda-pio-dashboard', "
            jQuery(function($){
                var nonce = '".esc_js($nonce)."';

                // Základní test
                $('#panda_pio_test').on('click', function(e){
                    e.preventDefault();
                    var \$out = $('#panda_pio_result');
                    \$out.text('⏳ Testuji připojení...');
                    $.post(ajaxurl,{ action:'panda_pio_test', nonce:nonce }, function(res){
                        if(res&&res.success) \$out.html('✅ '+(res.data.msg||'Připojení OK'));
                        else \$out.html('❌ '+(res.data&&res.data.msg?res.data.msg:'Chyba'));
                    }).fail(function(){ \$out.text('❌ Chyba spojení'); });
                });

                // Přepínání vlastního intervalu
                var $sel = $('#panda_pio_cron_interval');
                function toggleCustom(){ if($sel.val()==='custom') $('#panda_pio_custom_minutes_wrap').show(); else $('#panda_pio_custom_minutes_wrap').hide(); }
                toggleCustom(); $sel.on('change', toggleCustom);

                // Ověřit SSL
                $('#panda_pio_check_ssl').on('click', function(e){
                    e.preventDefault();
                    var \$out = $('#panda_pio_ssl_result');
                    \$out.text('⏳ Kontroluji SSL…');
                    $.post(ajaxurl,{ action:'panda_pio_check_ssl', nonce:nonce }, function(res){
                        if(res&&res.success) \$out.html('✅ '+(res.data.msg||'Certifikát ověřen'));
                        else \$out.html('❌ '+(res.data&&res.data.msg?res.data.msg:'SSL chyba'));
                    }).fail(function(){ \$out.text('❌ Chyba spojení'); });
                });

                // Log – načíst
                function loadLog(){
                    var $box = $('#panda_pio_log_box');
                    if(!$box.length) return;
                    $box.text('⏳ Načítám log...');
                    $.post(ajaxurl,{ action:'panda_pio_log_tail', nonce:nonce, lines:100 }, function(res){
                        if(res&&res.success){
                            var rows = res.data && res.data.lines ? res.data.lines : [];
                            $box.text(rows.length? rows.join('\\n') : 'Log je prázdný.');
                        } else $box.text('Nepodařilo se načíst log.');
                    }).fail(function(){ $box.text('Chyba při načítání logu.'); });
                }
                loadLog();

                // Log – smazat
                $('#panda_pio_log_clear').on('click', function(e){
                    e.preventDefault();
                    if(!confirm('Opravdu vymazat log?')) return;
                    var $btn = $(this);
                    $btn.prop('disabled', true).text('Mažu...');
                    $.post(ajaxurl,{ action:'panda_pio_log_clear', nonce:nonce }, function(res){
                        if(res&&res.success){ alert('Log vymazán.'); loadLog(); }
                        else { alert('Nepodařilo se vymazat log.'); }
                    }).fail(function(){ alert('Chyba při mazání logu.'); })
                      .always(function(){ $btn.prop('disabled', false).text('Vymazat log'); });
                });

                // Sandbox – zap/vyp
                $('#panda_pio_toggle_sandbox').on('click', function(e){
                    e.preventDefault();
                    var enable = $(this).data('enable') === 1 ? 1 : 0;
                    var hours  = parseInt($('#panda_pio_sandbox_hours').val(),10) || 6;
                    var tests  = $('#panda_pio_sandbox_tests').is(':checked') ? 1 : 0;
                    var \$status = $('#panda_pio_sandbox_status');
                    \$status.text('⏳ Provádím…');
                    $.post(ajaxurl,{
                        action:'panda_pio_toggle_sandbox',
                        nonce: nonce,
                        enable: enable,
                        hours: hours,
                        tests: tests
                    }, function(res){
                        if(res && res.success){
                            \$status.html('✅ '+(res.data.msg||'Hotovo'));
                            location.reload();
                        } else {
                            \$status.html('❌ '+(res && res.data && res.data.msg ? res.data.msg : 'Chyba'));
                        }
                    }).fail(function(){ \$status.text('❌ Chyba spojení'); });
                });

                // Sandbox – test produkčních funkcí (simulace)
                $('#panda_pio_run_prodtest').on('click', function(e){
                    e.preventDefault();
                    var \$out = $('#panda_pio_prodtest_result');
                    \$out.text('⏳ Spouštím simulační test…');
                    $.post(ajaxurl,{ action:'panda_pio_sandbox_prodtest', nonce:nonce }, function(res){
                        if(res && res.success){
                            var j = res.data && res.data.simulation ? res.data.simulation : res.data;
                            \$out.text(JSON.stringify(j, null, 2));
                        } else {
                            \$out.text('❌ ' + (res && res.data && res.data.msg ? res.data.msg : 'Chyba'));
                        }
                    }).fail(function(){ \$out.text('❌ Chyba spojení'); });
                });
            });
        ");

        // Secure HMAC check – (JS může být prázdný, HMAC řešíme serverem)
        wp_enqueue_script(
            'panda-pio-secure-check',
            plugins_url('assets/js/pio-secure-check.js', dirname(__FILE__)),
            ['jquery'],
            '1.4.0',
            true
        );
        wp_localize_script('panda-pio-secure-check','panda_pio_secure_vars',[
            'ajax_url'=>admin_url('admin-ajax.php'),
            'nonce'   =>$nonce_secure
        ]);
    }

    public function notice_insecure_endpoint(): void
    {
        if (!current_user_can('manage_options')) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || strpos((string)$screen->id, 'panda-pio-manager') === false) return;

        $url = (string) get_option('panda_pio_api_url','');
        if ($url === '') {
            echo '<div class="notice notice-warning"><p><strong>Panda PIO:</strong> Zadejte PIO API URL. Doporučeno <code>https://…</code>.</p></div>';
            return;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https' && !Sandbox::allow_http_for($url)) {
            echo '<div class="notice notice-error"><p><strong>Panda PIO:</strong> PIO API URL nepoužívá HTTPS. Mimo Sandbox je HTTPS <u>povinné</u>.</p></div>';
        }
    }

    public function notice_sandbox_enabled(): void
    {
        if (!current_user_can('manage_options')) return;
        if (!Sandbox::is_enabled()) return;

        $exp = Sandbox::expires_at();
        $when = $exp ? date_i18n(get_option('date_format').' '.get_option('time_format'), $exp) : 'neznámo';

        echo '<div class="notice notice-warning"><p><strong>Panda PIO – Sandbox aktivní:</strong> HTTP režim povolen pouze pro lokální/dev cíle. '
           . 'Režim vyprší: <code>'.esc_html($when).'</code>. Doporučujeme používat jen pro vývoj.</p></div>';
    }

    /** 🆕 Upozornění, pokud chybí .htaccess / index.html v pio-logs */
    public function notice_logs_protection(): void
    {
        if (!current_user_can('manage_options')) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || strpos((string)$screen->id, 'panda-pio-manager') === false) return;

        $dir = \Panda\PIOManager\Logger::get_log_dir();
        $htaccess = $dir . '/.htaccess';
        $index = $dir . '/index.html';

        if (!file_exists($htaccess) || !file_exists($index)) {
            echo '<div class="notice notice-warning"><p><strong>Panda PIO:</strong> Ochrana logů není kompletní. ';
            if (!file_exists($htaccess)) {
                echo 'Chybí soubor <code>.htaccess</code>. ';
            }
            if (!file_exists($index)) {
                echo 'Chybí soubor <code>index.html</code>. ';
            }
            echo 'Plugin tyto soubory vytvoří automaticky při dalším zápisu do logu, nebo je můžete přidat ručně do složky ';
            echo '<code>/wp-content/uploads/pio-logs/</code>.</p></div>';
        }
    }

    public function dashboard(): void
    {
        Cron::ensure_token();
        $callback_url = Cron::get_callback_url();
        $sandbox_hours = (int) get_option('panda_pio_sandbox_hours', 6);
        $sandbox_on    = Sandbox::is_enabled();
        $sandbox_exp   = Sandbox::expires_at();
        $sandbox_tests = get_option('panda_pio_sandbox_tests','0') === '1';
        ?>
        <div class="wrap">
            <h1>Panda PIO Manager</h1>

            <form method="post" action="options.php">
                <?php settings_fields('panda_pio_group'); do_settings_sections('panda_pio_group'); ?>

                <h2 class="title">Nastavení připojení</h2>
                <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row"><label for="panda_pio_api_url">PIO API URL</label></th>
                        <td>
                            <input type="url" id="panda_pio_api_url" name="panda_pio_api_url"
                                   value="<?php echo esc_attr(get_option('panda_pio_api_url','')); ?>"
                                   class="regular-text" placeholder="https://pio.example.com/wp-json/pio/v1/check"/>
                            <p><button id="panda_pio_check_ssl" class="button">Ověřit SSL certifikát</button></p>
                            <div id="panda_pio_ssl_result" style="margin-top:6px;font-weight:600;"></div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="panda_pio_app_key">PIO App Key</label></th>
                        <td>
                            <input type="text" id="panda_pio_app_key" name="panda_pio_app_key"
                                   value="<?php echo esc_attr(get_option('panda_pio_app_key','')); ?>"
                                   class="regular-text" autocomplete="off"/>
                            <p class="description">Veřejná část HMAC (<code>X-PIO-Key</code>).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="panda_pio_app_secret">PIO App Secret</label></th>
                        <td>
                            <input type="password" id="panda_pio_app_secret" name="panda_pio_app_secret"
                                   value="<?php echo esc_attr(get_option('panda_pio_app_secret','')); ?>"
                                   class="regular-text" autocomplete="new-password"/>
                            <p class="description">Sdílené tajemství pro HMAC (<code>X-PIO-Signature</code>).</p>
                        </td>
                    </tr>
                </tbody></table>

                <h2 class="title">Cron job server</h2>
                <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row">Povolit WP-Cron</th>
                        <td>
                            <label><input type="checkbox" name="panda_pio_cron_enabled" value="1" <?php checked(get_option('panda_pio_cron_enabled','0'),'1'); ?> />
                                Zapnout periodické běhy přes WP-Cron</label>
                            <p class="description">Doporučeno: „2× denně“ nebo „Denně“. Pro vývoj lze hodinově.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="panda_pio_cron_interval">Interval</label></th>
                        <td>
                            <?php $v=(string)get_option('panda_pio_cron_interval','daily'); ?>
                            <select id="panda_pio_cron_interval" name="panda_pio_cron_interval">
                                <option value="hourly"     <?php selected($v,'hourly'); ?>>Každou hodinu</option>
                                <option value="twicedaily" <?php selected($v,'twicedaily'); ?>>2× denně</option>
                                <option value="daily"      <?php selected($v,'daily'); ?>>Denně</option>
                                <option value="custom"     <?php selected($v,'custom'); ?>>Vlastní (minuty)</option>
                            </select>
                            <span id="panda_pio_custom_minutes_wrap" style="margin-left:10px; <?php echo ($v==='custom'?'':'display:none;'); ?>">
                                <label>Každých <input type="number" min="1" step="1" style="width:80px"
                                    name="panda_pio_cron_every_minutes"
                                    value="<?php echo esc_attr((string)max(1,(int)get_option('panda_pio_cron_every_minutes',15))); ?>"> minut</label>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Externí cron callback URL</th>
                        <td>
                            <code style="user-select:all;"><?php echo esc_html($callback_url); ?></code>
                            <p class="description">Externí spouštění přes <strong>token</strong> nebo HMAC hlavičky. Doporučujeme volat z <strong>HTTPS</strong> webu.</p>
                            <p><label>Token
                                <input type="text" readonly
                                       value="<?php echo esc_attr((string)get_option('panda_pio_cron_token','')); ?>"
                                       class="regular-text"/></label></p>
                        </td>
                    </tr>
                </tbody></table>

                <h2 class="title">Sandbox (HTTP režim pro vývoj)</h2>
                <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row">Stav Sandbox</th>
                        <td>
                            <p id="panda_pio_sandbox_status">
                                <?php if ($sandbox_on): ?>
                                    <strong>AKTIVNÍ</strong>
                                    <?php if ($sandbox_exp): ?>
                                        – vyprší: <code><?php echo esc_html(date_i18n(get_option('date_format').' '.get_option('time_format'), $sandbox_exp)); ?></code>
                                    <?php endif; ?>
                                <?php else: ?>
                                    VYPNUTÝ
                                <?php endif; ?>
                            </p>
                            <p>
                                <label>Doba zapnutí (hodiny):
                                    <input type="number" min="1" max="48" id="panda_pio_sandbox_hours" name="panda_pio_sandbox_hours"
                                           value="<?php echo esc_attr((string) max(1,(int)$sandbox_hours)); ?>" style="width:80px">
                                </label>
                            </p>
                            <p>
                                <label><input type="checkbox" id="panda_pio_sandbox_tests" name="panda_pio_sandbox_tests" value="1" <?php checked($sandbox_tests,'1'); ?> />
                                    Povolit testy produkčních funkcí (simulace) během Sandboxu</label>
                                <span class="description"> – výsledky jsou orientační, v produkci neplatí.</span>
                            </p>
                            <p>
                                <button id="panda_pio_toggle_sandbox" class="button"
                                        data-enable="<?php echo $sandbox_on? 0 : 1; ?>">
                                    <?php echo $sandbox_on ? 'Vypnout Sandbox (HTTP)' : 'Zapnout Sandbox (HTTP)'; ?>
                                </button>
                            </p>
                        </td>
                    </tr>
                    <?php if ($sandbox_on): ?>
                    <tr>
                        <th scope="row">Test produkčních funkcí (simulace)</th>
                        <td>
                            <button id="panda_pio_run_prodtest" class="button button-secondary" <?php disabled(!$sandbox_tests); ?>>Spustit test</button>
                            <pre id="panda_pio_prodtest_result" style="margin-top:8px;max-height:260px;overflow:auto;background:#111;color:#0f0;padding:12px;border-radius:4px;">(výstup se zobrazí zde)</pre>
                            <p class="description">Simulace vrací hlavičky <code>X-PIO-Sandbox: 1</code>, <code>X-PIO-Sandbox-Test: 1</code> a JSON s „SIMULATED_OK“.</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody></table>

                <?php submit_button('💾 Uložit nastavení'); ?>
            </form>

            <hr>
            <h2>Test připojení</h2>
            <p><button id="panda_pio_test" class="button button-secondary">Otestovat připojení (AJAX)</button></p>
            <div id="panda_pio_result" style="margin-top:8px;font-weight:600;"></div>

            <hr>
            <h2>Bezpečný test (HMAC přes server)</h2>
            <p><button id="panda_pio_secure_check" class="button button-primary">Spustit zabezpečený test</button></p>
            <div id="panda_pio_secure_result" style="margin-top:8px;font-weight:600;"></div>

            <hr>
            <h2>Prohlížeč logu</h2>
            <p>Plná cesta: <code><?php echo esc_html(Logger::get_log_path()); ?></code></p>
            <p><button id="panda_pio_log_clear" class="button">Vymazat log</button></p>
            <textarea id="panda_pio_log_box" rows="15" style="width:100%;font-family:monospace;" readonly>Načítám…</textarea>
        </div>
        <?php
    }

    public function maybe_reschedule($old,$new): void
    {
        if (get_option('panda_pio_cron_enabled','0')==='1') Cron::schedule_event();
        else Cron::deactivate();
    }

    // AJAX: základní test
    public function ajax_test(): void
    {
        check_ajax_referer('panda_pio_nonce','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>'Přístup zamítnut.']);

        $client = new Client();
        $result = $client->send_request(['ping'=>'true']);

        if (is_wp_error($result)) wp_send_json_error(['msg'=>$result->get_error_message()]);
        wp_send_json_success(['msg'=>'Připojení OK','response'=>$result]);
    }

    // AJAX: HMAC secure check
    public function ajax_secure_check(): void
    {
        check_ajax_referer('panda_pio_secure_nonce','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>'Přístup zamítnut.']);

        $api_url = (string) get_option('panda_pio_api_url','');
        $payload = isset($_POST['payload']) && is_array($_POST['payload']) ? wp_unslash($_POST['payload']) : ['ping'=>'true'];
        $body    = wp_json_encode(['payload'=>$payload]);

        $app_key    = (string) get_option('panda_pio_app_key','');
        $app_secret = (string) get_option('panda_pio_app_secret','');
        if ($app_key==='' || $app_secret==='') wp_send_json_error(['msg'=>'Chybí App Key / App Secret.']);

        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $method    = 'POST';
        $path      = parse_url($api_url, PHP_URL_PATH) ?: '/';

        $signature = Security::compute_signature($app_secret,$method,$path,$timestamp,$nonce,$body);

        $is_https  = (strtolower((string) parse_url($api_url, PHP_URL_SCHEME)) === 'https');
        $sslverify = $is_https ? true : false;

        $headers = [
            'Content-Type'    => 'application/json',
            'X-PIO-Key'       => $app_key,
            'X-PIO-Timestamp' => $timestamp,
            'X-PIO-Nonce'     => $nonce,
            'X-PIO-Signature' => $signature,
        ];
        $headers = Sandbox::add_sandbox_headers($headers);

        $resp = wp_remote_post($api_url,[
            'timeout'=>15,
            'headers'=>$headers,
            'body'=>$body,
            'sslverify'=>$sslverify,
        ]);

        if (is_wp_error($resp)) wp_send_json_error(['msg'=>$resp->get_error_message()]);
        $code = (int) wp_remote_retrieve_response_code($resp);
        $raw  = (string) wp_remote_retrieve_body($resp);
        if ($code < 200 || $code >= 300) wp_send_json_error(['msg'=>'HTTP '.$code,'raw'=>mb_substr($raw,0,500)]);

        $json = json_decode($raw,true);
        $msg  = is_array($json)&&isset($json['message']) ? (string)$json['message'] : 'Připojení OK';
        wp_send_json_success(['msg'=>$msg,'raw'=>$json]);
    }

    // AJAX: log tail
    public function ajax_log_tail(): void
    {
        check_ajax_referer('panda_pio_nonce','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>'Přístup zamítnut.']);

        $lines = isset($_POST['lines']) ? (int) $_POST['lines'] : 100;
        $lines = max(1, min(1000,$lines));
        $rows  = Logger::tail($lines);
        wp_send_json_success(['lines'=>$rows]);
    }

    // AJAX: log clear
    public function ajax_log_clear(): void
    {
        check_ajax_referer('panda_pio_nonce','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>'Přístup zamítnut.']);

        $ok = Logger::clear();
        if ($ok) wp_send_json_success();
        wp_send_json_error(['msg'=>'Nepodařilo se vymazat log.']);
    }

    // AJAX: SSL check
    public function ajax_check_ssl(): void
    {
        check_ajax_referer('panda_pio_nonce','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>'Přístup zamítnut.']);

        $url = (string) get_option('panda_pio_api_url','');
        $res = SSL::check_endpoint_ssl($url);
        if ($res['ok']) wp_send_json_success(['msg'=>$res['message']]);
        wp_send_json_error(['msg'=>$res['message']]);
    }

    // AJAX: Sandbox toggle (+ zapnout/vypnout testy)
    public function ajax_toggle_sandbox(): void
    {
        check_ajax_referer('panda_pio_nonce','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>'Přístup zamítnut.']);

        $enable = isset($_POST['enable']) ? (int) $_POST['enable'] : 0;
        $hours  = isset($_POST['hours'])  ? (int) $_POST['hours']  : (int) get_option('panda_pio_sandbox_hours', 6);
        $tests  = isset($_POST['tests'])  ? (int) $_POST['tests']  : 0;

        Sandbox::set_tests_enabled($tests === 1);

        if ($enable === 1) {
            Sandbox::enable_for_hours($hours);
            wp_send_json_success(['msg'=>'Sandbox zapnut na '.$hours.' h (tests: '.($tests?'on':'off').')']);
        } else {
            Sandbox::disable();
            wp_send_json_success(['msg'=>'Sandbox vypnut']);
        }
    }

    // AJAX: spustit simulační test produkčních funkcí
    public function ajax_sandbox_prodtest(): void
    {
        check_ajax_referer('panda_pio_nonce','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>'Přístup zamítnut.']);
        if (!Sandbox::is_test_enabled()) wp_send_json_error(['msg'=>'Sandbox testy nejsou povoleny.']);

        $url = rest_url('pio/v1/sandbox/test-prod-feature');
        $resp = wp_remote_post($url, ['timeout'=>10, 'blocking'=>true ]);
        if (is_wp_error($resp)) wp_send_json_error(['msg'=>$resp->get_error_message()]);
        $code = (int) wp_remote_retrieve_response_code($resp);
        $raw  = (string) wp_remote_retrieve_body($resp);
        if ($code<200 || $code>=300) wp_send_json_error(['msg'=>'HTTP '.$code, 'raw'=>mb_substr($raw,0,500)]);

        $json = json_decode($raw, true);
        wp_send_json_success($json);
    }
}