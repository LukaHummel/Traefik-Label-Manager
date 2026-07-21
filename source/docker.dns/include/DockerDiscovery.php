<?php

declare(strict_types=1);

namespace DockerDns;

use Closure;
use RuntimeException;

final class DockerDiscovery
{
    private Closure $runner;

    /** @param null|callable(string):string $runner */
    public function __construct(?callable $runner = null)
    {
        $this->runner = $runner !== null ? Closure::fromCallable($runner) : static function (string $command): string {
            $output = [];
            $status = 0;
            exec($command . ' 2>/dev/null', $output, $status);
            if ($status !== 0) {
                throw new RuntimeException("Command failed: $command");
            }
            return implode("\n", $output);
        };
    }

    /** @return list<array<string,mixed>> */
    public function discover(array $settings, array $overrides, array $previousState): array
    {
        $ids = preg_split('/\s+/', trim(($this->runner)('docker ps -aq --no-trunc'))) ?: [];
        $ids = array_values(array_filter($ids));
        if ($ids === []) {
            return [];
        }
        $quoted = implode(' ', array_map('escapeshellarg', $ids));
        $decoded = json_decode(($this->runner)('docker inspect ' . $quoted), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Docker returned invalid inspect data.');
        }
        $drivers = $this->networkDrivers($decoded);
        return $this->fromInspects($decoded, $drivers, $settings, $overrides, $previousState);
    }

