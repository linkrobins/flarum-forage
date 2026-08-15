<?php

namespace LinkRobins\Forage\Search;

use Flarum\Discussion\Search\FulltextFilter as CoreFulltextFilter;
use Flarum\Search\AbstractFulltextFilter;
use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\SearchState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;

/**
 * Discussion search, answered by the tenant.
 *
 * Fails open. If Forage is not configured, or the search server cannot be
 * reached, or it returns something unusable, this hands the query straight back
 * to the search Flarum ships with. A search box that quietly gets worse is a
 * far better failure than one that returns nothing, and an admin whose key
 * lapses should not find their forum's search broken.
 *
 * @extends AbstractFulltextFilter<DatabaseSearchState>
 */
class DiscussionFulltextFilter extends AbstractFulltextFilter
{
    public function __construct(
        protected ForageResults $results,
        protected CoreFulltextFilter $fallback
    ) {
    }

    public function search(SearchState $state, string $value): void
    {
        $posts = $this->results->visiblePosts($state->getActor(), $value);

        if ($posts === null) {
            $this->fallback->search($state, $value);

            return;
        }

        $best = $this->results->bestPostPerDiscussion($posts);

        $query = $state->getQuery();

        $query->whereIn('discussions.id', array_keys($best));

        if ($best === []) {
            return;
        }

        $grammar = $query->getGrammar();
        $id = $grammar->wrap('discussions.id');

        // Both expressions are built from integers we cast ourselves: ids the
        // search server returned, and ids the database just gave us. Nothing
        // typed by a user reaches this string.
        $mostRelevant = 'CASE '.$id;
        $rank = 'CASE '.$id;
        $position = 0;

        foreach ($best as $discussionId => $postId) {
            $mostRelevant .= ' WHEN '.(int) $discussionId.' THEN '.(int) $postId;
            $rank .= ' WHEN '.(int) $discussionId.' THEN '.$position++;
        }

        $mostRelevant .= ' END';
        $rank .= ' ELSE '.$position.' END';

        // Carries the matching post through to the result, the same way core's
        // filter does, so a search result still links to and quotes the post
        // that actually matched rather than the top of the discussion.
        $query->addSelect(new Expression('('.$mostRelevant.') as most_relevant_post_id'));

        $state->setDefaultSort(function (Builder $query) use ($rank) {
            $query->orderByRaw($rank);
        });
    }
}
