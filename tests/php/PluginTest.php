<?php

declare(strict_types=1);

use DockerDns\DockerDiscovery;
use DockerDns\ApiController;
use DockerDns\Config;
use DockerDns\CsrfException;
use DockerDns\Hostname;
use DockerDns\HttpClient;
use DockerDns\JsonStore;
use DockerDns\ProviderFactory;
use DockerDns\SyncEngine;
use DockerDns\Url;
use DockerDns\ProxyRouteResolver;
use DockerDns\CaddyProxyAdapter;
use DockerDns\TraefikProxyAdapter;
use DockerDns\DockerRunner;
use DockerDns\ProxyIntegration;
use DockerDns\providers\AdGuardProvider;
use DockerDns\providers\PiHoleProvider;
use PHPUnit\Framework\TestCase;

final class FakeHttpClient extends HttpClient
{
    /** @var list<array{status:int,body:mixed,raw:string}> */
    public array $responses = [];
    /** @var list<array<string,mixed>> */
    public array $calls = [];

    public function request(string $method, string $url, ?array $payload, array $headers, bool $verifyTls, int $timeout, ?string $basicAuth = null): array
    {
        $this->calls[] = compact('method', 'url', 'payload', 'headers', 'verifyTls', 'timeout', 'basicAuth');
        return array_shift($this->responses) ?? ['status' => 200, 'body' => [], 'raw' => ''];
    }
}

