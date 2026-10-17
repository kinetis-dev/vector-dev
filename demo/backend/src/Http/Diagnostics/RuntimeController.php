<?php

declare(strict_types=1);

namespace App\Http\Diagnostics;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Hidden;
use Kinetis\Http\Responses\JsonResponse;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Runtime\AppEnvironment;
use Kinetis\Vector\Database\VectorDatabase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Revolt\EventLoop;

/**
 * What runtime served this request, for bin/check-frankenphp: the boot
 * that answered it and how many requests that boot has served (more
 * than one only under a persistent worker), the database clients
 * DB_DRIVER=auto built (native or PDO) and the event-loop driver.
 * Development only. Under any other APP_ENV, RuntimeGate answers every
 * method on this path with the Kernel's own 404 for an unmatched path
 * before routing runs, so this controller is never constructed and its
 * database dependencies are never resolved there. The check below is a
 * fallback for a deployment that restricts middleware discovery.
 */
#[Hidden]
final readonly class RuntimeController
{
    public function __construct(
        private AppEnvironment $environment,
        private RuntimeProbe $probe,
        private MysqlLink $db,
        private VectorDatabase $vector,
    ) {}

    #[Get(RuntimeGate::PATH)]
    public function runtime(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->environment !== AppEnvironment::Development) {
            return RuntimeGate::notFound($request->getUri()->getPath());
        }

        return JsonResponse::create([
            'sapi' => PHP_SAPI,
            'pid' => getmypid(),
            'boot' => ['id' => $this->probe->bootId, 'requestsServed' => $this->probe->served()],
            'database' => [
                'default' => $this->db::class,
                $this->vector->connection => $this->vector->link()::class,
            ],
            'eventLoop' => EventLoop::getDriver()::class,
        ], 200, ['Cache-Control' => 'no-store']);
    }
}
