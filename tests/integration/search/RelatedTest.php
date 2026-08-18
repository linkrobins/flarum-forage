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
use Illuminate\Contracts\Cache\Repository as Cache;
use LinkRobins\Forage\Search\ForageResults;
use LinkRobins\Forage\Settings;
use LinkRobins\Forage\Search\RelatedDiscussions;
use PHPUnit\Framework\Attributes\Test;

/**
 * Related discussions is a panel nobody asked for, rendered on pages guests can
 * read, built from a list the search server chose. So the tests that matter are
 * about what it refuses to show and when it declines to ask at all.
 */
class RelatedTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-forage');

        $this->extend(
            (new Extend\ServiceProvider())->register(FakeForageProvider::class)
        );

        // A connected forum, with the fake standing in for its search server.
        // The service will not ask a forum that cannot search, so leaving these
        // out would test nothing but the guard.
        $this->setting(Settings::ENDPOINT, 'https://search.example.invalid');
        $this->setting(Settings::SEARCH_KEY, 'a-search-key');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(), // id 2
            ],
            'discussions' => [
                ['id' => 1, 'title' => 'Alpha', 'created_at' => '2026-01-01 00:00:00', 'last_posted_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'first_post_id' => 1, 'comment_count' => 3, 'slug' => 'alpha', 'is_private' => 0],
                ['id' => 2, 'title' => 'Beta', 'created_at' => '2026-01-01 00:00:00', 'last_posted_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'first_post_id' => 2, 'comment_count' => 1, 'slug' => 'beta', 'is_private' => 0],
                // Private, so nobody may read it without an extension that says otherwise.
                ['id' => 3, 'title' => 'Secret', 'created_at' => '2026-01-01 00:00:00', 'last_posted_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'first_post_id' => 3, 'comment_count' => 1, 'slug' => 'secret', 'is_private' => 1],
                // Two more, so asking for a longer list has something to give.
                ['id' => 4, 'title' => 'Gamma', 'created_at' => '2026-01-01 00:00:00', 'last_posted_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'first_post_id' => 4, 'comment_count' => 1, 'slug' => 'gamma', 'is_private' => 0],
                ['id' => 5, 'title' => 'Delta', 'created_at' => '2026-01-01 00:00:00', 'last_posted_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'first_post_id' => 5, 'comment_count' => 1, 'slug' => 'delta', 'is_private' => 0],
            ],
            'posts' => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>apples</p></t>', 'is_private' => 0],
                ['id' => 2, 'discussion_id' => 2, 'number' => 1, 'created_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>bananas</p></t>', 'is_private' => 0],
                ['id' => 3, 'discussion_id' => 3, 'number' => 1, 'created_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>cherries</p></t>', 'is_private' => 1],
                ['id' => 4, 'discussion_id' => 4, 'number' => 1, 'created_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>damsons</p></t>', 'is_private' => 0],
                ['id' => 5, 'discussion_id' => 5, 'number' => 1, 'created_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>elderberries</p></t>', 'is_private' => 0],
            ],
        ]);

        FakeForageClient::forget();

        // The candidate cache is keyed on the discussion, its title and its
        // last reply, all of which this seed recreates identically for every
        // test in the class. Without this, the first test to run would answer
        // for the ones after it.
        $this->flushCache();
    }

    /**
     * The whole point: what else is like this, not this.
     *
     * @test
     */
    #[Test]
    public function the_discussion_being_read_is_not_related_to_itself(): void
    {
        FakeForageClient::$ids = [1, 2];

        $this->assertEquals([2], $this->ids($this->related(['discussion' => 1])));
    }

    /**
     * The one that matters. The search server has no idea who is asking, so a
     * candidate a member cannot read must not reach them as a TITLE either —
     * this panel shows titles, which is exactly what a private discussion is
     * hiding.
     *
     * @test
     */
    #[Test]
    public function candidates_a_member_may_not_read_are_dropped(): void
    {
        FakeForageClient::$ids = [3, 1];

        $this->assertEquals([1], $this->ids($this->related(['discussion' => 2])));
    }

    /**
     * Asking about a discussion nobody may read is answered the same way as
     * asking about one that does not exist. Anything else turns the endpoint
     * into a way to confirm a private discussion is there.
     *
     * @test
     */
    #[Test]
    public function a_source_discussion_a_member_may_not_read_is_treated_as_absent(): void
    {
        FakeForageClient::$ids = [1, 2];

        $this->assertEquals([], $this->related(['discussion' => 3])['data']);
        $this->assertSame(0, FakeForageClient::$searches, 'the search server should never have been asked');
    }

    /**
     * Search falls back to the database when the server cannot answer, because
     * a member typed a query and is owed something. Nobody asked for this
     * panel, so it goes quiet instead.
     *
     * @test
     */
    #[Test]
    public function a_search_server_that_cannot_answer_shows_nothing(): void
    {
        FakeForageClient::$ids = null;

        $this->assertEquals([], $this->related(['discussion' => 1])['data']);
    }

    /**
     * The composer asks on a pause in typing, so the first few keystrokes would
     * otherwise each become a query for a word that matches half the forum.
     *
     * @test
     */
    #[Test]
    public function a_short_composer_query_is_never_sent_to_the_search_server(): void
    {
        FakeForageClient::$ids = [1, 2];

        $this->assertEquals([], $this->related(['q' => 'abc'])['data']);
        $this->assertSame(0, FakeForageClient::$searches);

        $this->assertEquals([1, 2], $this->ids($this->related(['q' => 'abcd'])));
        $this->assertSame(1, FakeForageClient::$searches);
    }

    /**
     * The composer has no discussion to exclude, and must still not leak one.
     *
     * @test
     */
    #[Test]
    public function composer_suggestions_are_scoped_to_the_member_typing(): void
    {
        FakeForageClient::$ids = [3, 2];

        $this->assertEquals([2], $this->ids($this->related(['q' => 'bananas'])));
    }

    /**
     * A panel under every discussion is a query on every discussion page, so
     * the answer is kept. Only the candidates are cached, never who may see
     * them, which the visibility tests above cover on the cached path too.
     *
     * @test
     */
    #[Test]
    public function a_discussions_candidates_are_only_fetched_once(): void
    {
        FakeForageClient::$ids = [1, 2];

        $this->related(['discussion' => 1]);
        $this->related(['discussion' => 1]);

        $this->assertSame(1, FakeForageClient::$searches);
    }

    /**
     * The title is what the panel searches for, so a renamed discussion must
     * not keep answering with the old title's matches.
     *
     * @test
     */
    #[Test]
    public function renaming_a_discussion_asks_again(): void
    {
        FakeForageClient::$ids = [2];

        $this->related(['discussion' => 1]);
        $this->assertSame('Alpha', FakeForageClient::$query);

        $this->database()->table('discussions')->where('id', 1)->update(['title' => 'Alpha renamed']);

        $this->related(['discussion' => 1]);
        $this->assertSame('Alpha renamed', FakeForageClient::$query);
        $this->assertSame(2, FakeForageClient::$searches);
    }

    /**
     * Reply counts are shown next to each title, and core counts the opening
     * post in comment_count. Sending the raw column keeps that decision in one
     * place — the frontend takes one off it.
     *
     * @test
     */
    #[Test]
    public function a_result_carries_what_the_list_needs_to_render(): void
    {
        FakeForageClient::$ids = [1];

        $row = $this->related(['discussion' => 2])['data'][0];

        $this->assertSame(1, $row['id']);
        $this->assertSame('Alpha', $row['title']);
        $this->assertSame('alpha', $row['slug']);
        $this->assertSame(3, $row['commentCount']);

        // When it was last worth reading. The panel shows this on every row,
        // and a reply count only when there are replies to count.
        $this->assertStringStartsWith('2026-01-01T00:00:00', $row['lastPostedAt']);
    }

    /**
     * A discussion nobody has answered still has to say something, so the date
     * it started stands in for the date it was last posted in.
     *
     * @test
     */
    #[Test]
    public function a_discussion_with_no_last_post_falls_back_to_when_it_started(): void
    {
        FakeForageClient::$ids = [1];

        $this->database()->table('discussions')->where('id', 1)->update(['last_posted_at' => null]);

        $row = $this->related(['discussion' => 2])['data'][0];

        $this->assertStringStartsWith('2026-01-01T00:00:00', $row['lastPostedAt']);
    }

    /**
     * Five titles are rendered from this list, and every candidate in it is
     * carried into an IN clause on every discussion page view and kept in the
     * cache for a day. Search's own ceiling is a paging one and far too big to
     * borrow here.
     *
     * @test
     */
    #[Test]
    public function far_fewer_candidates_are_asked_for_than_a_search_asks_for(): void
    {
        FakeForageClient::$ids = [1, 2];

        $this->related(['discussion' => 1]);

        $this->assertSame(RelatedDiscussions::CANDIDATE_HITS, FakeForageClient::$limit);
        $this->assertLessThan(ForageResults::MAX_HITS, FakeForageClient::$limit);
    }

    /**
     * "More like this" asks the same question with a bigger answer, so the
     * five-row footer is not the last word on a forum with more to say.
     *
     * @test
     */
    #[Test]
    public function the_list_can_be_asked_for_more_than_the_footer_shows(): void
    {
        FakeForageClient::$ids = [1, 4, 5];

        $this->assertSame([1, 4, 5], $this->ids($this->related(['discussion' => 2, 'limit' => 15])));
        $this->assertSame([1, 4], $this->ids($this->related(['discussion' => 2, 'limit' => 2])));
        $this->assertSame([1], $this->ids($this->related(['discussion' => 2, 'limit' => 1])));
    }

    /**
     * The one number a member controls on a route that reaches the search
     * server, so it is bounded here rather than trusted.
     *
     * @test
     */
    #[Test]
    public function the_limit_is_capped_however_it_is_asked_for(): void
    {
        FakeForageClient::$ids = [1, 2];

        foreach (['9999', '-3', 'lots', '0'] as $limit) {
            $rows = $this->related(['discussion' => 2, 'limit' => $limit])['data'];

            $this->assertLessThanOrEqual(RelatedDiscussions::EXPANDED_LIMIT, count($rows), "limit={$limit}");
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function related(array $params): array
    {
        // The cache outlives a single request, which is the point of it, so a
        // test that wants a fresh look has to say so. Only the caching tests
        // above deliberately do not.
        $response = $this->send(
            $this->request('GET', '/api/linkrobins-forage/related', ['authenticatedAs' => 2])
                ->withQueryParams($params)
        );

        $this->assertEquals(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true);
    }

    /**
     * @param array<string, mixed> $body
     * @return list<int>
     */
    protected function ids(array $body): array
    {
        return array_map(fn (array $row): int => (int) $row['id'], $body['data']);
    }

    protected function flushCache(): void
    {
        $this->app()->getContainer()->make(Cache::class)->clear();
    }
}
