<?php

namespace LinkRobins\Forage\Search;

use Flarum\Post\Post;
use Flarum\Search\IndexerInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use LinkRobins\Forage\ForageClient;
use LinkRobins\Forage\Indexer;
use LinkRobins\Forage\PostDocument;
use LinkRobins\Forage\Settings;
use Psr\Log\LoggerInterface;

/**
 * Keeps the search server in step with the forum's posts.
 *
 * Flarum watches the Post model for us and hands the changes over here on a
 * queue, which covers a post being written, edited, hidden, restored, deleted,
 * and approved. Approval needs no special handling: it is a change to the post,
 * so it arrives like any other.
 *
 * Everything here is safe to run twice. Queues deliver at least once, and a
 * document is written by primary key, so a repeat is a no-op rather than a
 * duplicate.
 */
class PostIndexer implements IndexerInterface
{
    public function __construct(
        protected Settings $settings,
        protected ForageClient $client,
        protected PostDocument $documents,
        protected Indexer $indexer,
        protected Cache $cache,
        protected LoggerInterface $log
    ) {
    }

    public static function index(): string
    {
        return Settings::DEFAULT_INDEX;
    }

    /**
     * @param Post[] $models
     */
    public function save(array $models): void
    {
        if (! $this->settings->isConfigured()) {
            return;
        }

        $documents = [];
        $remove = [];

        foreach ($models as $post) {
            $document = $this->documents->forPost($post);

            // A post that has just been hidden, or that is waiting for
            // approval, arrives here as a save. It has to come out of the
            // index: filtering it at search time instead would leave the text
            // sitting on the search server, which is the wrong place for it.
            if ($document === null) {
                $remove[] = (int) $post->id;
            } else {
                $documents[] = $document;
            }
        }

        if ($documents !== [] && ! $this->atCap()) {
            $this->client->indexDocuments($documents);
        }

        foreach ($remove as $id) {
            $this->client->deleteDocument($id);
        }
    }

    /**
     * @param Post[] $models
     */
    public function delete(array $models): void
    {
        if (! $this->settings->isConfigured()) {
            return;
        }

        foreach ($models as $post) {
            $this->client->deleteDocument((int) $post->id);
        }
    }

    public function build(): void
    {
        $this->indexer->reindex();
    }

    public function flush(): void
    {
        $this->client->clearIndex();
    }

    /**
     * The plan's document limit. It is enforced on the service's side as well,
     * but knowingly pushing past it is rude.
     *
     * The count is cached for a minute: asking the search server on every post
     * would double the request rate for a number that barely moves.
     */
    protected function atCap(): bool
    {
        $cap = $this->settings->postCap();

        if ($cap <= 0) {
            return false;
        }

        $count = $this->cache->remember('linkrobins-forage.document-count', 60, fn () => $this->client->documentCount());

        if ($count === null || $count < $cap) {
            return false;
        }

        $this->log->warning('[linkrobins/forage] at the plan limit of '.$cap.' posts, not indexing any more');

        return true;
    }
}
