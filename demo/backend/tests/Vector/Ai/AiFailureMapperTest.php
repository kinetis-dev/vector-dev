<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use Kinetis\RevoltHttpClient\Exception\HttpRequestException;
use Kinetis\VectorAi\Failure\AiFailureCategory;
use Kinetis\VectorAi\Failure\AiFailureMapper;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/** What a provider throws, as a category and a status; the log line carries those two only. */
final class AiFailureMapperTest extends TestCase
{
    public function testAStatusErrorIsCategorisedAndItsBodyIsNeverLogged(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, array<array-key, mixed>}> */
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = [(string) $message, $context];
            }
        };
        $request = new HttpRequest(HttpMethod::POST, 'https://api.example/v1/chat', ['Authorization' => 'Bearer sk-SECRET'], ['messages' => 'the prompt']);
        $mapper = new AiFailureMapper($logger);

        $failure = $mapper->map(HttpException::statusError($request, new HttpResponse(429, '{"error":"quota for the prompt"}', [])));
        self::assertSame(AiFailureCategory::Unavailable, $failure->category);
        self::assertSame(429, $failure->providerStatus);
        self::assertSame(503, $failure->httpStatus());
        self::assertStringNotContainsString('quota', $failure->getMessage());
        self::assertSame([['An AI provider call failed.', ['category' => 'unavailable', 'status' => 429]]], $logger->lines);
        self::assertStringNotContainsString('SECRET', json_encode($logger->lines, JSON_THROW_ON_ERROR));

        self::assertSame(AiFailureCategory::Refused, $mapper->map(HttpException::statusError($request, new HttpResponse(401, 'no', [])))->category);
        self::assertSame(AiFailureCategory::InvalidRequest, $mapper->map(HttpException::statusError($request, new HttpResponse(400, 'no', [])))->category);
        self::assertSame(AiFailureCategory::TooLarge, $mapper->map(HttpException::statusError($request, new HttpResponse(413, 'no', [])))->category);
        self::assertSame(AiFailureCategory::Refused, $mapper->map(new ProviderException('content policy'))->category);
    }

    public function testATransportFailureIsCategorisedByItsKind(): void
    {
        $mapper = new AiFailureMapper(new \Psr\Log\NullLogger());
        $request = new HttpRequest(HttpMethod::POST, 'https://api.example/v1/chat');
        $timeout = HttpRequestException::timedOut('POST', 'https://api.example', 90.0, 1);

        self::assertSame(AiFailureCategory::Timeout, $mapper->map(HttpException::networkError($request, 'timed out', $timeout))->category);
        self::assertSame(504, $mapper->map(HttpException::networkError($request, 'timed out', $timeout))->httpStatus());
        self::assertSame(AiFailureCategory::TooLarge, $mapper->map(HttpException::networkError($request, 'too large', HttpRequestException::responseTooLarge('POST', 'https://api.example', 1024)))->category);
        self::assertSame(AiFailureCategory::Unavailable, $mapper->map(new \RuntimeException('anything else'))->category);
    }
}
