<?php

declare(strict_types=1);

namespace SimpleSAML\Module\loginaudit\Tests\Support;

use DOMDocument;
use DOMXPath;
use RuntimeException;

/**
 * Minimal cookie-aware HTTP client that does not follow redirects, so a test can drive
 * a browser-style SAML flow step by step.
 */
final class HttpClient
{
    private string $cookieJar;


    public function __construct()
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'loginaudit-cookies-');
    }


    public function __destruct()
    {
        @unlink($this->cookieJar);
    }


    /**
     * @param array<string, string> $form
     * @return array{status: int, location: ?string, body: string, url: string}
     */
    public function request(string $method, string $url, array $form = []): array
    {
        $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
        }

        $body = curl_exec($ch);
        if ($body === false) {
            throw new RuntimeException('HTTP request failed: ' . curl_error($ch));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        $location = $headers['location'] ?? null;
        if ($location !== null) {
            $location = self::resolve($url, $location);
        }

        return ['status' => $status, 'location' => $location, 'body' => (string) $body, 'url' => $url];
    }


    /**
     * @return array{status: int, location: ?string, body: string, url: string}
     */
    public function get(string $url): array
    {
        return $this->request('GET', $url);
    }


    /**
     * The named inputs of the login or SAML form (else the first form) in a response, plus its action URL.
     *
     * @param array{body: string, url: string} $response
     * @return ?array{action: string, fields: array<string, string>}
     */
    public static function form(array $response): ?array
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($response['body']);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        // Pages can have several forms (e.g. a language selector); prefer the one that logs in or posts SAML.
        $form = $xpath->query('//form[.//input[@name="password" or @name="SAMLResponse"]]')->item(0)
            ?? $xpath->query('//form')->item(0);
        if ($form === null) {
            return null;
        }

        $fields = [];
        foreach ($xpath->query('.//input[@name]', $form) as $input) {
            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        }

        return [
            'action' => self::resolve($response['url'], $form->getAttribute('action')),
            'fields' => $fields,
        ];
    }


    /**
     * Submits the login or SAML form of a response (see form()). Overrides only replace fields the form already has.
     *
     * @param array{status: int, location: ?string, body: string, url: string} $response
     * @param array<string, string> $overrides
     * @return array{status: int, location: ?string, body: string, url: string}
     */
    public function submitForm(array $response, array $overrides = []): array
    {
        $form = self::form($response);
        if ($form === null) {
            throw new RuntimeException('No form found in response from ' . $response['url']);
        }

        foreach ($overrides as $name => $value) {
            if (array_key_exists($name, $form['fields'])) {
                $form['fields'][$name] = $value;
            }
        }

        return $this->request('POST', $form['action'], $form['fields']);
    }


    private static function resolve(string $base, string $target): string
    {
        if (preg_match('#^https?://#i', $target)) {
            return html_entity_decode($target);
        }

        $parts = parse_url($base);
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = $parts['path'] ?? '/';

        if ($target === '' || $target[0] === '?') {
            return $origin . $path . $target;
        }
        if ($target[0] === '/') {
            return $origin . $target;
        }

        return $origin . rtrim(substr($path, 0, (int) strrpos($path, '/')), '/') . '/' . $target;
    }
}
