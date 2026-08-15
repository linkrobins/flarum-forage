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
use PHPUnit\Framework\Attributes\Test;

class SearchTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-forage');

        $this->extend(
            (new Extend\ServiceProvider())->register(FakeForageProvider::class)
        );

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(), // id 2
            ],
            'discussions' => [
                ['id' => 1, 'title' => 'Alpha', 'created_at' => '2026-01-01 00:00:00', 'last_posted_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'first_post_id' => 1, 'comment_count' => 1, 'slug' => 'alpha', 'is_private' => 0],
                ['id' => 2, 'title' => 'Beta', 'created_at' => '2026-01-01 00:00:00', 'last_posted_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'first_post_id' => 2, 'comment_count' => 1, 'slug' => 'beta', 'is_private' => 0],
                // Private, so nobody may read it without an extension that says otherwise.
                ['id' => 3, 'title' => 'Secret', 'created_at' => '2026-01-01 00:00:00', 'last_posted_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'first_post_id' => 3, 'comment_count' => 1, 'slug' => 'secret', 'is_private' => 1],
            ],
            'posts' => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>apples</p></t>', 'is_private' => 0],
                ['id' => 2, 'discussion_id' => 2, 'number' => 1, 'created_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>bananas</p></t>', 'is_private' => 0],
                ['id' => 3, 'discussion_id' => 3, 'number' => 1, 'created_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>cherries</p></t>', 'is_private' => 1],
                // A second post in Alpha, hidden by a moderator.
                ['id' => 4, 'discussion_id' => 1, 'number' => 2, 'created_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>damsons</p></t>', 'is_private' => 0, 'hidden_at' => '2026-01-02 00:00:00', 'hidden_user_id' => 1],
            ],
        ]);
    }

    /**
     * The one that matters. The search server has no idea who is asking, so
     * everything it returns is a candidate: a forum whose search server is
     * confidently wrong must still not show anyone a post they cannot read.
     *
     * @test
     */
    #[Test]
    public function results_a_member_may_not_read_are_dropped(): void
    {
        FakeForageClient::$ids = [3, 4, 2, 1];

        $body = $this->searchDiscussions('anything');

        $this->assertEquals([2, 1], array_map(fn ($d) => (int) $d['id'], $body['data']));
    }

    /**
     * Relevance is the whole reason for sending the query out, so the order the
     * search server chose has to survive the trip through the database.
     *
     * @test
     */
    #[Test]
    public function discussions_come_back_in_the_order_the_search_server_ranked_them(): void
    {
        FakeForageClient::$ids = [1, 2];
        $this->assertEquals([1, 2], array_map(fn ($d) => (int) $d['id'], $this->searchDiscussions('anything')['data']));

        FakeForageClient::$ids = [2, 1];
        $this->assertEquals([2, 1], array_map(fn ($d) => (int) $d['id'], $this->searchDiscussions('anything')['data']));
    }

    /**
     * A search result should open on the post that matched, the same as it does
     * with the search Flarum ships with.
     *
     * @test
     */
    #[Test]
    public function a_result_points_at_the_post_that_matched(): void
    {
        FakeForageClient::$ids = [2];

        $body = $this->searchDiscussions('anything');

        $this->assertEquals('2', $body['data'][0]['relationships']['mostRelevantPost']['data']['id']);
    }

    /**
     * Nothing found means nothing found. Quietly running the query again
     * against the database would show results the search server had decided
     * were not matches.
     *
     * @test
     */
    #[Test]
    public function an_empty_answer_is_a_real_answer(): void
    {
        FakeForageClient::$ids = [];

        $this->assertEquals([], $this->searchDiscussions('bananas')['data']);
    }

    /**
     * A search server that cannot answer must not take the forum's search box
     * down with it: the query falls back to the search Flarum ships with.
     *
     * @test
     */
    #[Test]
    public function a_search_that_could_not_run_falls_back_to_the_forums_own_search(): void
    {
        // MySQL and MariaDB only add rows to a FULLTEXT index when the
        // transaction commits, and every test here runs inside a transaction
        // that is rolled back. So MATCH ... AGAINST cannot see anything this
        // test seeded, and no test on those drivers can exercise the search
        // Flarum ships with. Verified by hand: the same insert matches straight
        // after a commit and not before one. The other drivers evaluate their
        // fulltext condition per row, so they can.
        $driver = $this->database()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Fulltext indexes on '.$driver.' are not updated until commit, and tests run in a rolled-back transaction.');
        }

        FakeForageClient::$ids = null;

        $body = $this->searchDiscussions('bananas');

        $this->assertEquals([2], array_map(fn ($d) => (int) $d['id'], $body['data']));
    }

    /** @test */
    #[Test]
    public function post_search_drops_results_a_member_may_not_read(): void
    {
        FakeForageClient::$ids = [3, 4, 2, 1];

        $response = $this->send(
            $this->request('GET', '/api/posts', ['authenticatedAs' => 2])
                ->withQueryParams(['filter' => ['q' => 'anything']])
        );

        $body = json_decode((string) $response->getBody(), true);

        $this->assertEquals([2, 1], array_map(fn ($p) => (int) $p['id'], $body['data']));
    }

    /**
     * @return array<string, mixed>
     */
    protected function searchDiscussions(string $query): array
    {
        $response = $this->send(
            $this->request('GET', '/api/discussions', ['authenticatedAs' => 2])
                ->withQueryParams(['filter' => ['q' => $query]])
        );

        $this->assertEquals(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true);
    }
}
