<?php

namespace App\Traits;

use Psr\Http\Message\ResponseInterface as Response;

trait JsonResponseTrait
{
    /**
     * Send JSON response.
     *
     * @param Response $response Response object.
     * @param array    $data     Response data.
     * @param int      $status   HTTP status code.
     *
     * @return Response
     */
    protected function json_response(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
