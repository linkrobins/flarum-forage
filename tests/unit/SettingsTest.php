<?php

/*
 * This file is part of linkrobins/flarum-forage.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Forage\Tests\unit;

use LinkRobins\Forage\Settings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase
{
    /**
     * @param array<string, mixed> $values
     */
    protected function settings(array $values = []): Settings
    {
        return new Settings(new ArraySettingsRepository($values));
    }

    /** @test */
    #[Test]
    public function a_fresh_forum_is_unconfigured(): void
    {
        $settings = $this->settings();

        $this->assertEquals(Settings::STATUS_UNCONFIGURED, $settings->status());
        $this->assertFalse($settings->isConfigured());
        $this->assertFalse($settings->canSearch());
    }

    /** @test */
    #[Test]
    public function it_needs_both_an_endpoint_and_a_key_to_be_configured(): void
    {
        $this->assertFalse($this->settings([Settings::ENDPOINT => 'https://example.com'])->isConfigured());
        $this->assertFalse($this->settings([Settings::ADMIN_KEY => 'abc'])->isConfigured());
        $this->assertTrue($this->settings([
            Settings::ENDPOINT => 'https://example.com',
            Settings::ADMIN_KEY => 'abc',
        ])->isConfigured());
    }

    /**
     * A trailing slash in the stored endpoint would produce '//indexes/posts'
     * on every request.
     *
     * @test
     */
    #[Test]
    public function it_normalizes_a_trailing_slash_off_the_endpoint(): void
    {
        $settings = $this->settings([Settings::ENDPOINT => 'https://example.com/']);

        $this->assertEquals('https://example.com', $settings->endpoint());
    }

    /**
     * Searching with the admin key would work, which is exactly why it must not
     * be the first choice: the search-only key cannot write to the index.
     *
     * @test
     */
    #[Test]
    public function it_searches_with_the_search_key_when_there_is_one(): void
    {
        $settings = $this->settings([
            Settings::SEARCH_KEY => 'search',
            Settings::ADMIN_KEY => 'admin',
        ]);

        $this->assertEquals('search', $settings->keyForSearching());
    }

    /** @test */
    #[Test]
    public function it_falls_back_to_the_admin_key_when_no_search_key_was_issued(): void
    {
        $settings = $this->settings([Settings::ADMIN_KEY => 'admin']);

        $this->assertEquals('admin', $settings->keyForSearching());
    }

    /**
     * The index name is part of a contract shared with the service that runs
     * the search server, so an empty setting must not become an empty path.
     *
     * @test
     */
    #[Test]
    public function it_defaults_the_index_name(): void
    {
        $this->assertEquals('posts', $this->settings()->index());
        $this->assertEquals('posts', $this->settings([Settings::INDEX => ''])->index());
        $this->assertEquals('other', $this->settings([Settings::INDEX => 'other'])->index());
    }

    /** @test */
    #[Test]
    public function a_missing_or_negative_cap_means_no_cap(): void
    {
        $this->assertEquals(0, $this->settings()->postCap());
        $this->assertEquals(0, $this->settings([Settings::POST_CAP => '-5'])->postCap());
        $this->assertEquals(50000, $this->settings([Settings::POST_CAP => '50000'])->postCap());
    }

    /** @test */
    #[Test]
    public function storing_a_config_records_everything_the_exchange_returned(): void
    {
        $settings = $this->settings();

        $settings->storeConfig([
            'endpoint' => 'https://tenant.example.com/',
            'index' => 'posts',
            'search_key' => 'search',
            'admin_key' => 'admin',
            'post_cap' => 1000,
        ]);

        $this->assertEquals('https://tenant.example.com', $settings->endpoint());
        $this->assertEquals('search', $settings->searchKey());
        $this->assertEquals('admin', $settings->adminKey());
        $this->assertEquals(1000, $settings->postCap());
        $this->assertTrue($settings->isConfigured());
    }
}
