<?php

declare(strict_types=1);

namespace Scheb\TwoFactorBundle\Tests\Mailer;

use PHPUnit\Framework\MockObject\MockObject;
use Scheb\TwoFactorBundle\Mailer\SwiftAuthCodeMailer;
use Scheb\TwoFactorBundle\Model\Email\TwoFactorInterface;
use Scheb\TwoFactorBundle\Tests\TestCase;

class SwiftAuthCodeMailerTest extends TestCase
{
    /**
     * @var MockObject|\Swift_Mailer
     */
    private $swiftMailer;

    /**
     * @var SwiftAuthCodeMailer
     */
    private $mailer;

    protected function setUp(): void
    {
        $this->swiftMailer = $this->createMock(\Swift_Mailer::class);
        $this->mailer = new SwiftAuthCodeMailer($this->swiftMailer, 'sender@example.com', 'Sender Name');
    }

    /**
     * @test
     */
    public function sendAuthCode_withUserObject_sendEmail(): void
    {
        // Stub the user object
        $user = $this->createMock(TwoFactorInterface::class);
        $user
            ->expects($this->any())
            ->method('getEmailAuthRecipient')
            ->willReturn('recipient@example.com');
        $user
            ->expects($this->any())
            ->method('getEmailAuthCode')
            ->willReturn('1234');

        $messageValidator = function ($mail) {
            /* @var \Swift_Message $mail */
            $this->assertInstanceOf(\Swift_Message::class, $mail);
            $this->assertEquals('recipient@example.com', key($mail->getTo()));
            $this->assertEquals(['sender@example.com' => 'Sender Name'], $mail->getFrom());
            $this->assertEquals('Authentication Code', $mail->getSubject());
            $this->assertEquals('1234', $mail->getBody());

            return true;
        };

        // Expect mail to be sent
        $this->swiftMailer
            ->expects($this->once())
            ->method('send')
            ->with($this->callback($messageValidator));

        $this->mailer->sendAuthCode($user);
    }
}
