<?php

namespace App\Services;

use App\Data\GpsFix;
use App\Events\TripArrivalsUpdated;
use App\Models\Trip;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class EtaOrchestrationService
{
    /**
     * Orchestrates ETA calculation for a given trip fix by querying the ETA microservice.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function calculate(string $tripId, GpsFix $fix, ?string $bearerToken = null): ?array
    {
        $trip = Trip::query()->find($tripId);
        if (! $trip) {
            Log::warning("Cannot orchestrate ETA: Trip {$tripId} not found.");

            return null;
        }

        $snapshot = $trip->planned_route_snapshot ?? [];

        // Attempt to load authoritative context from Dashboard (or cache)
        $context = $this->getTripContext($tripId, $bearerToken);

        $routeRevision = $context['route_revision']
            ?? $snapshot['revision_hash']
            ?? $snapshot['route_revision']
            ?? hash('sha256', json_encode($snapshot));

        $routeGeometry = $context['route_geometry'] ?? $snapshot['route_geometry'] ?? $snapshot['geometry'] ?? null;
        $stops = $context['stop_occurrences'] ?? $snapshot['occurrences'] ?? $snapshot['stops'] ?? [];

        $progressData = $this->computeProgressWithGeometry($routeGeometry ?? $snapshot, $fix);
        $progressDistance = $progressData['distance_along_route_m'];

        $targets = [];
        foreach ($stops as $stop) {
            $stopOccurrenceId = $stop['occurrence_id'] ?? $stop['stop_occurrence_id'] ?? null;
            if (! $stopOccurrenceId) {
                continue;
            }

            $stopDistance = isset($stop['distance_along_route_m'])
                ? (float) $stop['distance_along_route_m']
                : null;

            if ($stopDistance === null) {
                $stopLat = (float) ($stop['lat'] ?? $stop['latitude'] ?? 0);
                $stopLng = (float) ($stop['lng'] ?? $stop['longitude'] ?? 0);
                $stopDistance = $this->distanceAlongLineString($routeGeometry['coordinates'] ?? [], $stopLat, $stopLng)['distance'];
            }

            $remaining = round(max(0.0, $stopDistance - $progressDistance), 3);

            // ETA validator forbids passed stops (remaining < 0)
            if ($stopDistance >= ($progressDistance - 0.001)) {
                $targets[] = [
                    'stop_occurrence_id' => $stopOccurrenceId,
                    'remaining_distance_m' => $remaining,
                ];
            }
        }

        // If all stops are passed (bus reached final stop), provide last stop with 0 remaining
        if (empty($targets) && ! empty($stops)) {
            $lastStop = end($stops);
            $lastId = $lastStop['occurrence_id'] ?? $lastStop['stop_occurrence_id'] ?? null;
            if ($lastId) {
                $targets[] = [
                    'stop_occurrence_id' => $lastId,
                    'remaining_distance_m' => 0.0,
                ];
            }
        }

        if (empty($targets)) {
            Log::warning("Cannot orchestrate ETA: Trip {$tripId} has no arrival targets.");

            return null;
        }

        $observedAt = $fix->recordedAt->toIso8601String();
        $currentSpeed = $fix->speedKmh !== null ? (float) $fix->speedKmh : 0.0;
        $accuracy = $fix->accuracyMeters !== null ? (float) $fix->accuracyMeters : null;

        $payload = [
            'contract_version' => 1,
            'route_revision' => $routeRevision,
            'position_version' => 1,
            'observed_at' => $observedAt,
            'tracking' => [
                'on_route' => (bool) $progressData['on_route'],
                'accuracy_meters' => $accuracy,
                'current_speed_kmh' => $currentSpeed,
                'effective_speed_kmh' => $currentSpeed,
                'effective_speed_observed_at' => $observedAt,
                'distance_along_route_m' => (float) $progressDistance,
                'confirmed_stop_occurrence_id' => null,
            ],
            'targets' => $targets,
        ];

        $baseUrl = rtrim(config('services.eta.url', 'http://eta-service:8000/api/v1/eta'), '/');
        $url = "{$baseUrl}/trips/{$tripId}/arrivals";
        $timeout = (int) config('services.eta.timeout', 15);

        try {
            $httpClient = Http::timeout($timeout);
            if ($bearerToken) {
                $httpClient = $httpClient->withToken($bearerToken);
            }

            $response = $httpClient->post($url, $payload);

            if ($response->successful()) {
                $rawArrivals = $response->json('data.attributes.arrivals', []);

                $arrivals = array_map(function (array $arrival) {
                    return [
                        'stop_occurrence_id' => $arrival['stop_occurrence_id'] ?? $arrival['occurrence_id'] ?? null,
                        'occurrence_id' => $arrival['stop_occurrence_id'] ?? $arrival['occurrence_id'] ?? null,
                        'stop_id' => $arrival['stop_id'] ?? null,
                        'stop_sequence' => $arrival['stop_sequence'] ?? null,
                        'remaining_distance_m' => $arrival['remaining_distance_m'] ?? null,
                        'eta_seconds' => $arrival['eta_seconds'] ?? null,
                        'arrival_at' => $arrival['arrival_at'] ?? null,
                        'method' => $arrival['method'] ?? null,
                        'quality_reason' => $arrival['quality_reason'] ?? null,
                    ];
                }, $rawArrivals);

                $calculatedAt = now()->toIso8601String();
                TripArrivalsUpdated::dispatch($tripId, $arrivals, $calculatedAt);

                return $arrivals;
            }

            Log::warning("ETA microservice returned HTTP {$response->status()} for trip {$tripId}: {$response->body()}");

            return null;
        } catch (Throwable $e) {
            Log::warning("ETA orchestration failed for trip {$tripId}: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Retrieves authoritative trip context from the Dashboard or local cache.
     *
     * @return array<string, mixed>|null
     */
    public function getTripContext(string $tripId, ?string $bearerToken = null): ?array
    {
        return Cache::remember("trip_tracking_context_{$tripId}", 300, function () use ($tripId, $bearerToken) {
            $dashboardUrl = rtrim(config('services.dashboard.url', 'http://dashboard:80'), '/');
            $timeout = (int) config('services.dashboard.timeout', 15);

            try {
                $client = Http::timeout($timeout);
                if ($bearerToken) {
                    $client = $client->withToken($bearerToken);
                }

                $response = $client->get("{$dashboardUrl}/api/internal/v1/trips/{$tripId}/tracking-context", [
                    'projection' => 'eta-v1',
                ]);

                if ($response->successful()) {
                    return $response->json();
                }
            } catch (Throwable $e) {
                Log::warning("Could not fetch tracking context from dashboard for trip {$tripId}: {$e->getMessage()}");
            }

            return null;
        });
    }

    /**
     * Computes route progress metrics from the planned route snapshot and the GPS fix.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{
     *     distance_along_route_m: float,
     *     current_speed_kmh: float,
     *     accuracy_meters: float|null,
     *     on_route: bool
     * }
     */
    public function computeProgress(array $snapshot, GpsFix $fix): array
    {
        return $this->computeProgressWithGeometry($snapshot, $fix);
    }

    /**
     * Computes route progress from geometry coordinates or snapshot.
     *
     * @param  array<string, mixed>|null  $geometry
     * @return array{
     *     distance_along_route_m: float,
     *     current_speed_kmh: float,
     *     accuracy_meters: float|null,
     *     on_route: bool
     * }
     */
    public function computeProgressWithGeometry(?array $geometry, GpsFix $fix): array
    {
        $currentSpeed = $fix->speedKmh !== null ? (float) $fix->speedKmh : 0.0;
        $accuracy = $fix->accuracyMeters !== null ? (float) $fix->accuracyMeters : null;

        $distanceAndOnRoute = $this->calculateDistanceAlongRoute($geometry ?? [], $fix->latitude, $fix->longitude);

        return [
            'distance_along_route_m' => $distanceAndOnRoute['distance'],
            'current_speed_kmh' => $currentSpeed,
            'accuracy_meters' => $accuracy,
            'on_route' => $distanceAndOnRoute['on_route'],
        ];
    }

    /**
     * Calculates distance along route in meters and whether the position is within 200m of the route.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{distance: float, on_route: bool}
     */
    private function calculateDistanceAlongRoute(array $snapshot, float $latitude, float $longitude): array
    {
        $coordinates = $snapshot['coordinates']
            ?? $snapshot['geometry']['coordinates']
            ?? $snapshot['route_geometry']['coordinates']
            ?? null;

        // Check for GeoJSON LineString coordinates [[lon, lat], ...]
        if (is_array($coordinates) && count($coordinates) >= 2) {
            return $this->distanceAlongLineString($coordinates, $latitude, $longitude);
        }

        // Check for stop occurrences / stops list
        $stops = $snapshot['stops'] ?? $snapshot['stop_occurrences'] ?? $snapshot['occurrences'] ?? null;
        if (is_array($stops) && count($stops) >= 2) {
            $coords = [];
            foreach ($stops as $stop) {
                if (isset($stop['latitude'], $stop['longitude'])) {
                    $coords[] = [(float) $stop['longitude'], (float) $stop['latitude']];
                } elseif (isset($stop['lat'], $stop['lng'])) {
                    $coords[] = [(float) $stop['lng'], (float) $stop['lat']];
                } elseif (isset($stop['coordinates']) && is_array($stop['coordinates'])) {
                    $coords[] = $stop['coordinates'];
                }
            }
            if (count($coords) >= 2) {
                return $this->distanceAlongLineString($coords, $latitude, $longitude);
            }
        }

        return [
            'distance' => 0.0,
            'on_route' => true,
        ];
    }

    /**
     * @param  list<list<float>>  $coordinates  Array of [lon, lat] pairs.
     * @return array{distance: float, on_route: bool}
     */
    private function distanceAlongLineString(array $coordinates, float $lat, float $lon): array
    {
        $closestDistanceToSegment = INF;
        $distanceAtClosestSegment = 0.0;
        $cumulativeDistance = 0.0;

        $pointCount = count($coordinates);
        for ($i = 0; $i < $pointCount - 1; $i++) {
            $lon1 = (float) $coordinates[$i][0];
            $lat1 = (float) $coordinates[$i][1];
            $lon2 = (float) $coordinates[$i + 1][0];
            $lat2 = (float) $coordinates[$i + 1][1];

            $segmentLength = $this->haversineMeters($lat1, $lon1, $lat2, $lon2);

            // Distance from point to start of segment
            $distToStart = $this->haversineMeters($lat, $lon, $lat1, $lon1);
            $distToEnd = $this->haversineMeters($lat, $lon, $lat2, $lon2);

            // Project point onto segment approximation
            if ($segmentLength > 0) {
                $t = (($lat - $lat1) * ($lat2 - $lat1) + ($lon - $lon1) * ($lon2 - $lon1))
                    / (($lat2 - $lat1) ** 2 + ($lon2 - $lon1) ** 2);
                $tClamped = max(0.0, min(1.0, $t));
                $projLat = $lat1 + $tClamped * ($lat2 - $lat1);
                $projLon = $lon1 + $tClamped * ($lon2 - $lon1);
                $minDist = $this->haversineMeters($lat, $lon, $projLat, $projLon);
                $segmentProgress = $cumulativeDistance + ($tClamped * $segmentLength);
            } else {
                $minDist = min($distToStart, $distToEnd);
                $segmentProgress = $cumulativeDistance;
            }

            if ($minDist < $closestDistanceToSegment) {
                $closestDistanceToSegment = $minDist;
                $distanceAtClosestSegment = $segmentProgress;
            }

            $cumulativeDistance += $segmentLength;
        }

        $onRoute = $closestDistanceToSegment <= 200.0;

        return [
            'distance' => round($distanceAtClosestSegment, 2),
            'on_route' => $onRoute,
        ];
    }

    private function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000.0; // meters
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
