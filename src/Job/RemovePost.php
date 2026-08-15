<?php

namespace LinkRobins\Forage\Job;

use Flarum\Queue\AbstractJob;
use LinkRobins\Forage\ForageClient;
use LinkRobins\Forage\Settings;

/**
 * Take one post out of the index, by id.
 *
 * Carrying the id rather than the post is the whole point of this job. Flarum's
 * own indexing path queues the deleted *model*, and a queued model is restored
 * by re-reading it: a job that says "delete post 41" cannot re-read post 41,
 * because it has just been deleted, so the framework drops the job as
 * un-runnable. Under the sync queue driver nothing is serialized and the
 * deletion goes through; under any real queue driver it would not, and the post
 * would stay searchable after being deleted.
 *
 * So deletions are also queued here, where an id is just an id. Running both is
 * harmless: removing a document twice is the same as removing it once.
 */
class RemovePost extends AbstractJob
{
    public function __construct(
        public int $postId
    ) {
    }

    public function handle(Settings $settings, ForageClient $client): void
    {
        if (! $settings->isConfigured()) {
            return;
        }

        $client->deleteDocument($this->postId);
    }
}
