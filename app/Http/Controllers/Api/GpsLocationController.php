<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGpsLocationRequest;
use App\Http\Resources\GpsLocationResource;
use App\Services\GpsLocationService;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Endpoints de coordenadas GPS (via API Gateway):
 *   POST /api/gps/locations             (HU1) recibe una coordenada del conductor.
 *   GET  /api/gps/trips/{tripId}/location (HU3) ultima posicion conocida del viaje.
 *
 * El controller es delgado: valida (FormRequest) -> delega (Service) -> serializa (Resource).
 */
class GpsLocationController extends Controller
{
    public function __construct(
        private readonly GpsLocationService $service,
    ) {}

    public function store(StoreGpsLocationRequest $request): HttpResponse
    {
        $location = $this->service->record($request->toGpsFix());

        return GpsLocationResource::make($location)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function latestForTrip(string $tripId): HttpResponse
    {
        abort_unless(ctype_digit($tripId), Response::HTTP_NOT_FOUND);

        $location = $this->service->latestForTrip((int) $tripId);

        return GpsLocationResource::make($location)->response();
    }
}
