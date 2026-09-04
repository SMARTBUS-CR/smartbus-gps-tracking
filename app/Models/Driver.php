<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
// NO se importa User: la tabla `users` no vive en esta BD (microservicio de Auth).

/**
 * Tabla existente `drivers` (NO administrada por este servicio).
 *
 * Columnas (ver docs/DATABASE.md):
 *   id          uuid    (PK, sin autoincrement, sin default en BD)
 *   user_id     bigint  UNIQUE  -> id opaco del microservicio de Auth (NO hay tabla users
 *                                 en esta BD, NO hay FK). Se expone tal cual como atributo.
 *   company_id  bigint  -> FK companies.id
 *   license     varchar(30)
 *   status      varchar(20)  default 'active'
 *   created_at / updated_at
 *
 * @property string $id
 * @property int    $user_id
 * @property int    $company_id
 * @property string $license
 * @property string $status
 */
class Driver extends Model
{
    protected $table = 'drivers';

    /**
     * La PK es UUID (string), no un autoincremental.
     */
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'company_id',
        'license',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'company_id' => 'integer',
        ];
    }

    /*
     * NOTA: este microservicio NO crea drivers (los administra otro equipo).
     * Si en el futuro hiciera falta generar el UUID al insertar, añadir:
     *
     *   use Illuminate\Database\Eloquent\Concerns\HasUuids;
     *   use HasUuids;
     *
     * verificando antes que la version de UUID coincida con la que usa el equipo de BD.
     */

    /*
     * NO existe relacion user(): la tabla `users` esta en la BD del microservicio de Auth.
     * `user_id` se expone como atributo entero y quien lo necesite (Gateway / frontend /
     * microservicio de Auth) resuelve los datos del usuario.
     */

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<Trip, $this>
     */
    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }
}
