<?php

/*
 * This file is part of linkrobins/flarum-forage.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Forage\Tests\integration\search;

use Flarum\Foundation\AbstractServiceProvider;
use LinkRobins\Forage\ForageClient;

class FakeForageProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(ForageClient::class, function ($container) {
            return $container->make(FakeForageClient::class);
        });
    }
}
