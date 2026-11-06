<?php

namespace BaseOS\Connect\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \BaseOS\Connect\Facades\BaseOSBridge
 */
class BaseOSConnect extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \BaseOS\Connect\BridgeManager::class;
    }
}
