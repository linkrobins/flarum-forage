<?php

namespace LinkRobins\Forage\Search;

use Flarum\Post\Post;
use Flarum\User\User;
use LinkRobins\Forage\ForageClient;

/**
 * Turns a query into the posts this actor is allowed to see, in the order the
 * search server ranked them.
 *
 * This is where the permission check lives. The search server has no idea who
 * is asking, so everything it returns is a candidate and nothing more: the
 * candidates are run back through Flarum's own visibility scope before they are
 * allowed to influence a result set.
 */
class ForageResults
{
    /**
     * How many hits to ask for.
     *
     * Every hit is carried into the SQL query as a literal, so this is a real
     * ceiling rather than a formality. Discussion search asks the server to
     * return one post per discussion (see $distinct below), so for that search
     * this is a ceiling on DISCUSSIONS, which is well past what anyone pages
     * through.
     */
    public const MAX_HITS = 250;

    /**
     * The field discussion search collapses on.
     *
     * Without it the ceiling is spent on posts rather than discussions, and one
     * busy thread can take all of it: measured on a 5,000-post forum, a common
     * word returned 250 posts belonging to 10 discussions when 200 actually
     * matched. Collapsing gives each discussion one slot and its best-matching
     * post, which is exactly what a list of discussions needs.
     */
    public const PER_DISCUSSION = 'discussion_id';

    public function __construct(
        protected ForageClient $client
    ) {
    }

    /**
     * Post id => discussion id, best match first, filtered to what $actor may
     * read.
     *
     * null means the search could not be run and the caller should fall back to
     * database search. An empty array means the search ran and matched nothing.
     *
     * @return array<int, int>|null
     */
    public function visiblePosts(User $actor, string $query, ?string $distinct = null): ?array
    {
        $ids = $this->client->searchPostIds($query, self::MAX_HITS, $distinct);

        if ($ids === null) {
            return null;
        }

        if ($ids === []) {
            return [];
        }

        /** @var \Illuminate\Support\Collection<int, int> $visible */
        $visible = Post::whereVisibleTo($actor)
            ->whereIn('id', $ids)
            ->where('type', 'comment')
            ->pluck('discussion_id', 'id');

        $ordered = [];

        // Walk the search server's order, not the database's: relevance is the
        // whole point of sending the query out in the first place.
        foreach ($ids as $id) {
            if ($visible->has($id)) {
                $ordered[$id] = (int) $visible->get($id);
            }
        }

        return $ordered;
    }

    /**
     * The best-matching visible post per discussion, in relevance order.
     *
     * @param array<int, int> $posts post id => discussion id
     * @return array<int, int> discussion id => post id
     */
    public function bestPostPerDiscussion(array $posts): array
    {
        $best = [];

        foreach ($posts as $postId => $discussionId) {
            if (! isset($best[$discussionId])) {
                $best[$discussionId] = $postId;
            }
        }

        return $best;
    }
}
