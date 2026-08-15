<?php

namespace LinkRobins\Forage;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * The tenant's search server.
 *
 * Two keys, used deliberately: writes go with the admin key, searches with the
 * search-only key. That is not decoration. The search key is scoped to queries
 * and 403s on any write, so routing searches through it means a search path can
 * never mutate the index even if something upstream is wrong.
 */
class ForageClient
{
    public function __construct(
        protected Settings $settings,
        protected LoggerInterface $log
    ) {
    }

    /**
     * Post IDs matching a query, best match first.
     *
     * IDs only, on purpose. Meilisearch has no idea who is asking, so it can
     * only ever supply candidates; the caller intersects them with what the
     * actor may actually see. Returning documents here would invite someone to
     * render them straight out, which would leak private posts.
     *
     * Returns null when the search could not be run at all, which is a
     * different thing from an empty list: the caller falls back to database
     * search on null, and reports "nothing found" on an empty list.
     *
     * @return list<int>|null
     */
    public function searchPostIds(string $query, int $limit = 200): ?array
    {
        if (! $this->settings->canSearch() || trim($query) === '') {
            return null;
        }

        $body = $this->request('POST', '/indexes/'.$this->settings->index().'/search', $this->settings->keyForSearching(), [
            'q' => $query,
            'limit' => $limit,
            'attributesToRetrieve' => ['id'],
        ]);

        if (! is_array($body) || ! isset($body['hits']) || ! is_array($body['hits'])) {
            return null;
        }

        $ids = [];

        foreach ($body['hits'] as $hit) {
            if (isset($hit['id']) && is_numeric($hit['id'])) {
                $ids[] = (int) $hit['id'];
            }
        }

        return $ids;
    }

    /**
     * Add or replace documents. Meilisearch upserts on the primary key, so this
     * is the same call for a new post and an edited one, which keeps the sync
     * jobs idempotent.
     *
     * @param list<array{id: int, discussion_id: int, title: string, content: string}> $documents
     */
    public function indexDocuments(array $documents): bool
    {
        if (! $this->settings->isConfigured() || $documents === []) {
            return false;
        }

        $body = $this->request('POST', '/indexes/'.$this->settings->index().'/documents?primaryKey=id', $this->settings->adminKey(), $documents);

        return $body !== null;
    }

    public function deleteDocument(int $id): bool
    {
        if (! $this->settings->isConfigured()) {
            return false;
        }

        return $this->request('DELETE', '/indexes/'.$this->settings->index().'/documents/'.$id, $this->settings->adminKey()) !== null;
    }

    /** Empty the index, for a rebuild from scratch. */
    public function clearIndex(): bool
    {
        if (! $this->settings->isConfigured()) {
            return false;
        }

        return $this->request('DELETE', '/indexes/'.$this->settings->index().'/documents', $this->settings->adminKey()) !== null;
    }

    /**
     * Drop every document belonging to a discussion.
     *
     * By filter rather than by id, because the caller usually reaches this
     * point precisely when the posts no longer exist to be listed.
     */
    public function deleteByDiscussion(int $discussionId): bool
    {
        if (! $this->settings->isConfigured()) {
            return false;
        }

        return $this->request('POST', '/indexes/'.$this->settings->index().'/documents/delete', $this->settings->adminKey(), [
            'filter' => 'discussion_id = '.$discussionId,
        ]) !== null;
    }

    /** How many documents the index currently holds, or null if it cannot be read. */
    public function documentCount(): ?int
    {
        if (! $this->settings->isConfigured()) {
            return null;
        }

        $body = $this->request('GET', '/indexes/'.$this->settings->index(), $this->settings->adminKey());

        if (is_array($body) && isset($body['numberOfDocuments'])) {
            return (int) $body['numberOfDocuments'];
        }

        $stats = $this->request('GET', '/indexes/'.$this->settings->index().'/stats', $this->settings->adminKey());

        return is_array($stats) && isset($stats['numberOfDocuments']) ? (int) $stats['numberOfDocuments'] : null;
    }

    /**
     * Make sure the index exists and knows which fields to search.
     *
     * Safe to run repeatedly: creating an index that exists is a no-op error we
     * swallow, and settings are declarative.
     */
    public function ensureIndex(): bool
    {
        if (! $this->settings->isConfigured()) {
            return false;
        }

        $this->request('POST', '/indexes', $this->settings->adminKey(), [
            'uid' => $this->settings->index(),
            'primaryKey' => 'id',
        ]);

        return $this->request('PATCH', '/indexes/'.$this->settings->index().'/settings', $this->settings->adminKey(), [
            'searchableAttributes' => ['title', 'content'],
            'filterableAttributes' => ['discussion_id'],
        ]) !== null;
    }

    public function isReachable(): bool
    {
        if ($this->settings->endpoint() === '') {
            return false;
        }

        try {
            $response = $this->client()->get($this->settings->endpoint().'/health', [
                'headers' => Agent::HEADERS,
                'timeout' => 8,
                'http_errors' => false,
            ]);

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            return false;
        }
    }

    /**
     * @param array<mixed>|null $payload
     * @return array<mixed>|null null on any failure, having logged it
     */
    protected function request(string $method, string $path, string $key, ?array $payload = null): ?array
    {
        if ($key === '') {
            return null;
        }

        $options = [
            'headers' => Agent::HEADERS + ['Authorization' => 'Bearer '.$key],
            'timeout' => 20,
            'http_errors' => false,
        ];

        if ($payload !== null) {
            $options['json'] = $payload;
        }

        try {
            $response = $this->client()->request($method, $this->settings->endpoint().$path, $options);
        } catch (GuzzleException $e) {
            $this->log->error('[linkrobins/forage] '.$method.' '.$path.' failed: '.$e->getMessage());

            return null;
        }

        $status = $response->getStatusCode();

        if ($status >= 400) {
            // Never log the body verbatim: an error from the search server can
            // echo back the document, and the key is in the request headers.
            $this->log->error('[linkrobins/forage] '.$method.' '.$path.' returned '.$status);

            return null;
        }

        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function client(): Client
    {
        return new Client();
    }
}
