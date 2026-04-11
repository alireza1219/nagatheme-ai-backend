<?php

namespace App\Helpers;

use App\Helpers\Env;

/**
 * Helper class for AI model detection and payload configuration.
 *
 * Handles the differences between reasoning models (o1, o3, gpt-5, etc.)
 * and standard models (gpt-4o, gpt-4o-mini, gpt-3.5-turbo, etc.)
 * across multiple AI providers (OpenAI, AvalAI, HeroAI, Nagatheme).
 *
 * Last updated: 2026-02-13
 */
class ModelHelper
{
    /**
     * Known reasoning models across all providers.
     *
     * These models use internal chain-of-thought reasoning that consumes
     * completion tokens before generating visible output.
     * They do NOT support the `temperature` parameter and require
     * `max_completion_tokens` instead of `max_tokens`.
     *
     * @var array
     */
    const REASONING_MODELS = [
        // OpenAI o-series
        'o1',
        'o1-mini',
        'o1-pro',
        'o3',
        'o3-mini',
        'o3-pro',
        'o4',
        'o4-mini',
        'o4-mini-deep',
        'o4-pro',

        // OpenAI GPT-5
        'gpt-5',
        'gpt-5-mini',
        'gpt-5-nano',
        'gpt-5.1',
        'gpt-5.1-mini',
        'gpt-5.1-nano',
        'gpt-5.2',
        'gpt-5.2-mini',

        // Google Gemini reasoning models
        'gemini-2.5-pro',
        'gemini-2.5-pro-preview',
        'gemini-2.5-flash',
        'gemini-2.5-flash-preview',
        'gemini-2.5-flash-lite',
        'gemini-2.5-flash-lite-preview',
        'gemini-3-pro',
        'gemini-3-pro-preview',
        'gemini-3-pro-image-preview',
        'gemini-3-flash',
        'gemini-3-flash-preview',
        'gemini-exp-1206',  // Experimental reasoning model

        // DeepSeek reasoning models
        'deepseek-r1',
        'deepseek-r1-0528',
        'deepseek-r1-distill-llama-70b',
        'deepseek-r1-distill-qwen-32b',
        'deepseek-r1-distill-qwen-14b',
        'deepseek-r1-distill-llama-8b',
        'deepseek-r1-distill-qwen-1.5b',

        // Qwen reasoning models
        'qwen3-235b-a22b',
        'qwen3-30b-a3b',
        'qwq-32b',
        'qwen-qwq-32b',

        // Anthropic Claude reasoning (extended thinking)
        'claude-opus-4-6-thinking',
        'claude-opus-4-5-thinking',
        'claude-sonnet-4-5-thinking',
        'claude-haiku-4-5-thinking',
        'claude-sonnet-4-thinking',
        'claude-3-7-sonnet-thinking',

        // xAI Grok reasoning models
        'grok-3-mini',
        'grok-3-mini-fast',
        'grok-4-mini',
    ];

