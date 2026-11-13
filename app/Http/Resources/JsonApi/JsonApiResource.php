<?php

namespace App\Http\Resources\JsonApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource as BaseJsonApiResource;
use Illuminate\Support\Str;

/**
 * Base class for every JSON:API resource of the GPS microservice.
 *
 * Extends Laravel 12's native JSON:API resource
 * (Illuminate\Http\Resources\JsonApi\JsonApiResource) and only centralizes one
 * team convention: the resource `type` is serialized as kebab-case plural
 * ("gps-locations", "trips") instead of Laravel's default "GpsLocations".
 *
 * It adds no business logic — the serialization stays 100% the native engine.
 */
abstract class JsonApiResource extends BaseJsonApiResource
{
    /**
     * The `type` of the resource object.
     *
     * Derived from the class name: GpsLocationResource -> "gps-locations".
     * Override this in a concrete resource if it needs a different type.
     */
    public function toType(Request $request): string
    {
        return (string) Str::of(class_basename(static::class))
            ->before('Resource')
            ->kebab()
            ->plural();
    }
}
