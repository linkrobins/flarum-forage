<?php

namespace LinkRobins\Forage\Api\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Queue\Queue;
use Laminas\Diactoros\Response\JsonResponse;
use LinkRobins\Forage\Api\StatusPayload;
use LinkRobins\Forage\ConfigExchange;
use LinkRobins\Forage\Job\ReindexAll;
use LinkRobins\Forage\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Try the stored setup key again.
 *
 * This exists for one case in particular: a key that is valid but whose search
 * server is still being built. That answer is "wait a moment", so the admin
 * needs a way to ask again without retyping anything.
 */
class RetryController implements RequestHandlerInterface
{
    public function __construct(
        protected Settings $settings,
        protected ConfigExchange $exchange,
        protected StatusPayload $payload,
        protected Queue $queue
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $wasConfigured = $this->settings->isConfigured();

        $status = $this->exchange->exchange($this->settings->token());

        // Only fill the index if this attempt is what connected the forum.
        // Retrying on an already-working setup should not rebuild everything.
        if ($status === Settings::STATUS_OK && ! $wasConfigured) {
            $this->queue->push(new ReindexAll());
        }

        return new JsonResponse($this->payload->build());
    }
}
