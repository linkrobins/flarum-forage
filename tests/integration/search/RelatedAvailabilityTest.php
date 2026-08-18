<?php

/*
 * This file is part of linkrobins/flarum-forage.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Forage\Tests\integration\search;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use LinkRobins\Forage\Settings;
use PHPUnit\Framework\Attributes\Test;

/**
 * The panels ask the forum on every discussion page and on every pause in the
 * composer, so a forum that cannot answer has to say so up front, in the
 * payload the page already loads. Anything else is a full Flarum boot per view
 * to be told no.
 */
class RelatedAvailabilityTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-forage');
    }

    /**
     * An extension that is enabled but has never been given a key: installed
     * on Monday, set up on Friday.
     *
     * @test
     */
    #[Test]
    public function a_forum_with_no_search_server_says_the_panels_are_off(): void
    {
        $this->assertFalse($this->available());
    }

    /**
     * Guests read discussion pages too, and the panel renders for them, so the
     * answer has to be there without logging in.
     *
     * @test
     */
    #[Test]
    public function a_connected_forum_says_the_panels_are_on(): void
    {
        $this->connected();

        $this->assertTrue($this->available());
        $this->assertTrue($this->available('forageRelatedComposer'));
    }

    /**
     * A key that was cleared, or a plan that lapsed and took the config with
     * it, is the same case as never having had one.
     *
     * @test
     */
    #[Test]
    public function an_endpoint_without_a_key_is_not_connected(): void
    {
        $this->setting(Settings::ENDPOINT, 'https://search.example.invalid');

        $this->assertFalse($this->available());
    }

    /**
     * An admin who wants the list under a thread but not the one that
     * interrupts the composer, and the other way round. Each panel asks its own
     * question of the payload, so each has to be able to answer differently.
     *
     * @test
     */
    #[Test]
    public function each_panel_can_be_switched_off_on_its_own(): void
    {
        $this->connected();
        $this->setting(Settings::RELATED_COMPOSER, false);

        $this->assertTrue($this->available());
        $this->assertFalse($this->available('forageRelatedComposer'));
    }

    /** @test */
    #[Test]
    public function the_panel_under_a_discussion_can_be_switched_off(): void
    {
        $this->connected();
        $this->setting(Settings::RELATED_DISCUSSION, false);

        $this->assertFalse($this->available());
        $this->assertTrue($this->available('forageRelatedComposer'));
    }

    /**
     * What a saved-off switch leaves in the settings table depends on the
     * driver, and an empty string must not read as "never set".
     *
     * @test
     */
    #[Test]
    public function an_empty_string_is_off_not_unset(): void
    {
        $this->connected();
        $this->setting(Settings::RELATED_DISCUSSION, '');

        $this->assertFalse($this->available());
    }

    /**
     * The admin page falls back to a setting's registered default whenever the
     * stored value is empty, and Flarum's own settings endpoint stores a JSON
     * false as '' on MariaDB. Left alone, an admin would switch a panel off,
     * save, and find the switch back on next time while the panel really was
     * off.
     *
     * @test
     */
    #[Test]
    public function switching_a_panel_off_stores_something_the_admin_page_reads_back_as_off(): void
    {
        $this->connected();

        $response = $this->send(
            $this->request('POST', '/api/settings', ['authenticatedAs' => 1])
                ->withParsedBody([Settings::RELATED_COMPOSER => false])
        );

        $this->assertEquals(204, $response->getStatusCode());

        $stored = $this->app()->getContainer()->make(SettingsRepositoryInterface::class)
            ->get(Settings::RELATED_COMPOSER);

        $this->assertSame('0', $stored);
        $this->assertFalse($this->available('forageRelatedComposer'));
    }

    /** @test */
    #[Test]
    public function switching_a_panel_back_on_stores_the_other_one(): void
    {
        $this->connected();

        $this->send(
            $this->request('POST', '/api/settings', ['authenticatedAs' => 1])
                ->withParsedBody([Settings::RELATED_COMPOSER => true])
        );

        $stored = $this->app()->getContainer()->make(SettingsRepositoryInterface::class)
            ->get(Settings::RELATED_COMPOSER);

        $this->assertSame('1', $stored);
        $this->assertTrue($this->available('forageRelatedComposer'));
    }

    protected function connected(): void
    {
        $this->setting(Settings::ENDPOINT, 'https://search.example.invalid');
        $this->setting(Settings::SEARCH_KEY, 'a-search-key');
    }

    protected function available(string $attribute = 'forageRelated'): mixed
    {
        $response = $this->send($this->request('GET', '/api'));

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);

        return $body['data']['attributes'][$attribute] ?? null;
    }
}
