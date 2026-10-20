<?php

declare(strict_types=1);

namespace Kinetis\Vector\Http;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Hidden;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\RoutePrefix;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Http\Responses\JsonResponse;
use Kinetis\Http\Responses\PlainTextResponse;
use Kinetis\Vector\OpenApi\OpenApiDocument;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Vector's REST API as an OpenAPI 3.1 document, for this user: the
 * resources, fields and actions the descriptor shows them, with
 * concrete paths, parameters and bodies. Served where the framework
 * serves its own `/openapi.json` ({@see \Kinetis\Vector\OpenApi\OpenApiGate});
 * the ETag covers the document and the identity, as the descriptor's
 * does. `#[Hidden]`, like the framework's own documentation routes,
 * keeps it out of the framework's document.
 */
#[Hidden]
#[RoutePrefix('/vector')]
#[Middleware('@vector')]
final readonly class OpenApiController
{
    public function __construct(
        private OpenApiDocument $document,
        private CurrentUserInterface $user,
    ) {}

    #[Get('/openapi.json')]
    public function document(ServerRequestInterface $request): ResponseInterface
    {
        $document = $this->document->build();
        $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $etag = '"' . hash('xxh128', $json . '|' . $this->user->id()) . '"';

        if (in_array($etag, array_map('trim', explode(',', $request->getHeaderLine('If-None-Match'))), true)) {
            return PlainTextResponse::create('', 304, ['ETag' => $etag]);
        }

        return JsonResponse::create($document, 200, ['ETag' => $etag, 'Cache-Control' => 'private, no-cache']);
    }
}
