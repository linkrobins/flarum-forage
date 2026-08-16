<?php

namespace LinkRobins\Forage\Api\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Queue\Queue;
use Laminas\Diactoros\Response\JsonResponse;
use LinkRobins\Forage\Api\StatusPayload;
use LinkRobins\Forage\ConfigExchange;
use LinkRobins\Forage\ForageClient;
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
        protected Queue $queue,
        protected ForageClient $client
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $wasConfigured = $this->settings->isConfigured();

        $status = $this->exchange->exchange($this->settings->token());

        // Same rule as the settings-save listener: fill the index when it is
        // empty behind a valid key, whatever got it into that state. Retrying
        // on an already-working, already-filled setup rebuilds nothing.
        if ($status === Settings::STATUS_OK) {
            $count = $this->client->documentCount();

            if ($count === 0 || ($count === null && ! $wasConfigured)) {
                $this->queue->push(new ReindexAll());
            }
        }

        return new JsonResponse($this->payload->build());
    }
}
