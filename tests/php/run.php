<?php

declare(strict_types=1);

require_once __DIR__ . '/../../source/traefik.label.manager/include/bootstrap.php';

use TraefikLabelManager\LabelManager;

function assert_true(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

$directory = sys_get_temp_dir() . '/traefik-label-manager-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700)) throw new RuntimeException('Cannot create test directory.');
$template = $directory . '/my-plex.xml';
$lock = $directory . '/manager.lock';
$xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Container version="2">
  <Name>plex</Name>
  <Repository>example/plex</Repository>
  <WebUI>http://[IP]:[PORT:32400]/</WebUI>
  <Config Name="HTTP" Target="80" Default="" Mode="tcp" Description="" Type="Port" Display="always" Required="false" Mask="false">8080</Config>
  <Config Name="WebUI" Target="32400" Default="" Mode="tcp" Description="" Type="Port" Display="always" Required="false" Mask="false">32400</Config>
  <Config Name="Discovery" Target="1900" Default="" Mode="udp" Description="" Type="Port" Display="always" Required="false" Mask="false">1900</Config>
  <Config Name="Manual" Target="manual.label" Default="" Mode="" Description="" Type="Label" Display="always" Required="false" Mask="false">keep</Config>
  <Config Name="Rule" Target="traefik.http.routers.plex.rule" Default="" Mode="" Description="" Type="Label" Display="always" Required="false" Mask="false">Host(`template.home.arpa`)</Config>
  <Config Name="Environment" Target="TZ" Default="" Mode="" Description="" Type="Variable" Display="always" Required="false" Mask="false">Europe/Berlin</Config>
</Container>
XML;
file_put_contents($template, $xml);

$runner = static function (array $arguments): string {
    if ($arguments === ['ps', '-a', '--format', '{{.Names}}']) return "database\nplex\nedge\ntraefik-lab";
    if ($arguments === ['inspect', '--', 'plex']) {
        return json_encode([[
            'State' => ['Running' => true, 'Status' => 'running'],
            'Config' => ['Image' => 'example/plex', 'Labels' => [
                'manual.label' => 'keep',
                'traefik.enable' => 'true',
                'traefik.http.routers.plex.rule' => 'Host(`active.home.arpa`)',
            ]],
        ]], JSON_THROW_ON_ERROR);
    }
    if ($arguments === ['inspect', '--', 'database']) {
        return json_encode([[
            'State' => ['Running' => false, 'Status' => 'exited'],
            'Config' => ['Image' => 'postgres:17', 'Labels' => ['traefik.enable' => 'false']],
        ]], JSON_THROW_ON_ERROR);
    }
    if ($arguments === ['inspect', '--', 'edge']) {
        return json_encode([[
            'State' => ['Running' => true, 'Status' => 'running'],
            'Config' => ['Image' => 'docker.io/library/traefik:v3.4', 'Labels' => []],
        ]], JSON_THROW_ON_ERROR);
    }
    if ($arguments === ['inspect', '--', 'traefik-lab']) {
        return json_encode([[
            'State' => ['Running' => false, 'Status' => 'exited'],
            'Config' => ['Image' => 'example/custom-proxy:latest', 'Labels' => []],
        ]], JSON_THROW_ON_ERROR);
    }
    throw new RuntimeException('Unexpected Docker arguments: ' . implode(' ', $arguments));
};

$manager = new LabelManager($directory, $lock, $runner);
$containers = $manager->containers();
assert_same(['edge', 'traefik-lab', 'database', 'plex'], array_column($containers, 'name'), 'Traefik containers must be pinned before other alphabetically sorted containers.');
assert_same([true, true, false, false], array_column($containers, 'is_traefik'), 'Traefik detection must support image and container names.');
assert_same(false, $containers[0]['template_found'], 'A container without an Unraid template must be read-only.');
assert_same(true, $containers[3]['pending'], 'Template and active label differences must be pending.');
assert_same(2, count($containers[3]['labels']), 'Only Traefik labels must be returned.');
assert_same([
    ['public_port' => 8080, 'private_port' => 80],
    ['public_port' => 32400, 'private_port' => 32400],
], $containers[3]['published_ports'], 'Published TCP ports must be exposed to the Settings page in host-port order.');
assert_same(32400, $containers[3]['default_backend_port'], 'The WebUI private port must be the default backend port.');

$router = 'tlm-plex-1234abcd';
$manager->save('plex', [
    ['key' => 'traefik.enable', 'value' => 'true'],
    ['key' => 'traefik.http.routers.plex.rule', 'value' => 'Host(`plex.home.arpa`)'],
    ['key' => LabelManager::ROUTER_MARKER, 'value' => $router],
    ['key' => LabelManager::OWNS_ENABLE, 'value' => 'true'],
]);
$saved = file_get_contents($template);
assert_true($saved !== false && str_contains($saved, 'Target="manual.label"'), 'Unrelated labels must be retained.');
assert_true(str_contains($saved, 'Target="TZ"'), 'Unrelated template entries must be retained.');
assert_true(str_contains($saved, 'Host(`plex.home.arpa`)'), 'Edited Traefik values must be saved.');
$templateLabels = $manager->readTemplateLabels($template);
assert_same($router, $templateLabels[LabelManager::ROUTER_MARKER], 'Ownership labels must be saved.');
assert_same('true', $templateLabels['traefik.enable'], 'Traefik labels must be saved.');

$invalidRejected = false;
try {
    $manager->save('plex', [['key' => 'manual.label', 'value' => 'changed']]);
} catch (InvalidArgumentException) {
    $invalidRejected = true;
}
assert_true($invalidRejected, 'Labels outside the allowed namespaces must be rejected.');

$duplicateRejected = false;
try {
    $manager->save('plex', [
        ['key' => 'traefik.enable', 'value' => 'true'],
        ['key' => 'traefik.enable', 'value' => 'false'],
    ]);
} catch (InvalidArgumentException) {
    $duplicateRejected = true;
}
assert_true($duplicateRejected, 'Duplicate labels must be rejected.');

$missingRejected = false;
try {
    $manager->save('database', []);
} catch (InvalidArgumentException) {
    $missingRejected = true;
}
assert_true($missingRejected, 'Containers without templates must not be writable.');

@unlink($lock);
@unlink($template);
@rmdir($directory);
echo "PHP label manager tests passed.\n";
