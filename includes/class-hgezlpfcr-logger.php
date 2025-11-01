<?php
if (!defined('ABSPATH')) exit;

class HGEZLPFCR_Logger {
    /**
     * Check if debug logging is enabled
     * @return bool
     */
    public static function is_debug_enabled() {
        // Check if fc_debug setting is enabled
        return get_option('hgezlpfcr_debug', 'no') === 'yes';
    }

    public static function log($message, array $context = []) {
        // Check if debug is enabled in settings
        if (!self::is_debug_enabled()) return;

        if (isset($context['password'])) $context['password'] = '***';
        if (isset($context['apiKey']))   $context['apiKey']   = '***';
        $logger = wc_get_logger();
        $msg = is_string($message) ? $message : wp_json_encode($message);
        $ctx = empty($context) ? '' : (' | ' . wp_json_encode($context));
        $logger->info('[Woo FanCourier] ' . $msg . $ctx, ['source' => 'woo-fancourier']);
    }

    public static function error($message, array $context = []) {
        // Errors are ALWAYS logged, regardless of debug setting
        if (isset($context['password'])) $context['password'] = '***';
        if (isset($context['apiKey']))   $context['apiKey']   = '***';
        $logger = wc_get_logger();
        $msg = is_string($message) ? $message : wp_json_encode($message);
        $ctx = empty($context) ? '' : (' | ' . wp_json_encode($context));
        $logger->error('[Woo FanCourier] ' . $msg . $ctx, ['source' => 'woo-fancourier']);
    }
}
