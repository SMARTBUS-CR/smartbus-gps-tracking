<?php

namespace App\Services;

use App\Data\GpsFix;
use App\Events\BusLocationUpdated;
use App\Models\GpsLocation;
use Throwable;

/**
 * Logica de aplicacion para las lecturas GPS (HU1).
 *
 * Punto unico donde nace una gps_location. Aqui se enchufaran despues:
 *   - reglas de negocio (viaje activo, descartar lecturas muy viejas/desordenadas)
 *   - lo que necesite el microservicio de ETA
 *
 * El controller NO habla con el modelo directamente: pasa por aqui.
 */
class GpsLocationService
{
    /**
     * Registra una lectura GPS y devuelve el modelo persistido.
     *
     * `location` (PostGIS) se deriva sola en el modelo (HasLocationPoint).
     */
    public function record(GpsFix $fix): GpsLocation
    {
        $location = GpsLocation::create($fix->toDatabaseRow());

        $this->broadcast($location);

        return $location;
    }

    /**
     * Ultima lectura GPS de un viaje (HU3 - estado inicial del mapa).
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException  si el viaje
     *         no existe o aun no tiene ninguna lectura -> el controller lo mapea a 404.
     */
    public function latestForTrip(int $tripId): GpsLocation
    {
        return GpsLocation::query()
            ->where('trip_id', $tripId)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->firstOrFail();
    }

    /**
     * HU2: transmitir la nueva coordenada por WebSocket (Reverb -> Echo).
     *
     * La transmision es best-effort: si Reverb esta caido o el broadcast falla,
     * la lectura YA quedo guardada y la ingesta (HU1) no debe romperse.
     * En produccion, con QUEUE_CONNECTION=redis, esto ademas se reintenta solo.
     */
    private function broadcast(GpsLocation $location): void
    {
        try {
            BusLocationUpdated::dispatch($location);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
