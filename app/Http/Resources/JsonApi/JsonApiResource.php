<?php

namespace App\Http\Resources\JsonApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource as BaseJsonApiResource;
use Illuminate\Support\Str;

/**
 * Clase base para TODOS los recursos JSON:API del microservicio GPS.
 *
 * Extiende la implementacion nativa de Laravel 12
 * (Illuminate\Http\Resources\JsonApi\JsonApiResource) y solo centraliza
 * dos convenciones del equipo:
 *
 *  1. El "type" se serializa en kebab-case plural  ->  "gps-locations", "trips".
 *     (Laravel por defecto lo generaria como "GpsLocations".)
 *  2. Todos los recursos comparten la misma version JSON:API.
 *
 * No agrega logica de negocio: sigue siendo 100% el motor nativo de Laravel.
 */
abstract class JsonApiResource extends BaseJsonApiResource
{
    /**
     * "type" del resource object.
     *
     * Deriva del nombre de la clase: GpsLocationResource -> "gps-locations".
     * Sobrescribe este metodo en un resource concreto si necesitas un type distinto.
     */
    public function toType(Request $request): string
    {
        return (string) Str::of(class_basename(static::class))
            ->before('Resource')
            ->kebab()
            ->plural();
    }
}