    /**
     * @param list<array<string,mixed>> $inspects
     * @param array<string,string> $networkDrivers
     * @return list<array<string,mixed>>
     */
    public function fromInspects(array $inspects, array $networkDrivers, array $settings, array $overrides, array $previousState): array
    {
        $eligibleNames = [];
        $parsed = [];
        foreach ($inspects as $inspect) {
            $ports = self::publishedPorts($inspect);
            if ($ports === []) {
                continue;
            }
            $name = ltrim((string)($inspect['Name'] ?? ''), '/');
            if ($name === '') {
                continue;
            }
            if (($settings['proxy_enabled'] ?? false) && $name === trim((string)($settings['proxy_container'] ?? ''))) {
                continue;
            }
            $eligibleNames[] = $name;
            $parsed[$name] = [$inspect, $ports];
        }
        $hostnames = Hostname::allocate($eligibleNames);
        $containers = [];
        $entries = is_array($overrides['containers'] ?? null) ? $overrides['containers'] : [];
        foreach ($parsed as $name => [$inspect, $ports]) {
            $entry = is_array($entries[$name] ?? null) ? $entries[$name] : [];
            $target = $this->targetIpv4($name, $inspect, $networkDrivers, $settings, $entry, $previousState);
            $hostname = $hostnames[$name];
            $label = (string)($inspect['Config']['Labels']['net.unraid.docker.webui'] ?? '');
            $directNetwork = false;
            $networkDriver = 'bridge';
            foreach (array_keys((array)($inspect['NetworkSettings']['Networks'] ?? [])) as $networkName) {
                $driver = $networkDrivers[(string)$networkName] ?? '';
                $directNetwork = $directNetwork || in_array($driver, ['macvlan', 'ipvlan'], true);
                if (in_array($driver, ['macvlan', 'ipvlan'], true)) {
                    $networkDriver = $driver;
                }
            }
            $automatic = Url::automatic($hostname, $ports, $label, $directNetwork);
            $override = trim((string)($entry['url_override'] ?? ''));
            $containers[] = [
                'id' => (string)($inspect['Id'] ?? ''),
                'name' => $name,
                'running' => (bool)($inspect['State']['Running'] ?? false),
                'included' => !array_key_exists('included', $entry) || (bool)$entry['included'],
                'ports' => $ports,
                'hostname' => $hostname,
                'target_ipv4' => $target['ip'],
                'target_status' => $target['status'],
                'automatic_url' => $automatic,
                'url_override' => $override,
                'url' => $override !== '' ? $override : $automatic,
                'webui_label' => $label,
                'direct_network' => $directNetwork,
                'network_driver' => $networkDriver,
                'proxy_enabled' => !array_key_exists('proxy_enabled', $entry) || (bool)$entry['proxy_enabled'],
                'proxy_private_port' => isset($entry['proxy_private_port']) ? (int)$entry['proxy_private_port'] : null,
                'proxy_scheme' => (string)($entry['proxy_scheme'] ?? 'auto'),
                'proxy_verify_tls' => !array_key_exists('proxy_verify_tls', $entry) || (bool)$entry['proxy_verify_tls'],
                'proxy_tls_server_name' => trim((string)($entry['proxy_tls_server_name'] ?? '')),
            ];
        }
        usort($containers, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        return $containers;
    }

    /** @return list<array{private:int,public:int,protocol:string}> */
    public static function publishedPorts(array $inspect): array
    {
        $bindings = $inspect['HostConfig']['PortBindings'] ?? null;
        if (!is_array($bindings)) {
            return [];
        }
        $result = [];
        foreach ($bindings as $containerPort => $hosts) {
            if (!preg_match('/^(\d+)\/(tcp|udp)$/i', (string)$containerPort, $match) || !is_array($hosts)) {
                continue;
            }
            foreach ($hosts as $host) {
                $published = trim((string)($host['HostPort'] ?? ''));
                if ($published === '' || !ctype_digit($published)) {
                    continue;
                }
                $public = (int)$published;
                if ($public < 1 || $public > 65535) {
                    continue;
                }
                $result[] = ['private' => (int)$match[1], 'public' => $public, 'protocol' => strtolower($match[2])];
            }
        }
        usort($result, static fn(array $a, array $b): int => [$a['public'], $a['private']] <=> [$b['public'], $b['private']]);
        return $result;
    }

    /** @param list<array<string,mixed>> $inspects @return array<string,string> */
    private function networkDrivers(array $inspects): array
    {
        $names = [];
        foreach ($inspects as $inspect) {
            foreach (array_keys((array)($inspect['NetworkSettings']['Networks'] ?? [])) as $name) {
                $names[(string)$name] = true;
            }
        }
        if ($names === []) {
            return [];
        }
        try {
            $quoted = implode(' ', array_map('escapeshellarg', array_keys($names)));
            $networks = json_decode(($this->runner)('docker network inspect ' . $quoted), true);
        } catch (RuntimeException) {
            return [];
        }
        $result = [];
        foreach ((array)$networks as $network) {
            if (isset($network['Name'], $network['Driver'])) {
                $result[(string)$network['Name']] = strtolower((string)$network['Driver']);
            }
        }
        return $result;
    }

    /** @return array{ip:string,status:string} */
    private function targetIpv4(string $name, array $inspect, array $drivers, array $settings, array $entry, array $previousState): array
    {
        $override = trim((string)($entry['target_ipv4_override'] ?? ''));
        if ($override !== '') {
            return filter_var($override, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                ? ['ip' => $override, 'status' => 'override']
                : ['ip' => '', 'status' => 'invalid IP override'];
        }
        $custom = [];
        foreach ((array)($inspect['NetworkSettings']['Networks'] ?? []) as $networkName => $network) {
            if (in_array($drivers[(string)$networkName] ?? '', ['macvlan', 'ipvlan'], true)) {
                $ip = trim((string)($network['IPAddress'] ?? ''));
                if ($ip === '') {
                    $ip = trim((string)($network['IPAMConfig']['IPv4Address'] ?? ''));
                }
                $ip = explode('/', $ip)[0];
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $custom[$ip] = true;
                }
            }
        }
        if (count($custom) === 1) {
            return ['ip' => array_key_first($custom), 'status' => 'custom network'];
        }
        if (count($custom) > 1) {
            return ['ip' => '', 'status' => 'multiple custom-network IPv4 addresses; choose an override'];
        }
        $last = (string)($previousState['containers'][$name]['target_ipv4'] ?? '');
        $hasCustomNetwork = false;
        foreach (array_keys((array)($inspect['NetworkSettings']['Networks'] ?? [])) as $networkName) {
            $hasCustomNetwork = $hasCustomNetwork || in_array($drivers[(string)$networkName] ?? '', ['macvlan', 'ipvlan'], true);
        }
        if ($hasCustomNetwork && filter_var($last, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ['ip' => $last, 'status' => 'last known custom-network address'];
        }
        $hostOverride = trim((string)($settings['host_ipv4_override'] ?? ''));
        if ($hostOverride !== '') {
            return filter_var($hostOverride, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                ? ['ip' => $hostOverride, 'status' => 'Unraid override']
                : ['ip' => '', 'status' => 'invalid Unraid IPv4 override'];
        }
        $detected = $this->detectHostIpv4();
        return $detected !== ''
            ? ['ip' => $detected, 'status' => 'Unraid LAN IPv4']
            : ['ip' => '', 'status' => 'Unraid LAN IPv4 not found'];
    }

    private function detectHostIpv4(): string
    {
        if (($test = getenv('DOCKER_DNS_HOST_IPV4')) !== false) {
            return filter_var($test, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $test : '';
        }
        try {
            $json = json_decode(($this->runner)('ip -4 -json addr show'), true);
        } catch (RuntimeException) {
            return '';
        }
        $preferred = ['br0' => 0, 'bond0' => 1, 'eth0' => 2];
        $candidates = [];
        foreach ((array)$json as $interface) {
            $ifname = (string)($interface['ifname'] ?? '');
            if ($ifname === 'lo' || str_starts_with($ifname, 'docker') || str_starts_with($ifname, 'veth')) {
                continue;
            }
            foreach ((array)($interface['addr_info'] ?? []) as $address) {
                $ip = (string)($address['local'] ?? '');
                if (($address['family'] ?? '') === 'inet' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $candidates[] = ['priority' => $preferred[$ifname] ?? 10, 'ip' => $ip];
                }
            }
        }
        usort($candidates, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);
        return (string)($candidates[0]['ip'] ?? '');
    }
}
