<?php

namespace LinkRobins\Forage\Listener;

use Flarum\Settings\Event\Saved;
use Illuminate\Contracts\Queue\Queue;
use LinkRobins\Forage\ConfigExchange;
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
        protected Queue $queue
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

        // Fill the index, but only when this save actually pointed the forum at
        // an empty one: a forum connecting for the first time, or moving to a
        // different search server. Re-saving a key that was already working
        // would otherwise rebuild the whole forum for nothing.
        //
        // Queued, because a forum with a long history takes a while and the
        // admin who pressed Save should not sit and watch it.
        if ($was !== $now) {
            $this->queue->push(new ReindexAll());
        }
    }
}
