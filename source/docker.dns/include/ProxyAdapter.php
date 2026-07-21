<?php

declare(strict_types=1);

namespace DockerDns;

interface ProxyAdapter
{
    /** @param list<array<string,mixed>> $routes */
    public function render(array $routes): string;

    public function filename(): string;

    public function validateAndReload(DockerRunner $docker, array $settings, string $containerPath): void;

    public function expectedProbeStatus(): int;
}
