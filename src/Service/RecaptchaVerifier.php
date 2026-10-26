<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class RecaptchaVerifier
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(string:RECAPTCHA_SECRET_KEY)%')]
        private readonly string $secretKey,
        #[Autowire('%env(float:RECAPTCHA_MIN_SCORE)%')]
        private readonly float $minScore,
    ) {
    }

    public function verify(string $token, string $expectedHostname, string $expectedAction = 'create_url'): bool
    {
        if ($token === '' || $this->secretKey === '' || $expectedHostname === '') {
            return false;
        }

        try {
            $response = $this->httpClient->request('POST', 'https://www.google.com/recaptcha/api/siteverify', [
                'body' => [
                    'secret' => $this->secretKey,
                    'response' => $token,
                ],
                'timeout' => 5,
                'max_duration' => 8,
            ]);

            if ($response->getStatusCode() !== 200) {
                return false;
            }

            $result = $response->toArray(false);

            return ($result['success'] ?? false) === true
                && ($result['action'] ?? null) === $expectedAction
                && isset($result['hostname'])
                && is_string($result['hostname'])
                && strcasecmp($result['hostname'], $expectedHostname) === 0
                && isset($result['score'])
                && is_numeric($result['score'])
                && (float) $result['score'] >= $this->minScore
                && !in_array('Over free quota.', $result['error-codes'] ?? [], true);
        } catch (\Throwable) {
            // Fail closed on verification network errors, timeouts and malformed responses.
            return false;
        }
    }
}
