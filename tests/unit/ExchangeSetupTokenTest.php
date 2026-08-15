<?php

/*
 * This file is part of linkrobins/flarum-forage.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Forage\Tests\unit;

use Flarum\Settings\Event\Saved;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LinkRobins\Forage\Job\ReindexAll;
use LinkRobins\Forage\Listener\ExchangeSetupToken;
use LinkRobins\Forage\Settings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ExchangeSetupTokenTest extends TestCase
{
    protected Settings $settings;
    protected RecordingQueue $queue;

    /**
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $returns what the config service answers with
     */
    protected function listener(array $stored = [], array $returns = []): ExchangeSetupToken
    {
        $this->settings = new Settings(new ArraySettingsRepository($stored));
        $this->queue = new RecordingQueue();

        $exchange = new StubbedConfigExchange($this->settings, new NullLogger());
        $exchange->stub = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, [], (string) json_encode($returns + [
                'endpoint' => 'https://tenant.example.com',
                'index' => 'posts',
                'search_key' => 'search-key',
                'admin_key' => 'admin-key',
                'post_cap' => 0,
            ])),
        ]))]);

        return new ExchangeSetupToken($this->settings, $exchange, $this->queue);
    }

    /** @test */
    #[Test]
    public function connecting_for_the_first_time_fills_the_index(): void
    {
        $this->listener()->handle(new Saved([Settings::TOKEN => 'a-setup-key']));

        $this->assertCount(1, $this->queue->jobs);
        $this->assertInstanceOf(ReindexAll::class, $this->queue->jobs[0]);
    }

    /**
     * Re-saving a key that already works must not rebuild the whole forum. On a
     * large forum that is hours of pointless work for a page the admin may have
     * opened to change something else.
     *
     * @test
     */
    #[Test]
    public function re_saving_a_working_key_does_not_rebuild_anything(): void
    {
        $listener = $this->listener([
            Settings::ENDPOINT => 'https://tenant.example.com',
            Settings::INDEX => 'posts',
            Settings::ADMIN_KEY => 'admin-key',
        ]);

        $listener->handle(new Saved([Settings::TOKEN => 'a-setup-key']));

        $this->assertCount(0, $this->queue->jobs);
    }

    /**
     * A key for a different search server points the forum at an index that is
     * empty, so that one does have to be filled.
     *
     * @test
     */
    #[Test]
    public function moving_to_a_different_search_server_fills_the_new_index(): void
    {
        $listener = $this->listener([
            Settings::ENDPOINT => 'https://old-tenant.example.com',
            Settings::INDEX => 'posts',
            Settings::ADMIN_KEY => 'old-key',
        ]);

        $listener->handle(new Saved([Settings::TOKEN => 'a-setup-key']));

        $this->assertCount(1, $this->queue->jobs);
    }

    /**
     * Every settings save on the forum passes through this listener, so one
     * that has nothing to do with Forage must not call the config service.
     *
     * @test
     */
    #[Test]
    public function a_settings_save_that_does_not_touch_the_key_is_ignored(): void
    {
        $listener = $this->listener();

        $listener->handle(new Saved(['forum_title' => 'Something else']));

        $this->assertCount(0, $this->queue->jobs);
        $this->assertEquals(Settings::STATUS_UNCONFIGURED, $this->settings->status());
    }
}
