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

        $status = $this->exchange->exchange($token);

        // A newly connected forum has an empty index, so fill it. Queued,
        // because a forum with a long history takes a while and the admin
        // pressing Save should not sit and watch it.
        if ($status === Settings::STATUS_OK) {
            $this->queue->push(new ReindexAll());
        }
    }
}
