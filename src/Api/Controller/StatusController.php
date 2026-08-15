<?php

namespace LinkRobins\Forage\Api\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use LinkRobins\Forage\Api\StatusPayload;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Live connection state for the admin page.
 *
 * The settings save writes the status server side, so the page cannot learn it
 * from the settings it just sent; it asks here instead.
 */
class StatusController implements RequestHandlerInterface
{
    public function __construct(
        protected StatusPayload $payload
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        return new JsonResponse($this->payload->build());
    }
}
