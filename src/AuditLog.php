<?php

declare(strict_types=1);

namespace SimpleSAML\Module\loginaudit;

/**
 * Reads AUDIT-LOGIN entries from the active and rotated SimpleSAMLphp log files.
 */
final class AuditLog
{
    private const string PATTERN = '/^(\w{3}\s+\d+\s+[\d:]+)\s+\S+\s+(\w+)\s+\[([^\]]+)\]\s+AUDIT-LOGIN:\s+'
        . 'user="([^"]*)"\s+name="([^"]*)"\s+email="([^"]*)"\s+idp="([^"]*)"\s+ip="([^"]*)"/';


    public function __construct(
        private readonly string $logDir,
        private readonly string $logFile,
    ) {
    }


    /**
     * The active log first, then rotated logs, newest first.
     *
     * @return string[]
     */
    public function files(): array
    {
        $files = [];
        $active = $this->logDir . '/' . $this->logFile;
        if (is_file($active) && is_readable($active)) {
            $files[] = $active;
        }

        $base = pathinfo($this->logFile, PATHINFO_FILENAME);
        $archives = glob($this->logDir . '/' . $base . '-*.log') ?: [];
        rsort($archives);

        return array_merge($files, array_filter($archives, 'is_readable'));
    }


    /**
     * All audit entries, newest first.
     *
     * @return list<array{date: string, level: string, track: string, user: string, name: string,
     *   email: string, idp: string, ip: string, file: string}>
     */
    public function entries(): array
    {
        $rows = [];
        foreach ($this->files() as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }

            foreach (array_reverse($lines) as $line) {
                if (strpos($line, 'AUDIT-LOGIN:') === false || !preg_match(self::PATTERN, $line, $m)) {
                    continue;
                }
                $rows[] = [
                    'date'  => $m[1],
                    'level' => $m[2],
                    'track' => $m[3],
                    'user'  => $m[4],
                    'name'  => $m[5],
                    'email' => $m[6],
                    'idp'   => $m[7],
                    'ip'    => $m[8],
                    'file'  => basename($file),
                ];
            }
        }

        return $rows;
    }
}
