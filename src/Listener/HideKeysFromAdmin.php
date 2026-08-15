<?php

namespace LinkRobins\Forage\Listener;

use Flarum\Settings\Event\Deserializing;
use LinkRobins\Forage\Settings;

/**
 * Keeps the tenant's keys out of the admin page's payload.
 *
 * Flarum hands the whole settings table to the admin frontend, so without this
 * both keys would be sitting in the page source of every admin session. The
 * admin key in particular can write to and delete from the search index, and
 * nothing in the interface has any use for either of them: the status banner is
 * driven by its own endpoint.
 *
 * The setup key an admin typed is left alone, so its field still shows what is
 * saved.
 */
class HideKeysFromAdmin
{
    public function handle(Deserializing $event): void
    {
        unset($event->settings[Settings::ADMIN_KEY], $event->settings[Settings::SEARCH_KEY]);
    }
}
