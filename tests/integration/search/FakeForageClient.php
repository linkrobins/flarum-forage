<?php

/*
 * This file is part of linkrobins/flarum-forage.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Forage\Tests\integration\search;

use LinkRobins\Forage\ForageClient;

/**
 * A search server that answers whatever the test tells it to, and writes down
 * everything it was asked to do.
 *
 * Dictating the answers is the point for searching: the interesting cases are
 * the ones where the search server returns ids the person searching is not
 * allowed to see. Recording the calls is the point for indexing: what matters
 * is that Flarum handed us the change at all.
 */
class FakeForageClient extends ForageClient
{
    /** @var list<int>|null what the search server "found" */
    public static ?array $ids = [];

    /** @var list<array{id: int, discussion_id: int, title: string, content: string}> */
    public static array $indexed = [];

    /** @var list<int> */
    public static array $deleted = [];

    /** @var list<int> */
    public static array $deletedDiscussions = [];

    public static function forget(): void
    {
        self::$indexed = [];
        self::$deleted = [];
        self::$deletedDiscussions = [];
    }

    /** @var string|null the collapse field the last search asked for */
    public static ?string $distinct = null;

    public function searchPostIds(string $query, int $limit = 200, ?string $distinct = null): ?array
    {
        self::$distinct = $distinct;

        return self::$ids;
    }

    public function indexDocuments(array $documents): bool
    {
        foreach ($documents as $document) {
            self::$indexed[] = $document;
        }

        return true;
    }

    public function deleteDocument(int $id): bool
    {
        self::$deleted[] = $id;

        return true;
    }

    public function deleteByDiscussion(int $discussionId): bool
    {
        self::$deletedDiscussions[] = $discussionId;

        return true;
    }

    public function ensureIndex(): bool
    {
        return true;
    }

    /** What the search server claims to hold; null stands for "would not say". */
    public static ?int $count = 0;

    public function documentCount(): ?int
    {
        return self::$count;
    }

    public function isReachable(): bool
    {
        return true;
    }
}
