<?php

namespace LinkRobins\Forage;

/**
 * How this extension identifies itself when it calls out.
 *
 * Sent on every request to both the config service and the search server. An
 * unnamed agent gets blocked before the request is ever seen, so this is not
 * decoration.
 */
final class Agent
{
    public const HEADERS = [
        'User-Agent' => 'linkrobins-forage (+https://linkrobins.com/forage)',
        'Accept' => 'application/json',
    ];
}
