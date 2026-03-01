<?php

declare(strict_types=1);

namespace Dozor\Facades;

use Illuminate\Support\Facades\Facade;
use Dozor\Contracts\DozorContract as CoreContract;

class DozorFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CoreContract::class;
    }
}
