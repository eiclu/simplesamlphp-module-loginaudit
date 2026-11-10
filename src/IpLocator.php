<?php

declare(strict_types=1);

namespace SimpleSAML\Module\loginaudit;

use PDO;
use Throwable;

/**
 * Resolves IPs to city and country, cached in SQLite, using the ip-api.com batch API.
 */
final class IpLocator
{
    private ?PDO $db = null;


    public function __construct(string $dbPath, private readonly bool $remoteLookup = true)
    {
        try {
            $this->db = new PDO('sqlite:' . $dbPath);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->db->exec('CREATE TABLE IF NOT EXISTS ip_lookup (
                ip TEXT PRIMARY KEY,
                city TEXT,
                country_code TEXT,
                resolved_at INTEGER
            )');
        } catch (Throwable) {
            $this->db = null;
        }
    }


    /**
     * @param string[] $ips
     * @return array<string, array{city: string, country_code: string, internal: bool}>
     */
    public function locate(array $ips): array
    {
        $result = [];
        $public = [];

        foreach (array_unique($ips) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                $result[$ip] = ['city' => '', 'country_code' => '', 'internal' => false];
            } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                $result[$ip] = ['city' => '', 'country_code' => '', 'internal' => true];
            } else {
                $public[] = $ip;
            }
        }

        if ($this->db !== null && $public !== []) {
            $placeholders = implode(',', array_fill(0, count($public), '?'));
            $stmt = $this->db->prepare("SELECT ip, city, country_code FROM ip_lookup WHERE ip IN ($placeholders)");
            $stmt->execute($public);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result[$row['ip']] = [
                    'city' => (string) $row['city'],
                    'country_code' => (string) $row['country_code'],
                    'internal' => false,
                ];
            }
        }

        $missing = array_values(array_filter($public, fn (string $ip): bool => !isset($result[$ip])));
        if ($this->remoteLookup) {
            foreach (array_chunk($missing, 100) as $chunk) {
                $result += $this->fetchBatch($chunk);
            }
        }

        return $result;
    }


    /**
     * Turns a 2-letter country code into its emoji flag.
     */
    public static function flag(string $code): string
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z]{2}$/', $code)) {
            return '';
        }

        return mb_chr(127397 + ord($code[0]), 'UTF-8') . mb_chr(127397 + ord($code[1]), 'UTF-8');
    }


    /**
     * @param string[] $ips
     * @return array<string, array{city: string, country_code: string, internal: bool}>
     */
    private function fetchBatch(array $ips): array
    {
        $payload = array_map(
            fn (string $ip): array => ['query' => $ip, 'fields' => 'status,city,countryCode,query'],
            $ips,
        );

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => json_encode($payload),
                'timeout' => 3,
                'ignore_errors' => true,
            ],
        ]);

        // The free ip-api.com tier only supports plain HTTP.
        $response = @file_get_contents('http://ip-api.com/batch', false, $context);
        $data = $response !== false ? json_decode($response, true) : null;
        if (!is_array($data)) {
            return [];
        }

        $result = [];
        $now = time();
        $insert = $this->db?->prepare(
            'INSERT OR REPLACE INTO ip_lookup (ip, city, country_code, resolved_at) VALUES (?, ?, ?, ?)',
        );

        foreach ($data as $item) {
            $ip = $item['query'] ?? null;
            if (!is_string($ip)) {
                continue;
            }
            $ok = ($item['status'] ?? '') === 'success';
            $city = $ok ? (string) ($item['city'] ?? '') : '';
            $cc = $ok ? (string) ($item['countryCode'] ?? '') : '';

            $result[$ip] = ['city' => $city, 'country_code' => $cc, 'internal' => false];
            $insert?->execute([$ip, $city, $cc, $now]);
        }

        return $result;
    }
}
