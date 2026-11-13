<?php

namespace App\Http\Controllers\Api;

use App\Data\TripTrackingState;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGpsLocationRequest;
use App\Http\Resources\GpsLocationResource;
use App\Models\Trip;
use App\Services\EtaOrchestrationService;
use App\Services\GpsLocationService;
use App\Services\TripPositionStore;
use App\Support\JsonApi\JsonApiErrors;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * GPS endpoints (reached through the API Gateway):
 *   POST /api/gps/locations               the assigned driver sends a reading.
 *   GET  /api/gps/trips/{tripId}/location current position of a trip (passenger map).
 *
 * Thin controller: validate (FormRequest) -> delegate (Service) -> serialize.
 */
#[Group('GPS Locations', 'GPS readings of the buses. Every response follows the JSON:API standard (`application/vnd.api+json`).')]
class GpsLocationController extends Controller
{
    /** JSON:API error document (401 / 403 / 409). */
    private const ERROR_DOC = 'array{errors: list<array{status: string, title: string, detail: string}>}';

    public function __construct(
        private readonly GpsLocationService $service,
        private readonly TripPositionStore $positions,
        private readonly EtaOrchestrationService $eta,
    ) {}

    /**
     * Store a GPS reading
     *
     * Receives one GPS reading from the driver's app. Only the **driver assigned**
     * to the trip can send it, and only while the trip is **in progress**.
     * Accepted readings are broadcast live on the private channel `trip.{tripId}`
     * (`BusLocationUpdated` event).
     *
     * Only the **start** and the **end** of each trip are stored: its first reading,
     * and the reading sent with `final: true` (right before the driver finishes the
     * trip). Every accepted reading is broadcast, stored or not.
     *
     * - **201**: broadcast and stored, the trip's start or end (JSON:API resource).
     * - **202** `meta.outcome = broadcast_only`: broadcast, not stored (a reading
     *   during the trip: neither the first one nor `final`).
     * - **202** `meta.outcome = duplicate | stale`: ignored. The reading was already
     *   processed, or it was taken before the current position (it arrived late) and
     *   must not move the bus back.
     *
     * All of them are success responses for the driver's app (no retry needed).
     *
     * @requestMediaType application/vnd.api+json
     */
    #[ResponseDoc(202, 'Accepted but not stored', mediaType: JsonApiErrors::MEDIA_TYPE, type: "array{meta: array{persisted: false, outcome: 'broadcast_only'|'duplicate'|'stale'}}")]
    #[ResponseDoc(401, 'Missing or invalid token', mediaType: JsonApiErrors::MEDIA_TYPE, type: self::ERROR_DOC)]
    #[ResponseDoc(403, 'Not the driver assigned to the trip', mediaType: JsonApiErrors::MEDIA_TYPE, type: self::ERROR_DOC)]
    #[ResponseDoc(404, 'The trip does not exist', mediaType: JsonApiErrors::MEDIA_TYPE, type: self::ERROR_DOC)]
    #[ResponseDoc(409, 'The trip is not in progress', mediaType: JsonApiErrors::MEDIA_TYPE, type: self::ERROR_DOC)]
    public function store(StoreGpsLocationRequest $request): HttpResponse
    {
        $fix = $request->toGpsFix();

        $result = $this->service->record($fix, $request->user());

        // The ETA watcher (separate process) calls the Dashboard and the ETA
        // service on behalf of the driver: it needs a current token. Local
        // cache only, no network: it never slows down or breaks this request.
        if (! $result->wasIgnored()) {
            $this->eta->rememberDriverToken($fix->tripId, $request->bearerToken());
        }

        if ($result->location !== null) {
            return GpsLocationResource::make($result->location)
                ->response()
                ->setStatusCode(Response::HTTP_CREATED);
        }

        return response()->json([
            'meta' => ['persisted' => false, 'outcome' => $result->outcome],
        ], Response::HTTP_ACCEPTED)->header('Content-Type', JsonApiErrors::MEDIA_TYPE);
    }

    /**
     * Get the current location of a trip
     *
     * What the passenger's map needs when it opens (or reconnects): the trip
     * status, the bus's current position and how fresh it is. Then the map keeps
     * it updated with the `BusLocationUpdated` events. When updates stop
     * (`no_signal`), call it again: `status = completed` means the trip ended.
     *
     * `freshness`:
     * - `live`: position taken in the last 30 s.
     * - `stale`: taken in the last 5 min (weak signal).
     * - `no_signal`: no position yet, or older than 5 min.
     * - `not_tracking`: the trip is not running (scheduled, completed or cancelled).
     *
     * `position` is `null` until the bus sends its first reading.
     * Returns a JSON:API 404 when the trip does not exist.
     *
     * @param  string  $tripId  UUID of the trip.
     */
    #[ResponseDoc(200, 'Tracking state of the trip', mediaType: JsonApiErrors::MEDIA_TYPE, type: "array{data: array{type: 'trip-trackings', id: string, attributes: array{trip_id: string, status: 'scheduled'|'in_progress'|'completed'|'cancelled', freshness: 'live'|'stale'|'no_signal'|'not_tracking', age_seconds: int|null, position: array{latitude: float, longitude: float, speed_kmh: float|null, accuracy_meters: float|null, recorded_at: string}|null}}}")]
    #[ResponseDoc(401, 'Missing or invalid token', mediaType: JsonApiErrors::MEDIA_TYPE, type: self::ERROR_DOC)]
    public function latestForTrip(Request $request, string $tripId): HttpResponse
    {
        // A non-UUID can never match a trip (and would make Postgres throw).
        abort_unless(Str::isUuid($tripId), Response::HTTP_NOT_FOUND);

        $trip = Trip::query()->findOrFail($tripId);

        $state = new TripTrackingState(
            $trip,
            $this->positions->get($trip->id),
            CarbonImmutable::now(),
        );

        return response()->json($state->toJsonApi())
            ->header('Content-Type', JsonApiErrors::MEDIA_TYPE);
    }
}
