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
use LinkRobins\Forage\Api\RelatedThrottler;
use LinkRobins\Forage\Settings;
use PHPUnit\Framework\Attributes\Test;

/**
 * The ceiling on this route, and the line between its two budgets.
 *
 * Each test seeds the counter to one below its limit rather than sending
 * hundreds of requests: the arithmetic is not what breaks. What breaks is a
 * caller landing in the wrong bucket, which is invisible from the outside,
 * because a throttled panel and a panel with nothing to show look identical.
 */
class RelatedThrottleTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-forage');

        $this->extend(
            (new Extend\ServiceProvider())->register(FakeForageProvider::class)
        );

        $this->setting(Settings::ENDPOINT, 'https://search.example.invalid');
        $this->setting(Settings::SEARCH_KEY, 'a-search-key');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(), // id 2
            ],
            'discussions' => [
                ['id' => 1, 'title' => 'Throttletest one', 'created_at' => '2026-01-01 00:00:00', 'last_posted_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'first_post_id' => 1, 'comment_count' => 1, 'slug' => 'throttletest-one', 'is_private' => 0],
                ['id' => 2, 'title' => 'Throttletest two', 'created_at' => '2026-01-01 00:00:00', 'last_posted_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'first_post_id' => 2, 'comment_count' => 1, 'slug' => 'throttletest-two', 'is_private' => 0],
            ],
            'posts' => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>apples</p></t>', 'is_private' => 0],
                ['id' => 2, 'discussion_id' => 2, 'number' => 1, 'created_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>apples</p></t>', 'is_private' => 0],
            ],
        ]);

        FakeForageClient::forget();
        FakeForageClient::$ids = [1, 2];
    }

    /** @test */
    #[Test]
    public function a_member_at_the_discussion_ceiling_is_turned_away(): void
    {
        $this->fill(true, RelatedThrottler::PER_MINUTE_DISCUSSION - 1);

        $this->assertEquals(200, $this->ask(['discussion' => 2]), 'the last one under the ceiling');
        $this->assertEquals(429, $this->ask(['discussion' => 2]), 'the one over it');
    }

    /** @test */
    #[Test]
    public function a_member_at_the_composer_ceiling_is_turned_away(): void
    {
        $this->fill(false, RelatedThrottler::PER_MINUTE_QUERY - 1);

        $this->assertEquals(200, $this->ask(['q' => 'apples']));
        $this->assertEquals(429, $this->ask(['q' => 'apples']));
    }

    /**
     * The reason the split exists: browsing a forum all afternoon must not use
     * up the budget the composer needs while somebody is writing a title.
     *
     * @test
     */
    #[Test]
    public function browsing_cannot_starve_the_composer(): void
    {
        $this->fill(true, RelatedThrottler::PER_MINUTE_DISCUSSION);

        $this->assertEquals(429, $this->ask(['discussion' => 2]));
        $this->assertEquals(200, $this->ask(['q' => 'apples']), 'the composer has its own budget');
    }

    /** @test */
    #[Test]
    public function the_composer_cannot_starve_browsing(): void
    {
        $this->fill(false, RelatedThrottler::PER_MINUTE_QUERY);

        $this->assertEquals(429, $this->ask(['q' => 'apples']));
        $this->assertEquals(200, $this->ask(['discussion' => 2]));
    }

    /**
     * Sending both is how a caller would try to spend the cheap budget and get
     * the expensive answer, so the bucket has to be chosen the same way the
     * controller chooses the work.
     *
     * @test
     */
    #[Test]
    public function asking_for_both_counts_as_the_one_that_is_answered(): void
    {
        $this->fill(true, RelatedThrottler::PER_MINUTE_DISCUSSION);

        $this->assertEquals(429, $this->ask(['discussion' => 2, 'q' => 'apples']));
    }

    /**
     * The ceilings are per member, not per forum.
     *
     * @test
     */
    #[Test]
    public function one_member_at_the_ceiling_does_not_stop_anyone_else(): void
    {
        $this->fill(true, RelatedThrottler::PER_MINUTE_DISCUSSION, 2);

        $this->assertEquals(429, $this->ask(['discussion' => 2], 2));
        $this->assertEquals(200, $this->ask(['discussion' => 2], 1));
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function ask(array $params, int $as = 2): int
    {
        return $this->send(
            $this->request('GET', '/api/linkrobins-forage/related', ['authenticatedAs' => $as])
                ->withQueryParams($params)
        )->getStatusCode();
    }

    /**
     * Put a bucket wherever the test needs it, through the same key builder the
     * throttler uses, so a renamed key cannot leave these passing against
     * nothing.
     */
    protected function fill(bool $discussion, int $hits, int $as = 2): void
    {
        $this->app()->getContainer()->make(Cache::class)->put(
            RelatedThrottler::bucketKey('u'.$as, $discussion),
            $hits,
            60
        );
    }
}
