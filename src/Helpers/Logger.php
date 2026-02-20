<?php

namespace App\Helpers;

/**
 * Simple PSR-3-like Logger for AI API operations.
 * 
 * Usage:
 *   Logger::debug('Processing request', ['model' => 'gpt-4']);
 *   Logger::error('API failed', ['http_code' => 500]);
 * 
 * Configure via .env:
 *   LOG_LEVEL=debug|info|warning|error
 *   LOG_FILE=/path/to/app.log
 *   LOG_TO_FILE=true|false
 */
class Logger
{
    const DEBUG = 0;
    const INFO = 1;
    const WARNING = 2;
    const ERROR = 3;

    private static $levels = [
        self::DEBUG => 'DEBUG',
        self::INFO => 'INFO',
        self::WARNING => 'WARNING',
        self::ERROR => 'ERROR',
    ];

    /**
     * Get the current log level from environment.
     *
     * @return int
     */
    private static function getLogLevel(): int
    {
        $level = strtolower(Env::get('LOG_LEVEL', 'info'));

        return match ($level) {
            'debug' => self::DEBUG,
            'info' => self::INFO,
            'warning', 'warn' => self::WARNING,
            'error' => self::ERROR,
            default => self::INFO,
        };
    }

    /**
     * Log a message at the specified level.
     *
     * @param int    $level   Log level constant.
     * @param string $message Log message.
     * @param array  $context Additional context data.
     */
    private static function log(int $level, string $message, array $context = []): void
    {
        if ($level < self::getLogLevel()) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $levelName = self::$levels[$level] ?? 'UNKNOWN';

        // Format context
        $contextStr = '';
        if (!empty($context)) {
            $contextStr = ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        }

        $logMessage = "[{$timestamp}] [{$levelName}] {$message}{$contextStr}";

        // Log to file if configured
        if (Env::get('LOG_TO_FILE', 'false') === 'true') {
            $logFile = Env::get('LOG_FILE', '/tmp/ai_api.log');
            error_log($logMessage . PHP_EOL, 3, $logFile);
        }

        // Also log to PHP error log for immediate visibility
        error_log($logMessage);
    }

    /**
     * Log debug message (detailed debugging information).
     *
     * @param string $message Log message.
     * @param array  $context Additional context.
     */
    public static function debug(string $message, array $context = []): void
    {
        self::log(self::DEBUG, $message, $context);
    }

    /**
     * Log info message (interesting events).
     *
     * @param string $message Log message.
     * @param array  $context Additional context.
     */
    public static function info(string $message, array $context = []): void
    {
        self::log(self::INFO, $message, $context);
    }

    /**
     * Log warning message (exceptional occurrences that are not errors).
     *
     * @param string $message Log message.
     * @param array  $context Additional context.
     */
    public static function warning(string $message, array $context = []): void
    {
        self::log(self::WARNING, $message, $context);
    }

    /**
     * Log error message (runtime errors).
     *
     * @param string $message Log message.
     * @param array  $context Additional context.
     */
    public static function error(string $message, array $context = []): void
    {
        self::log(self::ERROR, $message, $context);
    }
}
