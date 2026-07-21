<?php

declare(strict_types=1);

namespace DockerDns;

final class Config
{
    public const CONFIG_FILE = 'config.json';
    public const SECRETS_FILE = 'secrets.json';
    public const OVERRIDES_FILE = 'overrides.json';
    public const STATE_FILE = 'state.json';

    public function __construct(public readonly JsonStore $store)
    {
    }

    public function initialize(): void
    {
        foreach ([
            self::CONFIG_FILE => $this->settings(),
            self::SECRETS_FILE => $this->secrets(),
            self::OVERRIDES_FILE => $this->overrides(),
            self::STATE_FILE => $this->state(),
        ] as $file => $contents) {
            if (!is_file($this->store->path($file))) {
                $this->store->write($file, $contents);
            }
        }
    }

    /** @return array<string,mixed> */
    public function settings(): array
    {
        return $this->store->read(self::CONFIG_FILE, [
            'schema' => 2,
            'enabled' => false,
            'provider' => 'adguard',
            'base_url' => '',
            'verify_tls' => true,
            'timeout_seconds' => 10,
            'host_ipv4_override' => '',
            'proxy_enabled' => false,
            'proxy_adapter' => 'caddy',
            'proxy_container' => '',
            'proxy_network' => '',
            'proxy_mount_source' => '',
            'proxy_mount_destination' => '',
            'caddy_main_config' => '/etc/caddy/Caddyfile',
            'traefik_entrypoint' => 'web',
        ]);
    }

    /** @return array<string,mixed> */
    public function secrets(): array
    {
        return $this->store->read(self::SECRETS_FILE, [
            'username' => '',
            'password' => '',
        ]);
    }

    /** @return array<string,mixed> */
    public function overrides(): array
    {
        return $this->store->read(self::OVERRIDES_FILE, [
            'revision' => 0,
            'containers' => [],
        ]);
    }

    /** @return array<string,mixed> */
    public function state(): array
    {
        return $this->store->read(self::STATE_FILE, [
            'revision' => 0,
            'provider_identity' => '',
            'records' => [],
            'containers' => [],
            'context_urls' => [],
            'last_sync' => null,
            'last_success' => null,
            'last_error' => '',
            'integration_warning' => '',
            'proxy' => [
                'status' => 'disabled',
                'adapter' => '',
                'container_id' => '',
                'container_name' => '',
                'network' => '',
                'ipv4' => '',
                'config_hash' => '',
                'generated_path' => '',
                'last_apply' => null,
                'last_error' => '',
            ],
        ]);
    }

    /** @param array<string,mixed> $settings */
    public function saveSettings(array $settings): void
    {
        $this->store->write(self::CONFIG_FILE, $settings);
    }

    /** @param array<string,mixed> $secrets */
    public function saveSecrets(array $secrets): void
    {
        $this->store->write(self::SECRETS_FILE, $secrets);
    }

    /** @param array<string,mixed> $overrides */
    public function saveOverrides(array $overrides): void
    {
        $this->store->write(self::OVERRIDES_FILE, $overrides);
    }

    /** @param array<string,mixed> $state */
    public function saveState(array $state): void
    {
        $this->store->write(self::STATE_FILE, $state);
    }

    /** @param array<string,mixed> $settings */
    public static function providerIdentity(array $settings): string
    {
        return strtolower((string)($settings['provider'] ?? '')) . '|' . rtrim((string)($settings['base_url'] ?? ''), '/');
    }
}
