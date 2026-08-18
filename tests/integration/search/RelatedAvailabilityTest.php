<?php

/*
 * This file is part of linkrobins/flarum-forage.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Forage\Tests\integration\search;

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
        $this->setting(Settings::ENDPOINT, 'https://search.example.invalid');
        $this->setting(Settings::SEARCH_KEY, 'a-search-key');

        $this->assertTrue($this->available());
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

    protected function available(): mixed
    {
        $response = $this->send($this->request('GET', '/api'));

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);

        return $body['data']['attributes']['forageRelated'] ?? null;
    }
}
