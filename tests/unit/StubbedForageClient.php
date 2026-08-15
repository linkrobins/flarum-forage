<?php

/*
 * This file is part of linkrobins/flarum-forage.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Forage\Tests\unit;

use GuzzleHttp\Client;
use LinkRobins\Forage\ForageClient;

/**
 * The search-server client, talking to a mocked handler.
 */
class StubbedForageClient extends ForageClient
{
    public Client $stub;

    protected function client(): Client
    {
        return $this->stub;
    }
}
