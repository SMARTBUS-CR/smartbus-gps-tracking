<?php

namespace App\Support\JsonApi\Scramble;

use App\Support\JsonApi\JsonApiErrors;
use Dedoc\Scramble\Extensions\ExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types as OpenApi;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Documents validation (422) and not-found (404) errors as JSON:API error
 * documents, which is what App\Support\JsonApi\JsonApiErrors actually renders.
 *
 * Scramble's built-in extensions describe Laravel's default `{message, errors}`
 * shape instead; this one is registered later (config/scramble.php) so it wins.
 */
class JsonApiErrorResponseExtension extends ExceptionToResponseExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType && $this->statusFor($type) !== null;
    }

    public function toResponse(Type $type): ?Response
    {
        if (! $type instanceof ObjectType || ! $status = $this->statusFor($type)) {
            return null;
        }

        [$description, $title] = $status === 422
            ? ['Validation error', 'Unprocessable Entity']
            : ['Resource not found', 'Not Found'];

        return Response::make($status)
            ->setDescription($description)
            ->setContent(JsonApiErrors::MEDIA_TYPE, Schema::fromType($this->errorDocument($status, $title)));
    }

    public function reference(ObjectType $type): Reference
    {
        return new Reference('responses', 'JsonApi'.Str::afterLast($type->name, '\\'), $this->components);
    }

    private function statusFor(ObjectType $type): ?int
    {
        return match (true) {
            $type->isInstanceOf(ValidationException::class) => 422,
            $type->isInstanceOf(RecordsNotFoundException::class),
            $type->isInstanceOf(NotFoundHttpException::class) => 404,
            default => null,
        };
    }

    private function errorDocument(int $status, string $title): OpenApi\ObjectType
    {
        $error = (new OpenApi\ObjectType)
            ->addProperty('status', (new OpenApi\StringType)->example((string) $status))
            ->addProperty('title', (new OpenApi\StringType)->example($title))
            ->addProperty('detail', (new OpenApi\StringType)->setDescription('Human-readable explanation of the error.'))
            ->setRequired(['status', 'title', 'detail']);

        if ($status === 422) {
            $error->addProperty('source', (new OpenApi\ObjectType)
                ->addProperty('pointer', (new OpenApi\StringType)
                    ->setDescription('JSON Pointer to the invalid member of the request document.')
                    ->example('/data/attributes/latitude'))
                ->setRequired(['pointer']));
        }

        return (new OpenApi\ObjectType)
            ->addProperty('errors', (new OpenApi\ArrayType)->setItems($error))
            ->setRequired(['errors']);
    }
}
