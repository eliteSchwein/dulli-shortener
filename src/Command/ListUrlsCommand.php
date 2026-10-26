<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Url;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\Table;

#[AsCommand(
    name: 'app:urls:list',
    description: 'List all shortened URL hashes and their targets.',
)]
final class ListUrlsCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('full', null, InputOption::VALUE_NONE, 'Display complete target URLs without shortening them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $urls = $this->entityManager->createQueryBuilder()
            ->select('u.hash', 'u.url')
            ->from(Url::class, 'u')
            ->orderBy('u.hash', 'ASC')
            ->getQuery()
            ->toIterable();

        $table = new Table($output);
        $table->setHeaders(['Hash', 'Target URL']);
        $table->setStyle('box');

        $count = 0;
        $full = (bool) $input->getOption('full');
        foreach ($urls as $url) {
            $target = (string) $url['url'];
            if (!$full && strlen($target) > 80) {
                $target = substr($target, 0, 77) . '...';
            }
            $table->addRow([(string) $url['hash'], $target]);
            ++$count;
        }

        if ($count === 0) {
            $io->note('No shortened URLs found.');
            return Command::SUCCESS;
        }

        $io->title('Shortened URLs');
        $table->render();
        $io->writeln(sprintf(' <info>%d</info> URL%s total', $count, $count === 1 ? '' : 's'));
        if (!$full) {
            $io->writeln(' <comment>Tip: add --full to display complete targets.</comment>');
        }

        return Command::SUCCESS;
    }
}
