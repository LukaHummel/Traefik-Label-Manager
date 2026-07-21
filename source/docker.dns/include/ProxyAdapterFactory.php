<?php

declare(strict_types=1);

namespace DockerDns;

use InvalidArgumentException;

final class ProxyAdapterFactory
{
    public function create(string $adapter): ProxyAdapter
    {
        return match ($adapter) {
            'caddy' => new CaddyProxyAdapter(),
            'traefik' => new TraefikProxyAdapter(),
            default => throw new InvalidArgumentException('Proxy adapter must be Caddy or Traefik.'),
        };
    }
}
