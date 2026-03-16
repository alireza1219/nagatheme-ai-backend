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
 * Handles AI-powered smart comment replies.
 */
class CommentController
{
    use JsonResponseTrait;
    use CreditMiddlewareTrait;
    use RequestParserTrait;

    /**
     * Generate a smart reply to a WordPress comment.
     *
     * POST body:
     *   api_key      string  (required) Nagatheme API key
     *   comment      string  (required) The comment text to reply to
     *   post_title   string  (optional) Title of the post being commented on
     *   post_type    string  (optional) WordPress post type, default 'post'
     *   post_excerpt string  (optional) Short excerpt of the post for context
     *   language     string  (optional) ISO language code, default 'en'
     *
     * @param Request  $request
     * @param Response $response
     *
     * @return Response
     */
    public function reply(Request $request, Response $response): Response
    {
        $params = $this->parse_request_body($request);

        $validation = $this->validate_required($params, ['api_key    ', 'comment']);
        if ($validation !== true) {
            return $this->json_response($response, $validation, 400);
        }

        // Credit check
        $credit_check = $this->verify_credits($response, $params['api_key    ']);
        if (!$credit_check['ok']) {
            return $credit_check['response'];
        }
        $current_balance = $credit_check['balance'];

        // Build prompts
        $language  = $params['language']  ?? 'en';
        $post_type = $params['post_type'] ?? 'post';

        $system_prompt = SystemPrompts::comment_reply($language, $post_type);

        $context_parts = [];
        if (!empty($params['post_title'])) {
            $context_parts[] = "Post title: " . $params['post_title'];
        }
        if (!empty($params['post_excerpt'])) {
            $context_parts[] = "Post context: " . $params['post_excerpt'];
        }
        $context_parts[] = "Comment to reply to:\n" . $params['comment'];

        $user_prompt = implode("\n\n", $context_parts);

        // Call AI
        $ai_helper   = new AIHelper();
        $ai_response = $ai_helper->call_ai($system_prompt, $user_prompt);

        if (!$ai_response['success']) {
            return $this->ai_error_response($response, $ai_response['message'] ?? 'AI generation failed');
        }

        $reply = trim($ai_response['content']);

        // Check if AI flagged as spam/skip
        $decoded = json_decode($reply, true);
        if (isset($decoded['skip']) && $decoded['skip'] === true) {
            return $this->json_response($response, [
                'success' => true,
                'error'   => null,
                'data'    => ['reply' => null, 'skipped' => true, 'reason' => 'Comment flagged as spam or not worth replying to'],
            ]);
        }

        // Deduct credits
        $word_count = $ai_response['word_count'];
        $deduction  = $this->deduct_credits(
            $params['api_key    '],
            $word_count,
            $current_balance,
            'Comment Reply'
        );

        return $this->json_response($response, $this->build_result_response(
            ['reply' => $reply, 'skipped' => false],
            ['balance' => $deduction['balance'], 'balance_error' => $deduction['error']]
        ));
    }
}