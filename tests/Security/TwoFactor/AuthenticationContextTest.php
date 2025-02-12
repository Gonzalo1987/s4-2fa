<?php

declare(strict_types=1);

namespace Scheb\TwoFactorBundle\Tests\Security\TwoFactor;

use PHPUnit\Framework\MockObject\MockObject;
use Scheb\TwoFactorBundle\Security\TwoFactor\AuthenticationContext;
use Scheb\TwoFactorBundle\Tests\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class AuthenticationContextTest extends TestCase
{
    /**
     * @var MockObject|Request
     */
    private $request;

    /**
     * @var MockObject|TokenInterface
     */
    private $token;

    /**
     * @var AuthenticationContext
     */
    private $authContext;

    protected function setUp(): void
    {
        $this->request = $this->createMock(Request::class);
        $this->token = $this->createMock(TokenInterface::class);
        $this->authContext = new AuthenticationContext($this->request, $this->token, 'firewallName');
    }

    /**
     * @test
     */
    public function getToken_objectInitialized_returnToken(): void
    {
        $returnValue = $this->authContext->getToken();
        $this->assertEquals($this->token, $returnValue);
    }

    /**
     * @test
     */
    public function getRequest_objectInitialized_returnRequest(): void
    {
        $returnValue = $this->authContext->getRequest();
        $this->assertEquals($this->request, $returnValue);
    }

    /**
     * @test
     */
    public function getSession_objectInitialized_returnSession(): void
    {
        // Mock the Request object
        $session = $this->createMock(SessionInterface::class);
        $this->request
            ->expects($this->once())
            ->method('getSession')
            ->willReturn($session);

        $returnValue = $this->authContext->getSession();
        $this->assertEquals($session, $returnValue);
    }

    /**
     * @test
     * @dataProvider provideUserObjectAndReturnValue
     */
    public function getUser_objectInitialized_returnValid($userObject, $expectedReturnValue): void
    {
        // Mock the TokenInterface
        $this->token
            ->expects($this->once())
            ->method('getUser')
            ->willReturn($userObject);

        $returnValue = $this->authContext->getUser();
        $this->assertEquals($expectedReturnValue, $returnValue);
    }

    public function provideUserObjectAndReturnValue(): array
    {
        $user = $this->createMock(UserInterface::class);

        return [
            [$user, $user],
            [null, null],
            ['anon.', null],
        ];
    }

    /**
     * @test
     */
    public function getFirewallName_hasValue_returnFirewallName(): void
    {
        $returnValue = $this->authContext->getFirewallName();
        $this->assertEquals('firewallName', $returnValue);
    }
}
