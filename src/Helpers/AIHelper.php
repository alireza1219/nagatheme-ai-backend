<?php

namespace App\Helpers;

use App\Helpers\Env;
use App\Helpers\ModelHelper;
use App\Helpers\Logger;

class AIHelper
{
    /**
     * Maximum number of retry attempts with different strategies.
     *
     * @var int
     */
    const MAX_RETRY_ATTEMPTS = 5;

    /**
     * Maximum number of retry attempts specifically for timeout errors.
     *
     * @var int
     */
    const MAX_TIMEOUT_RETRIES = 2;

    /**
     * Maximum number of retry attempts for rate limit errors (429).
     *
     * @var int
     */
    const MAX_RATE_LIMIT_RETRIES = 3;

    /**
     * Connection timeout in seconds (time to establish connection).
     *
     * @var int
     */
    const CONNECTION_TIMEOUT = 10;

    /**
     * HTTP status codes that should NOT trigger strategy retries.
     * These indicate fundamental issues, not parameter problems.
     *
     * @var array
     */
    const NON_RETRYABLE_HTTP_CODES = [
        401, // Unauthorized - bad API key
        403, // Forbidden - insufficient permissions
        404, // Not Found - invalid endpoint/model
    ];

    /**
     * @var array Request parameters
     */
    private $params;

    /**
     * @var string Nagatheme AI Model
     */
    private $nagatheme_ai_model;

    /**
     * @var string Nagatheme API Key
     */
    private $nagatheme_api_key;

    /**
     * @var string Nagatheme AI API Endpoint
     */
    private $nagatheme_ai_endpoint;

    /**
     * @var string Nagatheme Auth Prefix
     */
    private $nagatheme_auth_prefix;


    /**
     * @var string Nagatheme AI Vision API Endpoint (for vision-specific tasks)
     */
    private $nagatheme_ai_vision_endpoint;

    /**
     * @var string Nagatheme AI Vision Model (for vision-specific tasks)
     */
    private $nagatheme_ai_vision_model;

