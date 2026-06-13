<?php
namespace Panda\Common\Security;

if (!defined('ABSPATH')) exit;

/** Sdílené upozornění na HTTPS/HTTP v administraci. */
class SSL_Check
{
    public function __construct(private string $plugin_id, private string $plugin_label)
    {
        add_action('admin_notices', [$this, 'notice']);
    }

    public function notice(): void
    {
        if (!current_user_can('manage_options')) return;

        $home     = (string) home_url();
        $is_https = str_starts_with($home, 'https://');

        if ($is_https) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>'
                . esc_html($this->plugin_label)
                . ':</strong> Web běží na <code>HTTPS</code> (SSL aktivní).</p></div>';
        } else {
            echo '<div class="notice notice-warning is-dismissible"><p><strong>'
                . esc_html($this->plugin_label)
                . ':</strong> Web běží přes <code>HTTP</code>. Doporučujeme aktivovat SSL certifikát.</p></div>';
        }
    }
}