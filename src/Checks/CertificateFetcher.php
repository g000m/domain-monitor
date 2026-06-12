<?php
declare(strict_types=1);

namespace DomainMonitor\Checks;

interface CertificateFetcher
{
    /**
     * Fetch the SSL certificate for the given domain on port 443.
     *
     * Returns an array with the parsed certificate fields on success, or null
     * if the connection could not be established or no certificate was found.
     *
     * @return array<string,mixed>|null
     */
    public function fetch(string $domain): ?array;
}
