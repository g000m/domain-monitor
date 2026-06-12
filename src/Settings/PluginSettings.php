<?php
declare(strict_types=1);

namespace DomainMonitor\Settings;

/**
 * Accessor for plugin-level notification settings stored in the
 * `domain_monitor_settings` option (a single serialised array).
 *
 * All keys default to true / empty-string when missing, so a fresh install
 * behaves exactly as before the settings feature was added.
 *
 * Accepts an optional $rawOption parameter for unit-test injection; production
 * code reads the option via get_option() in the static factory method.
 *
 * @phpstan-type RawOption array<string,mixed>
 */
final class PluginSettings
{
    public const OPTION_NAME = 'domain_monitor_settings';

    private bool $notifyStatusChange;
    private bool $notifyNsChanged;
    private bool $notifyAChanged;
    private bool $notifyMxChanged;
    private bool $notifyTransferLockRemoved;
    private string $notificationEmail;

    /** @param array<string,mixed> $raw */
    public function __construct(array $raw = [])
    {
        // All notification toggles default to true when missing.
        $this->notifyStatusChange        = isset($raw['notify_status_change'])        ? (bool) $raw['notify_status_change']        : true;
        $this->notifyNsChanged           = isset($raw['notify_ns_changed'])           ? (bool) $raw['notify_ns_changed']           : true;
        $this->notifyAChanged            = isset($raw['notify_a_changed'])            ? (bool) $raw['notify_a_changed']            : true;
        $this->notifyMxChanged           = isset($raw['notify_mx_changed'])           ? (bool) $raw['notify_mx_changed']           : true;
        $this->notifyTransferLockRemoved = isset($raw['notify_transfer_lock_removed']) ? (bool) $raw['notify_transfer_lock_removed'] : true;
        $this->notificationEmail         = isset($raw['notification_email']) && is_string($raw['notification_email']) ? $raw['notification_email'] : '';
    }

    /**
     * Read settings from the WordPress options table.
     * Falls back to defaults when get_option is unavailable (unit tests).
     */
    public static function fromWordPress(): self
    {
        if (function_exists('get_option')) {
            $raw = get_option(self::OPTION_NAME, []);
            return new self(is_array($raw) ? $raw : []);
        }

        return new self();
    }

    public function notifyStatusChange(): bool
    {
        return $this->notifyStatusChange;
    }

    public function notifyNsChanged(): bool
    {
        return $this->notifyNsChanged;
    }

    public function notifyAChanged(): bool
    {
        return $this->notifyAChanged;
    }

    public function notifyMxChanged(): bool
    {
        return $this->notifyMxChanged;
    }

    public function notifyTransferLockRemoved(): bool
    {
        return $this->notifyTransferLockRemoved;
    }

    /**
     * Email address to notify. Empty string means "use admin_email".
     */
    public function notificationEmail(): string
    {
        return $this->notificationEmail;
    }

    /**
     * Serialise to the array format persisted in the options table.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'notify_status_change'        => $this->notifyStatusChange,
            'notify_ns_changed'           => $this->notifyNsChanged,
            'notify_a_changed'            => $this->notifyAChanged,
            'notify_mx_changed'           => $this->notifyMxChanged,
            'notify_transfer_lock_removed' => $this->notifyTransferLockRemoved,
            'notification_email'          => $this->notificationEmail,
        ];
    }
}
