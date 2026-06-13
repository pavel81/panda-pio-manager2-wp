<?php
namespace Panda\PIOManager;

if (!defined('ABSPATH')) exit;

/**
 * Sandbox – dočasně povolí HTTP pro dev hosty + volitelné testy produkčních funkcí.
 */
class Sandbox
{
    private const OPT_ENABLED    = 'panda_pio_sandbox_mode';
    private const OPT_EXPIRES    = 'panda_pio_sandbox_expires_at';
    private const OPT_HOURS      = 'panda_pio_sandbox_hours';
    private const OPT_TESTS      = 'panda_pio_sandbox_tests';

    public static function is_enabled(): bool
    {
        $enabled = get_option(self::OPT_ENABLED, '0') === '1';
        if (!$enabled) return false;

        $exp = (int) get_option(self::OPT_EXPIRES, 0);
        if ($exp > 0 && time() > $exp) {
            update_option(self::OPT_ENABLED, '0');
            delete_option(self::OPT_EXPIRES);
            return false;
        }
        return true;
    }

    public static function is_test_enabled(): bool
    {
        return self::is_enabled() && (get_option(self::OPT_TESTS, '0') === '1');
    }

    public static function expires_at(): ?int
    {
        $exp = (int) get_option(self::OPT_EXPIRES, 0);
        return $exp > 0 ? $exp : null;
    }

    public static function enable_for_hours(int $hours): void
    {
        $hours = max(1, min(48, $hours));
        update_option(self::OPT_ENABLED, '1');
        update_option(self::OPT_HOURS, $hours);
        update_option(self::OPT_EXPIRES, time() + ($hours * 3600));
    }

    public static function disable(): void
    {
        update_option(self::OPT_ENABLED, '0');
        delete_option(self::OPT_EXPIRES);
    }

    public static function set_tests_enabled(bool $on): void
    {
        update_option(self::OPT_TESTS, $on ? '1' : '0');
    }

    public static function allow_http_for(string $url): bool
    {
        if (!self::is_enabled()) return false;

        $u = @parse_url($url);
        if (!is_array($u)) return false;
        $scheme = strtolower($u['scheme'] ?? '');
        $host   = strtolower($u['host'] ?? '');

        if ($scheme !== 'http') return false;

        $ok = self::is_dev_host($host) || self::is_private_ip($host);
        $ok = (bool) apply_filters('panda_pio/sandbox_allow_http', $ok, $url, $host);
        return $ok;
    }

    public static function add_sandbox_headers(array $headers): array
    {
        if (self::is_enabled()) {
            $headers['X-PIO-Sandbox'] = '1';
            if (self::is_test_enabled()) $headers['X-PIO-Sandbox-Test'] = '1';
        }
        return $headers;
    }

    private static function is_dev_host(string $host): bool
    {
        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') return true;
        foreach (['.test', '.local', '.lan', '.invalid'] as $tld) {
            if (str_ends_with($host, $tld)) return true;
        }
        return false;
    }

    private static function is_private_ip(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if (preg_match('#^10\.\d+\.\d+\.\d+$#', $host)) return true;
            if (preg_match('#^172\.(1[6-9]|2\d|3[0-1])\.\d+\.\d+$#', $host)) return true;
            if (preg_match('#^192\.168\.\d+\.\d+$#', $host)) return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($host === '::1') return true;
        }
        return false;
    }
}