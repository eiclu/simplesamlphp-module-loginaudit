<?php

declare(strict_types=1);

namespace SimpleSAML\Module\loginaudit;

/**
 * Daily copy-and-truncate rotation of the SimpleSAMLphp log, plus pruning of old archives.
 */
final class LogRotator
{
    public function __construct(
        private readonly string $logDir,
        private readonly string $logFile,
        private readonly int $retentionDays,
    ) {
    }


    /**
     * @return array{rotated: ?string, pruned: list<string>}
     */
    public function run(): array
    {
        return ['rotated' => $this->rotate(), 'pruned' => $this->prune()];
    }


    private function rotate(): ?string
    {
        $active = $this->logDir . '/' . $this->logFile;
        if (!is_file($active) || filesize($active) === 0) {
            return null;
        }

        $base = pathinfo($this->logFile, PATHINFO_FILENAME);
        $archive = $this->logDir . '/' . $base . '-' . date('Y-m-d') . '.log';

        // Copy and truncate under one lock so lines written meanwhile are not lost.
        $in = fopen($active, 'r+');
        if ($in === false) {
            return null;
        }
        try {
            if (!flock($in, LOCK_EX)) {
                return null;
            }
            $out = fopen($archive, 'a'); // append: a second run on the same day must not overwrite
            if ($out === false) {
                return null;
            }
            $ok = stream_copy_to_stream($in, $out) !== false;
            fclose($out);
            if (!$ok) {
                return null;
            }
            ftruncate($in, 0);
            flock($in, LOCK_UN);
        } finally {
            fclose($in);
        }

        return basename($archive);
    }


    /**
     * @return list<string>
     */
    private function prune(): array
    {
        $base = pathinfo($this->logFile, PATHINFO_FILENAME);
        $cutoff = time() - $this->retentionDays * 86400;
        $pruned = [];

        foreach (glob($this->logDir . '/' . $base . '-*.log') ?: [] as $file) {
            if (filemtime($file) < $cutoff && @unlink($file)) {
                $pruned[] = basename($file);
            }
        }

        return $pruned;
    }
}
