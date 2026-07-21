<?php

declare(strict_types=1);

namespace DockerDns;

use InvalidArgumentException;

final class ProxyRouteResolver
{
    /** @param list<array<string,mixed>> $containers @return list<array<string,mixed>> */
    public function resolve(array $containers): array
    {
        $routes = [];
        foreach ($containers as $container) {
            if (!($container['included'] ?? false) || !($container['proxy_enabled'] ?? true)) {
                continue;
            }
            $tcp = array_values(array_filter((array)($container['ports'] ?? []), static fn(array $port): bool => ($port['protocol'] ?? '') === 'tcp'));
            if ($tcp === []) {
                continue;
            }
            $selected = $this->selectPort($tcp, (string)($container['webui_label'] ?? ''), $container['proxy_private_port'] ?? null);
            $target = trim((string)($container['target_ipv4'] ?? ''));
            if (!filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                continue;
            }
            $automatic = parse_url((string)($container['automatic_url'] ?? '')) ?: [];
            $overrideValue = trim((string)($container['url_override'] ?? ''));
            $override = $overrideValue === '' ? [] : (parse_url($overrideValue) ?: []);
            $scheme = (string)($container['proxy_scheme'] ?? 'auto');
            if ($scheme === 'auto') {
                $scheme = strtolower((string)($automatic['scheme'] ?? 'http'));
            }
            if (!in_array($scheme, ['http', 'https'], true)) {
                $scheme = 'http';
            }
            $pathParts = $override !== [] ? $override : $automatic;
            $path = (string)($pathParts['path'] ?? '/');
            if ($path === '') $path = '/';
            if (isset($pathParts['query'])) $path .= '?' . $pathParts['query'];
            $serverName = (string)($container['proxy_tls_server_name'] ?? '');
            if ($serverName !== '' && !Hostname::isValidHost($serverName)) {
                throw new InvalidArgumentException("Invalid proxy TLS server name for {$container['name']}.");
            }
            $routes[] = [
                'name' => (string)$container['name'],
                'hostname' => strtolower((string)$container['hostname']),
                'public_url' => 'http://' . strtolower((string)$container['hostname']) . $path,
                'upstream_scheme' => $scheme,
                'upstream_host' => $target,
                'upstream_port' => (int)(($container['direct_network'] ?? false) ? $selected['private'] : $selected['public']),
                'verify_tls' => (bool)($container['proxy_verify_tls'] ?? true),
                'tls_server_name' => $serverName,
            ];
        }
        return $routes;
    }

    /** @param list<array{private:int,public:int,protocol:string}> $ports */
    private function selectPort(array $ports, string $label, mixed $override): array
    {
        $explicit = $override !== null && $override !== '';
        $private = is_int($override) || ctype_digit((string)$override) ? (int)$override : 0;
        if ($private === 0 && preg_match('/\[PORT:(\d+)\]/i', $label, $match)) {
            $private = (int)$match[1];
        }
        if ($private > 0) {
            foreach ($ports as $port) {
                if ((int)$port['private'] === $private) return $port;
            }
            if ($explicit) throw new InvalidArgumentException("Selected proxy port $private is not published by the container.");
        }
        usort($ports, static fn(array $a, array $b): int => (int)$a['public'] <=> (int)$b['public']);
        return $ports[0];
    }
}
