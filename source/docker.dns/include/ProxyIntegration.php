<?php

declare(strict_types=1);

namespace DockerDns;

use Closure;
use RuntimeException;
use Throwable;

final class ProxyIntegration
{
    private ?Closure $probeRunner;

    public function __construct(
        private readonly DockerRunner $docker = new DockerRunner(),
        private readonly ProxyAdapterFactory $adapters = new ProxyAdapterFactory(),
        ?callable $probeRunner = null,
    ) {
        $this->probeRunner = $probeRunner !== null ? Closure::fromCallable($probeRunner) : null;
    }

    /** @return list<array<string,mixed>> */
    public function candidates(): array
    {
        try {
            return $this->docker->candidates();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string,mixed> */
    public function describe(array $settings): array
    {
        $container = trim((string)($settings['proxy_container'] ?? ''));
        $networkName = trim((string)($settings['proxy_network'] ?? ''));
        if ($container === '' || $networkName === '') {
            throw new RuntimeException('Select a proxy container and its LAN network.');
        }
        $inspect = $this->docker->inspect($container);
        if (!($inspect['State']['Running'] ?? false)) {
            throw new RuntimeException("Proxy container $container is not running.");
        }
        $network = $inspect['NetworkSettings']['Networks'][$networkName] ?? null;
        if (!is_array($network)) {
            throw new RuntimeException("Proxy container is not attached to network $networkName.");
        }
        $networkInspect = $this->docker->inspectNetwork($networkName);
        if (!in_array(strtolower((string)($networkInspect['Driver'] ?? '')), ['macvlan', 'ipvlan'], true)) {
            throw new RuntimeException('The proxy must use a macvlan or ipvlan network with its own LAN address.');
        }
        $ipv4 = trim((string)($network['IPAddress'] ?? ''));
        $static = trim((string)($network['IPAMConfig']['IPv4Address'] ?? ''));
        if (!filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new RuntimeException('The selected proxy network has no IPv4 address.');
        }
        if ($static === '') {
            throw new RuntimeException('The proxy IPv4 must be assigned statically in the container configuration.');
        }
        [$source, $destination] = $this->selectedMount($inspect, $settings);
        $adapterName = (string)($settings['proxy_adapter'] ?? 'caddy');
        $this->adapters->create($adapterName);
        $version = $this->docker->exec($container, [$adapterName, 'version']);
        if (($adapterName === 'caddy' && !preg_match('/\bv?2\.\d+/i', $version))
            || ($adapterName === 'traefik' && !preg_match('/\bVersion:\s*[23]\.\d+/i', $version))) {
            throw new RuntimeException('The selected container does not expose a supported ' . ucfirst($adapterName) . ' version.');
        }
        return [
            'status' => 'ready',
            'adapter' => $adapterName,
            'container_id' => (string)($inspect['Id'] ?? ''),
            'container_name' => $container,
            'network' => $networkName,
            'ipv4' => $ipv4,
            'mount_source' => $source,
            'mount_destination' => $destination,
            'generated_path' => rtrim($source, '/') . '/docker-dns',
            'container_generated_path' => rtrim($destination, '/') . '/docker-dns',
        ];
    }

    /** @param list<array<string,mixed>> $routes @return array<string,mixed> */
    public function apply(array $settings, array $routes): array
    {
        $description = $this->describe($settings);
        $adapter = $this->adapters->create((string)$description['adapter']);
        $directory = (string)$description['generated_path'];
        $this->prepareDirectory($directory, $description);
        $file = $directory . '/' . $adapter->filename();
        $previous = is_file($file) ? file_get_contents($file) : false;
        $rendered = $adapter instanceof TraefikProxyAdapter
            ? $this->renderTraefik($adapter, $routes, (string)($settings['traefik_entrypoint'] ?? 'web'))
            : $adapter->render($routes);
        $hash = hash('sha256', $rendered);
        if ($previous === $rendered && $this->probe((string)$description['ipv4'], $adapter->expectedProbeStatus())) {
            return $description + ['config_hash' => $hash, 'last_apply' => gmdate(DATE_ATOM), 'last_error' => ''];
        }
        $this->writeAtomic($file, $rendered);
        try {
            $adapter->validateAndReload($this->docker, $settings, (string)$description['container_generated_path'] . '/' . $adapter->filename());
            if (!$this->probe((string)$description['ipv4'], $adapter->expectedProbeStatus())) {
                throw new RuntimeException('The proxy did not load the Docker DNS configuration. Check the Caddy import or Traefik file provider.');
            }
        } catch (Throwable $error) {
            if ($previous === false) {
                @unlink($file);
            } else {
                $this->writeAtomic($file, $previous);
            }
            try {
                $adapter->validateAndReload($this->docker, $settings, (string)$description['container_generated_path'] . '/' . $adapter->filename());
            } catch (Throwable) {
            }
            throw $error;
        }
        $other = $directory . '/' . ($adapter instanceof CaddyProxyAdapter ? 'docker-dns.yml' : 'docker-dns.caddy');
        if (is_file($other)) @unlink($other);
        return $description + ['config_hash' => $hash, 'last_apply' => gmdate(DATE_ATOM), 'last_error' => ''];
    }

    /** @param list<array<string,mixed>> $routes */
    private function renderTraefik(TraefikProxyAdapter $adapter, array $routes, string $entrypoint): string
    {
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $entrypoint)) {
            throw new RuntimeException('Invalid Traefik entrypoint.');
        }
        return str_replace('["web"]', '["' . $entrypoint . '"]', $adapter->render($routes));
    }

