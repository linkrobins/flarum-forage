<?php

/*
 * This file is part of linkrobins/flarum-forage.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Forage\Tests\unit;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * A settings table in an array, so the unit tests do not need a database.
 */
class ArraySettingsRepository implements SettingsRepositoryInterface
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        protected array $values = []
    ) {
    }

    public function all(): array
    {
        return $this->values;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function delete(string $keyLike): void
    {
        foreach (array_keys($this->values) as $key) {
            if (fnmatch(str_replace('%', '*', $keyLike), (string) $key)) {
                unset($this->values[$key]);
            }
        }
    }
}
