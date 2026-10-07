<?php

declare(strict_types=1);

namespace App\Auth;

use Kinetis\AuthJwt\JwtIssuer;
use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Session\Session;
use Kinetis\Vector\Auth\ImpersonationInterface;
use Kinetis\Vector\Exception\ForbiddenOperationException;

/**
 * Impersonation in both modes. Bearer mode re-issues a short token for
 * the target that carries `impersonator`; cookie mode swaps the session
 * identity and remembers the impersonator in the session. Either way the
 * impersonated user's own admission applies — an admin acting as an
 * editor sees what the editor sees.
 */
final readonly class DemoImpersonation implements ImpersonationInterface
{
    // as short as a signed-in session's access token, and never renewed: impersonation has no refresh token
    private const int TTL = LoginController::ACCESS_TTL_SECONDS;

    public function __construct(
        private JwtIssuer $issuer,
        private RequestScope $scope,
        private DemoActorResolver $users,
    ) {}

    #[\Override]
    public function canImpersonate(CurrentUserInterface $user): bool
    {
        return $user instanceof DemoActor && $user->isAdmin;
    }

    #[\Override]
    public function describe(string $targetId): ?array
    {
        $target = $this->users->resolve($targetId);

        return $target instanceof DemoActor ? ['id' => $target->id(), 'name' => $target->displayName()] : null;
    }

    #[\Override]
    public function start(CurrentUserInterface $actor, string $targetId): array
    {
        $target = $this->users->resolve($targetId);

        if (!$target instanceof DemoActor || !$actor instanceof DemoActor) {
            throw new ForbiddenOperationException('Unknown user.');
        }

        $session = $this->session();

        if ($session !== null) {
            $session->regenerate(); // a privilege change, like login: new id, new CSRF token
            $session->set('userId', $target->id());
            $session->set('userName', $target->displayName());
            $session->set('isAdmin', $target->isAdmin);
            $session->set('impersonator', $actor->id());
            $session->set('impersonatorName', $actor->displayName());

            return ['reload' => true];
        }

        return $this->token($target, $actor);
    }

    #[\Override]
    public function stop(CurrentUserInterface $impersonated): array
    {
        $impersonatorId = $impersonated instanceof DemoActor ? $impersonated->impersonatorId() : null;
        $impersonator = $impersonatorId === null ? null : $this->users->resolve((string) $impersonatorId);

        if (!$impersonator instanceof DemoActor) {
            throw new ForbiddenOperationException('The impersonator no longer exists.');
        }

        $session = $this->session();

        if ($session !== null) {
            $session->regenerate();
            $session->set('userId', $impersonator->id());
            $session->set('userName', $impersonator->displayName());
            $session->set('isAdmin', $impersonator->isAdmin);
            $session->remove('impersonator');
            $session->remove('impersonatorName');

            return ['reload' => true];
        }

        return $this->token($impersonator, null);
    }

    /** @return array<string, mixed> */
    private function token(DemoActor $subject, ?DemoActor $impersonator): array
    {
        $claims = ['name' => $subject->displayName(), 'vector' => $subject->isAdmin];

        if ($impersonator !== null) {
            $claims['impersonator'] = $impersonator->id();
            $claims['impersonatorName'] = $impersonator->displayName();
        }

        return [
            'accessToken' => $this->issuer->issue((string) $subject->id(), $claims, ttlSeconds: self::TTL),
            'tokenType' => 'Bearer',
            'expiresIn' => self::TTL,
            'user' => ['id' => $subject->id(), 'name' => $subject->displayName()],
        ];
    }

    private function session(): ?Session
    {
        if (!$this->scope->isRegistered(Session::class)) {
            return null;
        }

        $session = $this->scope->get(Session::class);

        return $session instanceof Session && is_int($session->get('userId')) ? $session : null;
    }
}
