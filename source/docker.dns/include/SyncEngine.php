<?php

declare(strict_types=1);

namespace DockerDns;

use RuntimeException;
use Throwable;

final class SyncEngine
{
    public function __construct(
        private readonly Config $config,
        private readonly DockerDiscovery $discovery = new DockerDiscovery(),
        private readonly ProviderFactory $providers = new ProviderFactory(),
        private readonly ProxyIntegration $proxy = new ProxyIntegration(),
        private readonly ProxyRouteResolver $routes = new ProxyRouteResolver(),
    ) {
    }

    /** @return array<string,mixed> */
    public function preview(): array
    {
        $settings = $this->config->settings();
        $overrides = $this->config->overrides();
        $state = $this->config->state();
        $containers = $this->discovery->discover($settings, $overrides, $state);
        $routes = $this->routes->resolve($containers);
        $proxyActive = (bool)($settings['proxy_enabled'] ?? false) && ($state['proxy']['status'] ?? '') === 'active';
        return $this->writeDiscoveryState($state, $containers, $routes, true, $proxyActive);
    }

    /** @return array<string,mixed> */
    public function sync(bool $force = false): array
    {
        return $this->withLock(function () use ($force): array {
            $settings = $this->config->settings();
            $secrets = $this->config->secrets();
            $overrides = $this->config->overrides();
            $state = $this->config->state();
            $containers = $this->discovery->discover($settings, $overrides, $state);
            $routes = $this->routes->resolve($containers);
            $state = $this->writeDiscoveryState($state, $containers, $routes, false);
            $state['last_sync'] = gmdate(DATE_ATOM);
            if (!$force && !($settings['enabled'] ?? false)) {
                $state['last_error'] = '';
                $this->config->saveState($state);
                return $state;
            }
            $proxyEnabled = (bool)($settings['proxy_enabled'] ?? false);
            $proxyInfo = null;
            try {
                if ($proxyEnabled) {
                    $proxyInfo = $this->proxy->apply($settings, $routes);
                    $state['proxy'] = array_replace($proxyInfo, ['status' => 'active']);
                    $state = $this->writeDiscoveryState($state, $containers, $routes, false, true);
                } else {
                    $state['proxy'] = array_replace((array)($state['proxy'] ?? []), ['status' => 'disabled', 'last_error' => '']);
                }
            } catch (Throwable $error) {
                $state['proxy'] = array_replace((array)($state['proxy'] ?? []), ['status' => 'error', 'last_error' => $error->getMessage()]);
                $state['last_error'] = 'Reverse proxy: ' . $error->getMessage();
                $this->config->saveState($state);
                Logger::error($state['last_error']);
                throw $error;
            }
            $routeHosts = [];
            foreach ($routes as $route) $routeHosts[(string)$route['hostname']] = $route;
            $desired = [];
            foreach ($containers as $container) {
                if ($container['included'] && filter_var($container['target_ipv4'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $hostname = strtolower((string)$container['hostname']);
                    $desired[$hostname] = $proxyInfo !== null && isset($routeHosts[$hostname])
                        ? (string)$proxyInfo['ipv4'] : (string)$container['target_ipv4'];
                }
            }
            $previous = is_array($state['records'] ?? null) ? $state['records'] : [];
            $remove = array_values(array_diff(array_keys($previous), array_keys($desired)));
            try {
                $provider = $this->providers->create($settings, $secrets);
                $provider->reconcile($desired, $remove);
                $state['records'] = $desired;
                $state['provider_identity'] = Config::providerIdentity($settings);
                $state['last_success'] = gmdate(DATE_ATOM);
                $state['last_error'] = '';
                foreach ($state['containers'] as &$containerState) {
                    if (!($containerState['included'] ?? false)) {
                        $containerState['dns_status'] = 'excluded';
                    } elseif (isset($desired[$containerState['hostname'] ?? ''])) {
                        $containerState['dns_status'] = 'synchronized';
                    } else {
                        $containerState['dns_status'] = (string)($containerState['target_status'] ?? 'waiting for an IPv4 address');
                    }
                }
                unset($containerState);
                if (!$proxyEnabled && (($state['proxy']['container_name'] ?? '') !== '') && trim((string)($settings['proxy_container'] ?? '')) !== '') {
                    try {
                        $this->proxy->apply($settings, []);
                    } catch (Throwable $error) {
                        $state['proxy'] = array_replace((array)$state['proxy'], ['status' => 'error', 'last_error' => $error->getMessage()]);
                        $state['last_error'] = 'DNS was restored directly, but generated proxy routes could not be cleared: ' . $error->getMessage();
                        Logger::warning('Could not clear generated proxy routes: ' . $error->getMessage());
                    }
                }
            } catch (Throwable $error) {
                $state['last_error'] = $error->getMessage();
                $this->config->saveState($state);
                Logger::error($error->getMessage());
                throw $error;
            }
            $this->config->saveState($state);
            return $state;
        });
    }

    public function testProvider(array $settings, array $secrets): void
    {
        $this->providers->create($settings, $secrets)->test();
    }

    /** @return list<array<string,mixed>> */
    public function proxyCandidates(): array
    {
        return $this->proxy->candidates();
    }

    /** @return array<string,mixed> */
    public function validateProxy(array $settings): array
    {
        $containers = $this->discovery->discover($settings, $this->config->overrides(), $this->config->state());
        return $this->proxy->apply($settings, $this->routes->resolve($containers));
    }

    public function cleanup(array $settings, array $secrets, bool $clearState = true): void
    {
        $this->withLock(function () use ($settings, $secrets, $clearState): void {
            $state = $this->config->state();
            $records = is_array($state['records'] ?? null) ? $state['records'] : [];
            if ($records !== []) {
                $this->providers->create($settings, $secrets)->reconcile([], array_keys($records));
            }
            if (trim((string)($settings['proxy_container'] ?? '')) !== '') {
                try {
                    $this->proxy->apply($settings, []);
                } catch (Throwable $error) {
                    Logger::warning('Best-effort proxy cleanup failed: ' . $error->getMessage());
                }
            }
            if ($clearState) {
                $state['records'] = [];
                $state['last_sync'] = gmdate(DATE_ATOM);
                $state['last_success'] = gmdate(DATE_ATOM);
                $state['last_error'] = '';
                $this->config->saveState($state);
            }
        });
    }

    /** @param list<array<string,mixed>> $containers @return array<string,mixed> */
    private function writeDiscoveryState(array $state, array $containers, array $routes = [], bool $save = true, bool $proxyActive = false): array
    {
        $routeMap = [];
        foreach ($routes as $route) $routeMap[(string)$route['name']] = $route;
        $contextUrls = [];
        $indexed = [];
        foreach ($containers as $container) {
            $route = $routeMap[$container['name']] ?? null;
            $proxyUrl = is_array($route) ? (string)$route['public_url'] : '';
            $effectiveUrl = $proxyActive && $proxyUrl !== '' ? $proxyUrl : $container['url'];
            $indexed[$container['name']] = [
                'name' => $container['name'],
                'running' => $container['running'],
                'included' => $container['included'],
                'ports' => $container['ports'],
                'hostname' => $container['hostname'],
                'target_ipv4' => $container['target_ipv4'],
                'target_status' => $container['target_status'],
                'automatic_url' => $container['automatic_url'],
                'url_override' => $container['url_override'],
                'url' => $effectiveUrl,
                'direct_url' => $container['url'],
                'proxy_url' => $proxyUrl,
                'proxy_enabled' => (bool)($container['proxy_enabled'] ?? true),
                'proxy_eligible' => is_array($route),
                'proxy_status' => $proxyActive && is_array($route) ? 'active' : (is_array($route) ? 'pending' : 'direct'),
                'proxy_private_port' => $container['proxy_private_port'] ?? null,
                'proxy_scheme' => $container['proxy_scheme'] ?? 'auto',
                'proxy_verify_tls' => $container['proxy_verify_tls'] ?? true,
                'proxy_tls_server_name' => $container['proxy_tls_server_name'] ?? '',
                'proxy_upstream' => is_array($route) ? $route['upstream_scheme'] . '://' . $route['upstream_host'] . ':' . $route['upstream_port'] : '',
                'network_driver' => $container['network_driver'] ?? 'bridge',
                'dns_status' => (string)($state['containers'][$container['name']]['dns_status'] ?? 'pending'),
            ];
            if ($container['running'] && $container['included'] && is_string($effectiveUrl) && $effectiveUrl !== '') {
                $contextUrls[$container['name']] = $effectiveUrl;
            }
        }
        $state['revision'] = (int)($state['revision'] ?? 0) + 1;
        $state['containers'] = $indexed;
        $state['context_urls'] = $contextUrls;
        if ($save) {
            $this->config->saveState($state);
        }
        return $state;
    }

    /** @template T @param callable():T $operation @return T */
    private function withLock(callable $operation): mixed
    {
        $lockPath = getenv('DOCKER_DNS_LOCK_FILE') ?: '/var/run/docker.dns.sync.lock';
        $handle = fopen($lockPath, 'c');
        if ($handle === false) {
            throw new RuntimeException("Cannot open synchronization lock: $lockPath");
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Cannot acquire synchronization lock.');
            }
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
