<?php

namespace App\Http\Middleware;

use App\Support\JsonApi\JsonApiErrors;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * JSON:API content negotiation for every route in the `api` group.
 *
 * Rules (https://jsonapi.org/format/#content-negotiation):
 *  - 415 when the Content-Type is application/vnd.api+json WITH media type parameters.
 *  - 406 when the Accept header mentions application/vnd.api+json and every
 *        occurrence of it carries media type parameters.
 *
 * It also normalizes the Accept header so Laravel treats the request as
 * "expects JSON".
 */
class NegotiatesJsonApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $jsonApi = JsonApiErrors::MEDIA_TYPE;

        $contentType = $request->headers->get('Content-Type');
        if ($contentType !== null && str_starts_with($contentType, $jsonApi) && str_contains($contentType, ';')) {
            return JsonApiErrors::make(415, 'Unsupported Media Type',
                'The JSON:API media type must be sent without media type parameters.');
        }

        $accepts = $request->getAcceptableContentTypes();
        $mentionsJsonApi = false;
        $hasCleanJsonApi = false;
        foreach ($accepts as $accept) {
            if ($accept === $jsonApi) {
                $mentionsJsonApi = true;
                $hasCleanJsonApi = true;
            } elseif (str_starts_with($accept, $jsonApi)) {
                $mentionsJsonApi = true;
            }
        }
        if ($mentionsJsonApi && ! $hasCleanJsonApi) {
            return JsonApiErrors::make(406, 'Not Acceptable',
                'The JSON:API media type in the Accept header must be sent without media type parameters.');
        }

        // Make $request->expectsJson() / wantsJson() return true.
        if (! $request->expectsJson()) {
            $request->headers->set('Accept', $jsonApi);
        }

        return $next($request);
    }
}
