<?php
namespace Panda\PIOManager\Admin;

if (!defined('ABSPATH')) exit;

class Settings
{
    public const OPTION = 'panda_pio_settings';

    public function __construct()
    {
        add_action('admin_menu',  [$this, 'menu']);
        add_action('admin_init',  [$this, 'register']);
    }

    public function menu(): void
    {
        add_menu_page(
            'Panda PIO Manager',
            'Panda PIO',
            'manage_options',
            'panda-pio-manager',
            [$this, 'render'],
            'dashicons-rest-api',
            58
        );
    }

    public function register(): void
    {
        register_setting(self::OPTION, self::OPTION, [
            'sanitize_callback' => [$this, 'sanitize'],
            'type'              => 'array',
            'default'           => [
                'api_url'       => '',
                'app_key'       => '',
                'app_secret'    => '',
                'sandbox_mode'  => 0,
            ],
            'show_in_rest'      => false,
        ]);

        add_settings_section('pio_main', 'Nastavení připojení', '__return_false', self::OPTION);

        add_settings_field('api_url', 'PIO API URL', function () {
            $o = get_option(self::OPTION, []);
            printf(
                '<input type="url" class="regular-text" name="%s[api_url]" value="%s" placeholder="https://example.com/wp-json/pio/v1">',
                esc_attr(self::OPTION),
                isset($o['api_url']) ? esc_attr($o['api_url']) : ''
            );
            echo '<p class="description">V sandboxu se schéma přepne na <code>http://</code>.</p>';
        }, self::OPTION, 'pio_main');

        add_settings_field('app_key', 'PIO App Key', function () {
            $o = get_option(self::OPTION, []);
            printf(
                '<input type="text" class="regular-text" name="%s[app_key]" value="%s" autocomplete="off">',
                esc_attr(self::OPTION),
                isset($o['app_key']) ? esc_attr($o['app_key']) : ''
            );
        }, self::OPTION, 'pio_main');

        add_settings_field('app_secret', 'PIO App Secret', function () {
            $o = get_option(self::OPTION, []);
            printf(
                '<input type="password" class="regular-text" name="%s[app_secret]" value="%s" autocomplete="new-password">',
                esc_attr(self::OPTION),
                isset($o['app_secret']) ? esc_attr($o['app_secret']) : ''
            );
        }, self::OPTION, 'pio_main');

        add_settings_field('sandbox_mode', 'Sandbox (HTTP)', function () {
            $o = get_option(self::OPTION, []);
            $checked = !empty($o['sandbox_mode']) ? 'checked' : '';
            printf(
                '<label><input type="checkbox" name="%s[sandbox_mode]" value="1" %s> Povolit testovací režim bez SSL (HTTP)</label>',
                esc_attr(self::OPTION),
                $checked
            );
            echo '<p class="description">Pouze pro lokální/test prostředí. V produkci vypnout.</p>';
        }, self::OPTION, 'pio_main');
    }

    public function sanitize($in): array
    {
        $out = [];
        $out['api_url']      = isset($in['api_url']) ? esc_url_raw($in['api_url']) : '';
        $out['app_key']      = isset($in['app_key']) ? sanitize_text_field($in['app_key']) : '';
        $out['app_secret']   = isset($in['app_secret']) ? sanitize_text_field($in['app_secret']) : '';
        $out['sandbox_mode'] = empty($in['sandbox_mode']) ? 0 : 1;
        return $out;
    }

    public function render(): void
    {
        echo '<div class="wrap"><h1>Panda PIO Manager</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields(self::OPTION);
        do_settings_sections(self::OPTION);
        submit_button('💾 Uložit');
        echo '</form>';

        echo '<hr/><h2>Rychlý test připojení</h2>';
        echo '<p><button class="button button-primary" id="panda-pio-test">Otestovat připojení</button></p>';
        echo '<div id="panda-pio-result" class="panda-pio-result" style="display:none"></div>';

        echo '</div>';
    }
}