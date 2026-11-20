<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Failure;

use Kinetis\RevoltHttpClient\Exception\HttpFailure;
use Kinetis\RevoltHttpClient\Exception\HttpRequestException;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use Psr\Log\LoggerInterface;

/**
 * Turns what a Neuron provider throws into an AiFailure, and logs the
 * category and the status only. A Neuron HttpException's message ends
 * with the provider's error body, which can quote the prompt, and its
 * request carries the API key in its headers, so neither is ever logged
 * or shown. Built per request.
 */
final readonly class AiFailureMapper
{
    public function __construct(private LoggerInterface $logger) {}

    public function map(\Throwable $failure): AiFailure
    {
        $mapped = match (true) {
            $failure instanceof AiFailure => $failure,
            $failure instanceof HttpException => self::fromHttp($failure),
            $failure instanceof ProviderException => new AiFailure(AiFailureCategory::Refused, null, null, $failure),
            default => new AiFailure(AiFailureCategory::Unavailable, null, null, $failure),
        };

        $this->logger->warning('An AI provider call failed.', ['category' => $mapped->category->value, 'status' => $mapped->providerStatus]);

        return $mapped;
    }

    private static function fromHttp(HttpException $failure): AiFailure
    {
        $status = $failure->response?->statusCode;

        if ($status !== null) {
            $category = match (true) {
                $status === 408, $status === 504 => AiFailureCategory::Timeout,
                $status === 413 => AiFailureCategory::TooLarge,
                $status === 401, $status === 403 => AiFailureCategory::Refused,
                $status === 400, $status === 404, $status === 422 => AiFailureCategory::InvalidRequest,
                default => AiFailureCategory::Unavailable,
            };

            return new AiFailure($category, null, $status, $failure);
        }

        $transport = $failure->getPrevious();
        $category = $transport instanceof HttpRequestException ? match ($transport->category) {
            HttpFailure::Timeout => AiFailureCategory::Timeout,
            HttpFailure::ResponseTooLarge => AiFailureCategory::TooLarge,
            HttpFailure::InvalidRequest => AiFailureCategory::InvalidRequest,
            default => AiFailureCategory::Unavailable,
        } : AiFailureCategory::Unavailable;

        return new AiFailure($category, null, null, $failure);
    }
}
