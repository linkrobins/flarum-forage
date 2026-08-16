<?php

/*
 * This file is part of linkrobins/flarum-forage.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Forage\Tests\unit;

use LinkRobins\Forage\ForageClient;
use LinkRobins\Forage\Settings;
use Psr\Log\NullLogger;

/**
 * A search-server client whose document count is whatever the test says it is.
 * The backfill decision reads nothing else, so nothing else is faked.
 */
class FixedCountForageClient extends ForageClient
{
    public function __construct(
        Settings $settings,
        public ?int $count = null
    ) {
        parent::__construct($settings, new NullLogger());
    }

    public function documentCount(): ?int
    {
        return $this->count;
    }
}
