#!/usr/bin/env php
<?php
/**
 * Generator script: downloads the Public Suffix List (ICANN section only) and
 * writes src/Domain/data/public-suffix-list.php.
 *
 * Usage: php bin/generate-psl.php
 *
 * Source: https://publicsuffix.org/list/public_suffix_list.dat
 * License of the PSL data file: Mozilla Public License 2.0
 *   https://mozilla.org/MPL/2.0/
 */

declare(strict_types=1);

$url     = 'https://publicsuffix.org/list/public_suffix_list.dat';
$mirror  = 'https://raw.githubusercontent.com/publicsuffix/list/master/public_suffix_list.dat';
$outFile = __DIR__ . '/../src/Domain/data/public-suffix-list.php';

/**
 * Download a URL, trying curl first then stream context.
 */
function psl_download(string $url): string
{
    // Try curl first (more reliable in restricted PHP environments).
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => 'domain-monitor-psl-generator/1.0',
        ]);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($data !== false && $code === 200) {
            return (string) $data;
        }
    }

    // Fall back to stream context.
    $ctx = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'follow_location' => 1,
            'timeout'         => 30,
            'header'          => "User-Agent: domain-monitor-psl-generator/1.0\r\n",
        ],
    ]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false) {
        return '';
    }
    return $data;
}

echo "Downloading PSL from {$url} ...\n";
$raw = psl_download($url);
if ($raw === '') {
    echo "Primary URL failed, trying mirror {$mirror} ...\n";
    $raw = psl_download($mirror);
}
if ($raw === '') {
    fwrite(STDERR, "ERROR: Could not download the Public Suffix List from either source.\n");
    exit(1);
}

echo 'Downloaded ' . strlen($raw) . " bytes.\n";

// Parse: collect ICANN section only (stop at BEGIN PRIVATE DOMAINS).
$lines   = explode("\n", str_replace("\r\n", "\n", $raw));
$inIcann = false;
$suffixes = [];   // 'suffix' => 'normal'|'wildcard'|'exception'

foreach ($lines as $line) {
    $line = trim($line);

    if ($line === '// ===BEGIN ICANN DOMAINS===') {
        $inIcann = true;
        continue;
    }
    if ($line === '// ===END ICANN DOMAINS===') {
        break;
    }
    if (! $inIcann) {
        continue;
    }
    // Skip blank lines and comment lines.
    if ($line === '' || strpos($line, '//') === 0) {
        continue;
    }

    if (strpos($line, '!') === 0) {
        // Exclusion rule, e.g. !www.ck  -> exception for www.ck under *.ck
        $suffixes[substr($line, 1)] = 'exception';
    } elseif (strpos($line, '*.') === 0) {
        // Wildcard rule, e.g. *.ck -> store bare TLD (after the *.)
        $suffixes[substr($line, 2)] = 'wildcard';
    } else {
        $suffixes[$line] = 'normal';
    }
}

$count = count($suffixes);
echo "Parsed {$count} ICANN entries (normal + wildcard + exception).\n";

// Build PHP source.
$date    = date('Y-m-d');
$entries = '';
foreach ($suffixes as $suffix => $type) {
    $sSuffix = var_export($suffix, true);
    $sType   = var_export($type, true);
    $entries .= "    {$sSuffix} => {$sType},\n";
}

$source = <<<PHP
<?php
/**
 * Public Suffix List -- ICANN section only.
 *
 * Source  : https://publicsuffix.org/list/public_suffix_list.dat
 * License : Mozilla Public License 2.0 (https://mozilla.org/MPL/2.0/)
 * Generated: {$date}
 *
 * DO NOT EDIT BY HAND. Re-generate with:
 *   php bin/generate-psl.php
 *
 * Array values:
 *   'normal'    -- standard suffix (e.g. 'com', 'co.uk')
 *   'wildcard'  -- every label under this is a public suffix (e.g. 'ck' means *.ck)
 *   'exception' -- explicitly NOT a public suffix under its wildcard parent (e.g. 'www.ck')
 */
declare(strict_types=1);

return [
{$entries}];
PHP;

file_put_contents($outFile, $source);
$size = filesize($outFile);
echo "Written {$outFile} ({$size} bytes, {$count} entries).\n";
