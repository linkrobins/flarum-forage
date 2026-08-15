<?php

/*
 * This file is part of linkrobins/flarum-forage.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Forage\Tests\unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LinkRobins\Forage\Settings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ForageClientTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    protected array $sent = [];

    /**
     * @param list<Response> $responses
     * @param array<string, mixed> $stored
     */
    protected function client(array $responses, array $stored = []): StubbedForageClient
    {
        $settings = new Settings(new ArraySettingsRepository($stored + [
            Settings::ENDPOINT => 'https://tenant.example.com',
            Settings::SEARCH_KEY => 'search-key',
            Settings::ADMIN_KEY => 'admin-key',
        ]));

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->sent));

        $client = new StubbedForageClient($settings, new NullLogger());
        $client->stub = new Client(['handler' => $stack]);

        return $client;
    }

    /** @test */
    #[Test]
    public function it_returns_matching_ids_in_the_order_they_were_ranked(): void
    {
        $client = $this->client([
            new Response(200, [], (string) json_encode([
                'hits' => [['id' => 9], ['id' => 4], ['id' => 7]],
            ])),
        ]);

        $this->assertEquals([9, 4, 7], $client->searchPostIds('anything'));
    }

    /**
     * Searching for discussions asks the server for one post per discussion.
     * Without it a single busy thread eats the whole result set and everything
     * below it falls off the end — measured at 250 posts across 10 discussions
     * when 200 matched.
     *
     * @test
     */
    #[Test]
    public function discussion_search_collapses_to_one_post_per_discussion(): void
    {
        $client = $this->client([new Response(200, [], (string) json_encode(['hits' => []]))]);

        $client->searchPostIds('anything', 250, 'discussion_id');

        $body = json_decode((string) $this->sent[0]['request']->getBody(), true);

        $this->assertEquals('discussion_id', $body['distinct']);
        $this->assertEquals(250, $body['limit']);
    }

    /**
     * Post search must NOT collapse: there the individual posts are the
     * results, so one per discussion would throw most of them away.
     *
     * @test
     */
    #[Test]
    public function post_search_does_not_collapse(): void
    {
        $client = $this->client([new Response(200, [], (string) json_encode(['hits' => []]))]);

        $client->searchPostIds('anything');

        $this->assertArrayNotHasKey('distinct', json_decode((string) $this->sent[0]['request']->getBody(), true));
    }

    /**
     * A search that could not run and a search that matched nothing are
     * different answers: one falls back to the forum's own search, the other
     * legitimately shows no results.
     *
     * @test
     */
    #[Test]
    public function a_failed_search_is_not_an_empty_result(): void
    {
        $this->assertNull($this->client([new Response(500)])->searchPostIds('anything'));
        $this->assertEquals([], $this->client([
            new Response(200, [], (string) json_encode(['hits' => []])),
        ])->searchPostIds('anything'));
    }

    /** @test */
    #[Test]
    public function it_does_not_call_out_when_nothing_is_configured(): void
    {
        $client = $this->client([], [Settings::ENDPOINT => '', Settings::SEARCH_KEY => '', Settings::ADMIN_KEY => '']);

        $this->assertNull($client->searchPostIds('anything'));
        $this->assertFalse($client->indexDocuments([['id' => 1, 'discussion_id' => 1, 'title' => 't', 'content' => 'c']]));
        $this->assertCount(0, $this->sent);
    }

    /**
     * The search-only key cannot write to the index, which is the point of
     * using it: nothing on the search path can change what is stored.
     *
     * @test
     */
    #[Test]
    public function searching_uses_the_search_key_and_writing_uses_the_admin_key(): void
    {
        $client = $this->client([
            new Response(200, [], (string) json_encode(['hits' => []])),
            new Response(202, [], (string) json_encode(['taskUid' => 1])),
        ]);

        $client->searchPostIds('anything');
        $client->indexDocuments([['id' => 1, 'discussion_id' => 1, 'title' => 't', 'content' => 'c']]);

        $this->assertEquals('Bearer search-key', $this->sent[0]['request']->getHeaderLine('Authorization'));
        $this->assertEquals('Bearer admin-key', $this->sent[1]['request']->getHeaderLine('Authorization'));
    }

    /**
     * Upserting on the primary key is what makes the sync jobs safe to run
     * twice, which matters because queues deliver at least once.
     *
     * @test
     */
    #[Test]
    public function indexing_declares_the_primary_key(): void
    {
        $client = $this->client([new Response(202, [], (string) json_encode(['taskUid' => 1]))]);

        $client->indexDocuments([['id' => 1, 'discussion_id' => 1, 'title' => 't', 'content' => 'c']]);

        $this->assertStringContainsString('/indexes/posts/documents', (string) $this->sent[0]['request']->getUri());
        $this->assertStringContainsString('primaryKey=id', (string) $this->sent[0]['request']->getUri());
    }

    /**
     * A deleted discussion takes its posts with it, so there is nothing left to
     * list the ids from; the documents have to go by filter instead.
     *
     * @test
     */
    #[Test]
    public function a_discussion_is_cleared_by_filter(): void
    {
        $client = $this->client([new Response(202, [], (string) json_encode(['taskUid' => 1]))]);

        $client->deleteByDiscussion(42);

        $body = json_decode((string) $this->sent[0]['request']->getBody(), true);

        $this->assertEquals(['filter' => 'discussion_id = 42'], $body);
    }

    /**
     * Only the stats endpoint carries the count, so it is the only one asked.
     * The index itself answers with its uid, timestamps and primary key.
     *
     * @test
     */
    #[Test]
    public function it_counts_documents_from_the_stats_endpoint(): void
    {
        $client = $this->client([new Response(200, [], (string) json_encode(['numberOfDocuments' => 1234]))]);

        $this->assertEquals(1234, $client->documentCount());
        $this->assertStringEndsWith('/indexes/posts/stats', (string) $this->sent[0]['request']->getUri());
        $this->assertCount(1, $this->sent);
    }

    /**
     * A scoped key may be allowed to write to the index and not to read its
     * statistics, so "cannot count" has to be an ordinary answer rather than
     * something that breaks the page asking.
     *
     * @test
     */
    #[Test]
    public function a_key_that_may_not_read_the_stats_simply_returns_no_count(): void
    {
        $this->assertNull($this->client([new Response(403)])->documentCount());
    }

    /** @test */
    #[Test]
    public function it_declares_which_fields_are_searchable_and_filterable(): void
    {
        $client = $this->client([
            new Response(202, [], (string) json_encode(['taskUid' => 1])),
            new Response(202, [], (string) json_encode(['taskUid' => 2])),
        ]);

        $client->ensureIndex();

        $created = json_decode((string) $this->sent[0]['request']->getBody(), true);
        $configured = json_decode((string) $this->sent[1]['request']->getBody(), true);

        $this->assertEquals(['uid' => 'posts', 'primaryKey' => 'id'], $created);
        $this->assertEquals(['title', 'content'], $configured['searchableAttributes']);
        // Without this, a deleted discussion could not be cleared by filter.
        $this->assertEquals(['discussion_id'], $configured['filterableAttributes']);
        // Spelled out rather than left to whatever the server was set up with,
        // so every forum searches the same way.
        $this->assertEquals(['oneTypo' => 5, 'twoTypos' => 9], $configured['typoTolerance']['minWordSizeForTypos']);
    }
}
