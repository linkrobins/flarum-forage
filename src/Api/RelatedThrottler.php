<?php

/*
 * This file is part of linkrobins/flarum-forage.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Forage\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A ceiling on how often one client can ask for related discussions.
 *
 * Two ceilings, because the route serves two costs. Asking about a discussion
 * is answered from a day-long cache almost every time, so it costs a visibility
 * query against this forum's own database; asking about a title being typed is
 * never cached, because every pause in typing is a different string, so every
 * one of those is a query against the search server. Sharing a budget between
 * them meant browsing could starve the composer, and the composer is the half
 * a member is actually waiting on.
 *
 * The ceiling matters more than its size suggests. Everything on this route
 * fails silent by design: a throttled member gets an absent panel, which is
 * exactly what a member with nothing related also gets. There is no honest way
 * to say "you are being throttled" over a panel nobody asked for. So these
 * numbers are not a tuning dial to be trimmed; they are set where real use
 * never arrives, and anything that does arrive is not real use.
 *
 * Returns true or nothing, never false: a throttler that returns false marks
 * the request as exempt and overrides every OTHER throttler in the forum,
 * which would quietly lift core's limits on this route.
 */
class RelatedThrottler
{
    /**
     * Asking about a discussion. Cheap, and it fires on every discussion page,
     * so this has to clear a whole office reading the forum from one address
     * (see the note on guests below) while still stopping a script.
     */
    public const PER_MINUTE_DISCUSSION = 300;

    /**
     * Asking about a title being typed. Every one is a real search. A four
     * hundred millisecond debounce and one person writing one title lands
     * nowhere near this, even retyping the whole thing repeatedly.
     */
    public const PER_MINUTE_QUERY = 30;

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

        $params = $request->getQueryParams();

        // Which bucket has to be decided the same way the controller decides
        // which work to do, or a caller could spend the cheap budget and get
        // the expensive answer by sending both.
        $discussion = is_numeric($params['discussion'] ?? null) && (int) $params['discussion'] > 0;

        $key = self::bucketKey($this->who($request), $discussion);
        $limit = $discussion ? self::PER_MINUTE_DISCUSSION : self::PER_MINUTE_QUERY;

        // Increment first, then give the window an expiry if this request is
        // the one that opened it.
        //
        // Seeding with add() first looked tidier and had a trap in it. If the
        // key expired between the add and the increment, increment() read an
        // empty payload, whose remaining time is null, and put the counter back
        // with `$raw['time'] ?? 0` seconds. Zero does not mean "expire now" to
        // the file store: expiration() turns it into 9999999999, so the bucket
        // was written to live effectively forever. It would then fill up over
        // the life of the forum, cross the ceiling, and quietly take related
        // discussions away from that member or that address until somebody
        // cleared the cache. A narrow window, on a route that fires on every
        // discussion page for as long as the forum runs.
        //
        // Incrementing a missing key writes the same permanent entry, so the
        // put() below is what bounds it, whichever way the key came to exist.
        // Three operations on the first request of a window, two after.
        $hits = (int) $this->cache->increment($key);

        if ($hits <= 1) {
            $this->cache->put($key, $hits, self::WINDOW);
        }

        // NOT atomic, and deliberately not pretending to be. Flarum hardwires
        // cache.store to the file store (Foundation\InstalledSite), whose
        // increment() is itself a get followed by a put, so two requests
        // arriving together can read the same number on a stock install. A
        // forum that rebinds the store to Redis gets a real atomic counter and
        // nothing here has to change. This is a courtesy ceiling on a cheap
        // route, not a security boundary: the cost of a race is a request or
        // two over the line, and the cost of pretending otherwise is somebody
        // trusting it for something it cannot do.
        return $hits > $limit ? true : null;
    }

    /** Which bucket, for whom. Public so a test can fill one without guessing. */
    public static function bucketKey(string $who, bool $discussion): string
    {
        return 'linkrobins-forage.related.rate.'.($discussion ? 'd' : 'q').'.'.$who;
    }

    /**
     * Who is being counted.
     *
     * Members are counted per account. Guests are counted per address, which
     * means everyone behind one office or campus NAT shares a bucket. That is
     * a deliberate choice rather than an oversight: keying guests by session
     * would be fairer, and would also hand an unlimited budget to any client
     * that drops its cookie, which is precisely the client this exists for.
     * The discussion ceiling is set high enough that a shared address full of
     * readers never reaches it.
     */
    protected function who(ServerRequestInterface $request): string
    {
        $actor = RequestUtil::getActor($request);

        return $actor->id
            ? 'u'.$actor->id
            : 'ip'.sha1((string) $request->getAttribute('ipAddress'));
    }
}
