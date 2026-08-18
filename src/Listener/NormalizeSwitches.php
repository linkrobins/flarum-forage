<?php

/*
 * This file is part of linkrobins/flarum-forage.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Forage\Listener;

use Flarum\Settings\Event\Serializing;
use LinkRobins\Forage\Settings;

/**
 * Writes the two panel switches as '1' or '0', never as ''.
 *
 * Flarum's admin page sends a switch as a JSON boolean and the settings table
 * takes it as it comes, so on MariaDB a switched-off panel lands as an empty
 * string. The backend reads that correctly, but the admin page does not: it
 * falls back to the registered default when a value is empty, so the switch
 * came back ON next time the page was opened while the panel was really off.
 *
 * Normalising here fixes the display and the storage at once, and leaves
 * "never saved" as the only absent case, which is what means "on".
 */
class NormalizeSwitches
{
    private const SWITCHES = [
        Settings::RELATED_DISCUSSION,
        Settings::RELATED_COMPOSER,
    ];

    public function handle(Serializing $event): void
    {
        if (! in_array($event->key, self::SWITCHES, true)) {
            return;
        }

        $event->value = $event->value === '' || $event->value === '0' ? '0' : '1';
    }
}
