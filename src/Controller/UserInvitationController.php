<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserInvitationRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Public endpoints consumed by the webshop /register page: verify an invitation
 * token from the email, then complete it by creating the user account with the
 * password the invitee chose. Both paths are PUBLIC_ACCESS in security.yaml
 * (deliberately outside /api/v1, matching the access_control rules).
 */
class UserInvitationController extends AbstractController
{
    public function __construct(
        private readonly UserInvitationRepository $invitationRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/user_invitations/verify/{token}', name: 'user_invitation_verify', methods: ['GET'])]
    public function verify(string $token): JsonResponse
    {
        $invitation = $this->invitationRepository->findOneBy(['token' => $token]);

        if (!$invitation || !$invitation->canBeCompleted()) {
            // One generic answer for unknown, completed, revoked and expired tokens:
            // don't give an anonymous caller a token-state oracle.
            return $this->json([
                'valid' => false,
                'error' => 'This invitation link is invalid or has expired.',
            ], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'valid' => true,
            'email' => $invitation->getEmail(),
            'firstName' => $invitation->getFirstName(),
            'lastName' => $invitation->getLastName(),
        ]);
    }

    #[Route('/api/user_invitations/complete', name: 'user_invitation_complete', methods: ['POST'])]
    public function complete(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['token']) || !isset($data['password'])) {
            return $this->json(['error' => 'Token and password are required.'], Response::HTTP_BAD_REQUEST);
        }

        $invitation = $this->invitationRepository->findOneBy(['token' => (string) $data['token']]);
        if (!$invitation || !$invitation->canBeCompleted()) {
            return $this->json([
                'error' => 'This invitation link is invalid or has expired.',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Same password policy as the reset-password endpoint
        // (PasswordResetService::validatePassword): length + complexity.
        $violations = $this->validator->validate((string) $data['password'], [
            new Assert\NotBlank(['message' => 'Password cannot be blank']),
            new Assert\Length([
                'min' => 8,
                'minMessage' => 'Password must be at least {{ limit }} characters',
                'max' => 100,
                'maxMessage' => 'Password cannot be longer than {{ limit }} characters',
            ]),
            new Assert\Regex([
                'pattern' => '/[A-Z]/',
                'message' => 'Password must contain at least one uppercase letter.',
            ]),
            new Assert\Regex([
                'pattern' => '/[a-z]/',
                'message' => 'Password must contain at least one lowercase letter.',
            ]),
            new Assert\Regex([
                'pattern' => '/[0-9]/',
                'message' => 'Password must contain at least one number.',
            ]),
        ]);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[] = $violation->getMessage();
            }
            return $this->json([
                'error' => 'Password validation failed',
                'details' => $errors,
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($this->userRepository->findOneBy(['email' => $invitation->getEmail()])) {
            // Account already exists (e.g. created manually in the meantime).
            $invitation->markAsCompleted();
            $this->entityManager->flush();
            return $this->json([
                'error' => 'An account with this email already exists. Please log in or reset your password.',
            ], Response::HTTP_CONFLICT);
        }

        $user = new User();
        $user->setEmail($invitation->getEmail());
        $user->setFirstName($invitation->getFirstName());
        $user->setLastName($invitation->getLastName());
        $user->setRoles($invitation->getRoles());
        if ($invitation->getClient() !== null) {
            $user->setClient($invitation->getClient());
        }
        $user->setPassword($this->passwordHasher->hashPassword($user, (string) $data['password']));

        $invitation->markAsCompleted();

        try {
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            // Concurrent double-submit of the same token: the unique constraint on
            // user.email serializes the two requests — the loser lands here. Return
            // a clean 409 instead of an unhandled 500. (The winner already created
            // the account and marked the invitation completed.)
            return $this->json([
                'error' => 'An account with this email already exists. Please log in or reset your password.',
            ], Response::HTTP_CONFLICT);
        }

        $this->logger->info('User invitation completed', [
            'invitation_id' => $invitation->getId(),
            'user_id' => $user->getId(),
            'email' => $user->getEmail(),
        ]);

        return $this->json(['success' => true], Response::HTTP_CREATED);
    }
}
