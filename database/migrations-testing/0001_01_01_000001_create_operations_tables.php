<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TEST-ONLY mirror of the operations tables this microservice reads/writes
 * (companies, buses, routes, drivers, trips, gps_locations).
 *
 * Keep it in sync with the real schema (docs/DATABASE.md) whenever the DB team
 * changes it. All primary and foreign keys are UUIDs with no DB default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('legal_id')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('buses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('plate_number')->unique();
            $table->string('unit_number');
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->smallInteger('year')->nullable();
            $table->smallInteger('capacity')->default(40);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });

        Schema::create('routes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code')->index();
            $table->string('name');
            $table->string('origin');
            $table->string('destination');
            $table->decimal('distance_km', 8, 2)->nullable();
            $table->smallInteger('estimated_duration_minutes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'is_active']);
        });

        Schema::create('drivers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Opaque id of the Auth microservice user: no FK, `users` lives elsewhere.
            $table->uuid('user_id')->unique();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('license', 30);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        Schema::create('trips', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('route_id')->constrained('routes')->cascadeOnDelete();
            $table->foreignUuid('bus_id')->constrained('buses')->cascadeOnDelete();
            $table->foreignUuid('driver_id')->index()->constrained('drivers')->cascadeOnDelete();
            $table->string('status', 20)->default('scheduled')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('gps_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trip_id')->index()->constrained('trips')->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('speed_kmh', 8, 2)->nullable();
            $table->timestamp('recorded_at')->index();
            // (longitude, latitude), SRID 4326 — derived by App\Models\Concerns\HasLocationPoint.
            $table->geography('location', subtype: 'point', srid: 4326);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gps_locations');
        Schema::dropIfExists('trips');
        Schema::dropIfExists('drivers');
        Schema::dropIfExists('routes');
        Schema::dropIfExists('buses');
        Schema::dropIfExists('companies');
    }
};
