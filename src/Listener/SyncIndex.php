<?php

namespace LinkRobins\Forage\Listener;

use Flarum\Discussion\Event\Deleted;
use Flarum\Discussion\Event\Hidden;
use Flarum\Discussion\Event\Renamed;
use Flarum\Discussion\Event\Restored;
use Flarum\Post\Event\Deleted as PostDeleted;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Queue;
use LinkRobins\Forage\Job\RemoveDiscussion;
use LinkRobins\Forage\Job\RemovePost;
use LinkRobins\Forage\Job\SyncDiscussion;
use LinkRobins\Forage\Settings;

/**
 * The discussion half of keeping the index in step.
 *
 * Posts are handled by Flarum itself, which watches the model and queues the
 * work: see PostIndexer. Discussions are not indexed as documents, so nothing
 * watches them, but they still change what a post's document should say and
 * whether it belongs in the index at all:
 *
 *   renamed    every one of its documents carries the title
 *   hidden     its posts stop being readable
 *   restored   they become readable again
 *   deleted    its posts are gone
 *
 * Only these four, deliberately. Watching the discussion model instead would
 * fire on every reply, because a reply bumps the discussion, and re-indexing a
 * whole discussion every time somebody posts in it would be absurd.
 *
 * One post event is handled here too, for a reason RemovePost explains.
 */
class SyncIndex
{
    public function __construct(
        protected Settings $settings,
        protected Queue $queue
    ) {
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Renamed::class, [$this, 'whenChanged']);
        $events->listen(Hidden::class, [$this, 'whenChanged']);
        $events->listen(Restored::class, [$this, 'whenChanged']);
        $events->listen(Deleted::class, [$this, 'whenDeleted']);

        // See RemovePost: Flarum's own path cannot carry a deletion across a
        // real queue, so deletions are queued here by id as well.
        $events->listen(PostDeleted::class, [$this, 'whenPostDeleted']);
    }

    public function whenChanged(Renamed|Hidden|Restored $event): void
    {
        $this->push(new SyncDiscussion((int) $event->discussion->id));
    }

    public function whenDeleted(Deleted $event): void
    {
        $this->push(new RemoveDiscussion((int) $event->discussion->id));
    }

    public function whenPostDeleted(PostDeleted $event): void
    {
        $this->push(new RemovePost((int) $event->post->id));
    }

    /**
     * Nothing is queued until the forum is actually connected, so a forum with
     * the extension enabled but no key does not pile up jobs that can only
     * no-op.
     */
    protected function push(object $job): void
    {
        if ($this->settings->isConfigured()) {
            $this->queue->push($job);
        }
    }
}
