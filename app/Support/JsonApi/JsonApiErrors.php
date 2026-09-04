<?php

namespace App\Support\JsonApi;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Construye documentos de error segun el estandar JSON:API.
 *
 *   {
 *     "errors": [
 *       {
 *         "status": "422",
 *         "title":  "Unprocessable Entity",
 *         "detail": "The latitude field is required.",
 *         "source": { "pointer": "/data/attributes/latitude" }
 *       }
 *     ]
 *   }
 *
 * Se usa desde bootstrap/app.php (->withExceptions) para que NINGUNA respuesta
 * de error del microservicio salga como HTML o como JSON arbitrario de Laravel.
 */
final class JsonApiErrors
{
    public const MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * Convierte cualquier excepcion en una respuesta JSON:API.
     */
    public static function fromThrowable(Throwable $e, bool $debug = false): JsonResponse
    {
        return match (true) {
            $e instanceof ValidationException      => self::fromValidation($e),
            $e instanceof ModelNotFoundException   => self::make(404, 'Not Found', 'The requested resource does not exist.'),
            $e instanceof AuthenticationException  => self::make(401, 'Unauthorized', 'Authentication is required.'),
            $e instanceof AuthorizationException   => self::make(403, 'Forbidden', 'This action is not authorized.'),
            $e instanceof HttpExceptionInterface   => self::make(
                $e->getStatusCode(),
                self::reason($e->getStatusCode()),
                $e->getMessage() ?: self::reason($e->getStatusCode()),
            ),
            default => self::make(
                500,
                'Internal Server Error',
                $debug ? $e->getMessage() : 'An unexpected error occurred.',
                $debug ? ['exception' => $e::class, 'line' => $e->getLine(), 'file' => $e->getFile()] : [],
            ),
        };
    }

    /**
     * Documento de error a partir de una ValidationException (422).
     */
    public static function fromValidation(ValidationException $e): JsonResponse
    {
        $errors = [];

        foreach ($e->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $errors[] = [
                    'status' => '422',
                    'title' => 'Unprocessable Entity',
                    'detail' => $message,
                    'source' => ['pointer' => self::pointerFor($field)],
                ];
            }
        }

        return self::document($errors, 422);
    }

    /**
     * Documento de error de un solo objeto.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function make(int $status, string $title, string $detail, array $meta = []): JsonResponse
    {
        $error = [
            'status' => (string) $status,
            'title' => $title,
            'detail' => $detail,
        ];

        if ($meta !== []) {
            $error['meta'] = $meta;
        }

        return self::document([$error], $status);
    }

    /**
     * Envuelve la lista de errores y fija el Content-Type JSON:API.
     *
     * @param  array<int, array<string, mixed>>  $errors
     */
    public static function document(array $errors, int $status): JsonResponse
    {
        return new JsonResponse(
            ['errors' => $errors],
            $status,
            ['Content-Type' => self::MEDIA_TYPE],
        );
    }

    /**
     * JSON pointer al miembro que falla.
     *
     *   "latitude"                     -> /data/attributes/latitude
     *   "data.attributes.latitude"     -> /data/attributes/latitude
     *   "data.type"                    -> /data/type
     */
    private static function pointerFor(string $field): string
    {
        if ($field === 'data' || str_starts_with($field, 'data.')) {
            return '/'.str_replace('.', '/', $field);
        }

        return '/data/attributes/'.str_replace('.', '/', $field);
    }

    private static function reason(int $status): string
    {
        return Response::$statusTexts[$status] ?? 'Error';
    }
}
