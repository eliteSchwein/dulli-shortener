<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PhishingDomainRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Hostname imported from the Phishing.Database list (see app:phishing-list:update).
 */
#[ORM\Entity(repositoryClass: PhishingDomainRepository::class, readOnly: true)]
class PhishingDomain
{
    #[ORM\Id]
    #[ORM\Column(length: 253)]
    private string $domain;

    public function __construct(string $domain)
    {
        $this->domain = $domain;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }
}
