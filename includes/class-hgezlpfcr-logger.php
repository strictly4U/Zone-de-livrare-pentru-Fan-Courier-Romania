<?php
/**
 * Logger class for FAN Courier plugin
 *
 * Implements PSR-3 compatible logging levels using WooCommerce logger.
 *
 * Log levels (from most severe to least):
 * - emergency: System is unusable
 * - alert: Action must be taken immediately
 * - critical: Critical conditions
 * - error: Error conditions (always logged)
 * - warning: Warning conditions (always logged)
 * - notice: Normal but significant conditions
 * - info: Informational messages (same as log())
 * - debug: Debug-level messages
 *
 * @package HgE_FAN_Courier
 * @since 2.0.7 Added PSR-3 compatible log levels
 */

if (!defined('ABSPATH')) exit;

class HGEZLPFCR_Logger {

    /**
     * Log source identifier
     */
    const LOG_SOURCE = 'woo-fancourier';

    /**
     * Check if debug logging is enabled
     *
     * @return bool
     */
    public static function is_debug_enabled() {
        return get_option('hgezlpfcr_debug', 'no') === 'yes';
    }

    /**
     * Sanitize context array - remove sensitive data
     *
     * @param array $context Context data
     * @return array Sanitized context
     */
    private static function sanitize_context(array $context) {
        $sensitive_keys = ['password', 'apiKey', 'api_key', 'secret', 'token', 'webhook_secret'];
        foreach ($sensitive_keys as $key) {
            if (isset($context[$key])) {
                $context[$key] = '***';
            }
        }
        return $context;
    }

    /**
     * Format log message
     *
     * @param string|mixed $message Message to log
     * @param array $context Context data
     * @return string Formatted message
     */
    private static function format_message($message, array $context) {
        $context = self::sanitize_context($context);
        $msg = is_string($message) ? $message : wp_json_encode($message);
        $ctx = empty($context) ? '' : (' | ' . wp_json_encode($context));
        return '[Woo FanCourier] ' . $msg . $ctx;
    }

    /**
     * Log informational message (requires debug enabled)
     *
     * @param string|mixed $message Message to log
     * @param array $context Context data
     */
    public static function log($message, array $context = []) {
        if (!self::is_debug_enabled()) return;

        $logger = wc_get_logger();
        $logger->info(self::format_message($message, $context), ['source' => self::LOG_SOURCE]);
    }

    /**
     * Alias for log() - PSR-3 compatible
     *
     * @param string|mixed $message Message to log
     * @param array $context Context data
     */
    public static function info($message, array $context = []) {
        self::log($message, $context);
    }

    /**
     * Log debug message (requires debug enabled)
     *
     * @param string|mixed $message Message to log
     * @param array $context Context data
     */
    public static function debug($message, array $context = []) {
        if (!self::is_debug_enabled()) return;

        $logger = wc_get_logger();
        $logger->debug(self::format_message($message, $context), ['source' => self::LOG_SOURCE]);
    }

    /**
     * Log notice (requires debug enabled)
     *
     * @param string|mixed $message Message to log
     * @param array $context Context data
     */
    public static function notice($message, array $context = []) {
        if (!self::is_debug_enabled()) return;

        $logger = wc_get_logger();
        $logger->notice(self::format_message($message, $context), ['source' => self::LOG_SOURCE]);
    }

    /**
     * Log warning (always logged, regardless of debug setting)
     *
     * @param string|mixed $message Message to log
     * @param array $context Context data
     */
    public static function warning($message, array $context = []) {
        $logger = wc_get_logger();
        $logger->warning(self::format_message($message, $context), ['source' => self::LOG_SOURCE]);
    }

    /**
     * Log error (always logged, regardless of debug setting)
     *
     * @param string|mixed $message Message to log
     * @param array $context Context data
     */
    public static function error($message, array $context = []) {
        $logger = wc_get_logger();
        $logger->error(self::format_message($message, $context), ['source' => self::LOG_SOURCE]);
    }

    /**
     * Log critical error (always logged, regardless of debug setting)
     *
     * @param string|mixed $message Message to log
     * @param array $context Context data
     */
    public static function critical($message, array $context = []) {
        $logger = wc_get_logger();
        $logger->critical(self::format_message($message, $context), ['source' => self::LOG_SOURCE]);
    }

    /**
     * Log alert (always logged, regardless of debug setting)
     *
     * @param string|mixed $message Message to log
     * @param array $context Context data
     */
    public static function alert($message, array $context = []) {
        $logger = wc_get_logger();
        $logger->alert(self::format_message($message, $context), ['source' => self::LOG_SOURCE]);
    }

    /**
     * Log emergency (always logged, regardless of debug setting)
     *
     * @param string|mixed $message Message to log
     * @param array $context Context data
     */
    public static function emergency($message, array $context = []) {
        $logger = wc_get_logger();
        $logger->emergency(self::format_message($message, $context), ['source' => self::LOG_SOURCE]);
    }
}
