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
 * Handles the AI Content Generator Gutenberg block requests.
 */
class ContentGeneratorController
{
    use JsonResponseTrait;
    use CreditMiddlewareTrait;
    use RequestParserTrait;

    /**
     * Generate content based on a prompt.
     *
     * POST body:
     *   api_key      string  (required)
     *   prompt       string  (required) What to write
     *   tone         string  (optional) professional|casual|persuasive|creative|technical|seo
     *   word_count   int     (optional) Target word count
     *   language     string  (optional) ISO language code
     *   context      string  (optional) Additional context about the site/page
     *
     * @param Request  $request
     * @param Response $response
     *
     * @return Response
     */
    public function generate(Request $request, Response $response): Response
    {
        $params = $this->parse_request_body($request);

        $validation = $this->validate_required($params, ['api_key', 'prompt']);
        if ($validation !== true) {
            return $this->json_response($response, $validation, 400);
        }

        $credit_check = $this->verify_credits($response, $params['api_key']);
        if (!$credit_check['ok']) {
            return $credit_check['response'];
        }
        $current_balance = $credit_check['balance'];

        $tone      = $params['tone']      ?? 'professional';
        $language  = $params['language']  ?? 'en';
        $word_count = isset($params['word_count']) ? (int) $params['word_count'] : null;

        $system_prompt = SystemPrompts::content_generator($tone, $language);

        $user_parts = [$params['prompt']];
        if ($word_count) {
            $user_parts[] = "Target length: approximately {$word_count} words.";
        }
        if (!empty($params['context'])) {
            $user_parts[] = "Additional context: " . $params['context'];
        }

        $user_prompt = implode("\n\n", $user_parts);

        $ai_helper   = new AIHelper();
        $ai_response = $ai_helper->call_ai($system_prompt, $user_prompt);

        if (!$ai_response['success']) {
            return $this->ai_error_response($response, $ai_response['message'] ?? 'AI generation failed');
        }

        $content      = trim($ai_response['content']);
        $actual_words = $ai_response['word_count'];

        $deduction = $this->deduct_credits(
            $params['api_key'],
            $actual_words,
            $current_balance,
            'Content Generator – ' . substr($params['prompt'], 0, 50)
        );

        return $this->json_response($response, $this->build_result_response(
            ['content' => $content, 'word_count' => $actual_words],
            ['balance' => $deduction['balance'], 'balance_error' => $deduction['error']]
        ));
    }

    /**
     * Generate an SEO meta description.
     *
     * POST body:
     *   api_key     string (required)
     *   content     string (required) Post content or title to base the meta on
     *   keyword     string (optional) Focus keyword
     *   language    string (optional)
     *
     * @param Request  $request
     * @param Response $response
     *
     * @return Response
     */
    public function meta_description(Request $request, Response $response): Response
    {
        $params = $this->parse_request_body($request);

        $validation = $this->validate_required($params, ['api_key', 'content']);
        if ($validation !== true) {
            return $this->json_response($response, $validation, 400);
        }

        $credit_check = $this->verify_credits($response, $params['api_key']);
        if (!$credit_check['ok']) {
            return $credit_check['response'];
        }
        $current_balance = $credit_check['balance'];

        $language      = $params['language'] ?? 'en';
        $system_prompt = SystemPrompts::seo_meta($language);

        $user_prompt = $params['content'];
        if (!empty($params['keyword'])) {
            $user_prompt = "Focus keyword: {$params['keyword']}\n\n" . $user_prompt;
        }

        $ai_helper   = new AIHelper();
        $ai_response = $ai_helper->call_ai($system_prompt, $user_prompt);

        if (!$ai_response['success']) {
            return $this->ai_error_response($response, $ai_response['message'] ?? 'AI generation failed');
        }

        $meta       = trim($ai_response['content']);
        $word_count = $ai_response['word_count'];

        $deduction = $this->deduct_credits(
            $params['api_key'],
            $word_count,
            $current_balance,
            'Meta Description'
        );

        return $this->json_response($response, $this->build_result_response(
            ['meta_description' => $meta, 'char_count' => strlen($meta)],
            ['balance' => $deduction['balance'], 'balance_error' => $deduction['error']]
        ));
    }

    /**
     * Generate a post excerpt.
     *
     * POST body:
     *   api_key     string (required)
     *   content     string (required) Full post content
     *   max_words   int    (optional) Default 55
     *   language    string (optional)
     *
     * @param Request  $request
     * @param Response $response
     *
     * @return Response
     */
    public function excerpt(Request $request, Response $response): Response
    {
        $params = $this->parse_request_body($request);

        $validation = $this->validate_required($params, ['api_key', 'content']);
        if ($validation !== true) {
            return $this->json_response($response, $validation, 400);
        }

        $credit_check = $this->verify_credits($response, $params['api_key']);
        if (!$credit_check['ok']) {
            return $credit_check['response'];
        }
        $current_balance = $credit_check['balance'];

        $max_words     = isset($params['max_words']) ? (int) $params['max_words'] : 55;
        $language      = $params['language'] ?? 'en';
        $system_prompt = SystemPrompts::excerpt($max_words, $language);

        $ai_helper   = new AIHelper();
        $ai_response = $ai_helper->call_ai($system_prompt, $params['content']);

        if (!$ai_response['success']) {
            return $this->ai_error_response($response, $ai_response['message'] ?? 'AI generation failed');
        }

        $excerpt    = trim($ai_response['content']);
        $word_count = $ai_response['word_count'];

        $deduction = $this->deduct_credits(
            $params['api_key'],
            $word_count,
            $current_balance,
            'Excerpt Generator'
        );

        return $this->json_response($response, $this->build_result_response(
            ['excerpt' => $excerpt],
            ['balance' => $deduction['balance'], 'balance_error' => $deduction['error']]
        ));
    }
}
