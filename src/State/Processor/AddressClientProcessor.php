<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Address;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Prevents cross-tenant address writes via the writable `client` field. On create/update
 * a non-admin may only attach an address to their OWN client, regardless of the submitted
 * `client` IRI or the {clientId} in the subresource URL. Admins may set any client.
 *
 * @implements ProcessorInterface<Address, Address|void>
 */
final class AddressClientProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ProcessorInterface $processor,
        private readonly Security $security
    ) {
    }

    /**
     * @param Address $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            $current = $this->security->getUser();
            $ownClient = ($current instanceof User) ? $current->getClient() : null;
            if ($ownClient !== null) {
                $data->setClient($ownClient);
            }
        }

        return $this->processor->process($data, $operation, $uriVariables, $context);
    }
}
