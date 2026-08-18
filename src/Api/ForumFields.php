<?php

/*
 * This file is part of linkrobins/flarum-forage.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Forage\Api;

use Flarum\Api\Schema;
use LinkRobins\Forage\Search\RelatedDiscussions;
use LinkRobins\Forage\Settings;

/**
 * Tells the forum frontend whether each related-discussions panel is on.
 *
 * Two answers rather than one, because an admin can keep the list under a
 * discussion and switch off the one that interrupts the composer, or the other
 * way round.
 *
 * Without this the panels would ask on every discussion page and every pause in
 * the composer of a forum that has no key, has let one lapse, is still
 * provisioning, or simply does not want them: a full Flarum boot per request,
 * for a list that is always going to be empty.
 *
 * Fail-closed, because this rides on every forum response: anything unexpected
 * reads as "not available" rather than 500ing the boot payload.
 */
class ForumFields
{
    public function __construct(
        protected Settings $settings
    ) {
    }

    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('forageRelated')
                ->get(fn (): bool => $this->on($this->settings->relatedUnderDiscussion(...))),

            Schema\Boolean::make('forageRelatedComposer')
                ->get(fn (): bool => $this->on($this->settings->relatedInComposer(...))),

            // The two numbers the frontend would otherwise keep its own copies
            // of. Both are enforced here whatever the frontend believes, so a
            // stale copy in the bundle was never dangerous, only silent: lower
            // the footer's limit in PHP and the old frontend would have gone on
            // offering a longer list that the server had already cut.
            Schema\Integer::make('forageRelatedExpandedLimit')
                ->get(fn (): int => RelatedDiscussions::EXPANDED_LIMIT),

            Schema\Integer::make('forageRelatedMinQueryLength')
                ->get(fn (): int => RelatedDiscussions::MIN_QUERY_LENGTH),
        ];
    }

    /**
     * @param callable(): bool $switch
     */
    private function on(callable $switch): bool
    {
        try {
            return $this->settings->canSearch() && $switch();
        } catch (\Throwable) {
            return false;
        }
    }
}
