<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Client;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Processor to handle updating users when a Client is updated.
 * This handles the inverse side of the Client-User OneToMany relationship.
 *
 * @implements ProcessorInterface<Client, Client|void>
 */
final class ClientUsersProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ProcessorInterface $processor,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly RequestStack $requestStack
    ) {
    }

    /**
     * @param Client $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Client
    {
        // Get the request content to check for users array
        $request = $this->requestStack->getCurrentRequest();
        if ($request) {
            $content = json_decode($request->getContent(), true);

            if (isset($content['users']) && is_array($content['users'])) {
                $this->updateClientUsers($data, $content['users']);
            }
        }

        return $this->processor->process($data, $operation, $uriVariables, $context);
    }

    /**
     * Update the users assigned to this client
     *
     * @param Client $client
     * @param array $userIris Array of user IRIs (e.g., ["/api/v1/users/123"])
     */
    private function updateClientUsers(Client $client, array $userIris): void
    {
        // Extract user IDs from IRIs
        $newUserIds = [];
        foreach ($userIris as $iri) {
            // Extract ID from IRI like "/api/v1/users/123"
            if (preg_match('/\/api\/v1\/users\/(\d+)$/i', $iri, $matches)) {
                $newUserIds[] = (int) $matches[1];
            }
        }

        // Get current users assigned to this client
        $currentUsers = $client->getUsers()->toArray();
        $currentUserIds = array_map(fn(User $user) => $user->getId(), $currentUsers);

        // NOTE: intentionally do NOT auto-detach users that are absent from the
        // payload. A partial/stray `users` array (e.g. a client PATCH that didn't
        // mean to manage membership, or a list that wasn't fully loaded) would
        // otherwise silently null those users' client -> "Account Not Configured"
        // and they can no longer log in. Removing a user from a client is done
        // explicitly on the User resource (PATCH user with client: null), so this
        // processor only ADDS the users listed here.

        // Find users to add (in new but not in current)
        $usersToAdd = array_diff($newUserIds, $currentUserIds);
        foreach ($usersToAdd as $userId) {
            $user = $this->userRepository->find($userId);
            if ($user) {
                $user->setClient($client);
                $this->entityManager->persist($user);
            }
        }
    }
}
