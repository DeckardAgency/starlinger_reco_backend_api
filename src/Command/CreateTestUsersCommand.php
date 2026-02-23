<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-test-users',
    description: 'Create test user accounts for development/testing'
)]
class CreateTestUsersCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('password', 'p', InputOption::VALUE_OPTIONAL, 'Password for test users', 'recouser123!');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $password = $input->getOption('password');

        $testUsers = [
            ['email' => 'super@starlinger.com', 'firstName' => 'Super', 'lastName' => 'Admin', 'roles' => ['ROLE_ADMIN']],
            ['email' => 'admin@starlinger.com', 'firstName' => 'Admin', 'lastName' => 'User', 'roles' => ['ROLE_ADMIN']],
            ['email' => 'clientadmin@starlinger.com', 'firstName' => 'Client', 'lastName' => 'Admin', 'roles' => ['ROLE_CLIENT_ADMIN']],
            ['email' => 'recouser@starlinger.com', 'firstName' => 'Reco', 'lastName' => 'User', 'roles' => ['ROLE_USER']],
        ];

        $repo = $this->em->getRepository(User::class);

        foreach ($testUsers as $data) {
            $existing = $repo->findOneBy(['email' => $data['email']]);
            if ($existing) {
                // Update password and roles
                $existing->setPassword($this->passwordHasher->hashPassword($existing, $password));
                $existing->setRoles($data['roles']);
                $io->writeln(sprintf('  Updated: %s (%s)', $data['email'], implode(', ', $data['roles'])));
            } else {
                $user = new User();
                $user->setEmail($data['email']);
                $user->setUsername($data['email']);
                $user->setFirstName($data['firstName']);
                $user->setLastName($data['lastName']);
                $user->setRoles($data['roles']);
                $user->setIsActive(true);
                $user->setPassword($this->passwordHasher->hashPassword($user, $password));
                $this->em->persist($user);
                $io->writeln(sprintf('  Created: %s (%s)', $data['email'], implode(', ', $data['roles'])));
            }
        }

        $this->em->flush();

        $io->success(sprintf('Test users ready. Password: %s', $password));
        return Command::SUCCESS;
    }
}
