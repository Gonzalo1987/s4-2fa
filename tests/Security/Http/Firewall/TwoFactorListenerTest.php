<?php

declare(strict_types=1);

namespace Scheb\TwoFactorBundle\Tests\Security\Http\Firewall;

use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenFactory;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenFactoryInterface;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Scheb\TwoFactorBundle\Security\Http\Authentication\AuthenticationRequiredHandlerInterface;
use Scheb\TwoFactorBundle\Security\Http\Firewall\TwoFactorListener;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Scheb\TwoFactorBundle\Security\TwoFactor\Trusted\TrustedDeviceManagerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\TwoFactorFirewallConfig;
use Scheb\TwoFactorBundle\Tests\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Authentication\AuthenticationManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\AuthenticationServiceException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

class TwoFactorListenerTest extends TestCase
{
    private const FIREWALL_NAME = 'firewallName';
    private const TWO_FACTOR_PROVIDERS = ['provider1', 'provider2'];

    /**
     * @var MockObject|TokenStorageInterface
     */
    private $tokenStorage;

    /**
     * @var MockObject|AuthenticationManagerInterface
     */
    private $authenticationManager;

    /**
     * @var MockObject|TwoFactorFirewallConfig
     */
    private $twoFactorFirewallConfig;

    /**
     * @var MockObject|AuthenticationSuccessHandlerInterface
     */
    private $successHandler;

    /**
     * @var MockObject|AuthenticationFailureHandlerInterface
     */
    private $failureHandler;

    /**
     * @var MockObject|AuthenticationRequiredHandlerInterface
     */
    private $authenticationRequiredHandler;

    /**
     * @var MockObject|CsrfTokenManagerInterface
     */
    private $csrfTokenManager;

    /**
     * @var MockObject|TrustedDeviceManagerInterface
     */
    private $trustedDeviceManager;

    /**
     * @var MockObject|EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var MockObject|TwoFactorTokenFactoryInterface
     */
    private $twoFactorTokenFactory;

    /**
     * @var MockObject|RequestEvent
     */
    private $requestEvent;

    /**
     * @var MockObject|Request
     */
    private $request;

    /**
     * @var MockObject|RedirectResponse
     */
    private $authFormRedirectResponse;

    /**
     * @var TwoFactorListener
     */
    private $listener;

