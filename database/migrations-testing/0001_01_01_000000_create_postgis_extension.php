<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TEST-ONLY schema (database/migrations-testing).
 *
 * The real operations DB (Aiven) is owned by the DB team and already has this
 * schema: this microservice never migrates it. These migrations mirror it
 * (introspected with `php artisan db:table`, see docs/DATABASE.md) so the test
 * suite can run against a disposable PostgreSQL + PostGIS database.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
    }

    public function down(): void
    {
        // The extension is left in place: other schemas may depend on it.
    }
};
