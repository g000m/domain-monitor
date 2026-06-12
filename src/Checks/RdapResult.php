<?php
declare(strict_types=1);

namespace DomainMonitor\Checks;

final class RdapResult
{
    private string $status;
    private ?string $expiresAt;
    private ?string $registrar;
    private string $message;
    /** @var bool|null */
    private $transferLocked;
    /** @var list<string> */
    private array $domainStatuses;

    /**
     * @param bool|null $transferLocked
     * @param list<string> $domainStatuses
     */
    public function __construct(
        string $status,
        ?string $expiresAt = null,
        ?string $registrar = null,
        string $message = '',
        $transferLocked = null,
        array $domainStatuses = []
    ) {
        $this->status         = $status;
        $this->expiresAt      = $expiresAt;
        $this->registrar      = $registrar;
        $this->message        = $message;
        $this->transferLocked = $transferLocked;
        $this->domainStatuses = $domainStatuses;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function expiresAt(): ?string
    {
        return $this->expiresAt;
    }

    public function registrar(): ?string
    {
        return $this->registrar;
    }

    public function message(): string
    {
        return $this->message;
    }

    /** @return bool|null */
    public function transferLocked()
    {
        return $this->transferLocked;
    }

    /** @return list<string> */
    public function domainStatuses(): array
    {
        return $this->domainStatuses;
    }
}
