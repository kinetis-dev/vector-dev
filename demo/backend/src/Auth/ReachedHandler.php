<?php

declare(strict_types=1);

namespace App\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** A handler that remembers whether the request reached it: how a refusal is told from the page's own answer. */
final class ReachedHandler implements RequestHandlerInterface
{
    public bool $reached = false;

    public function __construct(private readonly RequestHandlerInterface $inner) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->reached = true;

        return $this->inner->handle($request);
    }
}
