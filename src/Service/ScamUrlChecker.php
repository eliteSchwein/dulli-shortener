<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ScamCheckUnavailableException;
use App\Repository\PhishingDomainRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Checks URLs against the local Phishing.Database copy, Google Safe Browsing (v4 and v5) and abuse.ch URLhaus.
 * Fails closed: if any database cannot be queried, an exception is thrown.
 */
final class ScamUrlChecker
{
    private const string SAFE_BROWSING_V4_ENDPOINT = 'https://safebrowsing.googleapis.com/v4/threatMatches:find';
    private const string SAFE_BROWSING_V5_ENDPOINT = 'https://safebrowsing.googleapis.com/v5/urls:search';
    private const string URLHAUS_ENDPOINT = 'https://urlhaus-api.abuse.ch/v1/url/';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly PhishingDomainRepository $phishingDomainRepository,
        #[Autowire('%env(string:default::SAFE_BROWSING_API_KEY)%')]
        private readonly string $safeBrowsingApiKey,
        #[Autowire('%env(string:default::URLHAUS_AUTH_KEY)%')]
        private readonly string $urlhausAuthKey,
    ) {
    }

    /**
     * @throws ScamCheckUnavailableException when a database is not configured or cannot be reached
     */
    public function isScam(string $url): bool
    {
        if ($this->safeBrowsingApiKey === '' || $this->urlhausAuthKey === '') {
            $this->logger->error('Scam check is not configured (SAFE_BROWSING_API_KEY / URLHAUS_AUTH_KEY missing).');
            throw new ScamCheckUnavailableException('Scam check is not configured.');
        }

        // Local Phishing.Database copy first: it's fast and catches fresh phishing hosts the APIs miss.
        if ($this->isInPhishingDatabase($url)) {
            $this->logger->warning('URL flagged by Phishing.Database.', ['url' => $url]);

            return true;
        }

        // All requests are started before any is read, so they run concurrently.
        $safeBrowsingV4 = $this->requestSafeBrowsingV4($url);
        $safeBrowsingV5 = $this->requestSafeBrowsingV5($url);
        $urlhaus = $this->requestUrlhaus($url);

        try {
            $flagged = false;

            if ($this->evaluateSafeBrowsingV4($safeBrowsingV4)) {
                $this->logger->warning('URL flagged by Google Safe Browsing v4.', ['url' => $url]);
                $flagged = true;
            }

            if ($this->evaluateSafeBrowsingV5($safeBrowsingV5)) {
                $this->logger->warning('URL flagged by Google Safe Browsing v5.', ['url' => $url]);
                $flagged = true;
            }

            if ($this->evaluateUrlhaus($urlhaus)) {
                $this->logger->warning('URL flagged by URLhaus.', ['url' => $url]);
                $flagged = true;
            }

            return $flagged;
        } finally {
            // Prevent an unread response from throwing in its destructor when another lookup failed.
            $safeBrowsingV4->cancel();
            $safeBrowsingV5->cancel();
            $urlhaus->cancel();
        }
    }

    private function isInPhishingDatabase(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        // Exact host match only: the list contains full hostnames, and matching parent
        // domains would block entire shared hosting platforms.
        $host = rtrim(strtolower($host), '.');
        $hosts = [$host];
        if (str_starts_with($host, 'www.')) {
            $hosts[] = substr($host, 4);
        } else {
            $hosts[] = 'www.' . $host;
        }

        try {
            if ($this->phishingDomainRepository->isEmpty()) {
                throw new \RuntimeException('Phishing list is empty; run app:phishing-list:update.');
            }

            return $this->phishingDomainRepository->containsAny($hosts);
        } catch (\Throwable $e) {
            $this->logger->error('Phishing.Database lookup failed.', ['exception' => $e]);
            throw new ScamCheckUnavailableException('Phishing.Database lookup failed.', previous: $e);
        }
    }

    private function requestSafeBrowsingV4(string $url): ResponseInterface
    {
        return $this->httpClient->request('POST', self::SAFE_BROWSING_V4_ENDPOINT, [
            'query' => ['key' => $this->safeBrowsingApiKey],
            'json' => [
                'client' => [
                    'clientId' => 'dulli-shortener',
                    'clientVersion' => '1.0',
                ],
                'threatInfo' => [
                    'threatTypes' => ['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE', 'POTENTIALLY_HARMFUL_APPLICATION'],
                    'platformTypes' => ['ANY_PLATFORM'],
                    'threatEntryTypes' => ['URL'],
                    'threatEntries' => [['url' => $url]],
                ],
            ],
            'timeout' => 5,
            'max_duration' => 8,
        ]);
    }

    private function requestSafeBrowsingV5(string $url): ResponseInterface
    {
        // v5 covers all threat types by default and only answers in protobuf.
        return $this->httpClient->request('GET', self::SAFE_BROWSING_V5_ENDPOINT, [
            'query' => ['key' => $this->safeBrowsingApiKey, 'urls' => $url],
            'timeout' => 5,
            'max_duration' => 8,
        ]);
    }

    private function requestUrlhaus(string $url): ResponseInterface
    {
        return $this->httpClient->request('POST', self::URLHAUS_ENDPOINT, [
            'headers' => ['Auth-Key' => $this->urlhausAuthKey],
            'body' => ['url' => $url],
            'timeout' => 5,
            'max_duration' => 8,
        ]);
    }

    private function evaluateSafeBrowsingV4(ResponseInterface $response): bool
    {
        try {
            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException(sprintf('HTTP %d: %s', $response->getStatusCode(), $response->getContent(false)));
            }

            // An empty object means no match; otherwise "matches" lists the threats found.
            $result = $response->toArray(false);

            return !empty($result['matches']);
        } catch (\Throwable $e) {
            $this->logger->error('Google Safe Browsing v4 lookup failed.', ['exception' => $e]);
            throw new ScamCheckUnavailableException('Google Safe Browsing v4 lookup failed.', previous: $e);
        }
    }

    private function evaluateSafeBrowsingV5(ResponseInterface $response): bool
    {
        try {
            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException(sprintf('HTTP %d: %s', $response->getStatusCode(), $response->getContent(false)));
            }

            // SearchUrlsResponse: field 1 = repeated ThreatUrl (one per flagged URL), field 2 = cache duration.
            return self::protobufHasField($response->getContent(false), 1);
        } catch (\Throwable $e) {
            $this->logger->error('Google Safe Browsing v5 lookup failed.', ['exception' => $e]);
            throw new ScamCheckUnavailableException('Google Safe Browsing v5 lookup failed.', previous: $e);
        }
    }

    /**
     * Minimal protobuf wire-format scan: returns whether a top-level length-delimited field exists.
     * Avoids pulling in a protobuf library for a single response type.
     */
    private static function protobufHasField(string $data, int $fieldNumber): bool
    {
        $offset = 0;
        $length = strlen($data);

        while ($offset < $length) {
            $tag = self::readVarint($data, $offset);
            $field = $tag >> 3;
            $wireType = $tag & 0x7;

            switch ($wireType) {
                case 0: // varint
                    self::readVarint($data, $offset);
                    break;
                case 1: // 64-bit
                    $offset += 8;
                    break;
                case 2: // length-delimited
                    $size = self::readVarint($data, $offset);
                    if ($field === $fieldNumber) {
                        return true;
                    }
                    $offset += $size;
                    break;
                case 5: // 32-bit
                    $offset += 4;
                    break;
                default:
                    throw new \RuntimeException(sprintf('Unsupported protobuf wire type %d', $wireType));
            }

            if ($offset > $length) {
                throw new \RuntimeException('Truncated protobuf message');
            }
        }

        return false;
    }

    private static function readVarint(string $data, int &$offset): int
    {
        $result = 0;
        $shift = 0;

        do {
            if ($offset >= strlen($data) || $shift > 63) {
                throw new \RuntimeException('Malformed protobuf varint');
            }
            $byte = ord($data[$offset++]);
            $result |= ($byte & 0x7F) << $shift;
            $shift += 7;
        } while ($byte & 0x80);

        return $result;
    }

    private function evaluateUrlhaus(ResponseInterface $response): bool
    {
        try {
            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException(sprintf('HTTP %d', $response->getStatusCode()));
            }

            $status = $response->toArray(false)['query_status'] ?? null;

            return match ($status) {
                // "ok" means the URL is in the database (online or offline – both are treated as malicious).
                'ok' => true,
                'no_results', 'invalid_url' => false,
                default => throw new \RuntimeException(sprintf('Unexpected query_status "%s"', (string) $status)),
            };
        } catch (\Throwable $e) {
            $this->logger->error('URLhaus lookup failed.', ['exception' => $e]);
            throw new ScamCheckUnavailableException('URLhaus lookup failed.', previous: $e);
        }
    }
}
