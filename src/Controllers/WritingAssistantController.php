<?php

namespace App\Controllers;

use App\Helpers\AIHelper;
use App\Prompts\SystemPrompts;
use App\Traits\JsonResponseTrait;
use App\Traits\CreditMiddlewareTrait;
use App\Traits\RequestParserTrait;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Handles AI-powered writing assistant chat.
 */
class WritingAssistantController
{
    use JsonResponseTrait;
    use CreditMiddlewareTrait;
    use RequestParserTrait;

    /**
     * Chat with the AI writing assistant.
     *
     * POST body:
     *   api_key      string  (required) Nagatheme API key
     *   message      string  (required) User's latest message
     *   history      array   (optional) Previous conversation turns [{role, content}]
     *   action_type  string  (optional) Quick action hint (improve/outline/summarize/continue/ideas/seo)
     *   language     string  (optional) ISO language code, default 'en'
     *   post_context array   (optional) {title, type, excerpt} of the post being edited
     *
     * @param Request  $request
     * @param Response $response
     *
     * @return Response
     */
    public function chat(Request $request, Response $response): Response
    {
        $params = $this->parse_request_body($request);

        $validation = $this->validate_required($params, ['api_key', 'message']);
        if ($validation !== true) {
            return $this->json_response($response, $validation, 400);
        }

        // Credit check
        $credit_check = $this->verify_credits($response, $params['api_key']);
        if (!$credit_check['ok']) {
            return $credit_check['response'];
        }
        $current_balance = $credit_check['balance'];

        // Build prompts
        $language     = $params['language']    ?? 'en';
        $action_type  = $params['action_type'] ?? 'chat';
        $post_context = $params['post_context'] ?? [];
        $history      = $this->sanitize_history($params['history'] ?? []);

        $system_prompt = SystemPrompts::writing_assistant($language, $post_context);

        // Call AI with conversation history
        $ai_helper   = new AIHelper();
        $ai_response = $ai_helper->call_ai_with_history(
            $system_prompt,
            $params['message'],
            $history
        );

        if (!$ai_response['success']) {
            return $this->ai_error_response($response, $ai_response['message'] ?? 'AI generation failed');
        }

        $reply = trim($ai_response['content']);

        // Deduct credits
        $word_count = $ai_response['word_count'];
        $deduction  = $this->deduct_credits(
            $params['api_key'],
            $word_count,
            $current_balance,
            'Writing Assistant – ' . ucfirst($action_type)
        );

        return $this->json_response($response, $this->build_result_response(
            ['reply' => $reply],
            ['balance' => $deduction['balance'], 'balance_error' => $deduction['error']]
        ));
    }

    /**
     * Sanitize conversation history.
     * Caps at 20 messages (10 turns) and each message content at 4000 chars.
     *
     * @param mixed $history
     * @return array
     */
    private function sanitize_history($history): array
    {
        if (!is_array($history)) {
            return [];
        }

        $sanitized = [];
        foreach ($history as $entry) {
            if (!isset($entry['role'], $entry['content'])) {
                continue;
            }
            $role    = in_array($entry['role'], ['user', 'assistant'], true) ? $entry['role'] : 'user';
            $content = substr((string) $entry['content'], 0, 4000);
            $sanitized[] = ['role' => $role, 'content' => $content];
        }

        // Keep last 20 messages (10 turns)
        if (count($sanitized) > 20) {
            $sanitized = array_slice($sanitized, -20);
        }

        return $sanitized;
    }
}
