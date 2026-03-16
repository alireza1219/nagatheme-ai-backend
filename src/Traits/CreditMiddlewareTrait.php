<?php

namespace App\Traits;

use App\Helpers\CreditManager;
use Psr\Http\Message\ResponseInterface as Response;

trait CreditMiddlewareTrait
{
    public function verify_credits(Response $response, string $api_key): array
    {
        $balance_result = CreditManager::get_balance($api_key);

        if (!$balance_result['success']) {
            return [
                'ok'       => false,
                'balance'  => 0,
                'response' => $this->json_response($response, [
                    'success' => false,
                    'error'   => [
                        'code'    => 'invalid_api_key',
                        'message' => $balance_result['message'],
                    ],
                    'data' => null,
                ], 401),
            ];
        }

        if ($balance_result['balance'] <= 50) {
            return [
                'ok'       => false,
                'balance'  => $balance_result['balance'],
                'response' => $this->json_response($response, [
                    'success' => false,
                    'error'   => [
                        'code'    => 'insufficient_credits',
                        'message' => 'You need at least 50 credits to use this tool.',
                    ],
                    'data' => null,
                ], 403),
            ];
        }

        return [
            'ok'      => true,
            'balance' => $balance_result['balance'],
        ];
    }

    public function deduct_credits(string $api_key, int $word_count, int $current_balance, string $note = ''): array
    {
        $deduct_result = CreditManager::decrease_credits($api_key, $word_count, $note);

        if ($deduct_result['success']) {
            return ['balance' => $deduct_result['balance'], 'error' => ''];
        }

        return [
            'balance' => $deduct_result['error'] === 'insufficient_credits' ? 0 : $current_balance,
            'error'   => $deduct_result['message'] ?? 'Failed to deduct credits',
        ];
    }

    public function build_result_response(array $results, array $extra = []): array
    {
        return array_merge([
            'success' => true,
            'error'   => null,
            'data'    => $results,
        ], $extra);
    }

    public function ai_error_response(Response $response, string $error_message): Response
    {
        return $this->json_response($response, [
            'success' => false,
            'error'   => [
                'code'    => 'ai_error',
                'message' => $error_message,
            ],
            'data' => null,
        ], 500);
    }
}
