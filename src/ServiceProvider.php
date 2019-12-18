<?php
namespace ReadMe;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/config.dist.php' => config_path('readme.php'),
        ]);
    }
}
