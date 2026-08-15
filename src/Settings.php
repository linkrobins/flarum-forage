<?php

namespace LinkRobins\Forage;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Typed access to Forage's settings, so nothing else parses raw setting strings.
 *
 * The split that matters: the admin types ONE thing (the setup token). Everything
 * else here is written by the extension after exchanging that token, and none of
 * it is ever serialized to the forum payload.
 */
final class Settings
{
    public const PREFIX = 'linkrobins-forage.';

    /** The one field an admin fills in. */
    public const TOKEN = self::PREFIX.'token';

    /** Written by the exchange, never by hand. */
    public const ENDPOINT = self::PREFIX.'endpoint';
    public const INDEX = self::PREFIX.'index';
    public const SEARCH_KEY = self::PREFIX.'search_key';
    public const ADMIN_KEY = self::PREFIX.'admin_key';
    public const POST_CAP = self::PREFIX.'post_cap';

    /** Status shown in the admin banner: ok | provisioning | invalid | error | unconfigured. */
    public const STATUS = self::PREFIX.'status';
    public const STATUS_DETAIL = self::PREFIX.'status_detail';
    public const CONFIGURED_AT = self::PREFIX.'configured_at';

    public const STATUS_OK = 'ok';
    public const STATUS_PROVISIONING = 'provisioning';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_ERROR = 'error';
    public const STATUS_UNCONFIGURED = 'unconfigured';

    /** The index contract is fixed: srvup's compaction cron assumes it. */
    public const DEFAULT_INDEX = 'posts';

    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function token(): string
    {
        return trim((string) $this->settings->get(self::TOKEN, ''));
    }

    public function endpoint(): string
    {
        return rtrim((string) $this->settings->get(self::ENDPOINT, ''), '/');
    }

    public function index(): string
    {
        $index = (string) $this->settings->get(self::INDEX, '');

        return $index !== '' ? $index : self::DEFAULT_INDEX;
    }

    public function searchKey(): string
    {
        return (string) $this->settings->get(self::SEARCH_KEY, '');
    }

    public function adminKey(): string
    {
        return (string) $this->settings->get(self::ADMIN_KEY, '');
    }

    /**
     * The tier's document limit. 0 means "not told", which we treat as no cap
     * rather than as a cap of zero.
     */
    public function postCap(): int
    {
        return max(0, (int) $this->settings->get(self::POST_CAP, 0));
    }

    public function status(): string
    {
        $status = (string) $this->settings->get(self::STATUS, '');

        return $status !== '' ? $status : self::STATUS_UNCONFIGURED;
    }

    public function statusDetail(): string
    {
        return (string) $this->settings->get(self::STATUS_DETAIL, '');
    }

    /**
     * Is there enough here to talk to the tenant at all?
     *
     * Deliberately does not consider status: a forum that is indexing fine
     * should not stop because a later token re-exchange happened to fail.
     */
    public function isConfigured(): bool
    {
        return $this->endpoint() !== '' && $this->adminKey() !== '';
    }

    /** Can we run a search? Searching needs less than indexing does. */
    public function canSearch(): bool
    {
        return $this->endpoint() !== '' && ($this->searchKey() !== '' || $this->adminKey() !== '');
    }

    /**
     * The key to search with. Prefers the search-only key, which is scoped to
     * queries and 403s on writes, so a search path can never mutate the index.
     */
    public function keyForSearching(): string
    {
        return $this->searchKey() !== '' ? $this->searchKey() : $this->adminKey();
    }

    /**
     * @param array{endpoint: string, index: string, search_key: string, admin_key: string, post_cap: int} $config
     */
    public function storeConfig(array $config): void
    {
        $this->settings->set(self::ENDPOINT, rtrim($config['endpoint'], '/'));
        $this->settings->set(self::INDEX, $config['index']);
        $this->settings->set(self::SEARCH_KEY, $config['search_key']);
        $this->settings->set(self::ADMIN_KEY, $config['admin_key']);
        $this->settings->set(self::POST_CAP, (string) $config['post_cap']);
        $this->settings->set(self::CONFIGURED_AT, (string) time());
    }

    public function setStatus(string $status, string $detail = ''): void
    {
        $this->settings->set(self::STATUS, $status);
        $this->settings->set(self::STATUS_DETAIL, $detail);
    }
}