    /**
     * Known standard (non-reasoning) models across all providers.
     *
     * These models support `temperature` and use `max_tokens`
     * for output length control.
     *
     * @var array
     */
    const STANDARD_MODELS = [
        // OpenAI GPT-4.1 family
        'gpt-4.1',
        'gpt-4.1-mini',
        'gpt-4.1-nano',

        // OpenAI GPT-4o family
        'gpt-4o',
        'gpt-4o-mini',
        'gpt-4o-audio-preview',
        'gpt-4o-realtime-preview',

        // OpenAI GPT-4 family (legacy)
        'gpt-4-turbo',
        'gpt-4',

        // OpenAI GPT-OSS (open-source)
        'gpt-oss',

        // OpenAI GPT-3.5 family (legacy)
        'gpt-3.5-turbo',
        'gpt-3.5-turbo-16k',

        // Google Gemini standard models
        'gemini-2.0-flash',
        'gemini-2.0-flash-exp',
        'gemini-2.0-flash-lite',
        'gemini-1.5-pro',
        'gemini-1.5-flash',
        'gemini-1.5-flash-8b',

        // Anthropic Claude 4.x models
        'claude-opus-4-6',
        'claude-opus-4-5',
        'claude-sonnet-4-5',
        'claude-haiku-4-5',
        'claude-opus-4-1',
        'claude-opus-4',
        'claude-sonnet-4',

        // Anthropic Claude 3.x models (legacy)
        'claude-3-7-sonnet',
        'claude-3-5-sonnet',
        'claude-3-5-haiku',
        'claude-3-opus',
        'claude-3-haiku',

        // Meta Llama 4 models
        'llama-4-scout',
        'llama-4-maverick',
        'llama-4-behemoth',

        // Meta Llama 3.x models
        'llama-3.3-70b-instruct',
        'llama-3.1-70b-instruct',
        'llama-3.1-8b-instruct',
        'llama-3-70b',
        'llama-3-8b',

        // DeepSeek standard models
        'deepseek-v3',
        'deepseek-v3-0324',
        'deepseek-chat',
        'deepseek-coder',

        // Mistral models
        'mistral-large',
        'mistral-large-3',
        'mistral-large-2',
        'mistral-medium',
        'mistral-small',
        'mistral-nemo',
        'mixtral-8x7b',
        'mixtral-8x22b',
        'codestral',
        'ministral-3b',
        'ministral-8b',
        'ministral-14b',

        // Qwen 3 standard models
        'qwen3-0.6b',
        'qwen3-1.7b',
        'qwen3-4b',
        'qwen3-8b',
        'qwen3-14b',
        'qwen3-32b',

        // Qwen 2.5 models
        'qwen-2.5-72b-instruct',
        'qwen-2.5-coder-32b-instruct',
        'qwen-turbo',
        'qwen-plus',
        'qwen-max',
        'qwen3-max',

        // Microsoft Phi models
        'phi-4',
        'phi-4-mini',
        'phi-3.5-mini',
        'phi-3-medium',

        // Cohere models
        'command-r-plus',
        'command-r',
        'command-a',
        'command-a-03-2025',

        // xAI Grok standard models
        'grok-2',
        'grok-3',
        'grok-3-fast',
        'grok-4',
        'grok-4-fast',
        'grok-4-turbo',
        'grok-beta',

        // Amazon Nova models
        'nova-pro-1.0',
        'nova-lite-1.0',
        'nova-micro-1.0',

        // AI21 Jamba models
        'jamba-1.5-large',
        'jamba-1.5-mini',
    ];

    /**
     * Hardcoded fallback defaults — used ONLY if .env is not loaded
     * or the variable is missing from .env.
     *
     * @var array
     */
    const FALLBACK_DEFAULTS = [
        'max_tokens'            => 4096,
        'max_completion_tokens' => 16000,
        'temperature'           => 0.7,
        'reasoning_effort'      => 'medium',
    ];

    /**
     * Payload strategies for reasoning models.
     * Ordered by likelihood of success (most common first).
     *
     * @var array
     */
    const REASONING_PAYLOAD_STRATEGIES = [
        // Strategy 1: Flat reasoning_effort (Chat Completions API style)
        'flat_reasoning_effort' => [
            'reasoning_param'  => 'reasoning_effort',
            'reasoning_format' => 'string',
            'token_param'      => 'max_completion_tokens',
            'supports_temperature' => false,
        ],

        // Strategy 2: Nested reasoning object (Responses API style)
        'nested_reasoning' => [
            'reasoning_param'  => 'reasoning',
            'reasoning_format' => 'object',
            'token_param'      => 'max_completion_tokens',
            'supports_temperature' => false,
        ],

        // Strategy 3: Nested reasoning with type (some providers)
        'nested_reasoning_with_type' => [
            'reasoning_param'  => 'reasoning',
            'reasoning_format' => 'object_with_type',
            'token_param'      => 'max_completion_tokens',
            'supports_temperature' => false,
        ],

        // Strategy 4: Thinking parameter (Anthropic/Claude style)
        'thinking_budget' => [
            'reasoning_param'  => 'thinking',
            'reasoning_format' => 'thinking_object',
            'token_param'      => 'max_tokens',
            'supports_temperature' => true,
        ],

        // Strategy 5: No reasoning param, just max_completion_tokens
        'no_reasoning_param' => [
            'reasoning_param'  => null,
            'reasoning_format' => null,
            'token_param'      => 'max_completion_tokens',
            'supports_temperature' => false,
        ],

        // Strategy 6: Minimal with max_tokens only (ultimate fallback)
        'minimal' => [
            'reasoning_param'  => null,
            'reasoning_format' => null,
            'token_param'      => 'max_tokens',
            'supports_temperature' => true,
        ],
    ];

