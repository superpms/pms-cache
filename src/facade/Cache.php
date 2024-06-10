<?php
namespace pms\facade;

use pms\cache\Driver;
use pms\Facade;

/**
 * @see Driver
 * @mixin Driver
 */
class Cache extends Facade
{
    protected static function getFacadeClass(): string
    {
        return Driver::class;
    }

}