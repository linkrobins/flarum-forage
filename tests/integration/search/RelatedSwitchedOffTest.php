<?php

/*
 * This file is part of linkrobins/flarum-forage.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Forage\Tests\integration\search;

use Flarum\Extend;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use LinkRobins\Forage\Settings;
use PHPUnit\Framework\Attributes\Test;

/**
 * The frontend stops asking when an admin switches a panel off, which is the
 * point of the switch. The endpoint still has to refuse on its own: it is open
 * to anyone who can type a URL, and a panel an admin turned off should not be
 * reachable through one, nor should it keep spending the forum's search quota.
 */
class RelatedSwitchedOffTest extends TestCase
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

        $this->setting(Settings::RELATED_DISCUSSION, false);
        $this->setting(Settings::RELATED_COMPOSER, false);

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(), // id 2
            ],
            'discussions' => [
                ['id' => 1, 'title' => 'Switchtest one', 'created_at' => '2026-01-01 00:00:00', 'last_posted_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'first_post_id' => 1, 'comment_count' => 1, 'slug' => 'switchtest-one', 'is_private' => 0],
                ['id' => 2, 'title' => 'Switchtest two', 'created_at' => '2026-01-01 00:00:00', 'last_posted_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'first_post_id' => 2, 'comment_count' => 1, 'slug' => 'switchtest-two', 'is_private' => 0],
            ],
            'posts' => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>apples</p></t>', 'is_private' => 0],
                ['id' => 2, 'discussion_id' => 2, 'number' => 1, 'created_at' => '2026-01-01 00:00:00', 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>apples again</p></t>', 'is_private' => 0],
            ],
        ]);

        FakeForageClient::forget();
        FakeForageClient::$ids = [1, 2];
    }

    /** @test */
    #[Test]
    public function the_panel_under_a_discussion_answers_nothing_when_it_is_off(): void
    {
        $this->assertSame([], $this->related(['discussion' => 1]));
        $this->assertSame(0, FakeForageClient::$searches, 'a switched-off panel should not spend a search');
    }

    /** @test */
    #[Test]
    public function the_composer_answers_nothing_when_it_is_off(): void
    {
        $this->assertSame([], $this->related(['q' => 'switchtest']));
        $this->assertSame(0, FakeForageClient::$searches);
    }

    /**
     * The candidate list is cached for a day, so a forum that stops being able
     * to search has to stop answering rather than serve yesterday's list until
     * the cache runs out.
     *
     * @test
     */
    #[Test]
    public function a_forum_that_can_no_longer_search_answers_nothing_from_the_cache(): void
    {
        $this->setting(Settings::RELATED_DISCUSSION, true);

        // Warm the cache while the forum is connected.
        $this->assertSame([2], array_column($this->related(['discussion' => 1]), 'id'));

        $this->app()->getContainer()->make(SettingsRepositoryInterface::class)
            ->delete(Settings::ENDPOINT);

        $this->assertSame([], $this->related(['discussion' => 1]));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, mixed>
     */
    protected function related(array $params): array
    {
        $response = $this->send(
            $this->request('GET', '/api/linkrobins-forage/related', ['authenticatedAs' => 2])
                ->withQueryParams($params)
        );

        $this->assertEquals(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true)['data'];
    }
}
