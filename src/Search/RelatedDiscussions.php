<?php

namespace LinkRobins\Forage\Search;

use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use LinkRobins\Forage\ForageClient;

/**
 * Discussions that look like a given piece of text.
 *
 * Two callers, one job: the footer of a discussion asks "what else is like
 * this one", and the composer asks "has this been asked before" while someone
 * is still typing the title. Both are the same search, and both are advisory —
 * nothing here decides what a member is allowed to do, only what they are
 * shown.
 *
 * Fails SILENT, which is the opposite of what search itself does. Search falls
 * back to the database because a member typed a query and is owed an answer;
 * nobody asked for this, so when the search server is unreachable the right
 * outcome is an absent panel rather than a slower page or a visible error.
 */
class RelatedDiscussions
{
    /** Below a discussion. Enough to be useful, short enough to scan. */
    public const FOOTER_LIMIT = 5;

    /** While composing. Deliberately fewer: this one interrupts. */
    public const COMPOSER_LIMIT = 3;

    /**
     * Shorter titles than this are not worth a round trip.
     *
     * "Help" and "bug" match half a forum, so the suggestions would be noise
     * at exactly the moment the composer is most intrusive.
     */
    public const MIN_QUERY_LENGTH = 4;

    /**
     * How long a discussion's candidate list is kept.
     *
     * A day, because what is related to a thread changes only as OTHER threads
     * are written, which no event on this discussion can tell us about. The key
     * additionally carries the title and the last reply, so a rename or a new
     * reply refreshes it sooner without any busting logic.
     */
    private const CACHE_TTL = 86400;

    public function __construct(
        protected ForageClient $client,
        protected ForageResults $results,
        protected Cache $cache
    ) {
    }

    /**
     * Discussions like $source, excluding $source itself.
     *
     * @return list<Discussion>
     */
    public function forDiscussion(User $actor, Discussion $source, int $limit = self::FOOTER_LIMIT): array
    {
        $title = (string) $source->title;

        $stamp = $source->last_posted_at?->getTimestamp() ?? 0;
        $key = 'linkrobins-forage.related.'.$source->id.'.'.md5($title.'|'.$stamp);

        return $this->lookup($actor, $title, (int) $source->id, $limit, $key);
    }

    /**
     * Discussions like a title being typed.
     *
     * Uncached on purpose: every keystroke pause is a different string, so a
     * cache here would be a growing pile of entries that are never read twice.
     * The throttler is what protects the search server for this one.
     *
     * @return list<Discussion>
     */
    public function forQuery(User $actor, string $query, int $limit = self::COMPOSER_LIMIT): array
    {
        if (mb_strlen(trim($query)) < self::MIN_QUERY_LENGTH) {
            return [];
        }

        return $this->lookup($actor, $query, null, $limit, null);
    }

    /**
     * @return list<Discussion>
     */
    private function lookup(User $actor, string $query, ?int $exclude, int $limit, ?string $cacheKey): array
    {
        if (trim($query) === '') {
            return [];
        }

        $ids = $this->candidates($query, $cacheKey);

        if ($ids === null || $ids === []) {
            return [];
        }

        // Visibility is applied to the cached candidates on every request, never
        // cached alongside them: the candidate list is the same for everybody,
        // and who may read it is not.
        $best = $this->results->bestPostPerDiscussion(
            $this->results->filterVisible($actor, $ids)
        );

        $discussionIds = array_keys($best);

        if ($exclude !== null) {
            $discussionIds = array_values(array_filter(
                $discussionIds,
                fn (int $id): bool => $id !== $exclude
            ));
        }

        $discussionIds = array_slice($discussionIds, 0, $limit);

        if ($discussionIds === []) {
            return [];
        }

        // The posts were scoped already, so this is belt and braces: it closes
        // the case of a readable post hanging off a discussion that is not, and
        // it is the query that loads the titles either way.
        $discussions = Discussion::whereVisibleTo($actor)
            ->whereIn('id', $discussionIds)
            ->get()
            ->keyBy('id');

        $ordered = [];

        foreach ($discussionIds as $id) {
            if ($discussions->has($id)) {
                $ordered[] = $discussions->get($id);
            }
        }

        return $ordered;
    }

    /**
     * Post ids the search server considers a match, one per discussion.
     *
     * null means it could not answer. That is never cached: pinning a failure
     * for a day would outlast the outage that caused it.
     *
     * @return list<int>|null
     */
    private function candidates(string $query, ?string $cacheKey): ?array
    {
        if ($cacheKey !== null) {
            $cached = $this->cache->get($cacheKey);

            if (is_array($cached)) {
                return $cached;
            }
        }

        $ids = $this->client->searchPostIds(
            $query,
            ForageResults::MAX_HITS,
            ForageResults::PER_DISCUSSION
        );

        if ($ids === null) {
            return null;
        }

        if ($cacheKey !== null) {
            $this->cache->put($cacheKey, $ids, self::CACHE_TTL);
        }

        return $ids;
    }
}
