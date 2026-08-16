<?php

namespace LinkRobins\Forage\Listener;

use Flarum\Settings\Event\Saved;
use Illuminate\Contracts\Queue\Queue;
use LinkRobins\Forage\ConfigExchange;
use LinkRobins\Forage\ForageClient;
use LinkRobins\Forage\Job\ReindexAll;
use LinkRobins\Forage\Settings;

/**
 * Turns a saved setup token into a working configuration.
 *
 * The admin pastes one token and saves. Everything after that, the endpoint,
 * the keys, the plan's limit, arrives from the config service, so this is the
 * moment the extension goes from "some text in a box" to "connected".
 */
class ExchangeSetupToken
{
    public function __construct(
        protected Settings $settings,
        protected ConfigExchange $exchange,
        protected Queue $queue,
        protected ForageClient $client
    ) {
    }

    public function handle(Saved $event): void
    {
        // Only when the token itself was part of this save. Every other
        // settings save on the forum comes through here too.
        if (! array_key_exists(Settings::TOKEN, $event->settings)) {
            return;
        }

        $token = trim((string) $event->settings[Settings::TOKEN]);

        $was = $this->settings->isConfigured() ? $this->settings->endpoint().'/'.$this->settings->index() : '';

        $status = $this->exchange->exchange($token);

        if ($status !== Settings::STATUS_OK) {
            return;
        }

        $now = $this->settings->endpoint().'/'.$this->settings->index();

        // Fill the index when it needs filling — which is a fact about the
        // INDEX, not about which fields changed in this save. Asking the
        // server "how much do you hold?" covers every way an admin arrives
        // here with an empty index behind a valid key: connecting for the
        // first time, moving to a different search server, and — the case the
        // old endpoint-comparison missed — re-subscribing, where the same
        // handle means the same endpoint but the new container is empty.
        // Re-saving a key over a filled index still rebuilds nothing.
        //
        // A null count means the server would not say (a hiccup, or a
        // pre-stats key); guessing "empty" would rebuild a large forum on a
        // blip, so fall back to the endpoint comparison instead.
        //
        // Queued, because a forum with a long history takes a while and the
        // admin who pressed Save should not sit and watch it.
        $count = $this->client->documentCount();

        if ($count === 0 || ($count === null && $was !== $now)) {
            $this->queue->push(new ReindexAll());
        }
    }
}
