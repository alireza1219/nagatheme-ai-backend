<?php

namespace App\Traits;

use Psr\Http\Message\ServerRequestInterface as Request;


trait RequestParserTrait
{
    public function parse_get_request_params(Request $request, array $array_params = []): array
    {
        $params = $request->getQueryParams() ?? [];

        foreach ($array_params as $key) {
            if (isset($params[$key]) && !is_array($params[$key])) {
                $params[$key] = [$params[$key]];
            }
        }

        return $params;
    }

    public function parse_request_body(Request $request, array $array_params = []): array
    {
        $content_type = $request->getHeaderLine('Content-Type');

        if (str_contains($content_type, 'application/json')) {
            return json_decode((string) $request->getBody(), true) ?? [];
        }

        $params = $request->getParsedBody() ?? [];

        foreach ($array_params as $key) {
            if (isset($params[$key]) && !is_array($params[$key])) {
                $params[$key] = [$params[$key]];
            }
        }

        return $params;
    }

    public function validate_required(array $params, array $required): bool|array
    {
        foreach ($required as $param) {
            if (empty($params[$param])) {
                return [
                    'success' => false,
                    'error'   => [
                        'code'    => 'missing_parameter',
                        'message' => "Missing required parameter: {$param}",
                    ],
                    'data' => null,
                ];
            }
        }

        return true;
    }
}