<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use App\Tests\Vector\Queues\Fixtures\RecordingLogger;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\VectorAi\Auth\AiAccessInterface;
use Kinetis\VectorAi\Auth\AiAvailability;
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
            self::flags($this->contributor(new ThrowingAiAccess())->offering(Feature::cases())),
        );

        // the same contributor with a policy that answers offers what the defaults turn on
        self::assertSame(
            ['filters' => true, 'assistant' => true, 'assistantWrites' => false, 'writing' => true],
            self::flags($this->contributor(new AllowingAiAccess())->offering(Feature::cases())),
        );
    }

    public function testAThrowingDependencyIsLoggedByClassOnly(): void
    {
        $logger = new RecordingLogger();
        $secret = 'sk-provider-detail-that-must-not-be-logged';

        self::assertSame(
            ['filters' => false, 'assistant' => false, 'assistantWrites' => false, 'writing' => false],
            self::flags($this->contributor(new ThrowingAiAccess(onUse: new \DomainException($secret)), $logger)->offering(Feature::cases())),
        );

        self::assertCount(1, $logger->lines);
        self::assertCount(1, $logger->at('warning'));
        $line = $logger->at('warning')[0];
        self::assertStringContainsString(\DomainException::class, $line['message']);
        self::assertSame(['exception_class' => \DomainException::class], $line['context']);
        self::assertStringNotContainsString($secret, $line['message'] . json_encode($line['context'], JSON_THROW_ON_ERROR));

        // nothing failing logs nothing
        $quiet = new RecordingLogger();
        $this->contributor(new AllowingAiAccess(), $quiet)->offering(Feature::cases());
        self::assertSame([], $quiet->lines);
    }

    public function testAFailureBuildingAnotherUsersScopeTurnsEveryFeatureOffRatherThanFailingTheSchema(): void
    {
        $logger = new RecordingLogger();

        // their actor cannot even be built: their impersonator cannot be read
        $flags = $this->contributor(new AllowingAiAccess(), $logger)->capabilities(new UnreadableImpersonation());

        self::assertSame(['filters' => false, 'assistant' => false, 'assistantWrites' => false, 'writing' => false], self::flags($flags));
        self::assertCount(1, $logger->at('warning'));
        self::assertSame(['exception_class' => \DomainException::class], $logger->at('warning')[0]['context']);
    }

    public function testAnUnshippedFeatureAsksNoDependency(): void
    {
        // the throwing policy is never reached: nothing ships
        self::assertSame(
            ['filters' => false, 'assistant' => false, 'assistantWrites' => false, 'writing' => false],
            self::flags($this->contributor(new ThrowingAiAccess(onUse: new \LogicException('asked')))->offering([])),
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
        $visible = $scope->get(\Kinetis\Vector\Http\VisibleResources::class);
        $boundary = $scope->get(\Kinetis\VectorAi\Governance\ModelBoundary::class);
        self::assertInstanceOf(AiConfig::class, $config);
        self::assertInstanceOf(AiSettingsReader::class, $settings);
        self::assertInstanceOf(UsageLedger::class, $usage);
        self::assertInstanceOf(ProviderResolver::class, $resolver);
        self::assertInstanceOf(\Kinetis\Vector\Http\VisibleResources::class, $visible);
        self::assertInstanceOf(\Kinetis\VectorAi\Governance\ModelBoundary::class, $boundary);

        $user = $scope->get(CurrentUserInterface::class);
        self::assertInstanceOf(CurrentUserInterface::class, $user);

        $scopes = $scope->get(\Kinetis\Vector\Auth\ActorScope::class);
        self::assertInstanceOf(\Kinetis\Vector\Auth\ActorScope::class, $scopes);

        return new AiShellContributor(new AiAvailability($user, $access, $config, $settings, $usage, $resolver), $logger ?? new RecordingLogger(), $visible, $boundary, $user, $scopes);
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

/** A user whose impersonator cannot be read: no actor can be built for them. */
final readonly class UnreadableImpersonation implements CurrentUserInterface, \Kinetis\Vector\Auth\ImpersonatedUserInterface
{
    #[\Override]
    public function id(): string
    {
        return 'test-unreadable';
    }

    #[\Override]
    public function impersonatorId(): string|int|null
    {
        throw new \DomainException('the impersonation could not be read');
    }
}
