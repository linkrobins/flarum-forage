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
            // of. Both are enforced here whatever the frontend believes, but
            // they are not equally worth carrying, and the difference decides
            // which one goes back into the bundle if the weight of the forum
            // payload ever matters.
            //
            // The footer's limit was the dangerous one, and it is not here at
            // all any more: the frontend used to decide whether to offer "see
            // more" by counting rows against its own copy of it, so lowering it
            // in PHP would have stopped the button appearing and made the modal
            // unreachable, with nothing logged. That is now answered by
            // meta.hasMore and cannot drift.
            //
            // The minimum length below is the benign one. The server refuses a
            // short query on its own, so a stale copy in the bundle costs one
            // round trip that comes back empty. Drop this one first.
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
