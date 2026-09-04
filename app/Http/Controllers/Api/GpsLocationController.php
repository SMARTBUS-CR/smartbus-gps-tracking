<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGpsLocationRequest;
use App\Http\Resources\GpsLocationResource;
use App\Services\GpsLocationService;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * POST /gps/locations  (HU1) - recibe una coordenada GPS de la app del conductor
 * (via API Gateway) y la persiste para tracking / ETA.
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
}