    /**
     * @var array Cache of working strategies per model (runtime only)
     */
    private static $working_strategies = [];

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->nagatheme_api_key = Env::get('NAGATHEME_API_KEY');
        $this->nagatheme_ai_endpoint = Env::get('NAGATHEME_ENDPOINT');
        $this->nagatheme_ai_model = Env::get('NAGATHEME_DEFAULT_MODEL', 'gpt-5-nano');
        $this->nagatheme_ai_vision_endpoint = Env::get('NAGATHEME_AI_VISION_ENDPOINT', $this->nagatheme_ai_endpoint);
        $this->nagatheme_ai_vision_model = Env::get('NAGATHEME_AI_VISION_MODEL', 'gpt-4o');
        $this->nagatheme_auth_prefix = Env::get('NAGATHEME_AUTH_PREFIX');
    }

    /**
     * Make an AI API call to the specified provider.
     *
     * Uses an intelligent retry mechanism that cycles through different
     * payload strategies when API errors occur.
     *
     * @param string $system_prompt The system prompt.
     * @param string $user_prompt   The user prompt.
     * @param array  $params        Request parameters (provider, model, api_key, etc.).
     *
     * @return array Response with success status, content, and word count or error.
     */
    public function call_ai($system_prompt, $user_prompt, $params = [])
    {
        $this->params = $params;

        $api_url = $this->nagatheme_ai_endpoint;
        $auth_prefix = $this->nagatheme_auth_prefix;
        $api_key = $this->nagatheme_api_key;
        $model = $this->nagatheme_ai_model;

        // Get model info for logging
        $model_type = ModelHelper::get_model_type($model);
        $timeout = ModelHelper::get_timeout($model);

        Logger::info('Processing AI request', [
            'model' => $model,
            'type' => $model_type,
            'timeout' => $timeout,
        ]);

        // Check if we have a known working strategy for this model
        $strategies = $this->get_strategies_to_try($model);

        // Try each strategy until one works
        $attempt = 0;
        $last_error = null;
        $tried_strategies = [];

        foreach ($strategies as $strategy) {
            if ($attempt >= self::MAX_RETRY_ATTEMPTS) {
                break;
            }

            // Skip if we already tried this strategy
            if (in_array($strategy, $tried_strategies, true)) {
                continue;
            }

            $tried_strategies[] = $strategy;
            $attempt++;

            Logger::debug("Trying strategy", [
                'attempt' => $attempt,
                'strategy' => $strategy,
                'model' => $model,
            ]);

            // Build payload with this strategy
            $payload = ModelHelper::build_payload_with_strategy(
                $model,
                $system_prompt,
                $user_prompt,
                $strategy
            );

            if ($payload === null) {
                Logger::warning("Strategy returned null payload", ['strategy' => $strategy]);
                continue;
            }

            // Make the API request with timeout retry logic
            $result = $this->execute_request_with_retry(
                $api_url,
                $auth_prefix,
                $api_key,
                $payload,
                $timeout,
                $model,
                $strategy
            );

            // Success!
            if ($result['success']) {
                // Cache the working strategy for this model
                self::$working_strategies[$model] = $strategy;
                Logger::info("Request succeeded", [
                    'strategy' => $strategy,
                    'model' => $model,
                    'elapsed_time' => $result['elapsed_time'] ?? 0,
                ]);

                $result['strategy_used'] = $strategy;
                $result['model_info'] = ModelHelper::get_model_info($model);
                return $result;
            }

            // Check if this is a non-retryable error (404, 401, 403)
            $http_code = $result['http_code'] ?? 0;
            if (in_array($http_code, self::NON_RETRYABLE_HTTP_CODES, true)) {
                Logger::error("Non-retryable HTTP error", [
                    'http_code' => $http_code,
                    'error' => $result['error'] ?? 'unknown',
                    'model' => $model,
                ]);

                // Return immediately - don't try other strategies
                $result['model_info'] = ModelHelper::get_model_info($model);
                return $result;
            }

            // If it's a timeout error and we've exhausted retries, continue to next strategy
            if (isset($result['error']) && $result['error'] === 'timeout-error') {
                Logger::warning("Timeout error persisted", ['strategy' => $strategy]);
                $last_error = $result;
                continue; // Try next strategy
            }

            // If it's a rate limit error, we've already retried - return it
            if (isset($result['error']) && $result['error'] === 'rate-limited') {
                Logger::warning("Rate limit error", [
                    'retry_after' => $result['retry_after'] ?? 'unknown',
                ]);
                $result['model_info'] = ModelHelper::get_model_info($model);
                return $result;
            }

            // Analyze the error to determine if we should retry with different strategy
            $error_analysis = ModelHelper::analyze_error(
                $result['debug'] ?? null,
                $http_code
            );

            $last_error = $result;
            $last_error['error_analysis'] = $error_analysis;

            if (!$error_analysis['should_retry']) {
                // This isn't a parameter error, don't try other strategies
                Logger::warning("Error is not retryable", [
                    'error_type' => $error_analysis['error_type'] ?? 'unknown',
                    'message' => $error_analysis['error_message'] ?? 'Unknown',
                ]);
                break;
            }

            Logger::debug("Strategy failed", [
                'strategy' => $strategy,
                'failed_param' => $error_analysis['failed_param'] ?? 'unknown',
                'error_type' => $error_analysis['error_type'] ?? 'unknown',
            ]);

            // Get next strategy that avoids the failed parameter
            $next_strategy = ModelHelper::get_next_strategy($model, $strategy, $error_analysis['failed_param']);

            if ($next_strategy !== null && !in_array($next_strategy, $tried_strategies, true)) {
                // Prioritize the suggested next strategy
                array_unshift($strategies, $next_strategy);
            }
        }

        // All strategies exhausted
        Logger::error("All strategies exhausted", [
            'model' => $model,
            'strategies_tried' => $tried_strategies,
        ]);

        return [
            'success' => false,
            'error' => 'all-strategies-exhausted',
            'message' => 'Unable to complete request. All payload formats failed for this model/provider.',
            'last_error' => $last_error,
            'strategies_tried' => $tried_strategies,
            'model_info' => ModelHelper::get_model_info($model),
        ];
    }

    /**
     * Make an AI API call with image support (vision models).
     *
     * Supports vision-capable models across multiple providers with
     * provider-specific payload formats.
     *
     * @param string $system_prompt The system prompt.
     * @param string $user_prompt   The user prompt.
     * @param string $image_data    Base64 image data in data URI format.
     * @param array  $params        Request parameters (provider, model, api_key, etc.).
     *
     * @return array Response with success status, content, and word count or error.
     */
    public function call_ai_with_vision($system_prompt, $user_prompt, $image_data, $params = [])
    {
        $this->params = $params;

        $api_url = $this->nagatheme_ai_vision_endpoint;
        $auth_prefix = $this->nagatheme_auth_prefix;
        $api_key = $this->nagatheme_api_key;
        $model = $this->nagatheme_ai_vision_model;

        // Get model info for logging
        $model_type = ModelHelper::get_model_type($model);
        $timeout = ModelHelper::get_timeout($model);

        Logger::info('Processing AI vision request', [
            'model' => $model,
            'type' => $model_type,
            'timeout' => $timeout,
        ]);

        // Validate image data format
        if (!preg_match('/data:(image\/[a-z]+);base64,(.+)/', $image_data, $matches)) {
            Logger::error('Invalid image data format', ['image_data_preview' => substr($image_data, 0, 50)]);
            return [
                'success' => false,
                'error' => 'invalid-image-format',
                'message' => 'Image data must be in format: data:image/[type];base64,[data]',
            ];
        }

        $media_type = $matches[1]; // e.g., "image/jpeg"
        $base64_data = $matches[2];

        Logger::debug('Image data parsed', [
            'media_type' => $media_type,
            'data_length' => strlen($base64_data),
        ]);

        // Build vision-compatible payload based on provider format
        $payload = $this->build_vision_payload(
            $model,
            $system_prompt,
            $user_prompt,
            $media_type,
            $base64_data
        );

        if ($payload === null) {
            Logger::error('Failed to build vision payload', [
                'model' => $model,
            ]);
            return [
                'success' => false,
                'error' => 'vision-not-supported',
                'message' => 'This model or provider does not support vision capabilities.',
            ];
        }

        // Execute the request
        $result = $this->execute_request($api_url, $auth_prefix, $api_key, $payload, $timeout);

        if ($result['success']) {
            Logger::info('Vision request succeeded', [
                'model' => $model,
                'elapsed_time' => $result['elapsed_time'] ?? 0,
            ]);
            $result['model_info'] = ModelHelper::get_model_info($model);
        } else {
            Logger::error('Vision request failed', [
                'model' => $model,
                'error' => $result['error'] ?? 'unknown',
                'message' => $result['message'] ?? 'Unknown error',
            ]);
        }

        return $result;
    }

    /**
     * Get the list of strategies to try for a model.
     * If we have a cached working strategy, try it first.
     *
     * @param string $model The model identifier.
     *
     * @return array List of strategy names.
     */
    private function get_strategies_to_try(string $model): array
    {
        $strategies = ModelHelper::get_payload_strategies($model);

        // If we have a known working strategy, put it first
        if (isset(self::$working_strategies[$model])) {
            $working = self::$working_strategies[$model];
            $strategies = array_diff($strategies, [$working]);
            array_unshift($strategies, $working);
        }

        return array_values($strategies);
    }

    /**
     * Execute an API request with automatic retry for transient errors.
     *
     * @param string $api_url     The API endpoint URL.
     * @param string $auth_prefix Authorization prefix.
     * @param string $api_key     The API key.
     * @param array  $payload     The request payload.
     * @param int    $timeout     Request timeout in seconds.
     * @param string $model       The model being used (for logging).
     * @param string $strategy    The strategy being used (for logging).
     *
     * @return array Response array with success status and content/error.
     */
    private function execute_request_with_retry(
        string $api_url,
        string $auth_prefix,
        string $api_key,
        array $payload,
        int $timeout,
        string $model,
        string $strategy
    ): array {
        $timeout_retries = 0;
        $rate_limit_retries = 0;
        $last_result = null;

        while (true) {
            $result = $this->execute_request($api_url, $auth_prefix, $api_key, $payload, $timeout);

            // If successful, return immediately
            if ($result['success']) {
                if ($timeout_retries > 0 || $rate_limit_retries > 0) {
                    Logger::info("Request succeeded after retries", [
                        'timeout_retries' => $timeout_retries,
                        'rate_limit_retries' => $rate_limit_retries,
                    ]);
                }
                return $result;
            }

            // Handle timeout errors
            if (isset($result['error']) && $result['error'] === 'timeout-error') {
                $timeout_retries++;

                if ($timeout_retries <= self::MAX_TIMEOUT_RETRIES) {
                    $wait_time = $timeout_retries * 2; // Exponential backoff: 2s, 4s
                    Logger::info("Retrying after timeout", [
                        'attempt' => $timeout_retries,
                        'wait_time' => $wait_time,
                    ]);
                    sleep($wait_time);
                    $last_result = $result;
                    continue;
                } else {
                    Logger::error("Max timeout retries reached", [
                        'max_retries' => self::MAX_TIMEOUT_RETRIES,
                        'model' => $model,
                        'strategy' => $strategy,
                    ]);
                    return $result;
                }
            }

            // Handle rate limit errors (429)
            if (isset($result['error']) && $result['error'] === 'rate-limited') {
                $rate_limit_retries++;

                if ($rate_limit_retries <= self::MAX_RATE_LIMIT_RETRIES) {
                    // Use Retry-After header if available, otherwise exponential backoff
                    $wait_time = $result['retry_after'] ?? ($rate_limit_retries * 5);
                    Logger::info("Retrying after rate limit", [
                        'attempt' => $rate_limit_retries,
                        'wait_time' => $wait_time,
                    ]);
                    sleep($wait_time);
                    $last_result = $result;
                    continue;
                } else {
                    Logger::error("Max rate limit retries reached", [
                        'max_retries' => self::MAX_RATE_LIMIT_RETRIES,
                    ]);
                    return $result;
                }
            }

            // For all other errors, return immediately (no retry)
            return $result;
        }

        return $last_result ?? [
            'success' => false,
            'error' => 'unknown-error',
            'message' => 'Unknown error occurred during request execution.',
        ];
    }

    /**
     * Execute an API request with the given payload.
     *
     * @param string $api_url     The API endpoint URL.
     * @param string $auth_prefix Authorization prefix.
     * @param string $api_key     The API key.
     * @param array  $payload     The request payload.
     * @param int    $timeout     Request timeout in seconds.
     *
     * @return array Response array with success status and content/error.
     */
    private function execute_request(
        string $api_url,
        string $auth_prefix,
        string $api_key,
        array $payload,
        int $timeout
    ): array {
        $ch = curl_init($api_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Authorization: {$auth_prefix} {$api_key}",
            ],
            CURLOPT_TIMEOUT => $timeout,              // Total request timeout
            CURLOPT_CONNECTTIMEOUT => self::CONNECTION_TIMEOUT, // Connection phase timeout
            CURLOPT_NOSIGNAL => 1,                    // Prevent issues with timeouts on some systems
            CURLOPT_HEADER => true,                   // Include headers in response (for Retry-After)
        ]);

        $start_time = microtime(true);
        $response = curl_exec($ch);
        $elapsed_time = round(microtime(true) - $start_time, 2);

        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curl_error = curl_error($ch);
        $curl_errno = curl_errno($ch);
        curl_close($ch);

        // Split headers and body
        $headers = '';
        $body = $response;
        if ($header_size > 0 && strlen($response) >= $header_size) {
            $headers = substr($response, 0, $header_size);
            $body = substr($response, $header_size);
        }

        // Handle cURL errors
        if ($curl_errno !== 0) {
            // Check if it's a timeout error
            if ($this->is_timeout_error($curl_errno)) {
                Logger::warning("Timeout error", [
                    'errno' => $curl_errno,
                    'elapsed_time' => $elapsed_time,
                    'message' => $curl_error,
                ]);

                return [
                    'success' => false,
                    'error' => 'timeout-error',
                    'message' => "Request timed out after {$elapsed_time}s. The AI model took too long to respond.",
                    'http_code' => 0,
                    'curl_errno' => $curl_errno,
                    'elapsed_time' => $elapsed_time,
                    'timeout_limit' => $timeout,
                ];
            }

            // Other connection errors
            Logger::error("Connection error", [
                'errno' => $curl_errno,
                'elapsed_time' => $elapsed_time,
                'message' => $curl_error,
            ]);

            return [
                'success' => false,
                'error' => 'api-connection-error',
                'message' => 'Failed to connect to AI API: ' . $curl_error,
                'http_code' => 0,
                'curl_errno' => $curl_errno,
                'elapsed_time' => $elapsed_time,
            ];
        }

        Logger::debug("Request completed", [
            'http_code' => $http_code,
            'elapsed_time' => $elapsed_time,
        ]);

        // Handle rate limiting (429)
        if ($http_code === 429) {
            $retry_after = $this->parse_retry_after_header($headers);

            Logger::warning("Rate limit exceeded", [
                'retry_after' => $retry_after,
            ]);

            return [
                'success' => false,
                'error' => 'rate-limited',
                'message' => 'API rate limit exceeded. Please retry later.',
                'http_code' => $http_code,
                'retry_after' => $retry_after,
                'elapsed_time' => $elapsed_time,
            ];
        }

        // Handle other HTTP errors
        if ($http_code !== 200) {
            $error_body = json_decode($body, true);

            $error_message = $this->extract_error_message($error_body, $http_code);

            Logger::error("API error", [
                'http_code' => $http_code,
                'message' => $error_message,
            ]);

            return [
                'success' => false,
                'error' => 'api-error',
                'message' => $error_message,
                'http_code' => $http_code,
                'debug' => $error_body,
                'elapsed_time' => $elapsed_time,
            ];
        }

        // Parse successful response
        $decoded = json_decode($body, true);

        if ($decoded === null) {
            Logger::error("Invalid JSON response", [
                'json_error' => json_last_error_msg(),
            ]);

            return [
                'success' => false,
                'error' => 'invalid-response',
                'message' => 'Invalid JSON response from AI API: ' . json_last_error_msg(),
                'http_code' => $http_code,
                'elapsed_time' => $elapsed_time,
            ];
        }

        $content = $decoded['choices'][0]['message']['content'] ?? null;
        $finish_reason = $decoded['choices'][0]['finish_reason'] ?? '';

        // Check for reasoning token overflow
        if (($content === null || trim($content) === '') && $finish_reason === 'length') {
            Logger::warning("Reasoning token overflow", [
                'finish_reason' => $finish_reason,
            ]);

            return [
                'success' => false,
                'error' => 'reasoning-token-overflow',
                'message' => 'The model used all available tokens for reasoning and produced no visible output. Try reducing the complexity of your request.',
                'http_code' => $http_code,
                'elapsed_time' => $elapsed_time,
            ];
        }

        if ($content === null) {
            return [
                'success' => false,
                'error' => 'invalid-response',
                'message' => 'No content returned from AI API.',
                'http_code' => $http_code,
                'elapsed_time' => $elapsed_time,
            ];
        }

        $content = trim($content);
        $word_count = self::word_count($content);

        return [
            'success' => true,
            'content' => $content,
            'word_count' => $word_count,
            'http_code' => $http_code,
            'usage' => $decoded['usage'] ?? null,
            'elapsed_time' => $elapsed_time,
            'finish_reason' => $finish_reason,
        ];
    }

    /**
     * Extract a user-friendly error message from API error response.
     *
     * @param array|null $error_body The decoded error body.
     * @param int        $http_code  The HTTP status code.
     *
     * @return string User-friendly error message.
     */
    private function extract_error_message(?array $error_body, int $http_code): string
    {
        // Handle common HTTP codes first
        $default_messages = [
            401 => 'Invalid API key. Please check your credentials.',
            403 => 'Access forbidden. You may not have permission to use this model.',
            404 => 'Model or endpoint not found. The requested model may not exist or is not available.',
            429 => 'Rate limit exceeded. Please try again later.',
            500 => 'AI API server error. Please try again.',
            502 => 'Bad gateway. The AI service is temporarily unavailable.',
            503 => 'Service unavailable. The AI service is temporarily down.',
        ];

        if (isset($default_messages[$http_code])) {
            $message = $default_messages[$http_code];

            // Try to add specific details from error body if available
            if ($error_body !== null) {
                $error = $error_body['error'] ?? $error_body;
                if (is_array($error) && isset($error['message'])) {
                    $message .= ' (' . $error['message'] . ')';
                } elseif (is_string($error)) {
                    $message .= ' (' . $error . ')';
                }
            }

            return $message;
        }

        // Try to extract message from error body
        if ($error_body !== null) {
            $error = $error_body['error'] ?? $error_body;

            if (is_array($error) && isset($error['message'])) {
                return $error['message'];
            }

            if (is_string($error)) {
                return $error;
            }
        }

        return "AI API returned error code: {$http_code}";
    }

    /**
     * Parse the Retry-After header from HTTP response.
     *
     * @param string $headers The response headers.
     *
     * @return int|null Seconds to wait, or null if not found.
     */
    private function parse_retry_after_header(string $headers): ?int
    {
        if (preg_match('/Retry-After:\s*(\d+)/i', $headers, $matches)) {
            return (int) $matches[1];
        }
        return null;
    }

    /**
     * Check if a curl error number indicates a timeout.
     *
     * @param int $curl_errno The curl error number.
     *
     * @return bool True if it's a timeout error, false otherwise.
     */
    private function is_timeout_error(int $curl_errno): bool
    {
        // Common timeout error codes:
        // CURLE_OPERATION_TIMEDOUT (28) - Operation timeout
        $timeout_errors = [
            28, // CURLE_OPERATION_TIMEDOUT
        ];

        return in_array($curl_errno, $timeout_errors, true);
    }

    /**
     * Build a vision-compatible API payload with provider-specific format.
     *
     * @param string $model         The model identifier.
     * @param string $system_prompt The system prompt.
     * @param string $user_prompt   The user prompt.
     * @param string $media_type    Image media type (e.g., "image/jpeg").
     * @param string $base64_data   Base64 encoded image data.
     * @param string $provider      The provider name.
     *
     * @return array|null The payload array, or null if vision not supported.
     */
    private function build_vision_payload($model, $system_prompt, $user_prompt, $media_type, $base64_data)
    {
        // Get default token settings
        $model_type = ModelHelper::get_model_type($model);
        $defaults = ModelHelper::get_token_defaults($model_type);

        // Detect provider format based on model name patterns and provider
        $format = $this->detect_provider_format($model);

        Logger::debug('Building vision payload', [
            'model' => $model,
            'format' => $format,
        ]);

        switch ($format) {
            case 'anthropic':
                return $this->build_anthropic_vision_payload(
                    $model,
                    $system_prompt,
                    $user_prompt,
                    $media_type,
                    $base64_data,
                    $defaults
                );

            case 'google':
                return $this->build_google_vision_payload(
                    $model,
                    $system_prompt,
                    $user_prompt,
                    $media_type,
                    $base64_data,
                    $defaults
                );

            case 'openai':
            default:
                return $this->build_openai_vision_payload(
                    $model,
                    $system_prompt,
                    $user_prompt,
                    $media_type,
                    $base64_data,
                    $defaults
                );
        }
    }

    /**
     * Detect the appropriate API format based on model and provider.
     *
     * @param string $model    The model identifier.
     * @param string $provider The provider name.
     *
     * @return string Format identifier ('openai', 'anthropic', 'google').
     */
    private function detect_provider_format($model)
    {
        $model_lower = strtolower($model);

        // Check model name patterns
        if (strpos($model_lower, 'claude') !== false) {
            return 'anthropic';
        }

        if (strpos($model_lower, 'gemini') !== false) {
            return 'google';
        }

        if (strpos($model_lower, 'gpt') !== false) {
            return 'openai';
        }

        // Default to OpenAI format (most compatible)
        return 'openai';
    }

    /**
     * Build OpenAI-style vision payload.
     * Used by: GPT-4o, GPT-4-vision, GPT-4o-mini, and most OpenAI-compatible APIs.
     *
     * @param string $model         Model identifier.
     * @param string $system_prompt System prompt.
     * @param string $user_prompt   User prompt.
     * @param string $media_type    Image media type.
     * @param string $base64_data   Base64 image data.
     * @param array  $defaults      Token defaults.
     *
     * @return array Payload.
     */
    private function build_openai_vision_payload($model, $system_prompt, $user_prompt, $media_type, $base64_data, $defaults)
    {
        $payload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $system_prompt
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $user_prompt
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => "data:{$media_type};base64,{$base64_data}"
                            ]
                        ]
                    ]
                ]
            ]
        ];

        // Add token limits
        $model_type = ModelHelper::get_model_type($model);
        if ($model_type === 'reasoning') {
            $payload['max_completion_tokens'] = $defaults['max_completion_tokens'] ?? 16000;
        } else {
            $payload['max_tokens'] = $defaults['max_tokens'] ?? 4096;
        }

        // Add temperature for standard models
        if ($model_type === 'standard') {
            $payload['temperature'] = $defaults['temperature'] ?? 0.7;
        }

        return $payload;
    }

    /**
     * Build Anthropic-style vision payload.
     * Used by: Claude Sonnet 4.5, Claude Opus 4.5, Claude 3.x models.
     *
     * @param string $model         Model identifier.
     * @param string $system_prompt System prompt.
     * @param string $user_prompt   User prompt.
     * @param string $media_type    Image media type.
     * @param string $base64_data   Base64 image data.
     * @param array  $defaults      Token defaults.
     *
     * @return array Payload.
     */
    private function build_anthropic_vision_payload($model, $system_prompt, $user_prompt, $media_type, $base64_data, $defaults)
    {
        $payload = [
            'model' => $model,
            'system' => $system_prompt,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'image',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => $media_type,
                                'data' => $base64_data
                            ]
                        ],
                        [
                            'type' => 'text',
                            'text' => $user_prompt
                        ]
                    ]
                ]
            ]
        ];

        // Add token limits
        $payload['max_tokens'] = $defaults['max_tokens'] ?? 4096;

        // Anthropic uses temperature for all models
        $payload['temperature'] = $defaults['temperature'] ?? 0.7;

        return $payload;
    }

    /**
     * Build Google-style vision payload.
     * Used by: Gemini 2.0, Gemini 1.5, Gemini Pro Vision.
     *
     * @param string $model         Model identifier.
     * @param string $system_prompt System prompt.
     * @param string $user_prompt   User prompt.
     * @param string $media_type    Image media type.
     * @param string $base64_data   Base64 image data.
     * @param array  $defaults      Token defaults.
     *
     * @return array Payload.
     */
    private function build_google_vision_payload($model, $system_prompt, $user_prompt, $media_type, $base64_data, $defaults)
    {
        // Google Gemini uses a different structure
        $payload = [
            'model' => $model,
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        [
                            'text' => $system_prompt . "\n\n" . $user_prompt
                        ],
                        [
                            'inline_data' => [
                                'mime_type' => $media_type,
                                'data' => $base64_data
                            ]
                        ]
                    ]
                ]
            ]
        ];

        // Google uses generationConfig for parameters
        $payload['generationConfig'] = [
            'maxOutputTokens' => $defaults['max_tokens'] ?? 4096,
            'temperature' => $defaults['temperature'] ?? 0.7,
        ];

        return $payload;
    }

    /**
     * Manually clear the cached working strategy for a model.
     * Useful if the API changes and the cached strategy stops working.
     *
     * @param string|null $model The model to clear, or null to clear all.
     */
    public static function clear_strategy_cache(?string $model = null): void
    {
        if ($model === null) {
            self::$working_strategies = [];
            Logger::info("Cleared all strategy caches");
        } else {
            unset(self::$working_strategies[$model]);
            Logger::info("Cleared strategy cache", ['model' => $model]);
        }
    }

    /**
     * Get the currently cached working strategies.
     *
     * @return array Map of model => strategy.
     */
    public static function get_cached_strategies(): array
    {
        return self::$working_strategies;
    }

    /**
     * Detect if text is in Persian/Farsi.
     *
     * @param string $text The text to check.
     *
     * @return bool True if Persian detected, false otherwise.
     */
    public static function is_persian($text)
    {
        // Check for Persian Unicode characters (range: 0600-06FF)
        return preg_match('/[\x{0600}-\x{06FF}]/u', $text) === 1;
    }

    /**
     * Word Count Function (PHP Replica)
     *
     * @param string $content The content to count words in.
     *
     * @return int Word count.
     */
    public static function word_count($content)
    {
        if (empty($content)) {
            return 0;
        }

        // Step 1: Remove script tags
        $content = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $content);

        // Step 2: Remove HTML comments
        $content = preg_replace('/<!--[\s\S]*?-->/', '', $content);

        // Step 3: Replace &nbsp; and normalize whitespace
        $content = preg_replace('/&nbsp;|&#160;/i', ' ', $content);
        // Multiple spaces → single
        $content = preg_replace('/\s{2,}/', ' ', $content);
        // Space before period           
        $content = preg_replace('/\s+\./', '.', $content);
        // Remove line breaks            
        $content = preg_replace("/[\r\n]+/", '', $content);

        // Step 4: Handle diacritics/normalization
        $content = self::normalize_diacritics($content);

        // Step 5: Strip HTML tags completely
        $text_content = strip_tags($content);
        $text_content = trim($text_content);

        if (empty($text_content)) {
            return 0;
        }

        // Step 7: Count words (matches JS: content.split(" ").length)
        $words = explode(' ', $text_content);

        // Filter out empty words
        $words = array_filter($words, function ($word) {
            return !empty(trim($word));
        });

        return count($words);
    }

    /**
     * Normalize diacritics (matches JS chunk 119)
     *
     * @param string $text The text to normalize.
     *
     * @return string Normalized text.
     */
    public static function normalize_diacritics($text)
    {
        $diacritics = [
            // Vowels with accents
            'à' => 'a',
            'á' => 'a',
            'â' => 'a',
            'ã' => 'a',
            'ä' => 'a',
            'å' => 'a',
            'À' => 'A',
            'Á' => 'A',
            'Â' => 'A',
            'Ã' => 'A',
            'Ä' => 'A',
            'Å' => 'A',
            'è' => 'e',
            'é' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'È' => 'E',
            'É' => 'E',
            'Ê' => 'E',
            'Ë' => 'E',
            'ì' => 'i',
            'í' => 'i',
            'î' => 'i',
            'ï' => 'i',
            'Ì' => 'I',
            'Í' => 'I',
            'Î' => 'I',
            'Ï' => 'I',
            'ò' => 'o',
            'ó' => 'o',
            'ô' => 'o',
            'õ' => 'o',
            'ö' => 'o',
            'ø' => 'o',
            'Ò' => 'O',
            'Ó' => 'O',
            'Ô' => 'O',
            'Õ' => 'O',
            'Ö' => 'O',
            'Ø' => 'O',
            'ù' => 'u',
            'ú' => 'u',
            'û' => 'u',
            'ü' => 'u',
            'Ù' => 'U',
            'Ú' => 'U',
            'Û' => 'U',
            'Ü' => 'U',
            // Other common replacements
            'ç' => 'c',
            'Ç' => 'C',
            'ñ' => 'n',
            'Ñ' => 'N',
            'ý' => 'y',
            'ÿ' => 'y',
            'Ý' => 'Y',
        ];

        return strtr($text, $diacritics);
    }
}
