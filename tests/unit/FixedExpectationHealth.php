<?php

/*
 * This file is part of linkrobins/flarum-forage.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Forage\Tests\unit;

use LinkRobins\Forage\IndexHealth;

/**
 * The health check with the database counted for it, so the judgement it makes
 * can be tested without one. Whether that count is RIGHT is a separate question
 * and needs a real forum, which is what the integration test is for.
 */
class FixedExpectationHealth extends IndexHealth
{
    public function __construct(
        protected int $expected
    ) {
    }

    public function expected(): int
    {
        return $this->expected;
    }
}
