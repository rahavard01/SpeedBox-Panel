<?php
declare(strict_types=1);

// Standalone: no Laravel bootstrap, .env, database, Go or network access.
try {
    $root = realpath($argv[2] ?? dirname(__DIR__));
    if ($root === false) throw new RuntimeException('Project directory missing.');
    $mode = $argv[1] ?? 'verify';
    if (!in_array($mode, ['verify', 'extracted', 'archives'], true)) throw new RuntimeException('Unknown artifact verification mode.');
    $manifest = json_decode(file_get_contents($root.'/agent-dist/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($manifest['format'] ?? null) !== 1 || !is_array($manifest['source_files'] ?? null)) {
        throw new RuntimeException('Unsupported agent manifest.');
    }
    $sourceFiles = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/agent', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if (str_contains('/'.$relative.'/', '/.git/')) continue;
        if ($file->isLink()) throw new RuntimeException('Agent source symlink: '.$relative);
        if ($file->isFile()) $sourceFiles[] = $relative;
    }
    foreach (['scripts/build-agent.sh', 'scripts/build-agent-from-source.sh', 'scripts/agent-artifacts.php', 'scripts/package-agent.py'] as $path) $sourceFiles[] = $path;
    sort($sourceFiles, SORT_STRING);
    $expected = array_keys($manifest['source_files']);
    sort($expected, SORT_STRING);
    if ($sourceFiles !== $expected) throw new RuntimeException('Agent source inventory changed; rebuild and package the binaries before publishing.');
    foreach ($sourceFiles as $path) {
        if (!preg_match('~^(agent|scripts)/[A-Za-z0-9_./-]+$~D', $path) || str_contains($path, '..') || is_link($root.'/'.$path)) {
            throw new RuntimeException('Unsafe agent source path.');
        }
        $data = file_get_contents($root.'/'.$path);
        if ($data === false || !hash_equals($manifest['source_files'][$path], hash('sha256', str_replace("\r\n", "\n", $data)))) {
            throw new RuntimeException('Agent binary/source mismatch: '.$path.'; rebuild and package before publishing.');
        }
    }
    foreach (['amd64' => 62, 'arm64' => 183] as $arch => $machine) {
        $archive = $root.'/agent-dist/linux-'.$arch.'.tar.gz';
        $entry = $manifest['archives'][$arch] ?? null;
        if (!is_array($entry) || !is_int($entry['bytes'] ?? null) || $entry['bytes'] < 1
            || !is_string($entry['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $entry['sha256'])) {
            throw new RuntimeException('Invalid agent archive manifest: '.$arch);
        }
        $inputs = [];
        if (array_key_exists('parts', $entry)) {
            if (!is_array($entry['parts']) || !array_is_list($entry['parts']) || count($entry['parts']) < 1 || count($entry['parts']) > 64) {
                throw new RuntimeException('Invalid agent archive parts: '.$arch);
            }
            foreach ($entry['parts'] as $index => $part) {
                $expectedName = 'linux-'.$arch.'.tar.gz.part-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT);
                if (!is_array($part) || ($part['file'] ?? null) !== $expectedName
                    || !is_int($part['bytes'] ?? null) || $part['bytes'] < 1 || $part['bytes'] > 8 * 1024 * 1024
                    || !is_string($part['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $part['sha256'])) {
                    throw new RuntimeException('Unsafe agent archive part: '.$arch);
                }
                $path = $root.'/agent-dist/'.$expectedName;
                if (is_link($path) || !is_file($path) || filesize($path) !== $part['bytes']
                    || !hash_equals($part['sha256'], hash_file('sha256', $path))) {
                    throw new RuntimeException('Agent archive part missing or checksum mismatch: '.$expectedName);
                }
                $inputs[] = $path;
            }
        } else {
            if (is_link($archive) || !is_file($archive)) throw new RuntimeException('Agent archive missing: '.$arch);
            $inputs[] = $archive;
        }
        $context = hash_init('sha256'); $bytes = 0;
        foreach ($inputs as $path) {
            $stream = fopen($path, 'rb');
            if ($stream === false) throw new RuntimeException('Agent archive unreadable: '.$arch);
            $bytes += hash_update_stream($context, $stream);
            fclose($stream);
        }
        if ($bytes !== $entry['bytes'] || !hash_equals($entry['sha256'], hash_final($context))) {
            throw new RuntimeException('Agent archive checksum mismatch: '.$arch);
        }
        if ($mode === 'archives') {
            $directory = realpath($argv[3] ?? '');
            if ($directory === false || !is_dir($directory)) throw new RuntimeException('Archive staging directory missing.');
            $destination = $directory.'/linux-'.$arch.'.tar.gz';
            $target = fopen($destination, 'xb');
            if ($target === false) throw new RuntimeException('Archive staging path already exists or is not writable.');
            try {
                foreach ($inputs as $path) {
                    $stream = fopen($path, 'rb');
                    if ($stream === false) throw new RuntimeException('Agent archive unreadable: '.$arch);
                    try { if (stream_copy_to_stream($stream, $target) === false) throw new RuntimeException('Agent archive staging failed.'); }
                    finally { fclose($stream); }
                }
            } finally { fclose($target); }
            if (!hash_equals($entry['sha256'], hash_file('sha256', $destination))) throw new RuntimeException('Staged archive checksum mismatch: '.$arch);
        }
        if ($mode === 'extracted') {
            $directory = realpath($argv[3] ?? '');
            if ($directory === false) throw new RuntimeException('Extracted directory missing.');
            foreach (['xboard-node', 'xbctl'] as $program) {
                $name = $program.'-linux-'.$arch;
                $path = $directory.'/'.$name;
                $binary = $entry['binaries'][$name] ?? null;
                if (!is_array($binary) || is_link($path) || !is_file($path) || filesize($path) !== ($binary['bytes'] ?? -1)
                    || !hash_equals($binary['sha256'], hash_file('sha256', $path))) {
                    throw new RuntimeException('Extracted agent checksum mismatch: '.$name);
                }
                $stream = fopen($path, 'rb');
                $header = fread($stream, 20);
                fclose($stream);
                if (strlen($header) !== 20 || substr($header, 0, 6) !== "\x7fELF\x02\x01" || unpack('v', substr($header, 18, 2))[1] !== $machine) {
                    throw new RuntimeException('Wrong Linux binary architecture: '.$name);
                }
            }
        }
    }
    echo 'AGENT ARTIFACTS OK: source and '.($mode === 'extracted' ? '4 Linux binaries' : '2 archives').PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'AGENT ARTIFACTS FAILED: '.$error->getMessage().PHP_EOL);
    exit(1);
}

