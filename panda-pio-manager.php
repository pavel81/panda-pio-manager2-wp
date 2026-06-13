<?php
/**
 * Plugin Name: Panda PIO Manager psr-4
 * Description: Řídicí a bezpečnostní brána (HMAC, SSL, Cron, Sandbox) pro Panda pluginy.
 * Version:     1.4.0
 * Author:      Panda Dev
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) exit;

/** Základní konstanty */
define('PANDA_PIO_VERSION', '1.4.0');
define('PANDA_PIO_DIR', plugin_dir_path(__FILE__));
define('PANDA_PIO_URL', plugin_dir_url(__FILE__));

/** Option klíče (držme je na jednom místě) */
define('PANDA_PIO_OPT_API_URL',            'pio_api_url');
define('PANDA_PIO_OPT_PUBLIC_KEY',         'pio_public_key');
define('PANDA_PIO_OPT_SECRET_KEY',         'pio_secret_key');
define('PANDA_PIO_OPT_SANDBOX',            'pio_sandbox_mode');
define('PANDA_PIO_OPT_DISMISS_API_NOTICE', 'pio_dismiss_apiurl_notice');
define('PANDA_PIO_OPT_CRON_ENABLED',       'pio_cron_enabled');
define('PANDA_PIO_OPT_CRON_INTERVAL',      'pio_cron_interval'); // pio_15min|hourly|daily|weekly

/** Cron hook název */
define('PANDA_PIO_CRON_HOOK', 'panda_pio/cron_run');

/** Legacy názvy option pro migraci */
const PANDA_PIO_LEGACY_API_OPTIONS = [
    'panda_pio_api_url',
    'panda_pio_manager_api_url',
    'pio_manager_api_url',
];

/**
 * Jednoduchý (bezpečný) PSR-4 autoloader pro náš plugin.
 *  - Panda\PIOManager\*        -> /includes/
 *  - Panda\Common\Security\*   -> /common/Security/
 */
spl_autoload_register(function (string $class) {
    if (strpos($class, 'Panda\\') !== 0) return;

    $base = __DIR__;
    $maps = [
        'Panda\\PIOManager\\'       => $base . '/includes/',
        'Panda\\Common\\Security\\' => $base . '/common/Security/',
    ];

    foreach ($maps as $ns => $dir) {
        if (strpos($class, $ns) === 0) {
            $rel  = str_replace('\\', '/', substr($class, strlen($ns)));
            // podpora jak Class.php tak Class.class.php (necháváme tolerantní)
            $candidates = [
                rtrim($dir, '/') . '/' . $rel . '.php',
                rtrim($dir, '/') . '/' . $rel . '.class.php',
            ];
            foreach ($candidates as $file) {
                if (is_readable($file)) {
                    require_once $file;
                    return;
                }
            }
        }
    }
});

/** Přidání vlastních intervalů do WP Cronu */
add_filter('cron_schedules', function ($schedules) {
    $schedules['pio_15min'] = [
        'interval' => 15 * 60,
        'display'  => __('Každých 15 minut (PIO)', 'panda-pio'),
    ];
    $schedules['pio_weekly'] = [
        'interval' => 7 * 24 * 60 * 60,
        'display'  => __('Týdně (PIO)', 'panda-pio'),
    ];
    return $schedules;
});

/** Aktivace – založení výchozích voleb + případné naplánování cronu */
register_activation_hook(__FILE__, function () {

    foreach ([
        PANDA_PIO_OPT_API_URL            => '',
        PANDA_PIO_OPT_PUBLIC_KEY         => '',
        PANDA_PIO_OPT_SECRET_KEY         => '',
        PANDA_PIO_OPT_SANDBOX            => false,
        PANDA_PIO_OPT_DISMISS_API_NOTICE => false,
        PANDA_PIO_OPT_CRON_ENABLED       => false,
        PANDA_PIO_OPT_CRON_INTERVAL      => 'pio_weekly',
    ] as $key => $default) {
        if (get_option($key, null) === null) {
            add_option($key, $default, false);
        }
    }

    // První migrace legacy API URL, pokud existuje
    $api = get_option(PANDA_PIO_OPT_API_URL, '');
    if ($api === '') {
        foreach (PANDA_PIO_LEGACY_API_OPTIONS as $legacy) {
            $val = trim((string) get_option($legacy, ''));
            if ($val !== '') {
                update_option(PANDA_PIO_OPT_API_URL, $val, false);
                break;
            }
        }
    }

    // Cron: naplánuj jen pokud je povolen
    if (get_option(PANDA_PIO_OPT_CRON_ENABLED, false)) {
        $interval = get_option(PANDA_PIO_OPT_CRON_INTERVAL, 'pio_weekly');
        if (!wp_next_scheduled(PANDA_PIO_CRON_HOOK)) {
            wp_schedule_event(time() + 60, $interval, PANDA_PIO_CRON_HOOK);
        }
    }

    // Volitelně inicializuj log adresář (pokud máš Logger)
    if (class_exists('\Panda\PIOManager\Logger')) {
        try { \Panda\PIOManager\Logger::ensure_dir(); } catch (\Throwable $e) {}
    }
});

