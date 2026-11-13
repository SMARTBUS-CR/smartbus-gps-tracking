<?php

namespace App\Services;

use App\Data\TripPosition;
use App\Events\TripArrivalsUpdated;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Arrival estimates (ETA) of the trips in progress, broadcast to passengers
 * as `TripArrivalsUpdated` on `private-trip.{id}`.
 *
 * Runs in its own process (`php artisan gps:eta-watch`), NEVER inside the
 * driver's GPS request: a slow or failing Dashboard / ETA service can only
 * delay the estimates, never the bus position. With `ETA_SERVICE_URL` or
 * `DASHBOARD_URL` unset the whole feature is off.
 *
 * Per trip and per new position:
 *   1. ETA context of the trip, from the Dashboard (cached): route line, stop
 *      distances and `route_revision`, exactly as the ETA service validates them.
 *   2. Bus progress along that line, same projection as the Dashboard.
 *   3. POST to the ETA service with the remaining stops.
 *   4. Broadcast the arrivals.
 *
 * Both calls carry the driver's own token (the Dashboard and the ETA service
 * authorize the active user): the GPS request leaves it in the cache,
 * encrypted and short-lived ([rememberDriverToken]).
 */
class EtaOrchestrationService
{
    private const EARTH_RADIUS_METERS = 6371000;

    /** The app sends a reading every 5 s: an older token means the driver stopped sending. */
    private const TOKEN_TTL_SECONDS = 600;

    /** The trip plan is frozen once the trip starts. */
    private const CONTEXT_TTL_SECONDS = 600;

    /** After a failed context request (401, 422 without route line, outage…). */
    private const CONTEXT_RETRY_SECONDS = 60;

    /** Projections this close to the best one are ambiguous (route passing by twice). */
    private const AMBIGUITY_METERS = 20.0;

    private const UNAVAILABLE = 'unavailable';

    public function __construct(private readonly TripPositionStore $positions) {}

    public function isEnabled(): bool
    {
        return filled(config('services.eta.url')) && filled(config('services.dashboard.url'));
    }

