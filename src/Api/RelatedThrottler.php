<?php

namespace LinkRobins\Forage\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A ceiling on how often one member can ask for related discussions.
 *
 * The composer asks while somebody types, so this route is the only one in the
 * extension a member can drive at will — and every uncached call becomes a
 * query against the forum's own search server. The debounce in the browser
 * keeps honest use far below this; the ceiling is here for everyone else.
 *
 * Returns true or nothing, never false: a throttler that returns false marks
 * the request as exempt and overrides every OTHER throttler in the forum,
 * which would quietly lift core's limits on this route.
 */
class RelatedThrottler
{
    public const PER_MINUTE = 30;

    private const WINDOW = 60;

    public function __construct(
        protected Cache $cache
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ?bool
    {
        if ($request->getAttribute('routeName') !== 'linkrobins-forage.related') {
            return null;
        }

        $actor = RequestUtil::getActor($request);

        // Guests share a forum-wide bucket per address. Crude, and right for
        // the shape of the abuse: one client hammering the composer endpoint.
        $who = $actor->id
            ? 'u'.$actor->id
            : 'ip'.sha1((string) $request->getAttribute('ipAddress'));

        $key = 'linkrobins-forage.related.rate.'.$who;

        $hits = (int) $this->cache->get($key, 0);

        if ($hits >= self::PER_MINUTE) {
            return true;
        }

        $this->cache->put($key, $hits + 1, self::WINDOW);

        return null;
    }
}
