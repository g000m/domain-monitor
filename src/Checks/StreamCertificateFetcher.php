<?php
declare(strict_types=1);

namespace DomainMonitor\Checks;

final class StreamCertificateFetcher implements CertificateFetcher
{
    private int $timeoutSeconds;

    public function __construct(int $timeoutSeconds = 5)
    {
        $this->timeoutSeconds = $timeoutSeconds;
    }

    /** @return array<string,mixed>|null */
    public function fetch(string $domain): ?array
    {
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer'       => false,
                'verify_peer_name'  => false,
            ],
        ]);

        $socket = @stream_socket_client(
            'ssl://' . $domain . ':443',
            $errno,
            $errstr,
            $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            return null;
        }

        $params = stream_context_get_params($socket);
        fclose($socket);

        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if (! is_resource($cert)) {
            return null;
        }

        $parsed = openssl_x509_parse($cert);
        return is_array($parsed) ? $parsed : null;
    }
}
