<?php

/*
 * This file is part of linkrobins/flarum-forage.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Forage\Api;

use Flarum\Api\Schema;
use LinkRobins\Forage\Settings;

/**
 * Tells the forum frontend whether related discussions can be answered at all.
 *
 * Without this the panels would ask on every discussion page and every pause in
 * the composer of a forum that has no key, has let one lapse, or is still
 * provisioning — a full Flarum boot per request, for a list that is always
 * going to be empty.
 *
 * Fail-closed, because this rides on every forum response: anything unexpected
 * reads as "not available" rather than 500ing the boot payload.
 */
class ForumFields
{
    public function __construct(
        protected Settings $settings
    ) {
    }

    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('forageRelated')
                ->get(function (): bool {
                    try {
                        return $this->settings->canSearch();
                    } catch (\Throwable) {
                        return false;
                    }
                }),
        ];
    }
}
