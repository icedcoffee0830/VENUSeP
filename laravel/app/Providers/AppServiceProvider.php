<?php

namespace App\Providers;

use Illuminate\Foundation\Exceptions\Renderer\Listener;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /* The debug page lists the request's queries: the SQL stays, the values go (bootstrap/app.php says why). */
        $this->app->singleton(Listener::class, fn () => new class extends Listener {
            public function queries()
            {
                return array_map(fn ($query) => ['bindings' => []] + $query, parent::queries());
            }
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
