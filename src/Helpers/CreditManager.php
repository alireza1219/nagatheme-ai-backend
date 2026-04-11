<?php

namespace App\Helpers;

class CreditManager
{
    /**
     * EDD Credits API Base URL
     *
     * @var string
     */
    private static $api_base_url = 'https://nagatheme.com/wp-json/edd-credits/v1';

    /**
     * Validate an API key and return the associated credit balance.
     *
     * @param string $api_key The API key to validate.
     *
     * @return array Response with validity status and balance or error.
     */
    public static function validate($api_key)
    {
        $url = self::$api_base_url . '/validate';

        $payload = [
            'api_key' => $api_key,
        ];

        $result = self::post($url, $payload);

        if ($result['error'] ?? false) {
            return $result;
        }

        $decoded = $result['decoded'];
        $http_code = $result['http_code'];

        if ($http_code === 403) {
            return [
                'success' => false,
                'error' => 'invalid_api_key',
                'message' => $decoded['message'] ?? 'Invalid API key.',
            ];
        }

        if ($http_code !== 200 || !isset($decoded['valid'])) {
            return [
                'success' => false,
                'error' => 'api-error',
                'message' => 'Failed to validate API key.',
            ];
        }

        return [
            'success' => true,
            'valid' => $decoded['valid'],
            'message' => $decoded['message'] ?? '',
            'balance' => $decoded['balance'] ?? 0,
        ];
    }

    /**
     * Get the current credit balance for an API key.
     *
     * @param string $api_key The API key.
     *
     * @return array Response with success status and balance or error.
     */
    public static function get_balance($api_key)
    {
        $url = self::$api_base_url . '/balance';

        $payload = [
            'api_key' => $api_key,
        ];

        $result = self::post($url, $payload);

        if ($result['error'] ?? false) {
            return $result;
        }

        $decoded = $result['decoded'];
        $http_code = $result['http_code'];

        if ($http_code === 403) {
            return [
                'success' => false,
                'error' => 'invalid_api_key',
                'message' => $decoded['message'] ?? 'Invalid API key.',
            ];
        }

        if ($http_code !== 200 || !isset($decoded['balance'])) {
            return [
                'success' => false,
                'error' => 'api-error',
                'message' => 'Failed to retrieve balance.',
            ];
        }

        return [
            'success' => true,
            'balance' => $decoded['balance'],
        ];
    }

    /**
     * Deduct credits for an API key.
     *
     * @param string $api_key The API key.
     * @param int    $amount  The number of credits to deduct (positive integer).
     * @param string $reason  A note explaining the reason for the deduction.
     *
     * @return array Response with success status and new balance or error.
     */
    public static function deduct_credits($api_key, $amount, $reason = '')
    {
        $url = self::$api_base_url . '/deduct';

        $payload = [
            'api_key' => $api_key,
            'amount' => $amount,
            'reason' => $reason,
        ];

        $result = self::post($url, $payload);

        if ($result['error'] ?? false) {
            return $result;
        }

        $decoded = $result['decoded'];
        $http_code = $result['http_code'];

        if ($http_code === 403) {
            return [
                'success' => false,
                'error' => 'invalid_api_key',
                'message' => $decoded['message'] ?? 'Invalid API key.',
            ];
        }

        if ($http_code === 402) {
            return [
                'success' => false,
                'error' => 'insufficient_credits',
                'message' => $decoded['message'] ?? 'Insufficient credits.',
                'balance' => $decoded['balance'] ?? 0,
            ];
        }

        if ($http_code === 400) {
            return [
                'success' => false,
                'error' => $decoded['code'] ?? 'bad-request',
                'message' => $decoded['message'] ?? 'Bad request.',
            ];
        }

        if ($http_code !== 200) {
            return [
                'success' => false,
                'error' => 'api-error',
                'message' => 'Failed to deduct credits.',
            ];
        }

        return [
            'success' => true,
            'balance' => $decoded['balance'],
        ];
    }

    /**
     * Set the EDD Credits API base URL.
     *
     * @param string $url The base URL.
     */
    public static function set_api_base_url($url)
    {
        self::$api_base_url = rtrim($url, '/');
    }

    /**
     * Send a POST request with JSON payload.
     *
     * @param string $url     The endpoint URL.
     * @param array  $payload The request body.
     *
     * @return array Parsed result with 'decoded' and 'http_code', or an error array.
     */
    private static function post($url, array $payload)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);

        if ($curl_error) {
            return [
                'success' => false,
                'error' => 'connection-error',
                'message' => 'Failed to connect to credit system: ' . $curl_error,
            ];
        }

        return [
            'decoded' => json_decode($response, true),
            'http_code' => $http_code,
        ];
    }
}
