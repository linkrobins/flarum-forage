<?php

/*
 * This file is part of linkrobins/flarum-forage.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Forage\Tests\integration\search;

use Flarum\Extend;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use LinkRobins\Forage\Settings;
use PHPUnit\Framework\Attributes\Test;

/**
 * That the forum actually tells us when a post changes.
 *
 * This is wiring, not logic, and wiring is exactly what unit tests cannot see.
 * The first version of this extension registered its indexer against
 * Flarum\Post\Post, which reads correctly and never runs: every post row is one
 * of Post's subclasses, Flarum looks the indexer up under the concrete class,
 * and finds nothing. Search kept working, the index simply stopped being
 * updated. Only a test that posts through the API catches that.
 */
class IndexingTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-forage');

        $this->extend(
            (new Extend\ServiceProvider())->register(FakeForageProvider::class)
        );

        // Enough for the extension to consider itself connected. Nothing here
        // reaches a network: the client is replaced above.
        $this->setting(Settings::ENDPOINT, 'https://tenant.example.com');
        $this->setting(Settings::ADMIN_KEY, 'admin-key');
        $this->setting(Settings::INDEX, 'posts');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(), // id 2
            ],
            'discussions' => [
                ['id' => 1, 'title' => 'Alpha', 'created_at' => '2026-01-01 00:00:00', 'last_posted_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'first_post_id' => 1, 'comment_count' => 1, 'slug' => 'alpha', 'is_private' => 0],
            ],
            'posts' => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>apples</p></t>', 'is_private' => 0],
            ],
        ]);

        FakeForageClient::forget();
    }

    /** @test */
    #[Test]
    public function writing_a_post_indexes_it(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/posts', ['authenticatedAs' => 2, 'json' => [
                'data' => [
                    'type' => 'posts',
                    'attributes' => ['content' => 'The kestrel nests on the quarry ledge.'],
                    'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]],
                ],
            ]])
        );

        $this->assertEquals(201, $response->getStatusCode());

        $document = $this->documentFor((int) json_decode((string) $response->getBody(), true)['data']['id']);

        $this->assertNotNull($document, 'the new post was never handed to the indexer');
        $this->assertEquals(1, $document['discussion_id']);
        $this->assertEquals('Alpha', $document['title']);
        // Plain text, not the s9e markup Flarum stores.
        $this->assertEquals('The kestrel nests on the quarry ledge.', $document['content']);
    }

    /** @test */
    #[Test]
    public function editing_a_post_reindexes_it(): void
    {
        $this->send(
            $this->request('PATCH', '/api/posts/1', ['authenticatedAs' => 1, 'json' => [
                'data' => ['type' => 'posts', 'id' => '1', 'attributes' => ['content' => 'pears, actually']],
            ]])
        );

        $document = $this->documentFor(1);

        $this->assertNotNull($document);
        $this->assertEquals('pears, actually', $document['content']);
    }

    /**
     * A hidden post has to leave the index rather than be filtered out at search
     * time, so its text is not sitting on the search server.
     *
     * @test
     */
    #[Test]
    public function hiding_a_post_takes_it_out_of_the_index(): void
    {
        $this->send(
            $this->request('PATCH', '/api/posts/1', ['authenticatedAs' => 1, 'json' => [
                'data' => ['type' => 'posts', 'id' => '1', 'attributes' => ['isHidden' => true]],
            ]])
        );

        $this->assertContains(1, FakeForageClient::$deleted);
        $this->assertNull($this->documentFor(1));
    }

    /** @test */
    #[Test]
    public function deleting_a_post_takes_it_out_of_the_index(): void
    {
        $this->send($this->request('DELETE', '/api/posts/1', ['authenticatedAs' => 1]));

        $this->assertContains(1, FakeForageClient::$deleted);
    }

    /**
     * Every document carries its discussion's title, so a rename rewrites all of
     * them.
     *
     * @test
     */
    #[Test]
    public function renaming_a_discussion_reindexes_its_posts(): void
    {
        $this->send(
            $this->request('PATCH', '/api/discussions/1', ['authenticatedAs' => 1, 'json' => [
                'data' => ['type' => 'discussions', 'id' => '1', 'attributes' => ['title' => 'Alpha, revisited']],
            ]])
        );

        $document = $this->documentFor(1);

        $this->assertNotNull($document);
        $this->assertEquals('Alpha, revisited', $document['title']);
    }

    /** @test */
    #[Test]
    public function deleting_a_discussion_clears_its_posts_from_the_index(): void
    {
        $this->send($this->request('DELETE', '/api/discussions/1', ['authenticatedAs' => 1]));

        $this->assertContains(1, FakeForageClient::$deletedDiscussions);
    }

    /**
     * The most recent document sent for a post, or null if the last thing that
     * happened to it was a removal.
     *
     * @return array{id: int, discussion_id: int, title: string, content: string}|null
     */
    protected function documentFor(int $postId): ?array
    {
        $document = null;

        foreach (FakeForageClient::$indexed as $indexed) {
            if ($indexed['id'] === $postId) {
                $document = $indexed;
            }
        }

        if ($document === null) {
            return null;
        }

        // A later removal wins over an earlier write.
        foreach (array_reverse(FakeForageClient::$deleted) as $deleted) {
            if ($deleted === $postId) {
                return null;
            }
        }

        return $document;
    }
}
