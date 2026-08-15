<?php

namespace LinkRobins\Forage;

use Flarum\Post\Post;

/**
 * Walks the whole forum into the index.
 *
 * Shared by the queued job that runs when a forum first connects and by the
 * console command an admin runs by hand, so both do exactly the same thing.
 */
class Indexer
{
    /** Documents per request. Big enough to be quick, small enough to stay well inside any body limit. */
    public const BATCH = 200;

    /** Posts read from the database at a time. */
    public const CHUNK = 500;

    public function __construct(
        protected Settings $settings,
        protected ForageClient $client,
        protected PostDocument $documents
    ) {
    }

    /**
     * @param (callable(int, int): void)|null $progress called with (indexed, skipped) after each batch
     * @return array{indexed: int, skipped: int, capped: bool}
     */
    public function reindex(bool $fresh = false, ?callable $progress = null): array
    {
        $indexed = 0;
        $skipped = 0;
        $capped = false;

        if (! $this->settings->isConfigured()) {
            return compact('indexed', 'skipped', 'capped');
        }

        $this->client->ensureIndex();

        if ($fresh) {
            $this->client->clearIndex();
        }

        $cap = $this->settings->postCap();
        $batch = [];

        // Paged by ascending id rather than by offset: posts are being written
        // while this runs, and an offset walk would skip or repeat rows as the
        // table shifts underneath it.
        $posts = Post::query()
            ->where('type', 'comment')
            ->with('discussion')
            ->orderBy('id')
            ->lazyById(self::CHUNK);

        foreach ($posts as $post) {
            $document = $this->documents->forPost($post);

            if ($document === null) {
                $skipped++;

                continue;
            }

            if ($cap > 0 && $indexed >= $cap) {
                $capped = true;

                break;
            }

            $batch[] = $document;
            $indexed++;

            if (count($batch) >= self::BATCH) {
                $this->client->indexDocuments($batch);
                $batch = [];

                if ($progress !== null) {
                    $progress($indexed, $skipped);
                }
            }
        }

        if ($batch !== []) {
            $this->client->indexDocuments($batch);
        }

        if ($progress !== null) {
            $progress($indexed, $skipped);
        }

        return compact('indexed', 'skipped', 'capped');
    }
}
