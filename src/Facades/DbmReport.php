<?php

namespace FiveStones\GmpReporting\Facades;

use Illuminate\Support\Facades\Facade;

class DbmReport extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \FiveStones\GmpReporting\DbmReport::class;
    }
}
