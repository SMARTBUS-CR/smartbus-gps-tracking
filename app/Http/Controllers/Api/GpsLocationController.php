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
     * GPS location POST endpoint (HU1).
     * 
     * HU1 — receive one GPS reading, always broadcast it live (HU2), and
     * return:
     *   - 201 with the JSON:API resource, when it was also persisted.
     *   - 202 with no `data` (`meta.persisted: false`), when it was below
     *     the distance/time threshold (config/gps.php) and only broadcast.
     * Both are success responses from the driver app's point of view — it
     * never needs to know or care which one happened.
     */
    public function store(StoreGpsLocationRequest $request): HttpResponse
    {
        $location = $this->service->record($request->toGpsFix());

        if ($location === null) {
            return response()->json([
                'meta' => ['persisted' => false],
            ], Response::HTTP_ACCEPTED)->header('Content-Type', 'application/vnd.api+json');
        }

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
