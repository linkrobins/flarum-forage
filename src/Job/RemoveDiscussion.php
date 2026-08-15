<?php

namespace LinkRobins\Forage\Job;

use Flarum\Queue\AbstractJob;
use LinkRobins\Forage\ForageClient;
use LinkRobins\Forage\Settings;

/**
 * Drop every document belonging to a discussion.
 *
 * Deleting a discussion takes its posts with it, so there is nothing left to
 * read the ids from. The tenant is asked to delete by discussion_id instead,
 * which is why that field is declared filterable when the index is set up.
 */
class RemoveDiscussion extends AbstractJob
{
    public function __construct(
        public int $discussionId
    ) {
    }

    public function handle(Settings $settings, ForageClient $client): void
    {
        if (! $settings->isConfigured()) {
            return;
        }

        $client->deleteByDiscussion($this->discussionId);
    }
}
