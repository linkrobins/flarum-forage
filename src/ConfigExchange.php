<?php

namespace LinkRobins\Forage;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Trades a setup token for a tenant's search configuration.
 *
 * The token is the only thing an admin ever types. Everything else, endpoint
 * and keys included, comes back from here.
 */
class ConfigExchange
{
    public const ENDPOINT = 'https://linkrobins.com/forage/config';

    public function __construct(
        protected Settings $settings,
        protected LoggerInterface $log
    ) {
    }

    /**
     * Exchange the token and store the result.
     *
     * Returns the status it settled on, which is also what the admin banner
     * reads. Deliberately never throws: a bad token is an ordinary thing for an
     * admin to do, and it must not 500 the settings page.
     */
    public function exchange(string $token): string
    {
        $token = trim($token);

        // Clearing the field is how an admin disconnects, so it has to
        // actually disconnect: drop the stored endpoint and keys as well as
        // the status. Leaving them behind would keep the forum indexing to a
        // search server its owner had just told it to stop using.
        if ($token === '') {
            $this->settings->forget();
            $this->settings->setStatus(Settings::STATUS_UNCONFIGURED);

            return Settings::STATUS_UNCONFIGURED;
        }

        try {
            $response = $this->client()->post(self::ENDPOINT, [
                'json' => ['token' => $token],
                'headers' => Agent::HEADERS,
                'timeout' => 15,
                'http_errors' => false,
            ]);
        } catch (RequestException $e) {
            // The service was unreachable, which is not the same as the token
            // being wrong. Say so, and keep any working config already stored.
            $this->log->error('[linkrobins/forage] could not reach the config service: '.$e->getMessage());
            $this->settings->setStatus(Settings::STATUS_ERROR, 'unreachable');

            return Settings::STATUS_ERROR;
        }

        $status = $response->getStatusCode();

        // 503 means the tenant is still being provisioned. That is a wait, not a
        // failure, so it must not read as a broken key.
        if ($status === 503) {
            $this->settings->setStatus(Settings::STATUS_PROVISIONING);

            return Settings::STATUS_PROVISIONING;
        }

        if ($status === 404) {
            $this->settings->setStatus(Settings::STATUS_INVALID);

            return Settings::STATUS_INVALID;
        }

        if ($status !== 200) {
            $this->log->error('[linkrobins/forage] config service returned '.$status);
            $this->settings->setStatus(Settings::STATUS_ERROR, (string) $status);

            return Settings::STATUS_ERROR;
        }

        $body = json_decode((string) $response->getBody(), true);

        if (! is_array($body) || ! $this->looksComplete($body)) {
            $this->log->error('[linkrobins/forage] config service returned an unusable body');
            $this->settings->setStatus(Settings::STATUS_ERROR, 'malformed');

            return Settings::STATUS_ERROR;
        }

        $this->settings->storeConfig([
            'endpoint' => (string) $body['endpoint'],
            'index' => (string) ($body['index'] ?? Settings::DEFAULT_INDEX),
            'search_key' => (string) ($body['search_key'] ?? ''),
            'admin_key' => (string) $body['admin_key'],
            'post_cap' => (int) ($body['post_cap'] ?? 0),
        ]);

        $this->settings->setStatus(Settings::STATUS_OK);

        return Settings::STATUS_OK;
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function looksComplete(array $body): bool
    {
        return ! empty($body['endpoint']) && ! empty($body['admin_key']);
    }

    protected function client(): Client
    {
        return new Client();
    }
}
