<?php

namespace App\Http\Middleware;

use App\Support\JsonApi\JsonApiErrors;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Content negotiation exigido por el estandar JSON:API para todas las rutas /api.
 *
 * Reglas (https://jsonapi.org/format/#content-negotiation):
 *  - 415 si el Content-Type es application/vnd.api+json CON parametros de media type.
 *  - 406 si el Accept incluye application/vnd.api+json y TODAS sus apariciones
 *        llevan parametros de media type.
 *
 * Ademas normaliza el Accept para que Laravel trate la peticion como "espera JSON".
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

        // Hace que $request->expectsJson() y wantsJson() sean verdaderos.
        if (! $request->expectsJson()) {
            $request->headers->set('Accept', $jsonApi);
        }

        return $next($request);
    }
}
