<?php
declare(strict_types=1);

namespace DomainMonitor\Cli;

use DomainMonitor\Admin\DomainAdminActions;
use DomainMonitor\Plugin;
use DomainMonitor\Storage\DomainRepository;
use InvalidArgumentException;

/**
 * WP-CLI command handler for Domain Monitor.
 *
 * Registered as `wp domain-monitor` when WP_CLI is active.
 *
 * Subcommands:
 *   wp domain-monitor list            -- table of all monitored domains
 *   wp domain-monitor check [<domain>] -- run a check for one or all domains
 *   wp domain-monitor add <domain>    -- add a domain to the monitor list
 */
final class DomainMonitorCommand
{
    private DomainRepository $repository;
    private Plugin $plugin;
    private DomainAdminActions $actions;
    private CliPrinter $printer;

    public function __construct(
        DomainRepository $repository,
        Plugin $plugin,
        DomainAdminActions $actions,
        CliPrinter $printer
    ) {
        $this->repository = $repository;
        $this->plugin     = $plugin;
        $this->actions    = $actions;
        $this->printer    = $printer;
    }

    /**
     * Static factory used for production wiring (called only when WP_CLI is defined).
     */
    public static function create(): self
    {
        global $wpdb;

        $store      = new \DomainMonitor\Storage\WpdbDomainStore(
            $wpdb,
            \DomainMonitor\Storage\DomainTable::tableName((string) $wpdb->prefix)
        );
        $repository = new DomainRepository($store);
        $alertStore = new \DomainMonitor\Storage\WpdbAlertStore($wpdb);
        $plugin     = new Plugin($repository, null, null, $alertStore);
        $host       = (string) parse_url((string) (function_exists('home_url') ? home_url('/') : ''), PHP_URL_HOST);
        $actions    = new DomainAdminActions($repository, null, $host !== '' ? $host : 'localhost');
        $printer    = new WpCliPrinter();

        return new self($repository, $plugin, $actions, $printer);
    }

    // -----------------------------------------------------------------
    // Subcommands
    // -----------------------------------------------------------------

    /**
     * List all monitored domains.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format: table, json, csv, ids, count.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - ids
     *   - count
     * ---
     *
     * ## EXAMPLES
     *
     *     wp domain-monitor list
     *     wp domain-monitor list --format=json
     *
     * @subcommand list
     * @param list<string>         $args
     * @param array<string,string> $assoc_args
     */
    public function list(array $args, array $assoc_args): void
    {
        $format  = isset($assoc_args['format']) ? (string) $assoc_args['format'] : 'table';
        $records = $this->repository->all();

        if ($records === []) {
            $this->printer->warning('No domains are being monitored.');
            return;
        }

        $fields = ['id', 'domain', 'source', 'status', 'last_checked_at'];
        $rows   = [];
        foreach ($records as $record) {
            $rows[] = [
                'id'             => $record->id(),
                'domain'         => $record->domain(),
                'source'         => $record->source(),
                'status'         => $this->plugin->statusCodeForRecord($record),
                'last_checked_at' => $record->lastCheckedAt() !== '' ? $record->lastCheckedAt() : 'never',
            ];
        }

        $this->printer->formatItems($rows, $fields, $format);
    }

    /**
     * Run a domain check.
     *
     * ## OPTIONS
     *
     * [<domain>]
     * : Domain name or numeric ID to check. Omit to check all domains.
     *
     * ## EXAMPLES
     *
     *     wp domain-monitor check
     *     wp domain-monitor check example.com
     *     wp domain-monitor check 3
     *
     * @subcommand check
     * @param list<string>         $args
     * @param array<string,string> $assoc_args
     */
    public function check(array $args, array $assoc_args): void
    {
        if (isset($args[0]) && $args[0] !== '') {
            $this->checkOne($args[0]);
            return;
        }

        $this->checkAll();
    }

    /**
     * Add a domain to the monitor list.
     *
     * ## OPTIONS
     *
     * <domain>
     * : The domain name to add.
     *
     * ## EXAMPLES
     *
     *     wp domain-monitor add example.com
     *
     * @subcommand add
     * @param list<string>         $args
     * @param array<string,string> $assoc_args
     */
    public function add(array $args, array $assoc_args): void
    {
        if (! isset($args[0]) || trim($args[0]) === '') {
            $this->printer->error('Please provide a domain name.');
            return;
        }

        try {
            $id = $this->actions->addDomain($args[0]);
            $this->printer->success(sprintf('Domain added with ID %d.', $id));
        } catch (InvalidArgumentException $e) {
            $this->printer->error(sprintf('Invalid domain: %s', $e->getMessage()));
        }
    }

    // -----------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------

    private function checkAll(): void
    {
        $records = $this->repository->all();

        if ($records === []) {
            $this->printer->warning('No domains are being monitored.');
            return;
        }

        $failed = 0;
        foreach ($records as $record) {
            $ok = $this->plugin->runCheckForDomainId($record->id());
            $status = $this->plugin->statusCodeForRecord($this->repository->find($record->id()));
            if ($ok) {
                $this->printer->line(sprintf('Checked %s -- status: %s', $record->domain(), $status));
            } else {
                $this->printer->line(sprintf('Check failed for %s', $record->domain()));
                $failed++;
            }
        }

        if ($failed > 0) {
            $this->printer->error(sprintf('%d domain(s) failed to check.', $failed), false);
        } else {
            $this->printer->success(sprintf('All %d domain(s) checked.', count($records)));
        }
    }

    private function checkOne(string $domainOrId): void
    {
        $record = null;

        // Numeric: look up by ID.
        if (ctype_digit($domainOrId)) {
            $record = $this->repository->find((int) $domainOrId);
        }

        // Try domain name lookup if not found yet.
        if ($record === null) {
            foreach ($this->repository->all() as $r) {
                if ($r->domain() === $domainOrId) {
                    $record = $r;
                    break;
                }
            }
        }

        if ($record === null) {
            $this->printer->error(sprintf('Domain not found: %s', $domainOrId));
            return;
        }

        $ok = $this->plugin->runCheckForDomainId($record->id());

        $fresh  = $this->repository->find($record->id());
        $status = $fresh !== null ? $this->plugin->statusCodeForRecord($fresh) : 'unknown';

        if ($ok) {
            $this->printer->success(sprintf('Checked %s -- status: %s', $record->domain(), $status));
        } else {
            $this->printer->error(sprintf('Check failed for %s', $record->domain()), false);
        }
    }
}
