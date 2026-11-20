<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use App\Tests\Vector\Queues\Fixtures\RecordingLogger;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\VectorAi\Auth\AiAccessInterface;
use Kinetis\VectorAi\Config\AiConfig;
use Kinetis\VectorAi\Feature;
use Kinetis\VectorAi\Providers\ProviderResolver;
use Kinetis\VectorAi\Settings\AiSettingsReader;
use Kinetis\VectorAi\Shell\AiShellContributor;
use Kinetis\VectorAi\Usage\UsageLedger;

/**
 * capabilities.ai never fails GET /vector/schema: whether a feature ships
 * is asked first, and a dependency that throws anything turns every
 * feature off. The demo's access policy is replaced by one that throws on
 * every use, with a provider configured and the settings at their
 * defaults, so nothing else would turn a feature off.
 */
final class AiShellContributorTest extends AiTestCase
{
    #[\Override]
    protected function aiConfig(): array
    {
        return ['VECTOR_AI_PROVIDERS' => 'test-fake', 'VECTOR_AI_TEST_FAKE_TYPE' => 'fake'];
    }

    #[\Override]
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $app->instance(AiAccessInterface::class, new ThrowingAiAccess());
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->vectorQuery()->table('vector_settings')->where('group', '=', 'ai')->delete();
    }

    public function testTheSchemaStillLoadsWithEveryFeatureOff(): void
    {
        $ai = $this->client->get('/vector/schema', headers: $this->adminHeaders())->assertOk()->json()['capabilities']['ai'] ?? null;

        self::assertSame(['filters' => false, 'assistant' => false, 'assistantWrites' => false, 'writing' => false, 'excluded' => [], 'excludedFields' => []], $ai);
    }

    public function testAThrowingDependencyTurnsEveryFeatureOff(): void
    {
        // with every feature shipping, the throwing policy is reached: an \Error, not an AiFailure
        self::assertSame(
            ['filters' => false, 'assistant' => false, 'assistantWrites' => false, 'writing' => false],
            self::flags($this->contributor(new ThrowingAiAccess())->offering(Feature::cases(), $this->user())),
        );

        // the same contributor with a policy that answers offers what the defaults turn on
        self::assertSame(
            ['filters' => true, 'assistant' => true, 'assistantWrites' => false, 'writing' => true],
            self::flags($this->contributor(new AllowingAiAccess())->offering(Feature::cases(), $this->user())),
        );
    }

    public function testAThrowingDependencyIsLoggedByClassOnly(): void
    {
        $logger = new RecordingLogger();
        $secret = 'sk-provider-detail-that-must-not-be-logged';

        self::assertSame(
            ['filters' => false, 'assistant' => false, 'assistantWrites' => false, 'writing' => false],
            self::flags($this->contributor(new ThrowingAiAccess(onUse: new \DomainException($secret)), $logger)->offering(Feature::cases(), $this->user())),
        );

        self::assertCount(1, $logger->lines);
        self::assertCount(1, $logger->at('warning'));
        $line = $logger->at('warning')[0];
        self::assertStringContainsString(\DomainException::class, $line['message']);
        self::assertSame(['exception_class' => \DomainException::class], $line['context']);
        self::assertStringNotContainsString($secret, $line['message'] . json_encode($line['context'], JSON_THROW_ON_ERROR));

        // nothing failing logs nothing
        $quiet = new RecordingLogger();
        $this->contributor(new AllowingAiAccess(), $quiet)->offering(Feature::cases(), $this->user());
        self::assertSame([], $quiet->lines);
    }

    public function testAnUnshippedFeatureAsksNoDependency(): void
    {
        // the throwing policy is never reached: nothing ships
        self::assertSame(
            ['filters' => false, 'assistant' => false, 'assistantWrites' => false, 'writing' => false],
            self::flags($this->contributor(new ThrowingAiAccess(onUse: new \LogicException('asked')))->offering([], $this->user())),
        );
    }

    /** The request scope contributor() builds its dependencies in, disposed after the test. */
    private ?\Kinetis\Container\RequestScope $scope = null;

    #[\Override]
    protected function tearDown(): void
    {
        $this->scope?->dispose();
        $this->scope = null;
        parent::tearDown();
    }

    private function contributor(AiAccessInterface $access, ?RecordingLogger $logger = null): AiShellContributor
    {
        if ($this->scope === null) {
            $this->scope = $this->app->createRequestScope();
            $this->scope->instance(CurrentUserInterface::class, $this->user());
        }

        $scope = $this->scope;
        $config = $scope->get(AiConfig::class);
        $settings = $scope->get(AiSettingsReader::class);
        $usage = $scope->get(UsageLedger::class);
        $resolver = $scope->get(ProviderResolver::class);
        $resources = $scope->get(\Kinetis\Vector\Query\ResourceService::class);
        $boundary = $scope->get(\Kinetis\VectorAi\Governance\ModelBoundary::class);
        self::assertInstanceOf(AiConfig::class, $config);
        self::assertInstanceOf(AiSettingsReader::class, $settings);
        self::assertInstanceOf(UsageLedger::class, $usage);
        self::assertInstanceOf(ProviderResolver::class, $resolver);
        self::assertInstanceOf(\Kinetis\Vector\Query\ResourceService::class, $resources);
        self::assertInstanceOf(\Kinetis\VectorAi\Governance\ModelBoundary::class, $boundary);

        return new AiShellContributor($config, $access, $settings, $usage, $resolver, $logger ?? new RecordingLogger(), $resources, $boundary);
    }

    /**
     * @param array{filters: bool, assistant: bool, assistantWrites: bool, writing: bool, excluded: object, excludedFields: object} $capabilities
     * @return array{filters: bool, assistant: bool, assistantWrites: bool, writing: bool}
     */
    private static function flags(array $capabilities): array
    {
        unset($capabilities['excluded'], $capabilities['excludedFields']);

        return $capabilities;
    }

    private function user(): CurrentUserInterface
    {
        $user = $this->createStub(CurrentUserInterface::class);
        $user->method('id')->willReturn('test-shell');

        return $user;
    }
}

/** A policy that fails on every use of a feature, and lets anyone configure. */
final readonly class ThrowingAiAccess implements AiAccessInterface
{
    public function __construct(private ?\Throwable $onUse = null) {}

    #[\Override]
    public function canUse(CurrentUserInterface $user, Feature $feature): bool
    {
        throw $this->onUse ?? new \Error('The access policy failed.');
    }

    #[\Override]
    public function canConfigure(CurrentUserInterface $user): bool
    {
        return true;
    }
}

/** A policy that lets anyone use and configure everything. */
final readonly class AllowingAiAccess implements AiAccessInterface
{
    #[\Override]
    public function canUse(CurrentUserInterface $user, Feature $feature): bool
    {
        return true;
    }

    #[\Override]
    public function canConfigure(CurrentUserInterface $user): bool
    {
        return true;
    }
}
