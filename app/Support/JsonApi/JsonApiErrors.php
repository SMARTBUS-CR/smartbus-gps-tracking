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
 * Builds error documents that follow the JSON:API standard.
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
 * Wired in bootstrap/app.php (->withExceptions) so NO error response of the
 * microservice ever leaves as HTML or as Laravel's arbitrary JSON.
 */
final class JsonApiErrors
{
    public const MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * Turns any exception into a JSON:API error response.
     */
    public static function fromThrowable(Throwable $e, bool $debug = false): JsonResponse
    {
        // Laravel converts ModelNotFoundException into NotFoundHttpException before
        // it reaches here, carrying an internal message ("No query results for
        // model [App\Models\X]") that must not leak to the client.
        $isModelNotFound = $e instanceof ModelNotFoundException
            || $e->getPrevious() instanceof ModelNotFoundException;

        return match (true) {
            $e instanceof ValidationException => self::fromValidation($e),
            $isModelNotFound => self::make(404, 'Not Found', 'The requested resource does not exist.'),
            $e instanceof AuthenticationException => self::make(401, 'Unauthorized', 'Authentication is required.'),
            $e instanceof AuthorizationException => self::make(403, 'Forbidden', 'This action is not authorized.'),
            $e instanceof HttpExceptionInterface => self::make(
                $e->getStatusCode(),
                self::reason($e->getStatusCode()),
                $e->getStatusCode() >= 500 ? self::reason($e->getStatusCode()) : ($e->getMessage() ?: self::reason($e->getStatusCode())),
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
     * Error document built from a ValidationException (422).
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
     * Single-object error document.
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
     * Wraps the list of errors and sets the JSON:API Content-Type.
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
     * JSON pointer to the failing member.
     *
     *   "latitude"                 -> /data/attributes/latitude
     *   "data.attributes.latitude" -> /data/attributes/latitude
     *   "data.type"                -> /data/type
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
