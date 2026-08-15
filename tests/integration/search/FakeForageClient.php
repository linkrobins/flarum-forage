<?php

/*
 * This file is part of linkrobins/flarum-forage.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Forage\Tests\integration\search;

use LinkRobins\Forage\ForageClient;

/**
 * A search server that answers whatever the test tells it to.
 *
 * The point of these tests is what the extension does with an answer, not how
 * the answer was arrived at, so the answer is dictated: including ids the
 * person searching is not allowed to see, which is the case that matters most.
 */
class FakeForageClient extends ForageClient
{
    /** @var list<int>|null what the search server "found" */
    public static ?array $ids = [];

    public function searchPostIds(string $query, int $limit = 200): ?array
    {
        return self::$ids;
    }

    public function isReachable(): bool
    {
        return true;
    }
}
