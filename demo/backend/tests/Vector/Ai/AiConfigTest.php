<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\VectorAi\AiTransports;
use Kinetis\VectorAi\Config\AiConfig;
use Kinetis\VectorAi\Config\ProviderType;
use Kinetis\VectorAi\PackageBootstrap;
use Kinetis\VectorAi\Secrets\SecretBox;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The operator's VECTOR_AI_* configuration, read once at boot: what it
 * builds, and every configuration that cannot work stopping the boot with
 * a message naming the key at fault.
 */
final class AiConfigTest extends TestCase
{
    public function testNothingConfiguredIsNoProvider(): void
    {
        $config = AiConfig::fromConfig(new Config([]));

        self::assertSame([], $config->providers);
        self::assertFalse($config->configured());
        self::assertSame(90.0, $config->timeout);
        self::assertSame(4194304, $config->maxResponseBytes);
        self::assertSame(2048, $config->maxOutputTokens);
    }

    public function testEachProviderIsReadFromItsOwnScopedKeys(): void
    {
        $config = AiConfig::fromConfig(new Config([
            'VECTOR_AI_PROVIDERS' => 'openai, local-llm,bedrock,anthropic',
            'VECTOR_AI_OPENAI_TYPE' => 'openai',
            'VECTOR_AI_OPENAI_KEY' => 'sk-from-env',
            'VECTOR_AI_OPENAI_MODELS' => 'gpt-a, gpt-b',
            'VECTOR_AI_LOCAL_LLM_TYPE' => 'ollama',
            'VECTOR_AI_LOCAL_LLM_BASE_URL' => 'http://ollama:11434/api/',
            'VECTOR_AI_BEDROCK_TYPE' => 'bedrock',
            'VECTOR_AI_BEDROCK_REGION' => 'eu-central-1',
            'VECTOR_AI_ANTHROPIC_TYPE' => 'anthropic',
            'VECTOR_AI_TIMEOUT' => '30',
            'VECTOR_AI_MAX_OUTPUT_TOKENS' => '512',
        ]));

        self::assertSame(['openai', 'local-llm', 'bedrock', 'anthropic'], array_keys($config->providers));
        self::assertSame('VECTOR_AI_LOCAL_LLM_TYPE', AiConfig::key('TYPE', 'local-llm'));

        $openai = $config->providers['openai'];
        self::assertSame(ProviderType::OpenAi, $openai->type);
        self::assertSame('https://api.openai.com/v1', $openai->baseUrl);
        self::assertSame(['gpt-a', 'gpt-b'], $openai->models);
        self::assertTrue($openai->keyFromEnvironment());
        self::assertTrue($openai->allows('gpt-b'));
        self::assertFalse($openai->allows('gpt-c'));
        self::assertStringNotContainsString('sk-from-env', print_r($openai, true), 'a dump never shows the key');

        self::assertSame('http://ollama:11434/api', $config->providers['local-llm']->baseUrl);
        self::assertSame('eu-central-1', $config->providers['bedrock']->region);
        self::assertNull($config->providers['bedrock']->baseUrl);
        self::assertSame('https://api.anthropic.com/v1', $config->providers['anthropic']->baseUrl);
        self::assertTrue($config->providers['anthropic']->allows('any-model'), 'no allow-list admits any model');
        self::assertSame(30.0, $config->timeout);
        self::assertSame(512, $config->maxOutputTokens);

        // the transports are built at boot: one per HTTP origin, one per Bedrock region
        $transports = AiTransports::fromConfig($config);
        self::assertSame($transports->http($openai), $transports->http($openai));
        $transports->bedrock($config->providers['bedrock']);
        $transports->http($config->providers['local-llm']);
    }

