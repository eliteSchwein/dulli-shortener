<?php

namespace App\Entity;

use App\Repository\TokenRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TokenRepository::class)]
class Token
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    private ?string $token = null;

    /**
     * @var Collection<int, Url>
     */
    #[ORM\OneToMany(targetEntity: Url::class, mappedBy: 'token')]
    private Collection $Urls;

    public function __construct()
    {
        $this->Urls = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setToken(string $token): static
    {
        $this->token = $token;

        return $this;
    }

    /**
     * @return Collection<int, Url>
     */
    public function getUrls(): Collection
    {
        return $this->Urls;
    }

    public function addUrl(Url $url): static
    {
        if (!$this->Urls->contains($url)) {
            $this->Urls->add($url);
            $url->setToken($this);
        }

        return $this;
    }

    public function removeUrl(Url $url): static
    {
        if ($this->Urls->removeElement($url)) {
            // set the owning side to null (unless already changed)
            if ($url->getToken() === $this) {
                $url->setToken(null);
            }
        }

        return $this;
    }
}
