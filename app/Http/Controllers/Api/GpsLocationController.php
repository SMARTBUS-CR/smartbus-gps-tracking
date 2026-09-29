<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGpsLocationRequest;
use App\Http\Resources\GpsLocationResource;
use App\Services\GpsLocationService;
use App\Support\JsonApi\JsonApiErrors;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * GPS coordinate endpoints (reached through the API Gateway):
 *   POST /api/gps/locations               (HU1) receive a coordinate from the driver.
 *   GET  /api/gps/trips/{tripId}/location (HU3) latest known position of a trip.
 *
 * Thin controller: validate (FormRequest) -> delegate (Service) -> serialize (Resource).
 */
#[Group('GPS Locations', 'GPS readings of the buses. Every response follows the JSON:API standard (`application/vnd.api+json`).')]
class GpsLocationController extends Controller
{
    public function __construct(
        private readonly GpsLocationService $service,
    ) {}

    /**
     * Store a GPS reading
     *
     * Receives one GPS reading from the driver's app and always broadcasts it live
     * on the private channel `trip.{tripId}` (`BusLocationUpdated` event).
     *
     * - **201**: the reading was also persisted; returns the JSON:API resource.
     * - **202**: the reading was only broadcast, because it is too close in distance
     *   and time to the last persisted one (`meta.persisted: false`, no `data`).
     *
     * Both are success responses for the driver's app.
     *
     * @requestMediaType application/vnd.api+json
     */
    #[ResponseDoc(202, 'Broadcast only, not persisted', mediaType: JsonApiErrors::MEDIA_TYPE, type: 'array{meta: array{persisted: false}}')]
    public function store(StoreGpsLocationRequest $request): HttpResponse
    {
        $location = $this->service->record($request->toGpsFix());

        if ($location === null) {
            return response()->json([
                'meta' => ['persisted' => false],
            ], Response::HTTP_ACCEPTED)->header('Content-Type', JsonApiErrors::MEDIA_TYPE);
        }

        return GpsLocationResource::make($location)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Get the latest location of a trip
     *
     * Returns the most recent GPS reading of the trip: the initial position of the
     * passenger's map before the next `BusLocationUpdated` event arrives.
     * Add `?include=trip` to embed the trip under `included`.
     *
     * Returns a JSON:API 404 when the trip does not exist or has no readings yet.
     *
     * @param  string  $tripId  UUID of the trip.
     */
    public function latestForTrip(string $tripId): HttpResponse
    {
        // A non-UUID can never match a trip (and would make Postgres throw).
        abort_unless(Str::isUuid($tripId), Response::HTTP_NOT_FOUND);

        $location = $this->service->latestForTrip($tripId);

        return GpsLocationResource::make($location)->response();
    }
}
