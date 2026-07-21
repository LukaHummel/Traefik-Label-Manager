<?php

declare(strict_types=1);

namespace DockerDns;

use Closure;
use RuntimeException;

final class DockerRunner
{
    private Closure $runner;

    /** @param null|callable(string):string $runner */
    public function __construct(?callable $runner = null)
    {
        $this->runner = $runner !== null ? Closure::fromCallable($runner) : static function (string $command): string {
            $output = [];
            $status = 0;
            exec($command . ' 2>&1', $output, $status);
            if ($status !== 0) {
                throw new RuntimeException(trim(implode("\n", $output)) ?: "Command failed: $command");
            }
            return implode("\n", $output);
        };
    }

    /** @param list<string> $arguments */
    public function docker(array $arguments): string
    {
        return ($this->runner)('docker ' . implode(' ', array_map('escapeshellarg', $arguments)));
    }

    /** @return array<string,mixed> */
    public function inspect(string $container): array
    {
        $decoded = json_decode($this->docker(['inspect', '--', $container]), true);
        if (!is_array($decoded) || !is_array($decoded[0] ?? null)) {
            throw new RuntimeException("Docker returned invalid inspection data for $container.");
        }
        return $decoded[0];
    }

    /** @return array<string,mixed> */
    public function inspectNetwork(string $network): array
    {
        $decoded = json_decode($this->docker(['network', 'inspect', '--', $network]), true);
        if (!is_array($decoded) || !is_array($decoded[0] ?? null)) {
            throw new RuntimeException("Docker returned invalid network inspection data for $network.");
        }
        return $decoded[0];
    }

    /** @param list<string> $arguments */
    public function exec(string $container, array $arguments): string
    {
        return $this->docker(array_merge(['exec', '--', $container], $arguments));
    }

    /** @return list<array<string,mixed>> */
    public function candidates(): array
    {
        $names = preg_split('/\R+/', trim($this->docker(['ps', '-a', '--format', '{{.Names}}']))) ?: [];
        $result = [];
        foreach (array_filter($names) as $name) {
            try {
                $inspect = $this->inspect($name);
            } catch (RuntimeException) {
                continue;
            }
            $networks = [];
            foreach ((array)($inspect['NetworkSettings']['Networks'] ?? []) as $network => $value) {
                $networks[] = [
                    'name' => (string)$network,
                    'ipv4' => (string)($value['IPAddress'] ?? ''),
                    'static_ipv4' => (string)($value['IPAMConfig']['IPv4Address'] ?? ''),
                ];
            }
            $mounts = [];
            foreach ((array)($inspect['Mounts'] ?? []) as $mount) {
                if (($mount['Type'] ?? '') === 'bind' && preg_match('#^/mnt/[^/]+/[^/]+(?:/.*)?$#', (string)($mount['Source'] ?? ''))) {
                    $mounts[] = [
                        'source' => (string)($mount['Source'] ?? ''),
                        'destination' => (string)($mount['Destination'] ?? ''),
                        'writable' => (bool)($mount['RW'] ?? false),
                    ];
                }
            }
            $result[] = [
                'name' => (string)$name,
                'id' => (string)($inspect['Id'] ?? ''),
                'running' => (bool)($inspect['State']['Running'] ?? false),
                'image' => (string)($inspect['Config']['Image'] ?? ''),
                'networks' => $networks,
                'mounts' => $mounts,
            ];
        }
        return $result;
    }
}
