<?php

namespace LinkRobins\Forage\Job;

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Queue\AbstractJob;
use LinkRobins\Forage\ForageClient;
use LinkRobins\Forage\PostDocument;
use LinkRobins\Forage\Settings;

/**
 * Re-sync every post in a discussion.
 *
 * Needed because a document carries its discussion's title, so renaming a
 * discussion changes every one of its documents, and because hiding or
 * restoring a discussion changes whether its posts belong in the index at all.
 */
class SyncDiscussion extends AbstractJob
{
    /** Posts are pushed in batches so a long discussion is not one enormous request. */
    public const BATCH = 200;

    public function __construct(
        public int $discussionId
    ) {
    }

    public function handle(Settings $settings, ForageClient $client, PostDocument $documents): void
    {
        if (! $settings->isConfigured()) {
            return;
        }

        /** @var Discussion|null $discussion */
        $discussion = Discussion::query()->find($this->discussionId);

        if ($discussion === null || $discussion->hidden_at !== null) {
            $client->deleteByDiscussion($this->discussionId);

            return;
        }

        $batch = [];
        $drop = [];

        Post::query()
            ->where('discussion_id', $this->discussionId)
            ->orderBy('id')
            ->each(function (Post $post) use ($discussion, $documents, $client, &$batch, &$drop) {
                $post->setRelation('discussion', $discussion);

                $document = $documents->forPost($post);

                if ($document === null) {
                    $drop[] = (int) $post->id;
                } else {
                    $batch[] = $document;
                }

                if (count($batch) >= self::BATCH) {
                    $client->indexDocuments($batch);
                    $batch = [];
                }
            });

        if ($batch !== []) {
            $client->indexDocuments($batch);
        }

        foreach ($drop as $id) {
            $client->deleteDocument($id);
        }
    }
}
