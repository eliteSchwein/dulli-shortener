<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\PhishingDomainRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:phishing-list:update',
    description: 'Download the Phishing.Database domain list and replace the local copy.',
)]
final class UpdatePhishingListCommand extends Command
{
    private const string SOURCE_URL = 'https://raw.githubusercontent.com/mitchellkrogza/Phishing.Database/master/phishing-domains-ACTIVE.txt';

    /**
     * Sanity check: a truncated or broken download must not wipe the existing list.
     */
    private const int MIN_EXPECTED_DOMAINS = 10000;

    /**
     * Shared hosting platforms that appear in the list as a whole. Importing them would
     * block every link on the platform, so they are skipped.
     */
    private const array IGNORED_HOSTS = [
        'sites.google.com',
        'appengine.google.com',
        'plus.google.com',
    ];

    private const string IGNORED_HOST_PATTERN = '/^s3[.-][a-z0-9-]*\.?amazonaws\.com$/';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly PhishingDomainRepository $phishingDomainRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $content = $this->httpClient->request('GET', self::SOURCE_URL, [
                'timeout' => 30,
                'max_duration' => 300,
            ])->getContent();
        } catch (\Throwable $e) {
            $io->error('Download failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $domains = [];
        $ignored = 0;
        foreach (preg_split('/\R/', $content) as $line) {
            $domain = rtrim(strtolower(trim($line)), '.');
            if ($domain === '' || str_starts_with($domain, '#') || strlen($domain) > 253) {
                continue;
            }
            if (!filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                continue;
            }
            if (in_array($domain, self::IGNORED_HOSTS, true) || preg_match(self::IGNORED_HOST_PATTERN, $domain)) {
                ++$ignored;
                continue;
            }
            $domains[$domain] = true;
        }
        $domains = array_keys($domains);

        if (count($domains) < self::MIN_EXPECTED_DOMAINS) {
            $io->error(sprintf('Only %d domains parsed (expected at least %d); keeping the existing list.', count($domains), self::MIN_EXPECTED_DOMAINS));

            return Command::FAILURE;
        }

        $this->phishingDomainRepository->replaceAll($domains);

        $io->success(sprintf('Imported %d phishing domains (%d shared hosts ignored).', count($domains), $ignored));

        return Command::SUCCESS;
    }
}
