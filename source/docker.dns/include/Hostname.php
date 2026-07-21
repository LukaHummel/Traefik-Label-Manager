<?php

declare(strict_types=1);

namespace DockerDns;

final class Hostname
{
    public static function isValidHost(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false
            || (bool)preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $host);
    }
    public static function label(string $containerName): string
    {
        $label = strtolower(trim($containerName));
        $label = preg_replace('/[^a-z0-9-]+/', '-', $label) ?? '';
        $label = trim(preg_replace('/-+/', '-', $label) ?? '', '-');
        if ($label === '') {
            $label = 'container-' . substr(hash('sha256', $containerName), 0, 8);
        }
        return rtrim(substr($label, 0, 63), '-');
    }

    /**
     * @param list<string> $containerNames
     * @return array<string,string> original name => fqdn
     */
    public static function allocate(array $containerNames): array
    {
        sort($containerNames, SORT_STRING);
        $groups = [];
        foreach ($containerNames as $name) {
            $groups[self::label($name)][] = $name;
        }
        $result = [];
        foreach ($groups as $base => $names) {
            foreach ($names as $name) {
                $label = $base;
                if (count($names) > 1) {
                    $suffix = '-' . substr(hash('sha256', $name), 0, 8);
                    $label = rtrim(substr($base, 0, 63 - strlen($suffix)), '-') . $suffix;
                }
                $result[$name] = $label . '.home.arpa';
            }
        }
        return $result;
    }
}
