<?php

/*
 * This file is part of linkrobins/flarum-forage.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Forage\Tests\unit;

use GuzzleHttp\Client;
use LinkRobins\Forage\ConfigExchange;

/**
 * The exchange, talking to a mocked handler instead of the real service.
 *
 * The production class builds its own client so that nothing has to be wired up
 * for it in the container; this is the seam the tests use instead.
 */
class StubbedConfigExchange extends ConfigExchange
{
    public Client $stub;

    protected function client(): Client
    {
        return $this->stub;
    }
}
