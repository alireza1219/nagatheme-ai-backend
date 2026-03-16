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
     * Get the current credit balance for a API key.
     *
     * @param string $api_key The API key.
     *
     * @return array Response with success status and balance or error.
     */
    public static function get_balance($api_key)
    {
        $url = self::$api_base_url . '/balance?api_key=' . urlencode($api_key);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            return [
                'success' => false,
                'error' => 'connection-error',
                'message' => 'Failed to connect to credit system: ' . $curl_error,
            ];
        }

        $decoded = json_decode($response, true);

        if ($http_code === 401) {
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
            'user_id' => $decoded['user_id'] ?? null,
        ];
    }

    /**
     * Decrease credits for a API key.
     *
     * @param string $api_key The API key.
     * @param int    $amount      The amount of credits to deduct.
     * @param string $note        A note explaining the deduction.
     *
     * @return array Response with success status and new balance or error.
     */
    public static function decrease_credits($api_key, $amount, $note = '')
    {
        $url = self::$api_base_url . '/decrease';

        $payload = [
            'api_key' => $api_key,
            'amount' => $amount,
            'note' => $note,
        ];

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
        curl_close($ch);

        if ($curl_error) {
            return [
                'success' => false,
                'error' => 'connection-error',
                'message' => 'Failed to connect to credit system: ' . $curl_error,
            ];
        }

        $decoded = json_decode($response, true);

        if ($http_code === 401) {
            return [
                'success' => false,
                'error' => 'invalid_api_key',
                'message' => $decoded['message'] ?? 'Invalid API key.',
            ];
        }

        if ($http_code === 400 && isset($decoded['code']) && $decoded['code'] === 'insufficient_credits') {
            return [
                'success' => false,
                'error' => 'insufficient_credits',
                'message' => $decoded['message'] ?? 'Insufficient credits.',
                'balance' => $decoded['balance'] ?? 0,
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
            'amount_decreased' => $decoded['amount_decreased'],
        ];
    }

    /**
     * Get transaction history for a API key.
     *
     * @param string $api_key The API key.
     * @param int    $limit       Number of transactions to retrieve.
     *
     * @return array Response with success status and transactions or error.
     */
    public static function get_transactions($api_key, $limit = 10)
    {
        $url = self::$api_base_url . '/transactions?api_key=' . urlencode($api_key) . '&limit=' . (int)$limit;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            return [
                'success' => false,
                'error' => 'connection-error',
                'message' => 'Failed to connect to credit system: ' . $curl_error,
            ];
        }

        $decoded = json_decode($response, true);

        if ($http_code === 401) {
            return [
                'success' => false,
                'error' => 'invalid_api_key',
                'message' => $decoded['message'] ?? 'Invalid API key.',
            ];
        }

        if ($http_code !== 200) {
            return [
                'success' => false,
                'error' => 'api-error',
                'message' => 'Failed to retrieve transactions.',
            ];
        }

        return [
            'success' => true,
            'transactions' => $decoded['transactions'] ?? [],
            'count' => $decoded['count'] ?? 0,
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
}