/** Deaktivace – zrušení cron událostí */
register_deactivation_hook(__FILE__, function () {
    $timestamp = wp_next_scheduled(PANDA_PIO_CRON_HOOK);
    if ($timestamp) {
        wp_unschedule_event($timestamp, PANDA_PIO_CRON_HOOK);
    }
});

/**
 * Při initu držíme cron naplánovaný podle aktuálních voleb.
 * (Užitečné po změně intervalu v administraci.)
 */
add_action('init', function () {
    // pouze admin (abychom to neprováděli na frontendu každému návštěvníkovi)
    if (!is_admin()) return;

    $enabled  = (bool) get_option(PANDA_PIO_OPT_CRON_ENABLED, false);
    $interval = (string) get_option(PANDA_PIO_OPT_CRON_INTERVAL, 'pio_weekly');

    $next = wp_next_scheduled(PANDA_PIO_CRON_HOOK);

    if ($enabled && !$next) {
        wp_schedule_event(time() + 60, $interval, PANDA_PIO_CRON_HOOK);
    } elseif (!$enabled && $next) {
        wp_unschedule_event($next, PANDA_PIO_CRON_HOOK);
    }
});

/** Vlastní handler cron hooku – předáme do tvého Runneru, pokud existuje */
add_action(PANDA_PIO_CRON_HOOK, function () {
    if (class_exists('\Panda\PIOManager\Cron\Runner')) {
        try {
            // Třída by měla mít statický entrypoint nebo __invoke
            if (method_exists('\Panda\PIOManager\Cron\Runner', 'dispatch')) {
                \Panda\PIOManager\Cron\Runner::dispatch();
            } else {
                (new \Panda\PIOManager\Cron\Runner())();
            }
        } catch (\Throwable $e) {
            if (class_exists('\Panda\PIOManager\Logger')) {
                \Panda\PIOManager\Logger::error('Cron dispatch failed: ' . $e->getMessage());
            }
        }
    }
});

/** Inicializace modulů po načtení pluginů */
add_action('plugins_loaded', function () {

    // Globální SSL upozornění (není-li sandbox, jen doporučení)
    if (class_exists('\Panda\Common\Security\SSL_Check')) {
        new \Panda\Common\Security\SSL_Check('pio-manager', 'Panda PIO Manager');
    }

    // Admin UI (nastavení, dashboard, assets)
    if (is_admin()) {
        if (class_exists('\Panda\PIOManager\Admin\Settings'))  new \Panda\PIOManager\Admin\Settings();
        if (class_exists('\Panda\PIOManager\Admin\Dashboard')) new \Panda\PIOManager\Admin\Dashboard();
        if (class_exists('\Panda\PIOManager\Admin\Notices'))   new \Panda\PIOManager\Admin\Notices();
        // pokud máš AJAX class:
        if (class_exists('\Panda\PIOManager\Admin\Ajax'))      new \Panda\PIOManager\Admin\Ajax();
    }

    // REST routes pro klientské pluginy
    if (class_exists('\Panda\PIOManager\Rest\Routes')) {
        (new \Panda\PIOManager\Rest\Routes())->register_routes();
    }
});

/**
 * Volitelný filtr pro úplné vypnutí hlášek odkudkoli:
 * add_filter('panda_pio_show_admin_notices', '__return_false');
 */

/**
 * Pro programovou změnu intervalu nastav např.:
 * update_option('pio_cron_enabled', true);
 * update_option('pio_cron_interval', 'pio_15min|hourly|daily|pio_weekly');
 */