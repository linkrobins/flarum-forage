<?php

namespace LinkRobins\Forage\Search;

use Flarum\Post\Filter\FulltextFilter as CoreFulltextFilter;
use Flarum\Search\AbstractFulltextFilter;
use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\SearchState;
use Illuminate\Database\Eloquent\Builder;

/**
 * Post search, answered by the tenant.
 *
 * Same fail-open contract as the discussion filter: anything short of a usable
 * answer from the search server means core's search runs instead.
 *
 * @extends AbstractFulltextFilter<DatabaseSearchState>
 */
class PostFulltextFilter extends AbstractFulltextFilter
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

        $ids = array_keys($posts);

        $query = $state->getQuery();

        $query->whereIn('posts.id', $ids);

        if ($ids === []) {
            return;
        }

        $grammar = $query->getGrammar();
        $rank = 'CASE '.$grammar->wrap('posts.id');
        $position = 0;

        // Integers only, cast on the way in; see the discussion filter.
        foreach ($ids as $id) {
            $rank .= ' WHEN '.(int) $id.' THEN '.$position++;
        }

        $rank .= ' ELSE '.$position.' END';

        $state->setDefaultSort(function (Builder $query) use ($rank) {
            $query->orderByRaw($rank);
        });
    }
}