    /** @return array{0:string,1:string} */
    private function selectedMount(array $inspect, array $settings): array
    {
        $source = rtrim(trim((string)($settings['proxy_mount_source'] ?? '')), '/');
        $destination = rtrim(trim((string)($settings['proxy_mount_destination'] ?? '')), '/');
        if ($source === '' || $destination === '' || !str_starts_with($destination, '/')) {
            throw new RuntimeException('Select an appdata bind mount for generated proxy configuration.');
        }
        if (!preg_match('#^/mnt/[^/]+/[^/]+(?:/.*)?$#', $source) && getenv('DOCKER_DNS_TEST_PROXY_PATH') !== $source) {
            throw new RuntimeException('The proxy configuration bind mount must be an appdata path below /mnt.');
        }
        foreach ((array)($inspect['Mounts'] ?? []) as $mount) {
            if (($mount['Type'] ?? '') === 'bind' && rtrim((string)($mount['Source'] ?? ''), '/') === $source
                && rtrim((string)($mount['Destination'] ?? ''), '/') === $destination) {
                if (!($mount['RW'] ?? false)) {
                    throw new RuntimeException('The selected proxy appdata bind mount is read-only.');
                }
                if (!is_dir($source) || is_link($source)) {
                    throw new RuntimeException('The selected proxy appdata source is not a safe directory.');
                }
                return [$source, $destination];
            }
        }
        throw new RuntimeException('The selected appdata bind mount no longer belongs to the proxy container.');
    }

    private function prepareDirectory(string $directory, array $description): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create proxy configuration directory: $directory");
        }
        if (is_link($directory)) {
            throw new RuntimeException('Proxy configuration directory must not be a symbolic link.');
        }
        $marker = $directory . '/.managed-by-docker-dns.json';
        $known = ['.managed-by-docker-dns.json', 'docker-dns.caddy', 'docker-dns.yml'];
        $names = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
        $unknown = array_values(array_filter($names, static fn(string $name): bool => !in_array($name, $known, true) && !str_starts_with($name, '.docker-dns-')));
        if (!is_file($marker) && $unknown !== []) {
            throw new RuntimeException('The generated proxy directory contains files not owned by Docker DNS.');
        }
        if (is_file($marker)) {
            $saved = json_decode((string)file_get_contents($marker), true);
            $sameOwner = is_array($saved) && (($saved['container_name'] ?? '') === $description['container_name']
                || (($saved['container_name'] ?? '') === '' && ($saved['container_id'] ?? '') === $description['container_id']));
            if (!$sameOwner) {
                throw new RuntimeException('The generated proxy directory is owned by another container.');
            }
        }
        $contents = json_encode(['schema' => 1, 'container_id' => $description['container_id'], 'container_name' => $description['container_name'], 'adapter' => $description['adapter']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $this->writeAtomic($marker, $contents);
    }

    private function writeAtomic(string $path, string $contents): void
    {
        $temporary = tempnam(dirname($path), '.docker-dns-');
        if ($temporary === false) throw new RuntimeException("Cannot create a temporary file for $path");
        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) === false) throw new RuntimeException("Cannot write $temporary");
            chmod($temporary, 0644);
            if (!rename($temporary, $path)) throw new RuntimeException("Cannot replace $path");
        } finally {
            if (is_file($temporary)) @unlink($temporary);
        }
    }

    private function probe(string $ipv4, int $expected): bool
    {
        if ($this->probeRunner !== null) return (bool)($this->probeRunner)($ipv4, $expected);
        if (!function_exists('curl_init')) return false;
        $handle = curl_init('http://' . $ipv4 . '/');
        if ($handle === false) return false;
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Host: docker-dns-probe.invalid'],
            CURLOPT_NOPROXY => '*',
        ]);
        $result = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        return $result !== false && $status === $expected;
    }
}
