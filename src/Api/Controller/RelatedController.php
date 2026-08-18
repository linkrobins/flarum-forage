<?php

namespace LinkRobins\Forage\Api\Controller;

use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use LinkRobins\Forage\Search\RelatedDiscussions;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Discussions related to one that exists, or to a title being typed.
 *
 * Open to guests, because the panel under a discussion is part of the page a
 * guest is already reading — and everything it can return has been through
 * Flarum's own visibility scope first.
 *
 * Answers a plain payload rather than JSON:API. Nothing here needs the store:
 * the frontend renders titles and links them, and pushing half-built discussion
 * records into the store would let a related panel overwrite the fuller copy of
 * a discussion the page already loaded.
 */
class RelatedController implements RequestHandlerInterface
{
    public function __construct(
        protected RelatedDiscussions $related
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $params = $request->getQueryParams();

        $discussionId = $this->intParam($params, 'discussion');

        if ($discussionId > 0) {
            // Scoped before it is used as a query: an id someone cannot read is
            // treated as an id that is not there, so the endpoint cannot be used
            // to confirm a private discussion exists.
            $source = Discussion::whereVisibleTo($actor)->find($discussionId);

            $discussions = $source === null
                ? []
                : $this->related->forDiscussion($actor, $source);
        } else {
            $discussions = $this->related->forQuery($actor, $this->stringParam($params, 'q'));
        }

        return new JsonResponse([
            'data' => array_map(fn (Discussion $discussion): array => [
                'id' => (int) $discussion->id,
                'slug' => (string) $discussion->slug,
                'title' => (string) $discussion->title,
                'commentCount' => (int) $discussion->comment_count,
                // When it was last worth reading, which is the better question
                // than how many replies it has: a quiet forum is mostly threads
                // with an opening post and nothing else, and a list of those
                // labelled "0 replies" argues against itself. Falls back to
                // when it started, for a discussion nobody has answered.
                'lastPostedAt' => ($discussion->last_posted_at ?? $discussion->created_at)?->toIso8601String(),
            ], $discussions),
        ]);
    }

    /** Query strings are attacker-shaped: ?discussion[]=1 arrives as an array. */
    private function intParam(array $params, string $key): int
    {
        $value = $params[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function stringParam(array $params, string $key): string
    {
        $value = $params[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
