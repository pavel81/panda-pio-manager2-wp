<?php
namespace Panda\PIOManager;

if (!defined('ABSPATH')) exit;

class Cron
{
    const HOOK = 'panda_pio_cron_event';

    public function __construct()
    {
        add_action(self::HOOK, [$this, 'run']);
    }

    public static function ensure_token(): void
    {
        if (!get_option('panda_pio_cron_token')) {
            update_option('panda_pio_cron_token', wp_generate_password(32, false, false));
        }
    }

    public static function get_callback_url(): string
    {
        self::ensure_token();
        $token = (string) get_option('panda_pio_cron_token', '');
        return add_query_arg(['token'=>$token], rest_url('pio/v1/cron-run'));
    }

    public static function schedule_event(): void
    {
        if (get_option('panda_pio_cron_enabled','0') !== '1') return;

        $interval = (string) get_option('panda_pio_cron_interval','daily');
        if ($interval === 'custom') {
            $mins = max(1, (int) get_option('panda_pio_cron_every_minutes', 15));
            add_filter('cron_schedules', function($s) use ($mins){
                $s['panda_pio_custom'] = ['interval' => $mins*60, 'display' => "Panda PIO každých {$mins} min"];
                return $s;
            });
            $schedule = 'panda_pio_custom';
        } else {
            $schedule = in_array($interval, ['hourly','twicedaily','daily'], true) ? $interval : 'daily';
        }

        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time()+60, $schedule, self::HOOK);
        }
    }

    public static function deactivate(): void
    {
        $timestamp = wp_next_scheduled(self::HOOK);
        if ($timestamp) wp_unschedule_event($timestamp, self::HOOK);
    }

    public function run(): void
    {
        Logger::log('Cron: run()', 'INFO');
        // … zde spustíš, co je potřeba (relay, synchronizace apod.)
    }
}