final class PluginTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('DOCKER_DNS_HOST_IPV4=192.168.1.10');
    }

    protected function tearDown(): void
    {
        putenv('DOCKER_DNS_HOST_IPV4');
    }

    public function testHostnameNormalizationAndStableCollisionSuffixes(): void
    {
        self::assertSame('my-plex', Hostname::label(' My_Plex '));
        $allocated = Hostname::allocate(['Same Name', 'same_name']);
        self::assertNotSame($allocated['Same Name'], $allocated['same_name']);
        self::assertStringEndsWith('.home.arpa', $allocated['Same Name']);
        self::assertLessThanOrEqual(63, strlen(explode('.', $allocated['Same Name'])[0]));
    }

    public function testEligibilityRequiresANonEmptyHostPort(): void
    {
        $inspect = ['HostConfig' => ['PortBindings' => [
            '80/tcp' => [['HostPort' => '8080']],
            '53/udp' => [['HostPort' => '']],
        ]]];
        self::assertSame([['private' => 80, 'public' => 8080, 'protocol' => 'tcp']], DockerDiscovery::publishedPorts($inspect));
        self::assertSame([], DockerDiscovery::publishedPorts(['Config' => ['ExposedPorts' => ['80/tcp' => []]]]));
    }

    public function testUrlGenerationAndValidation(): void
    {
        $ports = [['private' => 32400, 'public' => 32401, 'protocol' => 'tcp']];
        self::assertSame('http://plex.home.arpa:32401', Url::automatic('plex.home.arpa', $ports));
        self::assertSame('https://plex.home.arpa:32401/web?a=1', Url::automatic('plex.home.arpa', $ports, 'https://[IP]:[PORT:32400]/web?a=1'));
        self::assertSame('https://plex.home.arpa:32400/web', Url::automatic('plex.home.arpa', $ports, 'https://[IP]:[PORT:32400]/web', true));
        self::assertSame('https://plex.home.arpa/app?q=1', Url::validateOverride('https://plex.home.arpa/app?q=1'));
        $this->expectException(InvalidArgumentException::class);
        Url::validateOverride('https://user@example.home.arpa/app');
    }

    public function testBridgeAndMacvlanAddressSelection(): void
    {
        $bridge = $this->inspect('bridge-app', 'bridge', '');
        $custom = $this->inspect('lan-app', 'lan', '192.168.1.42');
        $containers = (new DockerDiscovery())->fromInspects([$bridge, $custom], ['bridge' => 'bridge', 'lan' => 'macvlan'], [], [], []);
        self::assertSame('192.168.1.10', $containers[0]['target_ipv4']);
        self::assertSame('192.168.1.42', $containers[1]['target_ipv4']);
    }

    public function testAdGuardTakesOverConflictWithUpdateAndVerifies(): void
    {
        $http = new FakeHttpClient();
        $http->responses = [
            ['status' => 200, 'body' => [['domain' => 'plex.home.arpa', 'answer' => '192.168.1.2']], 'raw' => ''],
            ['status' => 200, 'body' => null, 'raw' => ''],
            ['status' => 200, 'body' => [['domain' => 'plex.home.arpa', 'answer' => '192.168.1.10', 'enabled' => true]], 'raw' => ''],
        ];
        (new AdGuardProvider($http, 'http://adguard', 'admin', 'secret', true, 10))->reconcile(['plex.home.arpa' => '192.168.1.10'], []);
        self::assertSame('PUT', $http->calls[1]['method']);
        self::assertStringEndsWith('/control/rewrite/update', $http->calls[1]['url']);
    }

    public function testPiHolePreservesUnrelatedAliases(): void
    {
        $http = new FakeHttpClient();
        $hostsBefore = ['192.168.1.2 plex.home.arpa keep.home.arpa'];
        $hostsAfter = ['192.168.1.2 keep.home.arpa', '192.168.1.10 plex.home.arpa'];
        $http->responses = [
            ['status' => 200, 'body' => ['session' => ['valid' => true, 'sid' => 'sid']], 'raw' => ''],
            ['status' => 200, 'body' => ['config' => ['dns' => ['hosts' => $hostsBefore]]], 'raw' => ''],
            ['status' => 200, 'body' => [], 'raw' => ''],
            ['status' => 200, 'body' => ['config' => ['dns' => ['hosts' => $hostsAfter]]], 'raw' => ''],
            ['status' => 204, 'body' => null, 'raw' => ''],
        ];
        (new PiHoleProvider($http, 'http://pihole', 'secret', true, 10))->reconcile(['plex.home.arpa' => '192.168.1.10'], []);
        self::assertSame($hostsAfter, $http->calls[2]['payload']['config']['dns']['hosts']);
        self::assertSame(['X-FTL-SID' => 'sid'], $http->calls[1]['headers']);
        self::assertSame('DELETE', $http->calls[4]['method']);
    }

    public function testCsrfAndRenamedOverridePersistence(): void
    {
        $directory = sys_get_temp_dir() . '/docker-dns-test-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        putenv('DOCKER_DNS_CSRF_TOKEN=expected');
        try {
            $config = new Config(new JsonStore($directory));
            $discovery = new DockerDiscovery(static fn(string $command): string => '');
            $controller = new ApiController($config, new SyncEngine($config, $discovery, new ProviderFactory()));
            try {
                $controller->post(['action' => 'save-container-url', 'csrf_token' => 'wrong']);
                self::fail('Bad CSRF token was accepted.');
            } catch (CsrfException) {
                self::assertTrue(true);
            }
            $config->saveOverrides(['revision' => 1, 'containers' => ['old' => ['included' => false]]]);
            $controller->post([
                'action' => 'save-container-url', 'docker_dns_csrf_token' => 'expected',
                'previous_name' => 'old', 'container_name' => 'new',
                'url_override' => 'https://new.home.arpa/app',
            ]);
            $overrides = $config->overrides();
            self::assertArrayNotHasKey('old', $overrides['containers']);
            self::assertSame(false, $overrides['containers']['new']['included']);
            self::assertSame('https://new.home.arpa/app', $overrides['containers']['new']['url_override']);
        } finally {
            putenv('DOCKER_DNS_CSRF_TOKEN');
            foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
            rmdir($directory);
        }
    }

    public function testProviderSettingsAreReturnedAfterSaving(): void
    {
        $directory = sys_get_temp_dir() . '/docker-dns-test-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        putenv('DOCKER_DNS_CSRF_TOKEN=expected');
        try {
            $config = new Config(new JsonStore($directory));
            $discovery = new DockerDiscovery(static fn(string $command): string => '');
            $controller = new ApiController($config, new SyncEngine($config, $discovery, new ProviderFactory()));
            $result = $controller->post([
                'action' => 'save-settings',
                'docker_dns_csrf_token' => 'expected',
                'enabled' => 'true',
                'provider' => 'pihole',
                'base_url' => 'https://pihole.local/',
                'username' => '',
                'password' => 'secret',
                'verify_tls' => 'false',
                'host_ipv4_override' => '192.168.1.20',
                'timeout_seconds' => '25',
            ]);

            self::assertSame([
                'schema' => 2,
                'enabled' => true,
                'provider' => 'pihole',
                'base_url' => 'https://pihole.local',
                'verify_tls' => false,
                'timeout_seconds' => 25,
                'host_ipv4_override' => '192.168.1.20',
                'proxy_enabled' => false,
                'proxy_adapter' => 'caddy',
                'proxy_container' => '',
                'proxy_network' => '',
                'proxy_mount_source' => '',
                'proxy_mount_destination' => '',
                'caddy_main_config' => '/etc/caddy/Caddyfile',
                'traefik_entrypoint' => 'web',
            ], $result['status']['settings']);
            self::assertSame($result['status']['settings'], $controller->get('status', [])['settings']);
            self::assertTrue($result['status']['credentials']['password_set']);
        } finally {
            putenv('DOCKER_DNS_CSRF_TOKEN');
            foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
            rmdir($directory);
        }
    }

    public function testProxyRoutesUsePublishedAndPrivatePortsByNetworkMode(): void
    {
        $base = [
            'name' => 'app', 'included' => true, 'proxy_enabled' => true,
            'ports' => [['private' => 80, 'public' => 8080, 'protocol' => 'tcp']],
            'hostname' => 'app.home.arpa', 'target_ipv4' => '192.168.1.10',
            'webui_label' => 'http://[IP]:[PORT:80]/ui', 'automatic_url' => 'http://app.home.arpa:8080/ui',
            'url_override' => '', 'proxy_scheme' => 'auto', 'proxy_verify_tls' => true,
            'proxy_tls_server_name' => '', 'proxy_private_port' => null,
        ];
        $routes = (new ProxyRouteResolver())->resolve([$base]);
        self::assertSame(8080, $routes[0]['upstream_port']);

        $direct = $base;
        $direct['name'] = 'direct';
        $direct['hostname'] = 'direct.home.arpa';
        $direct['direct_network'] = true;
        $routes = (new ProxyRouteResolver())->resolve([$direct]);
        self::assertSame(80, $routes[0]['upstream_port']);
        self::assertSame('http://direct.home.arpa/ui', $routes[0]['public_url']);
    }

    public function testProxyAdaptersRenderIsolatedRoutesAndTlsPolicy(): void
    {
        $route = [[
            'hostname' => 'app.home.arpa', 'upstream_scheme' => 'https',
            'upstream_host' => '192.168.1.50', 'upstream_port' => 443,
            'verify_tls' => false, 'tls_server_name' => '',
        ]];
        $caddy = (new CaddyProxyAdapter())->render($route);
        self::assertStringContainsString('http://app.home.arpa', $caddy);
        self::assertStringContainsString('tls_insecure_skip_verify', $caddy);
        self::assertStringContainsString('docker-dns-probe.invalid', $caddy);

        $traefik = (new TraefikProxyAdapter())->render($route);
        self::assertStringContainsString('Host(`app.home.arpa`)', $traefik);
        self::assertStringContainsString('insecureSkipVerify: true', $traefik);
        self::assertStringContainsString('docker-dns-probe', $traefik);
    }

    public function testProxyIntegrationOnlyWritesOwnedConfigAndReloadsUserContainer(): void
    {
        $directory = sys_get_temp_dir() . '/docker-dns-proxy-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        putenv('DOCKER_DNS_TEST_PROXY_PATH=' . $directory);
        $commands = [];
        $inspect = [[
            'Id' => 'proxy-id', 'State' => ['Running' => true],
            'NetworkSettings' => ['Networks' => ['lan' => ['IPAddress' => '192.168.1.9', 'IPAMConfig' => ['IPv4Address' => '192.168.1.9']]]],
            'Mounts' => [['Type' => 'bind', 'Source' => $directory, 'Destination' => '/config', 'RW' => true]],
        ]];
        $network = [['Driver' => 'macvlan']];
        $runner = new DockerRunner(static function (string $command) use (&$commands, $inspect, $network): string {
            $commands[] = $command;
            if (str_contains($command, "'network' 'inspect' '--'")) return json_encode($network, JSON_THROW_ON_ERROR);
            if (str_contains($command, "'inspect' '--' 'caddy'")) return json_encode($inspect, JSON_THROW_ON_ERROR);
            if (str_contains($command, "'caddy' 'version'")) return 'v2.11.4';
            return 'ok';
        });
        try {
            $integration = new ProxyIntegration($runner, new DockerDns\ProxyAdapterFactory(), static fn(string $ip, int $status): bool => $ip === '192.168.1.9' && $status === 204);
            $result = $integration->apply([
                'proxy_adapter' => 'caddy', 'proxy_container' => 'caddy', 'proxy_network' => 'lan',
                'proxy_mount_source' => $directory, 'proxy_mount_destination' => '/config',
                'caddy_main_config' => '/etc/caddy/Caddyfile',
            ], []);
            self::assertSame('192.168.1.9', $result['ipv4']);
            self::assertFileExists($directory . '/docker-dns/docker-dns.caddy');
            self::assertStringContainsString('docker-dns-probe.invalid', (string)file_get_contents($directory . '/docker-dns/docker-dns.caddy'));
            self::assertTrue((bool)array_filter($commands, static fn(string $command): bool => str_contains($command, "'caddy' 'reload'")));
            self::assertFalse((bool)array_filter($commands, static fn(string $command): bool => preg_match("/ '(?:create|start|stop|rm)' /", $command) === 1));
        } finally {
            putenv('DOCKER_DNS_TEST_PROXY_PATH');
            foreach (glob($directory . '/docker-dns/*') ?: [] as $file) unlink($file);
            @unlink($directory . '/docker-dns/.managed-by-docker-dns.json');
            @rmdir($directory . '/docker-dns');
            rmdir($directory);
        }
    }

    /** @return array<string,mixed> */
    private function inspect(string $name, string $network, string $ip): array
    {
        return [
            'Id' => hash('sha256', $name), 'Name' => '/' . $name,
            'State' => ['Running' => true], 'Config' => ['Labels' => []],
            'HostConfig' => ['PortBindings' => ['80/tcp' => [['HostPort' => '8080']]]],
            'NetworkSettings' => ['Networks' => [$network => ['IPAddress' => $ip, 'IPAMConfig' => null]]],
        ];
    }
}
