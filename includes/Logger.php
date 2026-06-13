<?php
namespace Panda\PIOManager;

if (!defined('ABSPATH')) exit;

class Logger
{
    public static function get_log_dir(): string
    {
        $upload = wp_upload_dir(null, false);
        return trailingslashit($upload['basedir']) . 'pio-logs';
    }

    public static function get_log_path(): string
    {
        return self::get_log_dir() . '/debug-pio.log';
    }

    public static function ensure_dir(): void
    {
        $dir = self::get_log_dir();
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }
        self::ensure_htaccess($dir);
        self::ensure_index($dir);
    }

    /**
     * Vytvoří/aktualizuje .htaccess tak, aby znemožnil přímý přístup k souborům v /uploads/pio-logs/.
     * Pravidla jsou kompatibilní s Apache 2.2 i 2.4.
     */
    private static function ensure_htaccess(string $dir): void
    {
        $path = trailingslashit($dir) . '.htaccess';

        $rules = <<<HT
# Panda PIO – ochrana logů
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>

# Vypnout indexaci adresáře (pro jistotu)
Options -Indexes

# Zabránit přímému stahování známých log/konfig rozšíření
<FilesMatch "\\.(log|txt|json|csv|ini|conf|bak)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</FilesMatch>
HT;

        // Pouze pokud .htaccess neexistuje nebo se liší obsahem
        if (!file_exists($path) || md5_file($path) !== md5($rules)) {
            @file_put_contents($path, $rules);
        }
    }

    /** Přidá prázdný index.html, aby se adresář nikdy nevylistoval. */
    private static function ensure_index(string $dir): void
    {
        $index = trailingslashit($dir) . 'index.html';
        if (!file_exists($index)) {
            @file_put_contents($index, "<!doctype html><title>403</title>");
        }
    }

    public static function log(string $msg, string $level = 'INFO'): void
    {
        self::ensure_dir();
        $line = sprintf("[%s] %-5s %s\n", date_i18n('Y-m-d H:i:s'), strtoupper($level), $msg);
        @file_put_contents(self::get_log_path(), $line, FILE_APPEND);
    }

    public static function tail(int $lines = 100): array
    {
        $file = self::get_log_path();
        if (!file_exists($file)) return [];
        $data = @file($file, FILE_IGNORE_NEW_LINES);
        if (!is_array($data)) return [];
        return array_slice($data, -$lines);
    }

    public static function clear(): bool
    {
        self::ensure_dir();
        return (bool) @file_put_contents(self::get_log_path(), '');
    }
}