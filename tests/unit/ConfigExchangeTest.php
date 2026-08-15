<?php

/*
 * This file is part of linkrobins/flarum-forage.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Forage\Tests\unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LinkRobins\Forage\Settings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ConfigExchangeTest extends TestCase
{
    protected Settings $settings;

    /** @var array<int, array<string, mixed>> */
    protected array $sent = [];

    /**
     * @param list<Response> $responses
     * @param array<string, mixed> $stored
     */
    protected function exchange(array $responses, array $stored = []): StubbedConfigExchange
    {
        $this->settings = new Settings(new ArraySettingsRepository($stored));

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->sent));

        $exchange = new StubbedConfigExchange($this->settings, new NullLogger());
        $exchange->stub = new Client(['handler' => $stack]);

        return $exchange;
    }

    /** @test */
    #[Test]
    public function a_good_key_stores_the_whole_configuration(): void
    {
        $exchange = $this->exchange([
            new Response(200, [], (string) json_encode([
                'endpoint' => 'https://tenant.example.com',
                'index' => 'posts',
                'search_key' => 'search-key',
                'admin_key' => 'admin-key',
                'post_cap' => 50000,
            ])),
        ]);

        $this->assertEquals(Settings::STATUS_OK, $exchange->exchange('a-setup-key'));

        $this->assertEquals('https://tenant.example.com', $this->settings->endpoint());
        $this->assertEquals('posts', $this->settings->index());
        $this->assertEquals('search-key', $this->settings->searchKey());
        $this->assertEquals('admin-key', $this->settings->adminKey());
        $this->assertEquals(50000, $this->settings->postCap());
    }

    /**
     * The service is told which key to look up and nothing else. It has no
     * business knowing anything about the forum.
     *
     * @test
     */
    #[Test]
    public function it_sends_only_the_key(): void
    {
        $exchange = $this->exchange([
            new Response(200, [], (string) json_encode([
                'endpoint' => 'https://tenant.example.com',
                'admin_key' => 'admin-key',
            ])),
        ]);

        $exchange->exchange('a-setup-key');

        $request = $this->sent[0]['request'];
        $body = json_decode((string) $request->getBody(), true);

        $this->assertEquals(['token' => 'a-setup-key'], $body);
    }

    /**
     * An unnamed agent gets blocked before the request is ever seen.
     *
     * @test
     */
    #[Test]
    public function it_identifies_itself(): void
    {
        $exchange = $this->exchange([new Response(404)]);

        $exchange->exchange('a-setup-key');

        $this->assertStringContainsString('linkrobins-forage', $this->sent[0]['request']->getHeaderLine('User-Agent'));
    }

    /**
     * A server that is still being built is a wait, not a broken key, and the
     * two must not read the same to an admin.
     *
     * @test
     */
    #[Test]
    public function a_server_still_being_built_reads_as_provisioning(): void
    {
        $exchange = $this->exchange([new Response(503)]);

        $this->assertEquals(Settings::STATUS_PROVISIONING, $exchange->exchange('a-setup-key'));
    }

    /** @test */
    #[Test]
    public function an_unknown_key_reads_as_invalid(): void
    {
        $exchange = $this->exchange([new Response(404)]);

        $this->assertEquals(Settings::STATUS_INVALID, $exchange->exchange('a-setup-key'));
    }

    /** @test */
    #[Test]
    public function any_other_answer_is_an_error(): void
    {
        $exchange = $this->exchange([new Response(500)]);

        $this->assertEquals(Settings::STATUS_ERROR, $exchange->exchange('a-setup-key'));
    }

    /** @test */
    #[Test]
    public function a_body_without_a_key_in_it_is_an_error(): void
    {
        $exchange = $this->exchange([
            new Response(200, [], (string) json_encode(['endpoint' => 'https://tenant.example.com'])),
        ]);

        $this->assertEquals(Settings::STATUS_ERROR, $exchange->exchange('a-setup-key'));
    }

    /**
     * A forum that is already searching must not lose its configuration because
     * a later exchange failed. Turning a working search box off is a worse
     * outcome than an out-of-date status word.
     *
     * @test
     */
    #[Test]
    public function a_failed_exchange_leaves_a_working_configuration_alone(): void
    {
        $exchange = $this->exchange([new Response(503)], [
            Settings::ENDPOINT => 'https://tenant.example.com',
            Settings::ADMIN_KEY => 'admin-key',
        ]);

        $exchange->exchange('a-setup-key');

        $this->assertTrue($this->settings->isConfigured());
        $this->assertEquals('admin-key', $this->settings->adminKey());
    }

    /**
     * Clearing the field is how an admin disconnects, and the settings page
     * says so. It has to actually disconnect: a forum that kept indexing to a
     * search server after its owner cleared the key would be doing something
     * nobody asked for, and they would have no way of knowing.
     *
     * @test
     */
    #[Test]
    public function clearing_the_key_disconnects_the_forum(): void
    {
        $exchange = $this->exchange([], [
            Settings::ENDPOINT => 'https://tenant.example.com',
            Settings::INDEX => 'posts',
            Settings::SEARCH_KEY => 'search-key',
            Settings::ADMIN_KEY => 'admin-key',
            Settings::POST_CAP => '50000',
        ]);

        $this->assertEquals(Settings::STATUS_UNCONFIGURED, $exchange->exchange('   '));

        $this->assertFalse($this->settings->isConfigured(), 'nothing may be left that could keep indexing');
        $this->assertFalse($this->settings->canSearch(), 'nor anything that could keep answering searches');
        $this->assertEquals('', $this->settings->endpoint());
        $this->assertEquals('', $this->settings->adminKey());
        $this->assertEquals('', $this->settings->searchKey());
        // Nothing was asked of the service to do it.
        $this->assertCount(0, $this->sent);
    }

    /**
     * A key typed wrongly over a working one is a slip, not an instruction to
     * disconnect, so the setup that already worked is left alone.
     *
     * @test
     */
    #[Test]
    public function a_wrong_key_does_not_disconnect_a_working_forum(): void
    {
        $exchange = $this->exchange([new Response(404)], [
            Settings::ENDPOINT => 'https://tenant.example.com',
            Settings::ADMIN_KEY => 'admin-key',
        ]);

        $this->assertEquals(Settings::STATUS_INVALID, $exchange->exchange('a-mistyped-key'));
        $this->assertTrue($this->settings->isConfigured());
    }
}
