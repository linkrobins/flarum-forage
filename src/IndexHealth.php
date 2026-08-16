<?php

namespace LinkRobins\Forage;

use Flarum\Post\Post;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * Does the search server hold what this forum thinks it should?
 *
 * Nothing else asks. The index is filled once when a forum connects and kept in
 * step by events after that, so if it is emptied by something outside the forum
 * — a rebuilt or re-subscribed server, or somebody clearing it by hand — every
 * search quietly returns nothing while the settings page still says
 * "Connected", because the key is still perfectly valid. That happened to the
 * Link Robins forum itself, and nothing noticed for over an hour.
 *
 * The forum can tell, because it knows how many of its own posts belong in the
 * index. Comparing the two is the whole idea.
 */
class IndexHealth
{
    /** Everything is fine. */
    public const OK = 'ok';

    /** The index holds nothing at all while the forum has posts: searches find nothing. */
    public const EMPTY_INDEX = 'empty';

    /** Holding materially less than the forum has. */
    public const SHORT = 'short';

    /** No count available, so no opinion. */
    public const UNKNOWN = 'unknown';

    /**
     * How far behind is allowed before it is worth mentioning.
     *
     * Indexing is queued and the search server applies it asynchronously, so
     * being a little behind is normal rather than a fault — especially on a
     * busy forum, or one that connected a moment ago and is still filling. The
     * allowance is a proportion OR a flat number, whichever is larger, so that
     * a ten-post forum is not accused of being broken over a single post in
     * flight.
     */
    public const TOLERANCE = 0.1;

    public const MIN_SLACK = 10;

    /** Counting is a join over every post, so it is not done on every page load. */
    public const COUNT_TTL = 60;

    public function __construct(
        protected ConnectionInterface $db,
        protected Cache $cache
    ) {
    }

    /**
     * How many of this forum's posts belong in the index.
     *
     * Deliberately mirrors PostDocument::isIndexable(), in SQL: comments only,
     * not hidden, not awaiting approval, and not inside a hidden discussion.
     * If the two ever disagree, this reports a forum as permanently short and
     * cries wolf, so they are tested against each other.
     */
    public function expected(): int
    {
        return (int) $this->cache->remember('linkrobins-forage.expected-count', self::COUNT_TTL, function () {
            $query = Post::query()
                ->join('discussions', 'discussions.id', '=', 'posts.discussion_id')
                ->where('posts.type', 'comment')
                ->whereNull('posts.hidden_at')
                ->whereNull('discussions.hidden_at');

            // flarum/approval adds this column. On a forum without that
            // extension it does not exist, and asking for it would throw.
            if ($this->db->getSchemaBuilder()->hasColumn('posts', 'is_approved')) {
                $query->where(function ($query) {
                    $query->whereNull('posts.is_approved')->orWhere('posts.is_approved', true);
                });
            }

            return $query->count();
        });
    }

    /**
     * @param int|null $indexed what the search server says it holds
     * @param int $cap the plan's document limit, 0 for none
     */
    public function verdict(?int $indexed, int $cap = 0): string
    {
        if ($indexed === null) {
            return self::UNKNOWN;
        }

        $expected = $this->expected();

        // A forum with nothing to index is not missing anything.
        if ($expected === 0) {
            return self::OK;
        }

        if ($indexed === 0) {
            return self::EMPTY_INDEX;
        }

        // At the plan's limit the index is short on purpose, and the banner
        // already says so in its own words. Reporting it twice, once as a
        // fault, would be wrong.
        if ($cap > 0 && $indexed >= $cap) {
            return self::OK;
        }

        return $indexed < $expected - $this->slack($expected) ? self::SHORT : self::OK;
    }

    protected function slack(int $expected): float
    {
        return max(self::MIN_SLACK, $expected * self::TOLERANCE);
    }
}