    protected function setUp(): void
    {
        $this->tokenStorage = $this->createMock(TokenStorageInterface::class);
        $this->authenticationManager = $this->createMock(AuthenticationManagerInterface::class);
        $this->twoFactorFirewallConfig = $this->createMock(TwoFactorFirewallConfig::class);
        $this->successHandler = $this->createMock(AuthenticationSuccessHandlerInterface::class);
        $this->failureHandler = $this->createMock(AuthenticationFailureHandlerInterface::class);
        $this->authenticationRequiredHandler = $this->createMock(AuthenticationRequiredHandlerInterface::class);
        $this->csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $this->trustedDeviceManager = $this->createMock(TrustedDeviceManagerInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->twoFactorTokenFactory = $this->createMock(TwoFactorTokenFactory::class);

        $this->request = $this->createMock(Request::class);
        $this->requestEvent = $this->createMock(RequestEvent::class);
        $this->requestEvent
            ->expects($this->any())
            ->method('getRequest')
            ->willReturn($this->request);

        $this->twoFactorFirewallConfig
            ->expects($this->any())
            ->method('getFirewallName')
            ->willReturn(self::FIREWALL_NAME);
        $this->twoFactorFirewallConfig
            ->expects($this->any())
            ->method('getAuthCodeFromRequest')
            ->with($this->request)
            ->willReturn('authCode');
        $this->twoFactorFirewallConfig
            ->expects($this->any())
            ->method('getCsrfTokenId')
            ->willReturn('csrfTokenId');
        $this->twoFactorFirewallConfig
            ->expects($this->any())
            ->method('getCsrfTokenFromRequest')
            ->with($this->request)
            ->willReturn('csrfToken');

        $this->authFormRedirectResponse = $this->createMock(RedirectResponse::class);

        $this->listener = new TwoFactorListener(
            $this->tokenStorage,
            $this->authenticationManager,
            $this->twoFactorFirewallConfig,
            $this->successHandler,
            $this->failureHandler,
            $this->authenticationRequiredHandler,
            $this->csrfTokenManager,
            $this->trustedDeviceManager,
            $this->eventDispatcher,
            $this->twoFactorTokenFactory,
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @return MockObject|TokenInterface
     */
    private function createTwoFactorToken($firewallName = self::FIREWALL_NAME, $authenticatedToken = null, array $attributes = []): MockObject
    {
        $twoFactorToken = $this->createMock(TwoFactorTokenInterface::class);
        $twoFactorToken
            ->expects($this->any())
            ->method('getProviderKey')
            ->willReturn($firewallName);
        $twoFactorToken
            ->expects($this->any())
            ->method('getTwoFactorProviders')
            ->willReturn(self::TWO_FACTOR_PROVIDERS);
        $twoFactorToken
            ->expects($this->any())
            ->method('getAuthenticatedToken')
            ->willReturn($authenticatedToken ?? $this->createMock(TokenInterface::class));
        $twoFactorToken
            ->expects($this->any())
            ->method('getAttributes')
            ->willReturn($attributes);
        $twoFactorToken
            ->expects($this->any())
            ->method('hasAttribute')
            ->willReturnCallback(function ($key) use ($attributes) {
                return isset($attributes[$key]);
            });
        $twoFactorToken
            ->expects($this->any())
            ->method('getAttribute')
            ->willReturnCallback(function ($key) use ($attributes) {
                return $attributes[$key] ?? null;
            });

        return $twoFactorToken;
    }

    /**
     * @return MockObject|TokenInterface
     */
    private function createTwoFactorTokenWithRememberMeCookie(Cookie $rememberMeCookie): MockObject
    {
        $attributes = [TwoFactorTokenInterface::ATTRIBUTE_NAME_REMEMBER_ME_COOKIE => [$rememberMeCookie]];

        return $this->createTwoFactorToken(self::FIREWALL_NAME, null, $attributes);
    }

    /**
     * @return MockObject|TokenInterface
     */
    private function createAuthenticatedToken(string $user): MockObject
    {
        $authenticatedToken = $this->createMock(TokenInterface::class);
        $authenticatedToken
            ->expects($this->any())
            ->method('getUser')
            ->willReturn($user);

        return $authenticatedToken;
    }

    private function stubTokenStorageHasToken(TokenInterface $token): void
    {
        $this->tokenStorage
            ->expects($this->any())
            ->method('getToken')
            ->willReturn($token);
    }

    private function stubRequestIsCheckPath(bool $isCheckPath): void
    {
        $this->twoFactorFirewallConfig
            ->expects($this->any())
            ->method('isCheckPathRequest')
            ->with($this->request)
            ->willReturn($isCheckPath);
    }

    private function stubRequestHasTrustedParameter(bool $hasTrustedParam): void
    {
        $this->twoFactorFirewallConfig
            ->expects($this->any())
            ->method('hasTrustedDeviceParameterInRequest')
            ->with($this->request)
            ->willReturn($hasTrustedParam);
    }

    private function stubRememberMeSetsTrusted(bool $rememberMeSetsTrusted): void
    {
        $this->twoFactorFirewallConfig
            ->expects($this->any())
            ->method('isRememberMeSetsTrusted')
            ->willReturn($rememberMeSetsTrusted);
    }

    private function stubHandlersReturnResponse(): Response
    {
        $response = new Response();
        $this->successHandler
            ->expects($this->any())
            ->method('onAuthenticationSuccess')
            ->willReturn($response);
        $this->failureHandler
            ->expects($this->any())
            ->method('onAuthenticationFailure')
            ->willReturn($response);

        return $response;
    }

    private function stubAuthenticationManagerReturnsToken(MockObject $returnedToken): void
    {
        $this->authenticationManager
            ->expects($this->any())
            ->method('authenticate')
            ->willReturn($returnedToken);
    }

    private function stubAuthenticationManagerThrowsAuthenticationException(): void
    {
        $this->authenticationManager
            ->expects($this->any())
            ->method('authenticate')
            ->willThrowException(new AuthenticationException());
    }

    private function stubCsrfTokenIsValid(): void
    {
        $this->csrfTokenManager
            ->expects($this->any())
            ->method('isTokenValid')
            ->with(new CsrfToken('csrfTokenId', 'csrfToken'))
            ->willReturn(true);
    }

    private function stubCsrfTokenIsNotValid(): void
    {
        $this->csrfTokenManager
            ->expects($this->any())
            ->method('isTokenValid')
            ->with(new CsrfToken('csrfTokenId', 'csrfToken'))
            ->willReturn(false);
    }

    private function stubCanSetTrustedDevice(bool $canSetTrustedDevice): void
    {
        $this->trustedDeviceManager
            ->expects($this->any())
            ->method('canSetTrustedDevice')
            ->willReturn($canSetTrustedDevice);
    }

    private function assertRedirectToAuthForm(): void
    {
        $this->requestEvent
            ->expects($this->once())
            ->method('setResponse')
            ->with($this->identicalTo($this->authFormRedirectResponse));
    }

    private function assertEventsDispatched(array $eventTypes): void
    {
        $numEvents = \count($eventTypes);
        $consecutiveParams = [];
        foreach ($eventTypes as $eventType) {
            $consecutiveParams[] = [$this->isInstanceOf(TwoFactorAuthenticationEvent::class), $eventType];
        }

        $this->eventDispatcher
            ->expects($this->exactly($numEvents))
            ->method('dispatch')
            ->withConsecutive(...$consecutiveParams);
    }

    /**
     * @test
     */
    public function supports_notCheckPath_returnFalse(): void
    {
        $this->stubRequestIsCheckPath(false);
        $this->assertFalse($this->listener->supports($this->request));
    }

    /**
     * @test
     */
    public function supports_isCheckPath_returnTrue(): void
    {
        $this->stubRequestIsCheckPath(true);
        $this->assertTrue($this->listener->supports($this->request));
    }

    /**
     * @test
     */
    public function authenticate_noTwoFactorToken_throwAuthenticationServiceException(): void
    {
        $this->stubTokenStorageHasToken($this->createMock(TokenInterface::class));
        $this->stubRequestIsCheckPath(true);

        $this->expectException(AuthenticationServiceException::class);

        $this->listener->authenticate($this->requestEvent);
    }

    /**
     * @test
     */
    public function authenticate_differentFirewallName_throwAuthenticationServiceException(): void
    {
        $this->stubTokenStorageHasToken($this->createTwoFactorToken('otherFirewallName'));
        $this->stubRequestIsCheckPath(true);

        $this->expectException(AuthenticationServiceException::class);

        $this->listener->authenticate($this->requestEvent);
    }

    /**
     * @test
     */
    public function authenticate_isCheckPath_authenticateWithAuthenticationManager(): void
    {
        $authenticatedToken = $this->createMock(TokenInterface::class);
        $twoFactorToken = $this->createTwoFactorToken(self::FIREWALL_NAME, $authenticatedToken);
        $this->stubTokenStorageHasToken($twoFactorToken);
        $this->stubCsrfTokenIsValid();
        $this->stubHandlersReturnResponse();

        $credentialsToken = $this->createTwoFactorToken();
        $twoFactorToken
            ->expects($this->once())
            ->method('createWithCredentials')
            ->with('authCode')
            ->willReturn($credentialsToken);

        $this->authenticationManager
            ->expects($this->once())
            ->method('authenticate')
            ->with($this->identicalTo($credentialsToken))
            ->willReturn($this->createMock(TokenInterface::class));

        $this->listener->authenticate($this->requestEvent);
    }

    /**
     * @test
     */
    public function authenticate_authenticationException_dispatchFailureEvent(): void
    {
        $this->stubTokenStorageHasToken($this->createTwoFactorToken());
        $this->stubCsrfTokenIsValid();
        $this->stubAuthenticationManagerThrowsAuthenticationException();
        $this->stubHandlersReturnResponse();

        $this->assertEventsDispatched([
            TwoFactorAuthenticationEvents::ATTEMPT,
            TwoFactorAuthenticationEvents::FAILURE,
        ]);

        $this->listener->authenticate($this->requestEvent);
    }

    /**
     * @test
     */
    public function authenticate_authenticationException_setResponseFromFailureHandler(): void
    {
        $this->stubTokenStorageHasToken($this->createTwoFactorToken());
        $this->stubCsrfTokenIsValid();
        $this->stubAuthenticationManagerThrowsAuthenticationException();

        $response = $this->createMock(Response::class);
        $this->failureHandler
            ->expects($this->once())
            ->method('onAuthenticationFailure')
            ->willReturn($response);

        $this->requestEvent
            ->expects($this->once())
            ->method('setResponse')
            ->with($this->identicalTo($response));

        $this->listener->authenticate($this->requestEvent);
    }

    /**
     * @test
     */
    public function authenticate_csrfTokenInvalid_dispatchFailureEvent(): void
    {
        $this->stubTokenStorageHasToken($this->createTwoFactorToken());
        $this->stubCsrfTokenIsNotValid();
        $this->stubHandlersReturnResponse();

        $this->assertEventsDispatched([TwoFactorAuthenticationEvents::FAILURE]);

        $this->listener->authenticate($this->requestEvent);
    }

    /**
     * @test
     */
    public function authenticate_authenticationStepSuccessful_dispatchSuccessEvent(): void
    {
        $twoFactorToken = $this->createTwoFactorToken();
        $this->stubTokenStorageHasToken($twoFactorToken);
        $this->stubCsrfTokenIsValid();
        $this->stubAuthenticationManagerReturnsToken($twoFactorToken); // Must be TwoFactorToken

        $this->assertEventsDispatched([
            TwoFactorAuthenticationEvents::ATTEMPT,
            TwoFactorAuthenticationEvents::SUCCESS,
            $this->anything(),
        ]);

        $this->listener->authenticate($this->requestEvent);
    }

    /**
     * @test
     */
    public function authenticate_authenticationStepSuccessfulButNotCompleted_redirectToAuthenticationForm(): void
    {
        $twoFactorToken = $this->createTwoFactorToken();
        $this->stubTokenStorageHasToken($twoFactorToken);
        $this->stubCsrfTokenIsValid();
        $this->stubAuthenticationManagerReturnsToken($twoFactorToken); // Must be TwoFactorToken

        $this->authenticationRequiredHandler
            ->expects($this->once())
            ->method('onAuthenticationRequired')
            ->willReturn($this->authFormRedirectResponse);

        $this->assertRedirectToAuthForm();

        $this->listener->authenticate($this->requestEvent);
    }

    /**
     * @test
     */
    public function authenticate_authenticationStepSuccessfulButNotCompleted_dispatchRequireEvent(): void
    {
        $twoFactorToken = $this->createTwoFactorToken();
        $this->stubTokenStorageHasToken($twoFactorToken);
        $this->stubCsrfTokenIsValid();
        $this->stubAuthenticationManagerReturnsToken($twoFactorToken); // Must be TwoFactorToken

        $this->assertEventsDispatched([
            TwoFactorAuthenticationEvents::ATTEMPT,
            TwoFactorAuthenticationEvents::SUCCESS,
            TwoFactorAuthenticationEvents::REQUIRE,
        ]);

        $this->listener->authenticate($this->requestEvent);
    }

    /**
     * @test
     */
    public function authenticate_twoFactorProcessComplete_returnResponseFromSuccessHandler(): void
    {
        $this->stubTokenStorageHasToken($this->createTwoFactorToken());
        $this->stubCsrfTokenIsValid();
        $this->stubAuthenticationManagerReturnsToken($this->createMock(TokenInterface::class)); // Not a TwoFactorToken

        $response = $this->createMock(Response::class);
        $this->successHandler
            ->expects($this->once())
            ->method('onAuthenticationSuccess')
            ->willReturn($response);

        $this->requestEvent
            ->expects($this->once())
            ->method('setResponse')
            ->with($this->identicalTo($response));

        $this->listener->authenticate($this->requestEvent);
    }

    /**
     * @test
     */
    public function authenticate_twoFactorProcessComplete_dispatchCompleteEvent(): void
    {
        $this->stubTokenStorageHasToken($this->createTwoFactorToken());
        $this->stubCsrfTokenIsValid();
        $this->stubAuthenticationManagerReturnsToken($this->createMock(TokenInterface::class)); // Not a TwoFactorToken
        $this->stubHandlersReturnResponse();

        $this->assertEventsDispatched([
            TwoFactorAuthenticationEvents::ATTEMPT,
            TwoFactorAuthenticationEvents::SUCCESS,
            TwoFactorAuthenticationEvents::COMPLETE,
        ]);

        $this->listener->authenticate($this->requestEvent);
    }

    /**
     * @test
     */
    public function authenticate_twoFactorProcessCompleteWithTrustedEnabled_setTrustedDevice(): void
    {
        $authenticatedToken = $this->createAuthenticatedToken('user');

        $this->stubTokenStorageHasToken($this->createTwoFactorToken());
        $this->stubCsrfTokenIsValid();
        $this->stubRequestHasTrustedParameter(true);
        $this->stubRememberMeSetsTrusted(false);
        $this->stubCanSetTrustedDevice(true);
        $this->stubAuthenticationManagerReturnsToken($authenticatedToken); // Not a TwoFactorToken
        $this->stubHandlersReturnResponse();

        $this->trustedDeviceManager
            ->expects($this->once())
            ->method('addTrustedDevice')
            ->with('user', 'firewallName');

        $this->listener->authenticate($this->requestEvent);
    }

    /**
     * @test
     */
    public function authenticate_twoFactorProcessCompleteWithRememberMeSetsTrusted_setTrustedDevice(): void
    {
        $authenticatedToken = $this->createAuthenticatedToken('user');

        $rememberMeCookie = new Cookie('remember_me', 'value');
        $twoFactorToken = $this->createTwoFactorTokenWithRememberMeCookie($rememberMeCookie);

        $this->stubTokenStorageHasToken($twoFactorToken);
        $this->stubCsrfTokenIsValid();
        $this->stubRequestHasTrustedParameter(false);
        $this->stubRememberMeSetsTrusted(true);
        $this->stubCanSetTrustedDevice(true);
        $this->stubAuthenticationManagerReturnsToken($authenticatedToken); // Not a TwoFactorToken
        $this->stubHandlersReturnResponse();

        $this->trustedDeviceManager
            ->expects($this->once())
            ->method('addTrustedDevice')
            ->with('user', 'firewallName');

        $this->listener->authenticate($this->requestEvent);
    }

    /**
     * @test
     */
    public function authenticate_twoFactorProcessCompleteTrustedDeviceNotAllowed_notSetTrustedDevice(): void
    {
        $authenticatedToken = $this->createMock(TokenInterface::class);
        $authenticatedToken
            ->expects($this->any())
            ->method('getUser')
            ->willReturn('user');

        $this->stubTokenStorageHasToken($this->createTwoFactorToken());
        $this->stubCsrfTokenIsValid();
        $this->stubRequestHasTrustedParameter(true);
        $this->stubRememberMeSetsTrusted(false);
        $this->stubCanSetTrustedDevice(false);
        $this->stubAuthenticationManagerReturnsToken($authenticatedToken); // Not a TwoFactorToken
        $this->stubHandlersReturnResponse();

        $this->trustedDeviceManager
            ->expects($this->never())
            ->method('addTrustedDevice');

        $this->listener->authenticate($this->requestEvent);
    }

    /**
     * @test
     */
    public function authenticate_twoFactorProcessCompleteWithTrustedDisabled_notSetTrustedDevice(): void
    {
        $this->stubTokenStorageHasToken($this->createTwoFactorToken());
        $this->stubCsrfTokenIsValid();
        $this->stubRequestHasTrustedParameter(false);
        $this->stubRememberMeSetsTrusted(false);
        $this->stubCanSetTrustedDevice(true);
        $this->stubAuthenticationManagerReturnsToken($this->createMock(TokenInterface::class)); // Not a TwoFactorToken
        $this->stubHandlersReturnResponse();

        $this->trustedDeviceManager
            ->expects($this->never())
            ->method($this->anything());

        $this->listener->authenticate($this->requestEvent);
    }

    /**
     * @test
     */
    public function authenticate_twoFactorProcessCompleteWithRememberMeEnabled_setRememberMeCookies(): void
    {
        $authenticatedToken = $this->createMock(TokenInterface::class);
        $authenticatedToken
            ->expects($this->any())
            ->method('getUser')
            ->willReturn('user');

        $rememberMeCookie = new Cookie('remember_me', 'value');
        $twoFactorToken = $this->createTwoFactorTokenWithRememberMeCookie($rememberMeCookie);

        $this->stubTokenStorageHasToken($twoFactorToken);
        $this->stubCsrfTokenIsValid();
        $this->stubAuthenticationManagerReturnsToken($authenticatedToken); // Not a TwoFactorToken
        $response = $this->stubHandlersReturnResponse();

        $this->listener->authenticate($this->requestEvent);

        $this->assertContains($rememberMeCookie, $response->headers->getCookies());
    }
}
