<?php

namespace LinkRobins\Forage;

use Flarum\Post\CommentPost;
use Flarum\Post\Post;

/**
 * Turns a post into the document shape the tenant expects.
 *
 * The shape is fixed and shared with srvup, whose compaction cron and caps
 * assume it: {id, discussion_id, title, content}, primary key id, index "posts".
 * Do not add fields here without changing it there first.
 */
class PostDocument
{
    /** The search server rejects oversized documents, and a whole post is rarely the useful part. */
    public const MAX_CONTENT = 30000;

    /**
     * @return array{id: int, discussion_id: int, title: string, content: string}|null
     *         null when the post should not be in the index at all
     */
    public function forPost(Post $post): ?array
    {
        if (! $this->isIndexable($post)) {
            return null;
        }

        return [
            'id' => (int) $post->id,
            'discussion_id' => (int) $post->discussion_id,
            'title' => (string) ($post->discussion->title ?? ''),
            'content' => $this->plainText((string) $post->content),
        ];
    }

    /**
     * Only visible comments belong in the index.
     *
     * Hidden and unapproved posts are excluded at index time as well as at
     * query time. Indexing them and filtering later would mean the private text
     * still lives on the search server, which is the wrong place for it.
     */
    public function isIndexable(Post $post): bool
    {
        if (! $post instanceof CommentPost) {
            return false;
        }

        if ($post->hidden_at !== null) {
            return false;
        }

        // is_approved is added by flarum/approval and defaults to true; a post
        // awaiting approval is not public yet.
        if ($post->getAttribute('is_approved') === false) {
            return false;
        }

        return $post->discussion !== null && $post->discussion->hidden_at === null;
    }

    /**
     * Flarum stores post content as s9e XML, so the raw column is full of
     * markup. Strip it to plain text: the index is for matching words, and
     * shipping markup would both bloat the tenant and match on tag names.
     */
    public function plainText(string $content): string
    {
        $text = preg_replace('/<[^>]*>/', ' ', $content) ?? $content;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        if (mb_strlen($text) > self::MAX_CONTENT) {
            $text = mb_substr($text, 0, self::MAX_CONTENT);
        }

        return $text;
    }
}
