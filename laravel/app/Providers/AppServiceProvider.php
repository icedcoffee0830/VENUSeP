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
        /* Failover: a database server that can't be reached fails ONCE, in the 3 s connect timeout
           (config/database.php). Laravel would try a second time, so every outage page waited twice. */
        $this->app->bind('db.connector.mysql', fn () => new class extends \Illuminate\Database\Connectors\MySqlConnector {
            protected function tryAgainIfCausedByLostConnection(\Throwable $e, $dsn, $username, #[\SensitiveParameter] $password, $options)
            {
                throw $e;
            }
        });

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
