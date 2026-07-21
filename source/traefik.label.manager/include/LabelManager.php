<?php

declare(strict_types=1);

namespace TraefikLabelManager;

use Closure;
use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;
use RuntimeException;

final class LabelManager
{
    public const ROUTER_MARKER = 'io.github.lukahummel.traefik-label-manager.router';
    public const OWNS_ENABLE = 'io.github.lukahummel.traefik-label-manager.owns-enable';

    private Closure $runner;

    /** @param null|callable(list<string>):string $runner */
    public function __construct(
        private readonly string $templateDirectory,
        private readonly string $lockFile,
        ?callable $runner = null,
    ) {
        $this->runner = $runner !== null ? Closure::fromCallable($runner) : static function (array $arguments): string {
            $command = 'docker ' . implode(' ', array_map('escapeshellarg', $arguments));
            $output = [];
            $status = 0;
            exec($command . ' 2>&1', $output, $status);
            if ($status !== 0) {
                throw new RuntimeException(trim(implode("\n", $output)) ?: 'Docker command failed.');
            }
            return implode("\n", $output);
        };
    }

    /** @return list<array<string,mixed>> */
    public function containers(): array
    {
        $templates = $this->templatesByName();
        $output = trim(($this->runner)(['ps', '-a', '--format', '{{.Names}}']));
        $names = $output === '' ? [] : preg_split('/\R+/', $output);
        $result = [];
        foreach ($names ?: [] as $name) {
            if ($name === '') continue;
            try {
                $decoded = json_decode(($this->runner)(['inspect', '--', $name]), true, flags: JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                continue;
            }
            $inspect = is_array($decoded[0] ?? null) ? $decoded[0] : [];
            $isTraefik = $this->isTraefikContainer($name, $inspect);
            $active = $this->filterLabels((array)($inspect['Config']['Labels'] ?? []));
            $templatePath = $templates[$name] ?? null;
            $template = $templatePath !== null ? $this->readTemplateLabels($templatePath) : [];
            $keys = array_values(array_unique(array_merge(array_keys($template), array_keys($active))));
            sort($keys, SORT_STRING);
            $labels = [];
            foreach ($keys as $key) {
                $hasTemplate = array_key_exists($key, $template);
                $hasActive = array_key_exists($key, $active);
                $labels[] = [
                    'key' => $key,
                    'template_value' => $hasTemplate ? $template[$key] : null,
                    'active_value' => $hasActive ? $active[$key] : null,
                    'pending' => $hasTemplate !== $hasActive || ($hasTemplate && $template[$key] !== $active[$key]),
                ];
            }
            $result[] = [
                'name' => $name,
                'running' => (bool)($inspect['State']['Running'] ?? false),
                'status' => (string)($inspect['State']['Status'] ?? 'unknown'),
                'is_traefik' => $isTraefik,
                'template_found' => $templatePath !== null,
                'pending' => $templatePath !== null && $template !== $active,
                'labels' => $labels,
            ];
        }
        usort($result, static function (array $left, array $right): int {
            $traefikOrder = (int)$right['is_traefik'] <=> (int)$left['is_traefik'];
            return $traefikOrder !== 0 ? $traefikOrder : strcasecmp((string)$left['name'], (string)$right['name']);
        });
        return $result;
    }

    /** @param array<string,mixed> $inspect */
    private function isTraefikContainer(string $name, array $inspect): bool
    {
        $image = strtolower((string)($inspect['Config']['Image'] ?? ''));
        $image = explode('@', $image, 2)[0];
        $slash = strrpos($image, '/');
        $basename = $slash === false ? $image : substr($image, $slash + 1);
        $basename = explode(':', $basename, 2)[0];
        if (str_contains($basename, 'traefik')) return true;
        return stripos($name, 'traefik') !== false;
    }

    /** @param list<array{key:mixed,value:mixed}> $labels @return array<string,mixed> */
    public function save(string $container, array $labels): array
    {
        $container = $this->validateContainerName($container);
        $validated = $this->validateLabels($labels);
        $templates = $this->templatesByName();
        $path = $templates[$container] ?? null;
        if ($path === null) throw new InvalidArgumentException("No Unraid template was found for $container.");

        $lock = fopen($this->lockFile, 'c');
        if ($lock === false) throw new RuntimeException('Cannot open the label manager lock.');
        try {
            if (!flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock the label manager.');
            $document = $this->loadDocument($path);
            $xpath = new DOMXPath($document);
            $remove = [];
            foreach ($xpath->query('//Config[@Type="Label"]') ?: [] as $node) {
                if ($node instanceof DOMElement && $this->isAllowedKey($node->getAttribute('Target'))) $remove[] = $node;
            }
            foreach ($remove as $node) $node->parentNode?->removeChild($node);
            $root = $document->documentElement;
            if ($root === null) throw new RuntimeException('The container template has no root element.');
            foreach ($validated as $key => $value) {
                $config = $document->createElement('Config');
                $config->setAttribute('Name', 'Traefik Label Manager: ' . $key);
                $config->setAttribute('Target', $key);
                $config->setAttribute('Default', '');
                $config->setAttribute('Mode', '');
                $config->setAttribute('Description', 'Managed by Traefik Label Manager.');
                $config->setAttribute('Type', 'Label');
                $config->setAttribute('Display', 'advanced-hide');
                $config->setAttribute('Required', 'false');
                $config->setAttribute('Mask', 'false');
                $config->appendChild($document->createTextNode($value));
                $root->appendChild($config);
            }
            $this->writeDocument($path, $document);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return ['ok' => true, 'container' => $container, 'labels' => $validated];
    }

    /** @return array<string,string> */
    public function readTemplateLabels(string $path): array
    {
        $document = $this->loadDocument($path);
        $xpath = new DOMXPath($document);
        $labels = [];
        foreach ($xpath->query('//Config[@Type="Label"]') ?: [] as $node) {
            if (!$node instanceof DOMElement) continue;
            $key = $node->getAttribute('Target');
            if ($this->isAllowedKey($key)) $labels[$key] = $node->textContent;
        }
        ksort($labels, SORT_STRING);
        return $labels;
    }

    /** @return array<string,string> */
    private function templatesByName(): array
    {
        if (!is_dir($this->templateDirectory)) return [];
        $result = [];
        $paths = glob(rtrim($this->templateDirectory, '/') . '/my-*.xml') ?: [];
        sort($paths, SORT_STRING);
        foreach ($paths as $path) {
            if (!is_file($path) || is_link($path)) continue;
            try {
                $document = $this->loadDocument($path);
            } catch (RuntimeException) {
                continue;
            }
            $name = trim((string)$document->getElementsByTagName('Name')->item(0)?->textContent);
            if ($name !== '' && !isset($result[$name])) $result[$name] = $path;
        }
        return $result;
    }

    /** @param array<mixed,mixed> $labels @return array<string,string> */
    private function filterLabels(array $labels): array
    {
        $result = [];
        foreach ($labels as $key => $value) {
            if (is_string($key) && $this->isAllowedKey($key) && is_scalar($value)) $result[$key] = (string)$value;
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    /** @param list<array{key:mixed,value:mixed}> $labels @return array<string,string> */
    private function validateLabels(array $labels): array
    {
        if (count($labels) > 128) throw new InvalidArgumentException('A container may have at most 128 managed labels.');
        $result = [];
        foreach ($labels as $label) {
            if (!is_array($label)) throw new InvalidArgumentException('Each label must be an object.');
            $key = trim((string)($label['key'] ?? ''));
            $value = (string)($label['value'] ?? '');
            if (!$this->isAllowedKey($key)) throw new InvalidArgumentException("Label key is outside the allowed Traefik namespace: $key");
            if (isset($result[$key])) throw new InvalidArgumentException("Duplicate label key: $key");
            if (strlen($value) > 4096 || str_contains($value, "\0")) throw new InvalidArgumentException("Invalid value for label: $key");
            if ($key === self::ROUTER_MARKER && !preg_match('/^tlm-[a-z0-9-]+-[0-9a-f]{8}$/', $value)) {
                throw new InvalidArgumentException('Invalid router ownership marker.');
            }
            if ($key === self::OWNS_ENABLE && $value !== 'true') {
                throw new InvalidArgumentException('The owns-enable marker must be true.');
            }
            if ($key === 'traefik.enable' && !in_array($value, ['true', 'false'], true)) {
                throw new InvalidArgumentException('traefik.enable must be true or false.');
            }
            $result[$key] = $value;
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    private function isAllowedKey(string $key): bool
    {
        if (in_array($key, [self::ROUTER_MARKER, self::OWNS_ENABLE], true)) return true;
        return preg_match('/^traefik\.[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?$/', $key) === 1 && strlen($key) <= 255;
    }

    private function validateContainerName(string $name): string
    {
        $name = trim($name);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $name)) throw new InvalidArgumentException('Invalid container name.');
        return $name;
    }

    private function loadDocument(string $path): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = false;
        $document->formatOutput = true;
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->load($path, LIBXML_NONET)) throw new RuntimeException('Invalid Unraid container template: ' . basename($path));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return $document;
    }

    private function writeDocument(string $path, DOMDocument $document): void
    {
        $directory = dirname($path);
        $temporary = tempnam($directory, '.traefik-label-manager-');
        if ($temporary === false) throw new RuntimeException('Cannot create a temporary template file.');
        try {
            $contents = $document->saveXML();
            if ($contents === false || file_put_contents($temporary, $contents, LOCK_EX) === false) {
                throw new RuntimeException('Cannot write the container template.');
            }
            $mode = fileperms($path);
            chmod($temporary, $mode === false ? 0644 : ($mode & 0777));
            if (!rename($temporary, $path)) throw new RuntimeException('Cannot replace the container template.');
        } finally {
            if (is_file($temporary)) @unlink($temporary);
        }
    }
}
