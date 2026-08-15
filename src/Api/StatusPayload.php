<?php

namespace LinkRobins\Forage\Api;

use LinkRobins\Forage\ForageClient;
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
        protected ForageClient $client
    ) {
    }

    /**
     * @return array{status: string, detail: string, configured: bool, reachable: bool, indexed: int|null, cap: int}
     */
    public function build(): array
    {
        $configured = $this->settings->isConfigured();
        $reachable = $configured && $this->client->isReachable();

        return [
            'status' => $this->settings->status(),
            'detail' => $this->settings->statusDetail(),
            'configured' => $configured,
            'reachable' => $reachable,
            // Only worth asking for if the server is answering at all.
            'indexed' => $reachable ? $this->client->documentCount() : null,
            'cap' => $this->settings->postCap(),
        ];
    }
}
