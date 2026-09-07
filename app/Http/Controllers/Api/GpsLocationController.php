<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGpsLocationRequest;
use App\Http\Resources\GpsLocationResource;
use App\Services\GpsLocationService;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * GPS coordinate endpoints (reached through the API Gateway):
 *   POST /api/gps/locations              (HU1) receive a coordinate from the driver.
 *   GET  /api/gps/trips/{tripId}/location (HU3) latest known position of a trip.
 *
 * Thin controller: validate (FormRequest) -> delegate (Service) -> serialize (Resource).
 */
class GpsLocationController extends Controller
{
    public function __construct(
        private readonly GpsLocationService $service,
    ) {}

    /**
     * HU1 — store one GPS reading and return it as a JSON:API resource (201).
     */
    public function store(StoreGpsLocationRequest $request): HttpResponse
    {
        $location = $this->service->record($request->toGpsFix());

        return GpsLocationResource::make($location)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * HU3 — return the most recent GPS reading of the trip, or 404 if there is none.
     */
    public function latestForTrip(string $tripId): HttpResponse
    {
        // Route param is a string; a non-numeric id can never match a trip.
        abort_unless(ctype_digit($tripId), Response::HTTP_NOT_FOUND);

        $location = $this->service->latestForTrip((int) $tripId);

        return GpsLocationResource::make($location)->response();
    }
}