    /**
     * Called by the GPS request after an accepted reading. Local cache only;
     * it never throws (the reading is already accepted).
     */
    public function rememberDriverToken(string $tripId, ?string $token): void
    {
        if (! $this->isEnabled() || blank($token)) {
            return;
        }

        try {
            Cache::put($this->key($tripId, 'token'), Crypt::encryptString($token), self::TOKEN_TTL_SECONDS);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * One pass over the trips in progress. A failing trip never stops the others.
     *
     * @return int Trips whose arrivals were broadcast.
     */
    public function refreshActiveTrips(): int
    {
        if (! $this->isEnabled()) {
            return 0;
        }

        $updated = 0;
        foreach (Trip::query()->where('status', Trip::STATUS_IN_PROGRESS)->pluck('id') as $tripId) {
            try {
                if ($this->refreshTrip((string) $tripId) !== null) {
                    $updated++;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $updated;
    }

    /**
     * Estimates and broadcasts the arrivals of one trip, when it has a new and
     * recent position. `null` when there is nothing new or it could not be done.
     *
     * @return list<array<string, mixed>>|null
     */
    public function refreshTrip(string $tripId): ?array
    {
        // Cache first: a trip nobody is driving right now costs no query.
        $token = $this->driverToken($tripId);
        if ($token === null) {
            return null;
        }

        $position = $this->positions->get($tripId);
        if ($position === null) {
            return null;
        }

        $age = $position->recordedAt->diffInSeconds(CarbonImmutable::now(), false);
        if ($age > (int) config('services.eta.max_position_age', 30)) {
            return null; // the ETA service would answer "stale_observation"
        }

        $observedAt = $this->iso($position->recordedAt);
        if (Cache::get($this->key($tripId, 'last')) === $observedAt) {
            return null; // already estimated for this position
        }
        Cache::put($this->key($tripId, 'last'), $observedAt, 3600);

        $speed = $this->effectiveSpeed($tripId, $position);

        $context = $this->context($tripId, $token);
        if ($context === null) {
            return null;
        }

        $lastProgress = Cache::get($this->key($tripId, 'progress'));
        $payload = $this->buildPayload(
            $context,
            $position,
            $speed,
            is_numeric($lastProgress) ? (float) $lastProgress : null,
        );
        if ($payload === null) {
            return null;
        }
        Cache::put($this->key($tripId, 'progress'), $payload['tracking']['distance_along_route_m'], 3600);

        return $this->requestArrivals($tripId, $token, $payload);
    }

    /**
     * Request body of `POST {ETA}/trips/{id}/arrivals` (contract v1).
     *
     * Distances come from the Dashboard's context, so every
     * `remaining_distance_m` is exactly `stop distance - progress`, as the ETA
     * service checks. `null` when the context cannot be used.
     *
     * @param  array<string, mixed>  $context
     * @param  array{kmh: float, at: string}|null  $effectiveSpeed
     * @return array<string, mixed>|null
     */
    public function buildPayload(array $context, TripPosition $position, ?array $effectiveSpeed = null, ?float $lastProgress = null): ?array
    {
        $coordinates = $this->coordinates($context['route_geometry']['coordinates'] ?? null);
        $routeDistance = $context['route_distance_m'] ?? null;
        $stops = $context['stop_occurrences'] ?? null;
        if ($coordinates === null || ! is_numeric($routeDistance) || ! is_array($stops)) {
            return null;
        }

        [$progress, $offRouteMeters] = $this->project($coordinates, $position, $lastProgress);
        $progress = round(min(max($progress, 0.0), (float) $routeDistance), 3);

        $targets = [];
        foreach ($stops as $stop) {
            $distance = $stop['distance_along_route_m'] ?? null;
            $occurrenceId = $stop['occurrence_id'] ?? null;
            if (! is_string($occurrenceId) || ! is_numeric($distance)) {
                return null;
            }
            // Stops behind the bus are not sent (the ETA service rejects them).
            if ((float) $distance < $progress) {
                continue;
            }
            $targets[] = [
                'stop_occurrence_id' => $occurrenceId,
                'remaining_distance_m' => round((float) $distance - $progress, 3),
            ];
        }
        if ($targets === []) {
            return null;
        }

        $currentSpeed = $position->speedKmh !== null ? min(max($position->speedKmh, 0.0), 400.0) : null;
        $accuracy = $position->accuracyMeters !== null ? max($position->accuracyMeters, 0.0) : null;

        return [
            'contract_version' => 1,
            'route_revision' => (string) $context['route_revision'],
            // Readings only move forward (stale ones are rejected), so the
            // reading time is a valid increasing version.
            'position_version' => max(1, (int) $position->recordedAt->getTimestampMs()),
            'observed_at' => $this->iso($position->recordedAt),
            'tracking' => [
                'on_route' => $offRouteMeters <= (float) config('services.eta.on_route_meters', 100),
                'accuracy_meters' => $accuracy,
                'current_speed_kmh' => $currentSpeed,
                'effective_speed_kmh' => $effectiveSpeed['kmh'] ?? null,
                'effective_speed_observed_at' => $effectiveSpeed['at'] ?? null,
                'distance_along_route_m' => $progress,
                'confirmed_stop_occurrence_id' => null,
            ],
            'targets' => array_slice($targets, 0, 100),
        ];
    }

    /**
     * ETA context of the trip from the Dashboard (`projection=eta-v1`), cached.
     * A failure is also remembered for a minute, so an outage is not hit every 5 s.
     *
     * @return array<string, mixed>|null
     */
    private function context(string $tripId, string $token): ?array
    {
        $key = $this->key($tripId, 'context');
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        if ($cached === self::UNAVAILABLE) {
            return null;
        }

        $url = rtrim((string) config('services.dashboard.url'), '/')."/api/internal/v1/trips/{$tripId}/tracking-context";

        try {
            $response = Http::timeout((float) config('services.dashboard.timeout', 8))
                ->connectTimeout(3)
                ->acceptJson()
                ->withToken($token)
                ->get($url, ['projection' => 'eta-v1']);

            $context = $response->successful() ? $response->json() : null;
            if (is_array($context) && $this->isUsableContext($context)) {
                Cache::put($key, $context, self::CONTEXT_TTL_SECONDS);

                return $context;
            }

            Log::warning("ETA: no usable tracking context for trip {$tripId} (HTTP {$response->status()}).");
        } catch (Throwable $e) {
            Log::warning("ETA: tracking context request failed for trip {$tripId}: {$e->getMessage()}");
        }

        Cache::put($key, self::UNAVAILABLE, self::CONTEXT_RETRY_SECONDS);

        return null;
    }

    /** @param  array<string, mixed>  $context */
    private function isUsableContext(array $context): bool
    {
        return is_string($context['route_revision'] ?? null)
            && preg_match('/\A[0-9a-f]{64}\z/', $context['route_revision']) === 1
            && is_numeric($context['route_distance_m'] ?? null)
            && is_array($context['stop_occurrences'] ?? null)
            && $this->coordinates($context['route_geometry']['coordinates'] ?? null) !== null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>|null
     */
    private function requestArrivals(string $tripId, string $token, array $payload): ?array
    {
        $url = rtrim((string) config('services.eta.url'), '/')."/trips/{$tripId}/arrivals";

        try {
            $response = Http::timeout((float) config('services.eta.timeout', 8))
                ->connectTimeout(3)
                ->acceptJson()
                ->withToken($token)
                ->post($url, $payload);
        } catch (Throwable $e) {
            Log::warning("ETA: request failed for trip {$tripId}: {$e->getMessage()}");

            return null;
        }

        if (! $response->successful()) {
            if ($response->status() === 409) {
                // route_revision_mismatch (plan changed) or trip ended: read the context again.
                Cache::forget($this->key($tripId, 'context'));
            }
            Log::warning("ETA: service answered HTTP {$response->status()} for trip {$tripId}: {$response->body()}");

            return null;
        }

        $raw = $response->json('data.attributes.arrivals');
        if (! is_array($raw)) {
            return null;
        }

        $arrivals = [];
        foreach ($raw as $arrival) {
            if (! is_array($arrival)) {
                continue;
            }
            $arrivals[] = [
                'stop_occurrence_id' => $arrival['stop_occurrence_id'] ?? null,
                'occurrence_id' => $arrival['stop_occurrence_id'] ?? null,
                'stop_id' => $arrival['stop_id'] ?? null,
                'stop_sequence' => $arrival['stop_sequence'] ?? null,
                'remaining_distance_m' => $arrival['remaining_distance_m'] ?? null,
                'eta_seconds' => $arrival['eta_seconds'] ?? null,
                'arrival_at' => $arrival['arrival_at'] ?? null,
                'planned_arrival_at' => $arrival['planned_arrival_at'] ?? null,
                'method' => $arrival['method'] ?? null,
                'quality_reason' => $arrival['quality_reason'] ?? null,
            ];
        }

        try {
            TripArrivalsUpdated::dispatch($tripId, $arrivals, now()->toIso8601String());
        } catch (Throwable $e) {
            report($e); // Reverb down: the next position tries again
        }

        return $arrivals;
    }

    /**
     * Last moving speed, so a bus stopped at a light still gets live estimates
     * (the ETA service only uses it while it is recent).
     *
     * @return array{kmh: float, at: string}|null
     */
    private function effectiveSpeed(string $tripId, TripPosition $position): ?array
    {
        $key = $this->key($tripId, 'speed');
        if ($position->speedKmh !== null && $position->speedKmh > 0) {
            $speed = ['kmh' => min($position->speedKmh, 400.0), 'at' => $this->iso($position->recordedAt)];
            Cache::put($key, $speed, 3600);

            return $speed;
        }

        $cached = Cache::get($key);

        return is_array($cached) && is_numeric($cached['kmh'] ?? null) && is_string($cached['at'] ?? null)
            ? ['kmh' => (float) $cached['kmh'], 'at' => $cached['at']]
            : null;
    }

    private function driverToken(string $tripId): ?string
    {
        $encrypted = Cache::get($this->key($tripId, 'token'));
        if (! is_string($encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Distance along the line of the point closest to the bus, and how far
     * the bus is from the line. Same projection as the Dashboard's ETA
     * context, so both measure the route the same way. When the line passes
     * by twice, the candidate closest to the previous progress wins.
     *
     * @param  list<array{float, float}>  $coordinates  [lng, lat]
     * @return array{float, float}
     */
    private function project(array $coordinates, TripPosition $position, ?float $lastProgress): array
    {
        $point = [$position->longitude, $position->latitude];
        $candidates = [];
        $offset = 0.0;
        for ($i = 1; $i < count($coordinates); $i++) {
            [$start, $end] = [$coordinates[$i - 1], $coordinates[$i]];
            $length = $this->distance($start, $end);
            if ($length <= 0) {
                continue;
            }

            $longitudeDelta = $this->longitudeDelta($end[0] - $start[0]);
            $latitudeDelta = $end[1] - $start[1];
            $scale = cos(deg2rad(($start[1] + $end[1]) / 2));
            $x = $longitudeDelta * $scale;
            $y = $latitudeDelta;
            $pointX = $this->longitudeDelta($point[0] - $start[0]) * $scale;
            $pointY = $point[1] - $start[1];
            $fraction = max(0.0, min(1.0, ($x * $pointX + $y * $pointY) / ($x * $x + $y * $y)));
            $snapped = [$start[0] + $fraction * $longitudeDelta, $start[1] + $fraction * $latitudeDelta];

            $candidates[] = [$this->distance($point, $snapped), $offset + $fraction * $length];
            $offset += $length;
        }

        if ($candidates === []) {
            return [0.0, INF];
        }

        $best = min(array_column($candidates, 0));
        $near = array_values(array_filter($candidates, fn (array $c): bool => $c[0] <= $best + self::AMBIGUITY_METERS));
        usort($near, fn (array $a, array $b): int => $lastProgress === null
            ? $a[1] <=> $b[1]
            : abs($a[1] - $lastProgress) <=> abs($b[1] - $lastProgress));

        return [$near[0][1], $best];
    }

    /**
     * @return list<array{float, float}>|null
     */
    private function coordinates(mixed $coordinates): ?array
    {
        if (! is_array($coordinates) || count($coordinates) < 2) {
            return null;
        }

        $points = [];
        foreach ($coordinates as $point) {
            if (! is_array($point) || ! is_numeric($point[0] ?? null) || ! is_numeric($point[1] ?? null)) {
                return null;
            }
            $points[] = [(float) $point[0], (float) $point[1]];
        }

        return $points;
    }

    /**
     * @param  array{float, float}  $start
     * @param  array{float, float}  $end
     */
    private function distance(array $start, array $end): float
    {
        $latitudeDelta = deg2rad($end[1] - $start[1]);
        $longitudeDelta = deg2rad($this->longitudeDelta($end[0] - $start[0]));
        $haversine = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($start[1])) * cos(deg2rad($end[1])) * sin($longitudeDelta / 2) ** 2;

        return 2 * self::EARTH_RADIUS_METERS * asin(sqrt(min(1.0, $haversine)));
    }

    private function longitudeDelta(float $degrees): float
    {
        return fmod($degrees + 540, 360) - 180;
    }

    private function iso(CarbonImmutable $instant): string
    {
        return $instant->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    private function key(string $tripId, string $name): string
    {
        return "gps:trip:{$tripId}:eta:{$name}";
    }
}
