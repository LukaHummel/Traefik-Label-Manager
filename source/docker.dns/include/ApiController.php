<?php

declare(strict_types=1);

namespace DockerDns;

use InvalidArgumentException;
use RuntimeException;

final class ApiController
{
    public function __construct(private readonly Config $config, private readonly SyncEngine $sync)
    {
    }

    /** @return array<string,mixed> */
    public function get(string $action, array $query): array
    {
        return match ($action) {
            'context-urls' => $this->contextUrls(),
            'status' => $this->status(true),
            'container-url' => $this->containerUrl((string)($query['container_name'] ?? '')),
            default => throw new InvalidArgumentException('Unknown API action.'),
        };
    }

    /** @return array<string,mixed> */
    public function post(array $input): array
    {
        // Unraid validates and removes the standard form field before this script runs.
        $this->validateCsrf((string)($input['docker_dns_csrf_token'] ?? $input['csrf_token'] ?? ''));
        return match ((string)($input['action'] ?? '')) {
            'save-container-url' => $this->saveContainerUrl($input),
            'set-container' => $this->setContainer($input),
            'save-settings' => $this->saveSettings($input),
            'test-connection' => $this->testConnection($input),
            'sync-now' => ['ok' => true, 'state' => $this->sync->sync(true)],
            'validate-proxy' => $this->validateProxy($input),
            'cleanup-all' => $this->cleanupAll(),
            'integration-warning' => $this->integrationWarning((string)($input['message'] ?? '')),
            default => throw new InvalidArgumentException('Unknown API action.'),
        };
    }

    /** @return array<string,mixed> */
    private function contextUrls(): array
    {
        $state = $this->config->state();
        return ['revision' => (int)$state['revision'], 'containers' => (array)$state['context_urls']];
    }

    /** @return array<string,mixed> */
    private function status(bool $refresh): array
    {
        if ($refresh) {
            try {
                $this->sync->preview();
            } catch (\Throwable $error) {
                Logger::warning('Status discovery failed: ' . $error->getMessage());
            }
        }
        $settings = $this->config->settings();
        $secrets = $this->config->secrets();
        return [
            'settings' => $settings,
            'credentials' => [
                'username' => (string)($secrets['username'] ?? ''),
                'password_set' => (string)($secrets['password'] ?? '') !== '',
            ],
            'state' => $this->config->state(),
            'overrides_revision' => (int)($this->config->overrides()['revision'] ?? 0),
            'proxy_candidates' => $this->sync->proxyCandidates(),
        ];
    }

    /** @return array<string,mixed> */
    private function containerUrl(string $name): array
    {
        $state = $this->sync->preview();
        $container = $state['containers'][$name] ?? null;
        $entry = $this->config->overrides()['containers'][$name] ?? [];
        return [
            'container_name' => $name,
            'url_override' => (string)($entry['url_override'] ?? ''),
            'automatic_url' => (string)($container['automatic_url'] ?? ''),
            'proxy_url' => (string)($container['proxy_url'] ?? ''),
            'effective_url' => (string)($container['url'] ?? ''),
            'hostname' => (string)($container['hostname'] ?? (Hostname::label($name) . '.home.arpa')),
        ];
    }

    /** @return array<string,mixed> */
    private function saveContainerUrl(array $input): array
    {
        $newName = $this->validateContainerName((string)($input['container_name'] ?? ''));
        $previousName = trim((string)($input['previous_name'] ?? ''));
        if ($previousName !== '') {
            $previousName = $this->validateContainerName($previousName);
        }
        $url = Url::validateOverride((string)($input['url_override'] ?? ''));
        $overrides = $this->config->overrides();
        $entries = (array)($overrides['containers'] ?? []);
        $existing = is_array($entries[$previousName ?: $newName] ?? null) ? $entries[$previousName ?: $newName] : [];
        if ($previousName !== '' && $previousName !== $newName) {
            unset($entries[$previousName]);
        }
        if ($url === '') {
            unset($existing['url_override']);
        } else {
            $existing['url_override'] = $url;
        }
        if ($existing === []) {
            unset($entries[$newName]);
        } else {
            $entries[$newName] = $existing;
        }
        $overrides['containers'] = $entries;
        $overrides['revision'] = (int)($overrides['revision'] ?? 0) + 1;
        $this->config->saveOverrides($overrides);
        $state = $this->sync->preview();
        return ['ok' => true, 'revision' => $state['revision'], 'url_override' => $url];
    }

