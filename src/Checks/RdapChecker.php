<?php
declare(strict_types=1);

namespace DomainMonitor\Checks;

final class RdapChecker
{
    /** @var object */
    private $httpClient;

    /** @param object $httpClient Object exposing get(string $url): array{status:int, body:string}. */
    public function __construct($httpClient)
    {
        $this->httpClient = $httpClient;
    }

    public function check(string $domain): RdapResult
    {
        $url = 'https://www.rdap.net/domain/' . rawurlencode(strtolower($domain));
        $response = $this->httpClient->get($url);
        $statusCode = (int) ($response['status'] ?? 0);
        $body = (string) ($response['body'] ?? '');

        if ($statusCode < 200 || $statusCode >= 300) {
            return new RdapResult(
                'degraded',
                null,
                null,
                'We had trouble looking up registration details for this TLD.'
            );
        }

        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            return new RdapResult('degraded', null, null, 'We had trouble reading RDAP registration details.');
        }

        $domainStatuses = $this->extractDomainStatuses($payload);
        $transferLocked = $this->deriveTransferLocked($domainStatuses);

        return new RdapResult(
            'ok',
            $this->extractExpiration($payload),
            $this->extractRegistrar($payload),
            '',
            $transferLocked,
            $domainStatuses
        );
    }

    /** @param array<string,mixed> $payload */
    private function extractExpiration(array $payload): ?string
    {
        $events = $payload['events'] ?? [];
        if (! is_array($events)) {
            return null;
        }

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            if (($event['eventAction'] ?? null) === 'expiration' && isset($event['eventDate']) && is_string($event['eventDate'])) {
                return $event['eventDate'];
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<string>
     */
    private function extractDomainStatuses(array $payload): array
    {
        $statuses = $payload['status'] ?? [];
        if (! is_array($statuses)) {
            return [];
        }

        $result = [];
        foreach ($statuses as $s) {
            if (is_string($s)) {
                $result[] = $s;
            }
        }

        return $result;
    }

    /** @param list<string> $statuses */
    private function deriveTransferLocked(array $statuses): ?bool
    {
        if ($statuses === []) {
            return null;
        }

        // Normalize a status string to a canonical slug for comparison.
        // Handles camelCase ("clientTransferProhibited"), spaced ("client transfer prohibited"),
        // and hyphenated ("client-transfer-prohibited") forms from real-world RDAP providers.
        $normalize = static function (string $s): string {
            return strtolower(preg_replace('/[\s\-_]+/', '', $s) ?? $s);
        };

        $lockStatuses = ['clienttransferprohibited', 'servertransferprohibited'];
        foreach ($statuses as $s) {
            if (in_array($normalize($s), $lockStatuses, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $payload */
    private function extractRegistrar(array $payload): ?string
    {
        $entities = $payload['entities'] ?? [];
        if (! is_array($entities)) {
            return null;
        }

        foreach ($entities as $entity) {
            if (! is_array($entity)) {
                continue;
            }

            $roles = $entity['roles'] ?? [];
            if (! is_array($roles) || ! in_array('registrar', $roles, true)) {
                continue;
            }

            $vcard = $entity['vcardArray'][1] ?? [];
            if (! is_array($vcard)) {
                continue;
            }

            foreach ($vcard as $entry) {
                if (is_array($entry) && ($entry[0] ?? null) === 'fn' && isset($entry[3]) && is_string($entry[3])) {
                    return $entry[3];
                }
            }
        }

        return null;
    }
}
