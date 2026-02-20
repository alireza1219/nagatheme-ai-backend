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
 * Handles AI-powered image alt text generation.
 *
 * Accepts either:
 *   (a) a base64-encoded image (sent directly)
 *   (b) a publicly accessible image URL (fetched and encoded server-side)
 */
class ImageAltController
{
    use JsonResponseTrait;
    use CreditMiddlewareTrait;
    use RequestParserTrait;

    /** Maximum image file size to fetch remotely (5 MB). */
    const MAX_REMOTE_BYTES = 5_242_880;

    /**
     * Generate alt text for an image.
     *
     * POST body (one of image_url OR image_data is required):
     *   license_key  string (required)
     *   image_url    string (optional) Public URL of the image
     *   image_data   string (optional) Base64 data URI (data:image/...;base64,...)
     *   language     string (optional) ISO language code, default 'en'
     *   site_context string (optional) Describe the site for better alt text
     *
     * @param Request  $request
     * @param Response $response
     *
     * @return Response
     */
    public function generate(Request $request, Response $response): Response
    {
        $params = $this->parse_request_body($request);

        $validation = $this->validate_required($params, ['license_key']);
        if ($validation !== true) {
            return $this->json_response($response, $validation, 400);
        }

        if (empty($params['image_url']) && empty($params['image_data'])) {
            return $this->json_response($response, [
                'success' => false,
                'error'   => ['code' => 'missing_parameter', 'message' => 'Provide either image_url or image_data.'],
                'data'    => null,
            ], 400);
        }

        $credit_check = $this->verify_credits($response, $params['license_key']);
        if (!$credit_check['ok']) {
            return $credit_check['response'];
        }
        $current_balance = $credit_check['balance'];

        // Resolve image data
        if (!empty($params['image_data'])) {
            $image_data = $params['image_data'];
        } else {
            $fetch = $this->fetch_remote_image($params['image_url']);
            if (!$fetch['success']) {
                return $this->json_response($response, [
                    'success' => false,
                    'error'   => ['code' => 'image_fetch_error', 'message' => $fetch['message']],
                    'data'    => null,
                ], 422);
            }
            $image_data = $fetch['data_uri'];
        }

        $language      = $params['language']     ?? 'en';
        $site_context  = $params['site_context'] ?? '';
        $system_prompt = SystemPrompts::image_alt($language, $site_context);
        $user_prompt   = 'Generate the alt text for this image.';

        $ai_helper   = new AIHelper();
        $ai_response = $ai_helper->call_ai_with_vision($system_prompt, $user_prompt, $image_data);

        if (!$ai_response['success']) {
            return $this->ai_error_response($response, $ai_response['message'] ?? 'Vision AI failed');
        }

        $alt_text  = trim($ai_response['content'], " \t\n\r\"\u{0022}");
        $word_count = $ai_response['word_count'];

        // Decorative image — no credits charged
        if ($alt_text === '') {
            return $this->json_response($response, $this->build_result_response(
                ['alt_text' => '', 'decorative' => true],
                ['balance' => $current_balance, 'balance_error' => '']
            ));
        }

        $deduction = $this->deduct_credits(
            $params['license_key'],
            max($word_count, 1), // Charge at least 1 credit
            $current_balance,
            'Image Alt Generation'
        );

        return $this->json_response($response, $this->build_result_response(
            ['alt_text' => $alt_text, 'decorative' => false],
            ['balance' => $deduction['balance'], 'balance_error' => $deduction['error']]
        ));
    }

    /**
     * Batch generate alt text for multiple images.
     *
     * POST body:
     *   license_key string  (required)
     *   images      array   (required) Array of {id, image_url} or {id, image_data}
     *   language    string  (optional)
     *
     * @param Request  $request
     * @param Response $response
     *
     * @return Response
     */
    public function batch(Request $request, Response $response): Response
    {
        $params = $this->parse_request_body($request, ['images']);

        $validation = $this->validate_required($params, ['license_key', 'images']);
        if ($validation !== true) {
            return $this->json_response($response, $validation, 400);
        }

        if (!is_array($params['images']) || count($params['images']) === 0) {
            return $this->json_response($response, [
                'success' => false,
                'error'   => ['code' => 'invalid_parameter', 'message' => 'images must be a non-empty array.'],
                'data'    => null,
            ], 400);
        }

        if (count($params['images']) > 20) {
            return $this->json_response($response, [
                'success' => false,
                'error'   => ['code' => 'limit_exceeded', 'message' => 'Maximum 20 images per batch request.'],
                'data'    => null,
            ], 400);
        }

        $credit_check = $this->verify_credits($response, $params['license_key']);
        if (!$credit_check['ok']) {
            return $credit_check['response'];
        }
        $current_balance = $credit_check['balance'];

        $language      = $params['language'] ?? 'en';
        $system_prompt = SystemPrompts::image_alt($language);
        $user_prompt   = 'Generate the alt text for this image.';
        $ai_helper     = new AIHelper();

        $results         = [];
        $total_words     = 0;

        foreach ($params['images'] as $image_item) {
            $id = $image_item['id'] ?? null;

            if (!empty($image_item['image_data'])) {
                $image_data = $image_item['image_data'];
            } elseif (!empty($image_item['image_url'])) {
                $fetch = $this->fetch_remote_image($image_item['image_url']);
                if (!$fetch['success']) {
                    $results[] = ['id' => $id, 'success' => false, 'error' => $fetch['message']];
                    continue;
                }
                $image_data = $fetch['data_uri'];
            } else {
                $results[] = ['id' => $id, 'success' => false, 'error' => 'No image provided.'];
                continue;
            }

            $ai_response = $ai_helper->call_ai_with_vision($system_prompt, $user_prompt, $image_data);

            if (!$ai_response['success']) {
                $results[] = ['id' => $id, 'success' => false, 'error' => $ai_response['message']];
                continue;
            }

            $alt_text    = trim($ai_response['content'], " \t\n\r\"");
            $total_words += max($ai_response['word_count'], 1);

            $results[] = [
                'id'         => $id,
                'success'    => true,
                'alt_text'   => $alt_text,
                'decorative' => $alt_text === '',
            ];
        }

        $deduction = $this->deduct_credits(
            $params['license_key'],
            $total_words,
            $current_balance,
            'Image Alt Batch (' . count($params['images']) . ' images)'
        );

        return $this->json_response($response, $this->build_result_response(
            ['results' => $results, 'total_processed' => count($results)],
            ['balance' => $deduction['balance'], 'balance_error' => $deduction['error']]
        ));
    }

    /**
     * Fetch a remote image and convert it to a base64 data URI.
     *
     * @param string $url The public image URL.
     *
     * @return array {success, data_uri, message}
     */
    private function fetch_remote_image(string $url): array
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'message' => 'Invalid image URL.'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'NagathemeAI/1.0 ImageAltBot',
        ]);

        $body      = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $mime_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error || $http_code !== 200) {
            return ['success' => false, 'message' => "Could not fetch image (HTTP {$http_code}): {$curl_error}"];
        }

        if (strlen($body) > self::MAX_REMOTE_BYTES) {
            return ['success' => false, 'message' => 'Image too large (max 5 MB).'];
        }

        // Normalise MIME type (strip charset etc.)
        $mime_type = strtolower(explode(';', $mime_type)[0]);
        $allowed   = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        if (!in_array($mime_type, $allowed, true)) {
            return ['success' => false, 'message' => "Unsupported image type: {$mime_type}"];
        }

        $data_uri = "data:{$mime_type};base64," . base64_encode($body);

        return ['success' => true, 'data_uri' => $data_uri];
    }
}
