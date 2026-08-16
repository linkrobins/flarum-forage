<?php

namespace LinkRobins\Forage\Api;

use LinkRobins\Forage\ForageClient;
use LinkRobins\Forage\IndexHealth;
use LinkRobins\Forage\Settings;

/**
 * What the admin page is told about the connection.
 *
 * Deliberately narrow: a status word, whether the server answers, and how much
 * is indexed. No keys, no endpoint credentials, nothing that would be a secret
 * if it ended up in a screenshot.
 */
class StatusPayload
{
    public function __construct(
        protected Settings $settings,
        protected ForageClient $client,
        protected IndexHealth $health
    ) {
    }

    /**
     * @return array{status: string, detail: string, configured: bool, reachable: bool, indexed: int|null, expected: int|null, health: string, cap: int}
     */
    public function build(): array
    {
        $configured = $this->settings->isConfigured();
        $reachable = $configured && $this->client->isReachable();

        // Only worth asking for if the server is answering at all.
        $indexed = $reachable ? $this->client->documentCount() : null;

        // Counting the forum's own posts is only worth doing when there is a
        // number to compare it against.
        $expected = $indexed === null ? null : $this->health->expected();

        return [
            'status' => $this->settings->status(),
            'detail' => $this->settings->statusDetail(),
            'configured' => $configured,
            'reachable' => $reachable,
            'indexed' => $indexed,
            'expected' => $expected,
            // ok | empty | short | unknown — whether the index holds what this
            // forum thinks it should.
            'health' => $this->health->verdict($indexed, $this->settings->postCap()),
            'cap' => $this->settings->postCap(),
        ];
    }
}
