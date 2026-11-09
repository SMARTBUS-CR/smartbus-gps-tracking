<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TripResource;
use App\Models\Trip;
use App\Services\TripTrackingService;
use App\Support\JsonApi\JsonApiErrors;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Trip execution by its assigned driver (reached through the API Gateway):
 *   POST /api/gps/trips/{tripId}/start   "Iniciar recorrido"
 *   POST /api/gps/trips/{tripId}/finish  "Terminar recorrido"
 */
#[Group('Trips', 'Start and finish of a trip by its assigned driver. Every response follows the JSON:API standard.')]
class TripController extends Controller
{
    /** JSON:API error document (401 / 403 / 404 / 409). */
    private const ERROR_DOC = 'array{errors: list<array{status: string, title: string, detail: string}>}';

    public function __construct(
        private readonly TripTrackingService $tracking,
    ) {}

    /**
     * Start a trip
     *
     * The driver assigned to the trip starts it: `scheduled` -> `in_progress`
     * (sets `actual_departure_at`). From now on the driver can send GPS readings.
     *
     * @param  string  $tripId  UUID of the trip.
     */
    #[ResponseDoc(401, 'Missing or invalid token', mediaType: JsonApiErrors::MEDIA_TYPE, type: self::ERROR_DOC)]
    #[ResponseDoc(403, 'Not the driver assigned to the trip', mediaType: JsonApiErrors::MEDIA_TYPE, type: self::ERROR_DOC)]
    #[ResponseDoc(409, 'The trip is not scheduled', mediaType: JsonApiErrors::MEDIA_TYPE, type: self::ERROR_DOC)]
    public function start(Request $request, string $tripId): HttpResponse
    {
        $trip = $this->tracking->start($this->findTrip($tripId), $request->user());

        return TripResource::make($trip)->response();
    }

    /**
     * Finish a trip
     *
     * The driver assigned to the trip finishes it: `in_progress` -> `completed`
     * (sets `actual_arrival_at`). Passengers following it receive a
     * `TripTrackingEnded` event, and new GPS readings are rejected.
     *
     * @param  string  $tripId  UUID of the trip.
     */
    #[ResponseDoc(401, 'Missing or invalid token', mediaType: JsonApiErrors::MEDIA_TYPE, type: self::ERROR_DOC)]
    #[ResponseDoc(403, 'Not the driver assigned to the trip', mediaType: JsonApiErrors::MEDIA_TYPE, type: self::ERROR_DOC)]
    #[ResponseDoc(409, 'The trip is not in progress', mediaType: JsonApiErrors::MEDIA_TYPE, type: self::ERROR_DOC)]
    public function finish(Request $request, string $tripId): HttpResponse
    {
        $trip = $this->tracking->finish($this->findTrip($tripId), $request->user());

        return TripResource::make($trip)->response();
    }

    private function findTrip(string $tripId): Trip
    {
        // A non-UUID can never match a trip (and would make Postgres throw).
        abort_unless(Str::isUuid($tripId), Response::HTTP_NOT_FOUND);

        return Trip::query()->findOrFail($tripId);
    }
}
