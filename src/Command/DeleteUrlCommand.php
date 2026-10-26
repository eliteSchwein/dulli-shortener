<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UrlRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:urls:delete',
    description: 'Delete a shortened URL by its hash.',
)]
final class DeleteUrlCommand extends Command
{
    public function __construct(private readonly UrlRepository $urlRepository)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('hash', InputArgument::REQUIRED, 'The short URL hash to delete');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $hash = (string) $input->getArgument('hash');
        $url = $this->urlRepository->findOneBy(['hash' => $hash]);

        if ($url === null) {
            $io->writeln(sprintf(' <error>Not found:</error> No URL with hash <comment>%s</comment>.', $hash));
            return Command::FAILURE;
        }

        $this->urlRepository->removeUrl($url);
        $io->writeln(sprintf(' <info>✓</info> Deleted URL <comment>%s</comment>.', $hash));

        return Command::SUCCESS;
    }
}