    /**
     * Payload strategies for standard models.
     *
     * @var array
     */
    const STANDARD_PAYLOAD_STRATEGIES = [
        // Strategy 1: Standard max_tokens + temperature
        'standard' => [
            'token_param'          => 'max_tokens',
            'supports_temperature' => true,
        ],

        // Strategy 2: max_completion_tokens variant
        'completion_tokens' => [
            'token_param'          => 'max_completion_tokens',
            'supports_temperature' => true,
        ],

        // Strategy 3: Minimal (just max_tokens, no temperature)
        'minimal' => [
            'token_param'          => 'max_tokens',
            'supports_temperature' => false,
        ],
    ];

    /**
     * Get token defaults for the given model type, reading from .env
     * with hardcoded fallbacks.
     *
     * @param string $type 'reasoning', 'standard', or 'unknown'.
     *
     * @return array Token configuration for that model type.
     */
    public static function get_token_defaults(string $type = 'standard'): array
    {
        return match ($type) {
            'reasoning' => [
                'max_completion_tokens' => Env::getInt(
                    'REASONING_MAX_TOKENS',
                    self::FALLBACK_DEFAULTS['max_completion_tokens']
                ),
                'reasoning_effort' => Env::get(
                    'REASONING_EFFORT',
                    self::FALLBACK_DEFAULTS['reasoning_effort']
                ),
            ],

            'standard' => [
                'max_tokens' => Env::getInt(
                    'DEFAULT_MAX_TOKENS',
                    self::FALLBACK_DEFAULTS['max_tokens']
                ),
                'temperature' => Env::getFloat(
                    'DEFAULT_TEMPERATURE',
                    self::FALLBACK_DEFAULTS['temperature']
                ),
            ],

            default => [
                'max_tokens' => Env::getInt(
                    'DEFAULT_MAX_TOKENS',
                    self::FALLBACK_DEFAULTS['max_tokens']
                ),
                'max_completion_tokens' => Env::getInt(
                    'REASONING_MAX_TOKENS',
                    self::FALLBACK_DEFAULTS['max_completion_tokens']
                ),
                'temperature' => Env::getFloat(
                    'DEFAULT_TEMPERATURE',
                    self::FALLBACK_DEFAULTS['temperature']
                ),
            ],
        };
    }

