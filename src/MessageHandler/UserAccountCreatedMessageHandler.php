<?php

namespace App\MessageHandler;

use App\Message\UserAccountCreatedMessage;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

#[AsMessageHandler]
class UserAccountCreatedMessageHandler
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly Environment $twig,
        private readonly string $clientAppUrl,
        private readonly string $senderEmail = 'noreply@example.com',
        private readonly string $senderName = 'Starlinger RECO'
    ) {
    }

    public function __invoke(UserAccountCreatedMessage $message): void
    {
        $user = $this->userRepository->find($message->getUserId());
        if (!$user) {
            $this->logger->error('User not found for welcome email', ['user_id' => $message->getUserId()]);
            return;
        }

        try {
            $loginUrl = rtrim($this->clientAppUrl, '/') . '/login';

            $htmlContent = $this->twig->render('emails/user/account_created.html.twig', [
                'user' => $user,
                'loginUrl' => $loginUrl,
            ]);

            $email = (new Email())
                ->from(new Address($this->senderEmail, $this->senderName))
                ->to(new Address($user->getEmail(), $user->getFullName() ?? $user->getEmail()))
                ->subject('Your Starlinger RECO account has been created')
                ->html($htmlContent);

            $this->mailer->send($email);

            $this->logger->info('Account-created welcome email sent', [
                'user_id' => $user->getId(),
                'email' => $user->getEmail(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send account-created welcome email', [
                'user_id' => $message->getUserId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
