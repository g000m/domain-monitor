<?php
declare(strict_types=1);

namespace DomainMonitor\Checks;

final class WordPressHttpClient
{
    /** @return array{status:int, body:string} */
    public function get(string $url): array
    {
        if (! function_exists('wp_remote_get')) {
            return ['status' => 0, 'body' => ''];
        }

        $response = wp_remote_get($url, ['timeout' => 10]);
        if (function_exists('is_wp_error') && is_wp_error($response)) {
            return ['status' => 0, 'body' => $response->get_error_message()];
        }

        if (! is_array($response)) {
            return ['status' => 0, 'body' => ''];
        }

        $status = function_exists('wp_remote_retrieve_response_code')
            ? (int) wp_remote_retrieve_response_code($response)
            : (int) ($response['response']['code'] ?? 0);
        $body = function_exists('wp_remote_retrieve_body')
            ? (string) wp_remote_retrieve_body($response)
            : (string) ($response['body'] ?? '');

        return ['status' => $status, 'body' => $body];
    }
}
