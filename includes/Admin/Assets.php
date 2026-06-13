<?php
namespace Panda\PIOManager\Admin;

if (!defined('ABSPATH')) exit;

class Assets
{
    public function __construct()
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(string $hook): void
    {
        if ($hook !== 'toplevel_page_panda-pio-manager') return;

        $handle = 'panda-pio-admin';
        wp_register_script(
            $handle,
            plugins_url('assets/js/pio-admin.js', dirname(__FILE__)), // → /assets/js/pio-admin.js
            ['jquery'],
            defined('PANDA_PIO_VERSION') ? PANDA_PIO_VERSION : '1.0.0',
            true
        );
        wp_localize_script($handle, 'PandaPIO', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('panda_pio_test_conn'),
        ]);
        wp_enqueue_script($handle);

        $css = '.panda-pio-result{margin-top:10px;padding:8px;border-radius:4px}'.
               '.panda-ok{background:#e7f7ee;border:1px solid #8bd2ad}'.
               '.panda-err{background:#fdeaea;border:1px solid #e19a9a}';
        wp_add_inline_style('wp-admin', $css);
    }
}