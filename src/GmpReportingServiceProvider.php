<?php

namespace FiveStones\GmpReporting;

use Illuminate\Support\ServiceProvider;

class GmpReportingServiceProvider extends ServiceProvider
{
    public function boot()
    {
        //
    }

    public function register()
    {
        $this->app->alias(Dv360Sdf::class, 'fivestones.dv360sdf');
    }
}
