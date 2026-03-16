<?php

namespace App\Controllers;

use App\Helpers\AIHelper;
use App\Helpers\Env;
use App\Traits\JsonResponseTrait;
use App\Traits\CreditMiddlewareTrait;
use App\Traits\RequestParserTrait;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Handles AI-powered image generation.
 *
 * Uses the OpenAI-compatible images.generate endpoint configured via
 * NAGATHEME_IMAGE_GENERATION_ENDPOINT and NAGATHEME_IMAGE_GENERATION_MODEL.
 */
class ImageGenerationController
{
    use JsonResponseTrait;
    use CreditMiddlewareTrait;
    use RequestParserTrait;

    /** Valid image sizes accepted by the API. */
    const VALID_SIZES = ['256x256', '512x512', '1024x1024', '1792x1024', '1024x1792'];

    /**
     * Generate an image from a text prompt.
     *
     * POST body:
     *   api_key   string (required)
     *   prompt    string (required) Text description of the desired image
     *   size      string (optional) Image dimensions, default "1024x1024"
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

        $prompt = trim($params['prompt']);
        if ($prompt === '') {
            return $this->json_response($response, [
                'success' => false,
                'error'   => ['code' => 'invalid_parameter', 'message' => 'prompt must not be empty.'],
                'data'    => null,
            ], 400);
        }

        $size = $params['size'] ?? '1024x1024';
        if (!in_array($size, self::VALID_SIZES, true)) {
            return $this->json_response($response, [
                'success' => false,
                'error'   => [
                    'code'    => 'invalid_parameter',
                    'message' => 'Invalid size. Allowed: ' . implode(', ', self::VALID_SIZES),
                ],
                'data' => null,
            ], 400);
        }

        $credit_cost = Env::getInt('IMAGE_GENERATION_CREDIT_COST', 50);

        $credit_check = $this->verify_credits($response, $params['api_key']);
        if (!$credit_check['ok']) {
            return $credit_check['response'];
        }
        $current_balance = $credit_check['balance'];

        if ($current_balance < $credit_cost) {
            return $this->json_response($response, [
                'success' => false,
                'error'   => [
                    'code'    => 'insufficient_credits',
                    'message' => "You need at least {$credit_cost} credits to generate an image.",
                ],
                'data' => null,
            ], 403);
        }

        $ai_helper = new AIHelper();
        $result    = $ai_helper->generate_image($prompt, ['size' => $size]);

        if (!$result['success']) {
            return $this->ai_error_response($response, $result['message'] ?? 'Image generation failed.');
        }

        $deduction = $this->deduct_credits(
            $params['api_key'],
            $credit_cost,
            $current_balance,
            "Image Generation ({$size})"
        );

        $response_data = [
            'url'    => $result['url'],
            'prompt' => $prompt,
            'size'          => $result['size'],
            'output_format' => $result['output_format'],
        ];

        return $this->json_response($response, $this->build_result_response(
            $response_data,
            ['balance' => $deduction['balance'], 'balance_error' => $deduction['error']]
        ));
    }
}
