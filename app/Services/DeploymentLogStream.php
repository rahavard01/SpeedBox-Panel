<?php

namespace App\Services;

use App\Models\ServerDeployment;

/** Redact before persistence, including secrets split between process chunks. */
class DeploymentLogStream
{
    private string $pending = '';
    private string $ready = '';
    private float $flushedAt = 0;
    private array $secrets;

    public function __construct(private readonly ServerDeployment $deployment, array $secrets)
    {
        $this->secrets = array_values(array_unique(array_filter($secrets, fn ($value) => is_string($value) && $value !== '')));
        usort($this->secrets, fn ($a, $b) => strlen($b) <=> strlen($a));
    }

    public function append(string $type, string $chunk): void
    {
        $this->pending .= $chunk;
        $length = strlen($this->pending);
        $safeLength = $length;
        // A process read may end halfway through a UTF-8 character.
        for ($back = 1; $back <= min(4, $length); $back++) {
            $byte = ord($this->pending[$length - $back]);
            if ($byte >= 128 && $byte < 192) continue;
            $width = $byte >= 240 ? 4 : ($byte >= 224 ? 3 : ($byte >= 192 ? 2 : 1));
            if ($width > $back) $safeLength = $length - $back;
            break;
        }
        $offset = 0;
        while ($offset < $safeLength) {
            $matched = false;
            foreach ($this->secrets as $secret) {
                $available = $length - $offset;
                $compare = min(strlen($secret), $available);
                if (substr_compare($this->pending, substr($secret, 0, $compare), $offset, $compare) !== 0) continue;
                if ($available < strlen($secret)) break 2;
                $this->ready .= '[REDACTED]';
                $offset += strlen($secret);
                $matched = true;
                break;
            }
            if (!$matched) $this->ready .= $this->pending[$offset++];
        }
        $this->pending = substr($this->pending, $offset);
        if (microtime(true) - $this->flushedAt >= 1 || strlen($this->ready) >= 8192) $this->flush();
    }

    public function finish(): void
    {
        // A partial credential at EOF is also private.
        $this->ready .= $this->pending !== '' ? '[REDACTED]' : '';
        $this->pending = '';
        $this->flush();
    }

    private function flush(): void
    {
        if ($this->ready === '') return;
        $log = mb_strcut((string) $this->deployment->log."\n".$this->ready, -262144, null, 'UTF-8');
        $this->deployment->update(['log' => $log]);
        $this->ready = '';
        $this->flushedAt = microtime(true);
    }
}