    /** @return array<string,mixed> */
    private function setContainer(array $input): array
    {
        $name = $this->validateContainerName((string)($input['container_name'] ?? ''));
        $overrides = $this->config->overrides();
        $entry = is_array($overrides['containers'][$name] ?? null) ? $overrides['containers'][$name] : [];
        if (array_key_exists('included', $input)) {
            $entry['included'] = filter_var($input['included'], FILTER_VALIDATE_BOOL);
        }
        if (array_key_exists('target_ipv4_override', $input)) {
            $ip = trim((string)$input['target_ipv4_override']);
            if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                throw new InvalidArgumentException('Target override must be a valid IPv4 address.');
            }
            if ($ip === '') {
                unset($entry['target_ipv4_override']);
            } else {
                $entry['target_ipv4_override'] = $ip;
            }
        }
        if (array_key_exists('url_override', $input)) {
            $url = Url::validateOverride((string)$input['url_override']);
            if ($url === '') {
                unset($entry['url_override']);
            } else {
                $entry['url_override'] = $url;
            }
        }
        if (array_key_exists('proxy_enabled', $input)) {
            $entry['proxy_enabled'] = filter_var($input['proxy_enabled'], FILTER_VALIDATE_BOOL);
        }
        if (array_key_exists('proxy_private_port', $input)) {
            $port = trim((string)$input['proxy_private_port']);
            if ($port === '') {
                unset($entry['proxy_private_port']);
            } elseif (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) {
                throw new InvalidArgumentException('Proxy port must be between 1 and 65535.');
            } else {
                $entry['proxy_private_port'] = (int)$port;
            }
        }
        if (array_key_exists('proxy_scheme', $input)) {
            $scheme = strtolower((string)$input['proxy_scheme']);
            if (!in_array($scheme, ['auto', 'http', 'https'], true)) {
                throw new InvalidArgumentException('Proxy scheme must be automatic, HTTP, or HTTPS.');
            }
            $entry['proxy_scheme'] = $scheme;
        }
        if (array_key_exists('proxy_verify_tls', $input)) {
            $entry['proxy_verify_tls'] = filter_var($input['proxy_verify_tls'], FILTER_VALIDATE_BOOL);
        }
        if (array_key_exists('proxy_tls_server_name', $input)) {
            $serverName = strtolower(trim((string)$input['proxy_tls_server_name']));
            if ($serverName !== '' && !Hostname::isValidHost($serverName)) {
                throw new InvalidArgumentException('Proxy TLS server name is invalid.');
            }
            if ($serverName === '') unset($entry['proxy_tls_server_name']);
            else $entry['proxy_tls_server_name'] = $serverName;
        }
        $overrides['containers'][$name] = $entry;
        $overrides['revision'] = (int)($overrides['revision'] ?? 0) + 1;
        $this->config->saveOverrides($overrides);
        $state = ($this->config->settings()['enabled'] ?? false) ? $this->sync->sync() : $this->sync->preview();
        return ['ok' => true, 'state' => $state];
    }

    /** @return array<string,mixed> */
    private function saveSettings(array $input): array
    {
        [$settings, $secrets] = $this->validatedProviderInput($input);
        $oldSettings = $this->config->settings();
        $oldSecrets = $this->config->secrets();
        $state = $this->config->state();
        $proxyFields = ['proxy_adapter', 'proxy_container', 'proxy_network', 'proxy_mount_source', 'proxy_mount_destination'];
        $proxyChanged = false;
        foreach ($proxyFields as $field) {
            if (($oldSettings[$field] ?? '') !== ($settings[$field] ?? '')) $proxyChanged = true;
        }
        if ($proxyChanged && ($state['proxy']['status'] ?? '') === 'active') {
            throw new InvalidArgumentException('Disable the reverse proxy with its current settings and Sync Now before changing its container, adapter, network, or mount.');
        }
        if ((array)$state['records'] !== [] && Config::providerIdentity($oldSettings) !== Config::providerIdentity($settings)) {
            $this->sync->cleanup($oldSettings, $oldSecrets);
        }
        $this->config->saveSettings($settings);
        $this->config->saveSecrets($secrets);
        return ['ok' => true, 'status' => $this->status(false)];
    }

    /** @return array<string,mixed> */
    private function testConnection(array $input): array
    {
        [$settings, $secrets] = $this->validatedProviderInput($input);
        $this->sync->testProvider($settings, $secrets);
        return ['ok' => true, 'message' => 'Connection succeeded.'];
    }

    /** @return array<string,mixed> */
    private function validateProxy(array $input): array
    {
        [$settings] = $this->validatedProviderInput($input);
        return ['ok' => true, 'proxy' => $this->sync->validateProxy($settings), 'message' => 'Proxy integration loaded successfully.'];
    }

    /** @return array<string,mixed> */
    private function cleanupAll(): array
    {
        $this->sync->cleanup($this->config->settings(), $this->config->secrets());
        return ['ok' => true, 'state' => $this->config->state()];
    }

    /** @return array<string,mixed> */
    private function integrationWarning(string $message): array
    {
        $state = $this->config->state();
        $state['integration_warning'] = substr(str_replace(["\r", "\n"], ' ', $message), 0, 500);
        $this->config->saveState($state);
        if ($state['integration_warning'] !== '') {
            Logger::warning('UI compatibility: ' . $state['integration_warning']);
        }
        return ['ok' => true];
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function validatedProviderInput(array $input): array
    {
        $currentSettings = $this->config->settings();
        $currentSecrets = $this->config->secrets();
        $provider = (string)($input['provider'] ?? $currentSettings['provider']);
        if (!in_array($provider, ['adguard', 'pihole'], true)) {
            throw new InvalidArgumentException('Provider must be AdGuard Home or Pi-hole.');
        }
        $baseUrl = rtrim(trim((string)($input['base_url'] ?? $currentSettings['base_url'])), '/');
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Provider base URL must be an http(s) URL without credentials.');
        }
        $hostIp = trim((string)($input['host_ipv4_override'] ?? $currentSettings['host_ipv4_override']));
        if ($hostIp !== '' && !filter_var($hostIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new InvalidArgumentException('Unraid override must be a valid IPv4 address.');
        }
        $settings = [
            'schema' => 2,
            'enabled' => filter_var($input['enabled'] ?? $currentSettings['enabled'], FILTER_VALIDATE_BOOL),
            'provider' => $provider,
            'base_url' => $baseUrl,
            'verify_tls' => filter_var($input['verify_tls'] ?? $currentSettings['verify_tls'], FILTER_VALIDATE_BOOL),
            'timeout_seconds' => max(2, min(60, (int)($input['timeout_seconds'] ?? $currentSettings['timeout_seconds']))),
            'host_ipv4_override' => $hostIp,
            'proxy_enabled' => filter_var($input['proxy_enabled'] ?? $currentSettings['proxy_enabled'], FILTER_VALIDATE_BOOL),
            'proxy_adapter' => $this->validatedChoice((string)($input['proxy_adapter'] ?? $currentSettings['proxy_adapter']), ['caddy', 'traefik'], 'Proxy adapter'),
            'proxy_container' => trim((string)($input['proxy_container'] ?? $currentSettings['proxy_container'])),
            'proxy_network' => trim((string)($input['proxy_network'] ?? $currentSettings['proxy_network'])),
            'proxy_mount_source' => rtrim(trim((string)($input['proxy_mount_source'] ?? $currentSettings['proxy_mount_source'])), '/'),
            'proxy_mount_destination' => rtrim(trim((string)($input['proxy_mount_destination'] ?? $currentSettings['proxy_mount_destination'])), '/'),
            'caddy_main_config' => trim((string)($input['caddy_main_config'] ?? $currentSettings['caddy_main_config'])),
            'traefik_entrypoint' => trim((string)($input['traefik_entrypoint'] ?? $currentSettings['traefik_entrypoint'])),
        ];
        if ($settings['proxy_container'] !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $settings['proxy_container'])) {
            throw new InvalidArgumentException('Invalid proxy container name.');
        }
        if ($settings['proxy_network'] !== '' && !preg_match('/^[A-Za-z0-9_.-]+$/', $settings['proxy_network'])) {
            throw new InvalidArgumentException('Invalid proxy Docker network name.');
        }
        if ($settings['proxy_mount_source'] !== '' && !str_starts_with($settings['proxy_mount_source'], '/')) {
            throw new InvalidArgumentException('Proxy mount source must be an absolute path.');
        }
        if ($settings['proxy_mount_destination'] !== '' && !str_starts_with($settings['proxy_mount_destination'], '/')) {
            throw new InvalidArgumentException('Proxy mount destination must be an absolute path.');
        }
        if (!str_starts_with($settings['caddy_main_config'], '/') || !preg_match('/^[A-Za-z0-9_.-]+$/', $settings['traefik_entrypoint'])) {
            throw new InvalidArgumentException('Invalid adapter configuration path or entrypoint.');
        }
        $password = (string)($input['password'] ?? '');
        $secrets = [
            'username' => trim((string)($input['username'] ?? $currentSecrets['username'])),
            'password' => $password !== '' ? $password : (string)$currentSecrets['password'],
        ];
        return [$settings, $secrets];
    }

    /** @param list<string> $allowed */
    private function validatedChoice(string $value, array $allowed, string $label): string
    {
        if (!in_array($value, $allowed, true)) throw new InvalidArgumentException("$label is invalid.");
        return $value;
    }

    private function validateContainerName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 255 || preg_match('/[\x00-\x1F\x7F\/]/', $name)) {
            throw new InvalidArgumentException('Invalid container name.');
        }
        return $name;
    }

    private function validateCsrf(string $submitted): void
    {
        $expected = getenv('DOCKER_DNS_CSRF_TOKEN');
        if ($expected === false) {
            $ini = @parse_ini_file('/var/local/emhttp/var.ini');
            $expected = is_array($ini) ? (string)($ini['csrf_token'] ?? '') : '';
        }
        if ($expected === '' || $submitted === '' || !hash_equals($expected, $submitted)) {
            throw new CsrfException('CSRF validation failed.');
        }
    }
}