    /**
     * Check if a model is a reasoning model.
     *
     * @param string $model The model identifier.
     *
     * @return bool True if the model is a reasoning model.
     */
    public static function is_reasoning_model(string $model): bool
    {
        $normalized = strtolower(trim($model));

        foreach (self::REASONING_MODELS as $reasoning_model) {
            if ($normalized === $reasoning_model || strpos($normalized, $reasoning_model) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a model is a known standard model.
     *
     * @param string $model The model identifier.
     *
     * @return bool True if the model is a known standard model.
     */
    public static function is_standard_model(string $model): bool
    {
        $normalized = strtolower(trim($model));

        foreach (self::STANDARD_MODELS as $standard_model) {
            if ($normalized === $standard_model || strpos($normalized, $standard_model) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a model is recognized (either reasoning or standard).
     *
     * @param string $model The model identifier.
     *
     * @return bool True if the model is in any known list.
     */
    public static function is_known_model(string $model): bool
    {
        return self::is_reasoning_model($model) || self::is_standard_model($model);
    }

    /**
     * Get model type label for logging.
     *
     * @param string $model The model identifier.
     *
     * @return string 'reasoning', 'standard', or 'unknown'.
     */
    public static function get_model_type(string $model): string
    {
        if (self::is_reasoning_model($model)) {
            return 'reasoning';
        }
        if (self::is_standard_model($model)) {
            return 'standard';
        }
        return 'unknown';
    }

    /**
     * Get the list of payload strategies to try for a model.
     *
     * @param string $model The model identifier.
     *
     * @return array List of strategy names to try in order.
     */
    public static function get_payload_strategies(string $model): array
    {
        $model_type = self::get_model_type($model);

        if ($model_type === 'reasoning') {
            return array_keys(self::REASONING_PAYLOAD_STRATEGIES);
        }

        if ($model_type === 'standard') {
            return array_keys(self::STANDARD_PAYLOAD_STRATEGIES);
        }

        // Unknown: try reasoning first, then standard
        return array_merge(
            array_keys(self::REASONING_PAYLOAD_STRATEGIES),
            array_keys(self::STANDARD_PAYLOAD_STRATEGIES)
        );
    }

    /**
     * Build a payload using a specific strategy.
     *
     * @param string $model         The model identifier.
     * @param string $system_prompt The system prompt.
     * @param string $user_prompt   The user prompt.
     * @param string $strategy      The strategy name to use.
     * @param string $provider      The provider name (for provider-specific adjustments).
     *
     * @return array|null The payload array, or null if strategy not found.
     */
    public static function build_payload_with_strategy(
        string  $model,
        string  $system_prompt,
        string  $user_prompt,
        string  $strategy,
        string  $provider = 'openai'
    ): ?array {
        $model_type = self::get_model_type($model);
        $defaults = self::get_token_defaults($model_type === 'unknown' ? 'reasoning' : $model_type);

        $payload = [
            'model'    => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user',   'content' => $user_prompt],
            ],
        ];

        if (isset(self::REASONING_PAYLOAD_STRATEGIES[$strategy])) {
            $config = self::REASONING_PAYLOAD_STRATEGIES[$strategy];
            return self::apply_reasoning_strategy($payload, $config, $defaults);
        }

        if (isset(self::STANDARD_PAYLOAD_STRATEGIES[$strategy])) {
            $config = self::STANDARD_PAYLOAD_STRATEGIES[$strategy];
            return self::apply_standard_strategy($payload, $config, $defaults);
        }

        return null;
    }

    /**
     * Apply a reasoning strategy configuration to a payload.
     *
     * @param array $payload  Base payload.
     * @param array $config   Strategy configuration.
     * @param array $defaults Token defaults.
     *
     * @return array Modified payload.
     */
    private static function apply_reasoning_strategy(array $payload, array $config, array $defaults): array
    {
        $token_param = $config['token_param'];
        if ($token_param === 'max_completion_tokens') {
            $payload['max_completion_tokens'] = $defaults['max_completion_tokens'] ?? self::FALLBACK_DEFAULTS['max_completion_tokens'];
        } else {
            $payload['max_tokens'] = $defaults['max_tokens'] ?? self::FALLBACK_DEFAULTS['max_tokens'];
        }

        if ($config['supports_temperature']) {
            $payload['temperature'] = $defaults['temperature'] ?? self::FALLBACK_DEFAULTS['temperature'];
        }

        $effort = $defaults['reasoning_effort'] ?? self::FALLBACK_DEFAULTS['reasoning_effort'];

        if ($config['reasoning_param'] !== null) {
            switch ($config['reasoning_format']) {
                case 'string':
                    $payload[$config['reasoning_param']] = $effort;
                    break;
                case 'object':
                    $payload[$config['reasoning_param']] = ['effort' => $effort];
                    break;
                case 'object_with_type':
                    $payload[$config['reasoning_param']] = [
                        'type'   => 'summarized',
                        'effort' => $effort,
                    ];
                    break;
                case 'thinking_object':
                    $budget = $defaults['max_completion_tokens'] ?? self::FALLBACK_DEFAULTS['max_completion_tokens'];
                    $payload[$config['reasoning_param']] = [
                        'type'          => 'enabled',
                        'budget_tokens' => (int) ($budget / 2),
                    ];
                    break;
            }
        }

        return $payload;
    }

    /**
     * Apply a standard strategy configuration to a payload.
     *
     * @param array $payload  Base payload.
     * @param array $config   Strategy configuration.
     * @param array $defaults Token defaults.
     *
     * @return array Modified payload.
     */
    private static function apply_standard_strategy(array $payload, array $config, array $defaults): array
    {
        $token_param = $config['token_param'];
        if ($token_param === 'max_completion_tokens') {
            $payload['max_completion_tokens'] = $defaults['max_completion_tokens'] ?? self::FALLBACK_DEFAULTS['max_completion_tokens'];
        } else {
            $payload['max_tokens'] = $defaults['max_tokens'] ?? self::FALLBACK_DEFAULTS['max_tokens'];
        }

        if ($config['supports_temperature']) {
            $payload['temperature'] = $defaults['temperature'] ?? self::FALLBACK_DEFAULTS['temperature'];
        }

        return $payload;
    }

    /**
     * Build payload with the default (first) strategy for a model.
     * Backwards-compatible method.
     *
     * @param string      $model         The model identifier.
     * @param string      $system_prompt The system prompt.
     * @param string      $user_prompt   The user prompt.
     * @param string|null $provider      The provider name.
     *
     * @return array The formatted payload.
     */
    public static function build_payload(
        string  $model,
        string  $system_prompt,
        string  $user_prompt,
        ?string $provider = null
    ): array {
        $strategies = self::get_payload_strategies($model);
        $first_strategy = $strategies[0] ?? 'minimal';

        return self::build_payload_with_strategy($model, $system_prompt, $user_prompt, $first_strategy, $provider ?? 'openai');
    }

    /**
     * Analyze an API error response to determine which parameter caused it.
     *
     * @param array|null $error_body The decoded error response body.
     * @param int        $http_code  The HTTP status code.
     *
     * @return array Analysis result with 'should_retry', 'failed_param', 'error_type'.
     */
    public static function analyze_error(?array $error_body, int $http_code): array
    {
        $result = [
            'should_retry'  => false,
            'failed_param'  => null,
            'error_type'    => 'unknown',
            'error_message' => '',
        ];

        // 400 = Bad Request (parameter issues)
        // 404 = Not Found (invalid endpoint/model)
        // 429 = Rate Limited
        // 401 = Unauthorized
        // 403 = Forbidden
        // 500+ = Server errors

        if ($http_code === 404) {
            $result['error_type'] = 'model_not_found';
            $result['error_message'] = 'Model or endpoint does not exist';
            return $result; // Don't retry with different strategies
        }

        if ($http_code === 429) {
            $result['error_type'] = 'rate_limited';
            $result['error_message'] = 'API rate limit exceeded';
            $result['should_retry'] = true; // Can retry after delay
            return $result;
        }

        if ($http_code === 401 || $http_code === 403) {
            $result['error_type'] = 'authentication_error';
            $result['error_message'] = 'Invalid API key or insufficient permissions';
            return $result; // Don't retry
        }

        if ($http_code >= 500) {
            $result['error_type'] = 'server_error';
            $result['error_message'] = 'API server error';
            $result['should_retry'] = true; // Can retry with backoff
            return $result;
        }

        if ($http_code !== 400) {
            return $result;
        }

        // Handle 400 Bad Request (parameter validation errors)
        if ($error_body === null) {
            return $result;
        }

        $error = $error_body['error'] ?? $error_body;

        if (is_array($error)) {
            $message = $error['message'] ?? '';
            $code    = $error['code'] ?? '';
            $param   = $error['param'] ?? null;
            $type    = $error['type'] ?? '';
        } else {
            $message = (string) $error;
            $code    = '';
            $param   = null;
            $type    = '';
        }

        $result['error_message'] = $message;
        $message_lower = strtolower($message);

        if ($code === 'unknown_parameter' || strpos($message_lower, 'unknown parameter') !== false) {
            $result['should_retry'] = true;
            $result['error_type']   = 'unknown_parameter';
            $result['failed_param'] = $param ?? self::extract_param_from_message($message);
            return $result;
        }

        if (strpos($message_lower, 'not supported') !== false || strpos($message_lower, 'unsupported') !== false) {
            $result['should_retry'] = true;
            $result['error_type']   = 'unsupported_parameter';
            $result['failed_param'] = $param ?? self::extract_param_from_message($message);
            return $result;
        }

        if ($type === 'invalid_request_error' || strpos($message_lower, 'invalid') !== false) {
            $result['should_retry'] = true;
            $result['error_type']   = 'invalid_parameter';
            $result['failed_param'] = $param ?? self::extract_param_from_message($message);
            return $result;
        }

        if (strpos($message_lower, 'prompt') !== false && strpos($message_lower, 'messages') !== false) {
            $result['should_retry'] = true;
            $result['error_type']   = 'missing_messages';
            $result['failed_param'] = 'input';
            return $result;
        }

        if (strpos($message_lower, 'temperature') !== false) {
            $result['should_retry'] = true;
            $result['error_type']   = 'temperature_not_allowed';
            $result['failed_param'] = 'temperature';
            return $result;
        }

        return $result;
    }

    /**
     * Extract parameter name from an error message.
     *
     * @param string $message The error message.
     *
     * @return string|null The extracted parameter name, or null.
     */
    private static function extract_param_from_message(string $message): ?string
    {
        if (preg_match("/['\"]([a-z_]+)['\"]/i", $message, $matches)) {
            return $matches[1];
        }
        if (preg_match('/parameter[:\s]+([a-z_]+)/i', $message, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Get the next strategy to try after a failed parameter.
     *
     * @param string      $model            The model identifier.
     * @param string      $current_strategy The strategy that failed.
     * @param string|null $failed_param     The parameter that caused the error.
     *
     * @return string|null The next strategy to try, or null if none left.
     */
    public static function get_next_strategy(string $model, string $current_strategy, ?string $failed_param = null): ?string
    {
        $strategies = self::get_payload_strategies($model);
        $current_index = array_search($current_strategy, $strategies);

        if ($current_index === false) {
            return $strategies[0] ?? null;
        }

        if ($failed_param !== null) {
            for ($i = $current_index + 1; $i < count($strategies); $i++) {
                if (!self::strategy_uses_param($strategies[$i], $failed_param)) {
                    return $strategies[$i];
                }
            }
        }

        $next_index = $current_index + 1;
        return $strategies[$next_index] ?? null;
    }

    /**
     * Check if a strategy uses a specific parameter.
     *
     * @param string $strategy_name The strategy name.
     * @param string $param         The parameter name to check.
     *
     * @return bool True if the strategy uses the parameter.
     */
    private static function strategy_uses_param(string $strategy_name, string $param): bool
    {
        if (isset(self::REASONING_PAYLOAD_STRATEGIES[$strategy_name])) {
            $config = self::REASONING_PAYLOAD_STRATEGIES[$strategy_name];

            if ($param === 'reasoning' || $param === 'reasoning_effort') {
                return $config['reasoning_param'] !== null;
            }
            if ($param === 'temperature') {
                return $config['supports_temperature'];
            }
            if ($param === 'max_completion_tokens') {
                return $config['token_param'] === 'max_completion_tokens';
            }
            if ($param === 'max_tokens') {
                return $config['token_param'] === 'max_tokens';
            }
        }

        if (isset(self::STANDARD_PAYLOAD_STRATEGIES[$strategy_name])) {
            $config = self::STANDARD_PAYLOAD_STRATEGIES[$strategy_name];

            if ($param === 'temperature') {
                return $config['supports_temperature'];
            }
            if ($param === 'max_completion_tokens') {
                return $config['token_param'] === 'max_completion_tokens';
            }
            if ($param === 'max_tokens') {
                return $config['token_param'] === 'max_tokens';
            }
        }

        return false;
    }

    /**
     * Get the recommended cURL timeout for a model.
     *
     * @param string $model The model identifier.
     *
     * @return int Timeout in seconds.
     */
    public static function get_timeout(string $model): int
    {
        if (self::is_reasoning_model($model)) {
            return 120;
        }
        if (self::is_standard_model($model)) {
            return 60;
        }
        return 90;
    }

    /**
     * Get model display information for debugging/logging.
     *
     * @param string $model The model identifier.
     *
     * @return array Associative array with model metadata.
     */
    public static function get_model_info(string $model): array
    {
        $type = self::get_model_type($model);

        return [
            'model'                => $model,
            'type'                 => $type,
            'is_reasoning'         => $type === 'reasoning',
            'is_known'             => $type !== 'unknown',
            'token_config'         => self::get_token_defaults($type),
            'available_strategies' => self::get_payload_strategies($model),
        ];
    }

    /**
     * Get all supported models grouped by type.
     *
     * @return array Models grouped by 'reasoning' and 'standard'.
     */
    public static function get_all_models(): array
    {
        return [
            'reasoning' => self::REASONING_MODELS,
            'standard'  => self::STANDARD_MODELS,
        ];
    }
}
