<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * @implements ProcessorInterface<User, User|void>
 */
final  class UserPasswordHasher implements ProcessorInterface
{
    public function __construct(
        private readonly ProcessorInterface $processor,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager
    )
    {
    }

    /**
     * @param User $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): User
    {
        $this->enforceRolePolicy($data);
        $this->enforceClientPolicy($data);

        if (!$data->getPlainPassword()) {
            return $this->processor->process($data, $operation, $uriVariables, $context);
        }

        $hashedPassword = $this->passwordHasher->hashPassword(
            $data,
            $data->getPlainPassword()
        );
        $data->setPassword($hashedPassword);
        $data->eraseCredentials();

        return $this->processor->process($data, $operation, $uriVariables, $context);
    }

    /**
     * Prevent privilege escalation through the writable `roles` field:
     *  - only full admins may grant ROLE_ADMIN;
     *  - users who are neither admin nor client-admin (e.g. a customer editing
     *    their own profile) cannot change their roles at all.
     */
    private function enforceRolePolicy(User $data): void
    {
        $isAdmin = $this->security->isGranted('ROLE_ADMIN');
        $isClientAdmin = $this->security->isGranted('ROLE_CLIENT_ADMIN');

        if (!$isAdmin && in_array('ROLE_ADMIN', $data->getRoles(), true)) {
            throw new AccessDeniedHttpException('You are not allowed to assign the administrator role.');
        }

        if (!$isAdmin && !$isClientAdmin) {
            // Revert any attempted role change to the persisted value.
            $original = $data->getId() !== null
                ? ($this->entityManager->getUnitOfWork()->getOriginalEntityData($data)['roles'] ?? null)
                : null;
            $data->setRoles($original ?? []);
        }
    }

    /**
     * Prevent cross-tenant user creation via the writable `client` field: a non-admin
     * (client-admin) may only create/edit users within their OWN client. Admins may set
     * any client.
     */
    private function enforceClientPolicy(User $data): void
    {
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return;
        }

        $current = $this->security->getUser();
        $ownClient = ($current instanceof User) ? $current->getClient() : null;
        if ($ownClient !== null) {
            $data->setClient($ownClient);
        }
    }
}
