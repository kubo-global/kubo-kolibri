<?php

namespace KuboKolibri;

use Illuminate\Support\ServiceProvider;
use KuboKolibri\Client\KolibriClient;
use KuboKolibri\Console\SyncProgressCommand;
use KuboKolibri\Services\ExerciseRunService;
use KuboKolibri\Services\KolibriProvisioner;
use KuboKolibri\Services\KolibriSessionBridge;
use KuboKolibri\Services\SkillGraph;

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

        $this->app->singleton(KolibriProvisioner::class, function ($app) {
            return new KolibriProvisioner(
                $app->make(KolibriClient::class),
                $app['config']['kubo-kolibri.learner_password_secret'],
            );
        });

        $this->app->singleton(KolibriSessionBridge::class, function ($app) {
            return new KolibriSessionBridge(
                $app->make(KolibriClient::class),
                $app->make(KolibriProvisioner::class),
            );
        });

        $this->app->singleton(ExerciseRunService::class, function ($app) {
            return new ExerciseRunService(
                $app->make(KolibriClient::class),
                $app->make(SkillGraph::class),
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

            $this->commands([
                SyncProgressCommand::class,
            ]);
        }
    }
}
