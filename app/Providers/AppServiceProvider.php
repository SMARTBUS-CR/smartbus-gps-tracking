<?php

namespace App\Providers;

use App\Models\User;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Define a gate to allow access to the API documentation for all users
        Gate::define('viewApiDocs', fn (?User $user = null) => true);

        // Add a global header parameter for Accept-Language to all API operations in the generated OpenAPI documentation
        Scramble::afterOpenApiGenerated(function (OpenApi $openApi) {
            foreach ($openApi->paths as $path) {
                foreach ($path->operations as $operation) {
                    foreach ($operation->parameters as $parameter) {
                        if ($parameter->in === 'query' && str_ends_with($parameter->name, '[]')) {
                            $parameter->setName(substr($parameter->name, 0, -2));
                        }
                    }

                    $operation->addParameters([
                        Parameter::make('Accept-Language', 'header')
                            ->description('Language of the response. Supported values: en, es.')
                            ->setSchema(Schema::fromType((new StringType)->enum(['en', 'es'])->default('es'))),
                    ]);
                }
            }
        });
    }
}
