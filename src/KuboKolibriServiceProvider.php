<?php

namespace KuboKolibri;

use Illuminate\Support\ServiceProvider;
use KuboKolibri\Client\KolibriClient;

class KuboKolibriServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/Config/kubo-kolibri.php', 'kubo-kolibri');

        $this->app->singleton(KolibriClient::class, function ($app) {
            $config = $app['config']['kubo-kolibri'];
            return new KolibriClient(
                $config['kolibri_url'],
                $config['kolibri_username'],
                $config['kolibri_password'],
            );
        });
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'kubo-kolibri');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/Config/kubo-kolibri.php' => config_path('kubo-kolibri.php'),
            ], 'kubo-kolibri-config');
        }
    }
}
