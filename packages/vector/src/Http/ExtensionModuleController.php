<?php

declare(strict_types=1);

namespace Kinetis\Vector\Http;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Hidden;
use Kinetis\Http\Responses\ErrorResponse;
use Kinetis\Http\Responses\FileResponse;
use Kinetis\Http\Routing\Exception\RouteNotFoundException;
use Kinetis\Vector\Ui\ExtensionModules;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves the UI extension modules installed packages declare, from the
 * bytes {@see ExtensionModules} read at boot: a lookup in memory, so no
 * request reads a file, and only a declared module can be named — a
 * path is never resolved against the filesystem. Anything else under
 * the prefix, whatever it contains, gets the Kernel's own 404 for a path
 * no route matches.
 *
 * Outside `/vector/ui`, whose every path is the shell's deep link, and
 * outside the `vector` and `vector-shell` groups, as the hosted bundle's
 * own scripts are: those are static files the web server answers
 * without asking who is signed in. A module is code shipped in a
 * Composer package, not data; the data it shows comes through the
 * admin's API, which is behind the `vector` group.
 *
 * The hash in the path is a digest of the bytes served, so the response
 * is immutable: a changed module has another URL.
 */
#[Hidden]
final readonly class ExtensionModuleController
{
    public function __construct(private ExtensionModules $modules) {}

    #[Get('/vector/extensions/{vendor}/{package}/{hash}/{file}')]
    public function show(string $vendor, string $package, string $hash, string $file, ServerRequestInterface $request): ResponseInterface
    {
        $contents = $this->modules->contents("{$vendor}/{$package}/{$hash}/{$file}");

        if ($contents === null) {
            return ErrorResponse::create(404, RouteNotFoundException::forPath($request->getUri()->getPath())->getMessage());
        }

        return FileResponse::fromContents($contents, 'text/javascript; charset=utf-8', headers: [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
