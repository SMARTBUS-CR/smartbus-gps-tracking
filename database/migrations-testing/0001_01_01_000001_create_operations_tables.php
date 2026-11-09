<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TEST-ONLY mirror of the operations tables this microservice reads/writes.
 *
 * The real schema is owned by the Dashboard repository
 * (smartbus-dashboard-admin, database/migrations, branch `dev`, 2026-10-13):
 * companies, routes, route_patterns, route_schedules, buses, drivers, trips and
 * gps_locations. Only the columns and constraints that matter to GPS (or that
 * its foreign keys require) are mirrored here. Keep it in sync whenever the
 * Dashboard changes those tables. It never runs against the shared database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('legal_name');
            $table->string('trade_name')->nullable();
            $table->string('slug')->unique();
            $table->char('country_code', 2);
            $table->string('timezone', 64);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('routes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('route_patterns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('route_id')->constrained('routes')->restrictOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->string('headsign');
            $table->jsonb('route_geometry')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['route_id', 'code']);
        });

        Schema::create('route_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('route_pattern_id')->constrained('route_patterns')->restrictOnDelete();
            $table->smallInteger('day_of_week');
            $table->time('departure_time');
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamps();
        });

        Schema::create('buses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->char('country_code', 2);
            $table->string('plate_number', 30);
            $table->string('unit_number', 50);
            $table->smallInteger('capacity');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['country_code', 'plate_number']);
            $table->unique(['company_id', 'unit_number']);
            $table->unique(['id', 'company_id']);
        });

        Schema::create('drivers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            // Opaque id of the Auth microservice user (no FK: `users` lives elsewhere).
            // Unique per company only: one user can drive for several companies.
            $table->uuid('user_id');
            $table->string('license', 50);
            $table->date('license_expires_at');
            $table->date('employment_starts_on');
            $table->date('employment_ends_on')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'user_id']);
            $table->unique(['company_id', 'license']);
            $table->unique(['id', 'company_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('trips', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('route_pattern_id')->constrained('route_patterns')->restrictOnDelete();
            $table->foreignUuid('route_schedule_id')->constrained('route_schedules')->restrictOnDelete();
            $table->uuid('bus_id');
            $table->uuid('driver_id');
            $table->date('service_date');
            $table->string('status', 20)->default('scheduled');
            $table->timestampTz('scheduled_departure_at', 6);
            $table->timestampTz('scheduled_arrival_at', 6);
            $table->timestampTz('actual_departure_at', 6)->nullable();
            $table->timestampTz('actual_arrival_at', 6)->nullable();
            $table->jsonb('planned_route_snapshot');
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestampTz('cancelled_at', 6)->nullable();
            $table->timestamps();

            // The bus and the driver must belong to the trip's company.
            $table->foreign(['bus_id', 'company_id'])->references(['id', 'company_id'])->on('buses')->restrictOnDelete();
            $table->foreign(['driver_id', 'company_id'])->references(['id', 'company_id'])->on('drivers')->restrictOnDelete();
            $table->index(['driver_id', 'scheduled_departure_at']);
        });

        // Same execution rules as the real table: each status requires its timestamps.
        DB::statement(<<<'SQL'
            ALTER TABLE trips ADD CONSTRAINT trips_execution_check CHECK (
                (status = 'scheduled' AND actual_departure_at IS NULL
                    AND actual_arrival_at IS NULL AND cancelled_at IS NULL AND cancellation_reason IS NULL)
                OR (status = 'in_progress' AND actual_departure_at IS NOT NULL
                    AND actual_arrival_at IS NULL AND cancelled_at IS NULL AND cancellation_reason IS NULL)
                OR (status = 'completed' AND actual_departure_at IS NOT NULL
                    AND actual_arrival_at IS NOT NULL AND actual_arrival_at >= actual_departure_at
                    AND cancelled_at IS NULL AND cancellation_reason IS NULL)
                OR (status = 'cancelled' AND actual_departure_at IS NULL
                    AND actual_arrival_at IS NULL AND cancelled_at IS NOT NULL
                    AND cancellation_reason IS NOT NULL AND btrim(cancellation_reason) <> '')
            )
            SQL);

        Schema::create('gps_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trip_id')->constrained('trips')->restrictOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('speed_kmh', 8, 2)->nullable();
            $table->decimal('accuracy_meters', 10, 2)->nullable();
            $table->timestampTz('recorded_at', 6);
            $table->timestampTz('received_at', 6)->useCurrent();
            // (longitude, latitude), SRID 4326 — derived by App\Models\Concerns\HasLocationPoint.
            $table->geography('location', subtype: 'point', srid: 4326)->nullable();
            $table->timestamps();

            $table->unique(['trip_id', 'recorded_at']);
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        foreach (['gps_locations', 'trips', 'drivers', 'buses', 'route_schedules', 'route_patterns', 'routes', 'companies'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
