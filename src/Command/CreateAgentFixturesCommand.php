<?php

namespace App\Command;

use App\Entity\Client;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Seeds a client-agent demo: one agent company that manages a few client
 * companies, with agent users (ROLE_USER_CLIENT_AGENT) able to order on behalf
 * of those managed clients. Idempotent — safe to re-run.
 */
#[AsCommand(
    name: 'app:create-agent-fixtures',
    description: 'Create a client-agent demo: agent company, managed clients and agent users'
)]
class CreateAgentFixturesCommand extends Command
{
    private const AGENT_CODE = 'AGENT-DEMO';
    private const MANAGED_CODES = ['MC-DEMO-001', 'MC-DEMO-002', 'MC-DEMO-003'];

    private const AGENT_USERS = [
        ['email' => 'agent1@agent-demo.com', 'firstName' => 'Marco', 'lastName' => 'Hoffmann'],
        ['email' => 'agent2@agent-demo.com', 'firstName' => 'Elena', 'lastName' => 'Weber'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('password', 'p', InputOption::VALUE_OPTIONAL, 'Password for seeded users', 'recouser123!')
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Remove previously seeded agent fixtures, then re-create');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $password = $input->getOption('password');

        if ($input->getOption('clear')) {
            $this->clear($io);
        }

        $clientRepo = $this->em->getRepository(Client::class);
        $userRepo = $this->em->getRepository(User::class);

        // 1) Agent company
        $agent = $clientRepo->findOneBy(['code' => self::AGENT_CODE]);
        if (!$agent) {
            $agent = (new Client())
                ->setName('Spartan Parts Trading GmbH')
                ->setCode(self::AGENT_CODE)
                ->setIsActive(true);
            $this->em->persist($agent);
            $io->writeln(sprintf('  Created agent company: %s (%s)', $agent->getName(), self::AGENT_CODE));
        } else {
            $io->writeln(sprintf('  Agent company exists: %s', self::AGENT_CODE));
        }
        $agent->setIsClientAgent(true);

        // 2) Managed client companies
        $managed = [];
        foreach (self::MANAGED_CODES as $i => $code) {
            $client = $clientRepo->findOneBy(['code' => $code]);
            if (!$client) {
                $client = (new Client())
                    ->setName(sprintf('Managed Client %02d', $i + 1))
                    ->setCode($code)
                    ->setIsActive(true);
                $this->em->persist($client);
                $io->writeln(sprintf('  Created managed client: %s', $code));
            }
            $managed[] = $client;

            // Link to agent (addManagedClient is idempotent via contains())
            $agent->addManagedClient($client);

            // One ordinary user per managed client
            $email = sprintf('user@%s.com', strtolower($code));
            if (!$userRepo->findOneBy(['email' => $email])) {
                $u = (new User())
                    ->setEmail($email)
                    ->setUsername($email)
                    ->setFirstName('Client')
                    ->setLastName(sprintf('User %02d', $i + 1))
                    ->setRoles(['ROLE_USER', 'ROLE_CLIENT'])
                    ->setIsActive(true);
                $u->setClient($client);
                $u->setPassword($this->passwordHasher->hashPassword($u, $password));
                $this->em->persist($u);
                $io->writeln(sprintf('  Created managed-client user: %s', $email));
            }
        }

        // 3) Agent users (can act on behalf of the managed clients)
        foreach (self::AGENT_USERS as $data) {
            $user = $userRepo->findOneBy(['email' => $data['email']]);
            if (!$user) {
                $user = new User();
                $user->setEmail($data['email']);
                $user->setUsername($data['email']);
                $user->setFirstName($data['firstName']);
                $user->setLastName($data['lastName']);
                $io->writeln(sprintf('  Created agent user: %s', $data['email']));
            } else {
                $io->writeln(sprintf('  Updated agent user: %s', $data['email']));
            }
            // InqTool parity: agents hold ONLY the agent role (no ROLE_CLIENT);
            // they shop exclusively via the on-behalf-of flow.
            $user->setRoles(['ROLE_USER', 'ROLE_USER_CLIENT_AGENT']);
            $user->setIsActive(true);
            $user->setClient($agent);
            $user->setPassword($this->passwordHasher->hashPassword($user, $password));
            $this->em->persist($user);
        }

        $this->em->flush();

        $io->success(sprintf(
            "Agent demo ready. Password: %s\n  Agent users: %s\n  Manages %d clients: %s",
            $password,
            implode(', ', array_column(self::AGENT_USERS, 'email')),
            count($managed),
            implode(', ', self::MANAGED_CODES)
        ));

        return Command::SUCCESS;
    }

    /**
     * Remove seeded users and clients so the command can be re-run cleanly.
     * Managed clients are unlinked from the agent first to clear the join rows.
     */
    private function clear(SymfonyStyle $io): void
    {
        $clientRepo = $this->em->getRepository(Client::class);
        $userRepo = $this->em->getRepository(User::class);

        $emails = array_merge(
            array_column(self::AGENT_USERS, 'email'),
            array_map(fn (string $c) => sprintf('user@%s.com', strtolower($c)), self::MANAGED_CODES)
        );
        foreach ($emails as $email) {
            if ($user = $userRepo->findOneBy(['email' => $email])) {
                $this->em->remove($user);
            }
        }

        $agent = $clientRepo->findOneBy(['code' => self::AGENT_CODE]);
        if ($agent) {
            foreach ($agent->getManagedClients()->toArray() as $mc) {
                $agent->removeManagedClient($mc);
            }
        }
        $this->em->flush();

        foreach (self::MANAGED_CODES as $code) {
            if ($client = $clientRepo->findOneBy(['code' => $code])) {
                $this->em->remove($client);
            }
        }
        if ($agent) {
            $this->em->remove($agent);
        }
        $this->em->flush();

        $io->writeln('  Cleared previous agent fixtures.');
    }
}
