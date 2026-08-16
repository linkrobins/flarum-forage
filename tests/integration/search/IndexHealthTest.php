<?php

/*
 * This file is part of linkrobins/flarum-forage.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Forage\Tests\integration\search;

use Flarum\Extend;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use LinkRobins\Forage\IndexHealth;
use LinkRobins\Forage\PostDocument;
use LinkRobins\Forage\Settings;
use PHPUnit\Framework\Attributes\Test;

/**
 * The health check counts what BELONGS in the index with one SQL query, while
 * the indexer decides what belongs one post at a time in PHP. Two rules, one
 * meaning — and if they ever drift apart, every forum is told it is permanently
 * short and the warning becomes noise.
 *
 * So the important test here is not "does the count work", it is "do the two
 * rules agree", checked against a forum containing one of every awkward case.
 */
class IndexHealthTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-forage');

        $this->extend(
            (new Extend\ServiceProvider())->register(FakeForageProvider::class)
        );

        $this->setting(Settings::ENDPOINT, 'https://tenant.example.com');
        $this->setting(Settings::ADMIN_KEY, 'admin-key');
        $this->setting(Settings::INDEX, 'posts');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(), // id 2
            ],
            'discussions' => [
                ['id' => 1, 'title' => 'Ordinary', 'created_at' => '2026-01-01 00:00:00', 'last_posted_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'first_post_id' => 1, 'comment_count' => 1, 'slug' => 'ordinary', 'is_private' => 0],
                // Soft-deleted: its posts do not belong in the index.
                ['id' => 2, 'title' => 'Deleted thread', 'created_at' => '2026-01-01 00:00:00', 'last_posted_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'first_post_id' => 3, 'comment_count' => 1, 'slug' => 'deleted-thread', 'is_private' => 0, 'hidden_at' => '2026-01-02 00:00:00'],
            ],
            'posts' => [
                // Belongs.
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>one</p></t>', 'is_private' => 0],
                ['id' => 2, 'discussion_id' => 1, 'number' => 2, 'created_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>two</p></t>', 'is_private' => 0],
                // In a hidden discussion.
                ['id' => 3, 'discussion_id' => 2, 'number' => 1, 'created_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>three</p></t>', 'is_private' => 0],
                // Hidden by a moderator.
                ['id' => 4, 'discussion_id' => 1, 'number' => 3, 'created_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>four</p></t>', 'is_private' => 0, 'hidden_at' => '2026-01-02 00:00:00', 'hidden_user_id' => 1],
                // Not a comment: an event post carries nothing to search for.
                ['id' => 5, 'discussion_id' => 1, 'number' => 4, 'created_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'type' => 'discussionRenamed', 'content' => '["Old","New"]', 'is_private' => 0],
            ],
        ]);
    }

    /**
     * The one that keeps the warning honest.
     *
     * @test
     */
    #[Test]
    public function the_counted_rule_and_the_indexed_rule_agree(): void
    {
        /** @var IndexHealth $health */
        $health = $this->app()->getContainer()->make(IndexHealth::class);
        /** @var PostDocument $documents */
        $documents = $this->app()->getContainer()->make(PostDocument::class);

        $indexable = Post::query()->with('discussion')->get()
            ->filter(fn (Post $post) => $documents->isIndexable($post))
            ->count();

        $this->assertEquals(2, $indexable, 'only the two ordinary comments belong in the index');
        $this->assertEquals($indexable, $health->expected(), 'the SQL count must agree with the per-post rule');
    }

    /** @test */
    #[Test]
    public function an_empty_index_on_a_forum_with_posts_is_reported_through_the_api(): void
    {
        FakeForageClient::$count = 0;

        $body = $this->statusPayload();

        $this->assertEquals('empty', $body['health']);
        $this->assertEquals(0, $body['indexed']);
        $this->assertEquals(2, $body['expected']);
    }

    /** @test */
    #[Test]
    public function a_healthy_index_says_so(): void
    {
        FakeForageClient::$count = 2;

        $this->assertEquals('ok', $this->statusPayload()['health']);
    }

    /**
     * A key that may not read the index's statistics is not evidence of
     * anything, and must not put a warning on a working forum.
     *
     * @test
     */
    #[Test]
    public function an_unreadable_count_produces_no_verdict(): void
    {
        FakeForageClient::$count = null;

        $body = $this->statusPayload();

        $this->assertEquals('unknown', $body['health']);
        $this->assertNull($body['expected'], 'nothing to compare against, so the forum is not counted either');
    }

    /**
     * @return array<string, mixed>
     */
    protected function statusPayload(): array
    {
        $response = $this->send($this->request('GET', '/api/linkrobins-forage/status', ['authenticatedAs' => 1]));

        $this->assertEquals(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true);
    }
}
