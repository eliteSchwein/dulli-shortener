<?php

declare(strict_types=1);

namespace App\Service;

final class UrlBlacklist
{
    /**
     * Blocked domains. Subdomains of these domains are blocked as well.
     */
    private const array DOMAINS = [
        'trycloudflare.com',
        'utensiliosmedicosortopedia.com.mx',
    ];

    public function isBlacklisted(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        $host = rtrim(strtolower($host), '.');

        foreach (self::DOMAINS as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }
}
