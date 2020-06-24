<?php

namespace FiveStones\GmpReporting\Facades;

use Illuminate\Support\Facades\Facade;

class Dv360Sdf extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \FiveStones\GmpReporting\Dv360Sdf::class;
    }
}