    /**
     * @return iterable<string, array{0: array<string, string>, 1: string}>
     */
    public static function refusals(): iterable
    {
        yield 'an unknown type' => [['VECTOR_AI_PROVIDERS' => 'x', 'VECTOR_AI_X_TYPE' => 'gemini'], 'VECTOR_AI_X_TYPE is "gemini", not a provider type'];
        yield 'a provider without a type' => [['VECTOR_AI_PROVIDERS' => 'openai'], 'VECTOR_AI_OPENAI_TYPE is not set'];
        yield 'an http base URL for openai' => [['VECTOR_AI_PROVIDERS' => 'openai', 'VECTOR_AI_OPENAI_TYPE' => 'openai', 'VECTOR_AI_OPENAI_BASE_URL' => 'http://api.openai.com/v1'], 'VECTOR_AI_OPENAI_BASE_URL must use https://.'];
        yield 'an ftp base URL for ollama' => [['VECTOR_AI_PROVIDERS' => 'local', 'VECTOR_AI_LOCAL_TYPE' => 'ollama', 'VECTOR_AI_LOCAL_BASE_URL' => 'ftp://ollama/api'], 'VECTOR_AI_LOCAL_BASE_URL must use https:// or http://.'];
        yield 'credentials in a base URL' => [['VECTOR_AI_PROVIDERS' => 'proxy', 'VECTOR_AI_PROXY_TYPE' => 'openai-like', 'VECTOR_AI_PROXY_BASE_URL' => 'https://user:pass@proxy.example/v1'], 'VECTOR_AI_PROXY_BASE_URL is not a URL'];
        yield 'an openai-like provider without a base URL' => [['VECTOR_AI_PROVIDERS' => 'proxy', 'VECTOR_AI_PROXY_TYPE' => 'openai-like'], 'VECTOR_AI_PROXY_BASE_URL is not set'];
        yield 'another anthropic origin' => [['VECTOR_AI_PROVIDERS' => 'claude', 'VECTOR_AI_CLAUDE_TYPE' => 'anthropic', 'VECTOR_AI_CLAUDE_BASE_URL' => 'https://proxy.example/v1'], 'VECTOR_AI_CLAUDE_BASE_URL must be https://api.anthropic.com/v1'];
        yield 'bedrock without a region' => [['VECTOR_AI_PROVIDERS' => 'aws', 'VECTOR_AI_AWS_TYPE' => 'bedrock'], 'VECTOR_AI_AWS_REGION must name the AWS region'];
        yield 'a key for a keyless type' => [['VECTOR_AI_PROVIDERS' => 'aws', 'VECTOR_AI_AWS_TYPE' => 'bedrock', 'VECTOR_AI_AWS_REGION' => 'us-east-1', 'VECTOR_AI_AWS_KEY' => 'k'], 'VECTOR_AI_AWS_KEY is set, but a bedrock provider takes no API key'];
        yield 'a malformed name' => [['VECTOR_AI_PROVIDERS' => 'Open_AI'], 'VECTOR_AI_PROVIDERS names "Open_AI"'];
        yield 'a name twice' => [['VECTOR_AI_PROVIDERS' => 'f,f', 'VECTOR_AI_F_TYPE' => 'fake'], 'VECTOR_AI_PROVIDERS names "f" twice'];
        yield 'a bad timeout' => [['VECTOR_AI_TIMEOUT' => 'soon'], 'VECTOR_AI_TIMEOUT is "soon"'];
        yield 'a bad byte ceiling' => [['VECTOR_AI_MAX_RESPONSE_BYTES' => '-1'], 'VECTOR_AI_MAX_RESPONSE_BYTES is "-1"'];
    }

    /** @param array<string, string> $values */
    #[DataProvider('refusals')]
    public function testAConfigurationThatCannotWorkStopsTheBootNamingItsKey(array $values, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new PackageBootstrap())->register(new AppScope(), new Config($values));
    }

    public function testAMalformedVectorAiKeyStopsTheBoot(): void
    {
        foreach (['not-base64', 'base64:' . base64_encode('too short'), base64_encode(random_bytes(32))] as $value) {
            try {
                (new PackageBootstrap())->register(new AppScope(), new Config(['VECTOR_AI_KEY' => $value]));
                self::fail("VECTOR_AI_KEY \"{$value}\" was accepted");
            } catch (\InvalidArgumentException $e) {
                self::assertStringStartsWith('VECTOR_AI_KEY must be base64:', $e->getMessage());
                self::assertStringNotContainsString($value, $e->getMessage(), 'the message never repeats the value');
            }
        }
    }

    public function testTheBootstrapBindsRequestNeutralObjectsOnly(): void
    {
        $app = new AppScope();
        (new PackageBootstrap())->register($app, new Config(['VECTOR_AI_KEY' => 'base64:' . base64_encode(random_bytes(32))]));

        self::assertInstanceOf(AiConfig::class, $app->get(AiConfig::class));
        self::assertInstanceOf(AiTransports::class, $app->get(AiTransports::class));
        $box = $app->get(SecretBox::class);
        self::assertInstanceOf(SecretBox::class, $box);
        self::assertTrue($box->canSeal());
    }
}
