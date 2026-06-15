<?php

declare(strict_types=1);

namespace PostHog\Scripts;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use SplFileInfo;

final class PublicApiSnapshot
{
    public static function run(array $argv): int
    {
        $root = dirname(__DIR__);
        $snapshotFile = $root . '/api/public-api.json';
        $update = in_array('--update', $argv, true);

        require_once $root . '/vendor/autoload.php';

        $api = self::buildPublicApi($root . '/lib', 'PostHog\\');
        $json = json_encode($api, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        if ($update) {
            if (!is_dir(dirname($snapshotFile))) {
                mkdir(dirname($snapshotFile), 0777, true);
            }

            file_put_contents($snapshotFile, $json);
            echo 'Updated public API snapshot: ' . self::relativePath($snapshotFile, $root) . PHP_EOL;
            return 0;
        }

        if (!file_exists($snapshotFile)) {
            fwrite(STDERR, 'Missing public API snapshot: ' . self::relativePath($snapshotFile, $root) . PHP_EOL);
            fwrite(STDERR, "Run `composer api:update` to create it.\n");
            return 1;
        }

        $expected = file_get_contents($snapshotFile);
        if ($expected === $json) {
            echo "Public API snapshot is up to date.\n";
            return 0;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'posthog-public-api-');
        file_put_contents($tmpFile, $json);

        fwrite(STDERR, "Public API snapshot is out of date.\n");
        fwrite(STDERR, "Run `composer api:update` if this public API change is intentional.\n\n");
        fwrite(STDERR, self::unifiedDiff($snapshotFile, $tmpFile, $root));
        @unlink($tmpFile);
        return 1;
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildPublicApi(string $sourceDir, string $namespacePrefix): array
    {
        foreach (self::phpFiles($sourceDir) as $file) {
            require_once $file;
        }

        $symbols = [];
        foreach (array_merge(get_declared_interfaces(), get_declared_traits(), get_declared_classes()) as $name) {
            if (!str_starts_with($name, $namespacePrefix)) {
                continue;
            }

            $reflection = new ReflectionClass($name);
            $fileName = $reflection->getFileName();
            if ($fileName === false || !self::pathIsInside($fileName, $sourceDir)) {
                continue;
            }

            $symbols[$name] = self::reflectClass($reflection);
        }

        ksort($symbols);

        return [
            'schemaVersion' => 1,
            'namespace' => $namespacePrefix,
            'symbols' => $symbols,
        ];
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $directory): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        $files = [];
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    private static function reflectClass(ReflectionClass $class): array
    {
        $constants = [];
        foreach ($class->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
            if ($constant->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            $constants[$constant->getName()] = [
                'type' => get_debug_type($constant->getValue()),
                'value' => self::normalizeConstantValue($constant),
            ];
        }
        ksort($constants);

        $properties = [];
        foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            $properties[$property->getName()] = [
                'static' => $property->isStatic(),
                'readonly' => method_exists($property, 'isReadOnly') && $property->isReadOnly(),
                'type' => self::reflectionType($property->getType(), $class),
                'default' => $property->hasDefaultValue() ? self::normalizeValue($property->getDefaultValue()) : null,
                'hasDefault' => $property->hasDefaultValue(),
            ];
        }
        ksort($properties);

        $methods = [];
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            $methods[$method->getName()] = self::reflectMethod($method);
        }
        ksort($methods);

        return [
            'type' => self::classKind($class),
            'abstract' => $class->isAbstract(),
            'final' => $class->isFinal(),
            'readonly' => method_exists($class, 'isReadOnly') && $class->isReadOnly(),
            'extends' => $class->getParentClass() ? $class->getParentClass()->getName() : null,
            'implements' => self::interfaceNames($class),
            'constants' => $constants,
            'properties' => $properties,
            'methods' => $methods,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function reflectMethod(ReflectionMethod $method): array
    {
        return [
            'static' => $method->isStatic(),
            'abstract' => $method->isAbstract(),
            'final' => $method->isFinal(),
            'returnType' => self::reflectionType($method->getReturnType(), $method->getDeclaringClass()),
            'parameters' => array_map(
                static fn (ReflectionParameter $parameter): array => self::reflectParameter(
                    $parameter,
                    $method->getDeclaringClass()
                ),
                $method->getParameters()
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function reflectParameter(ReflectionParameter $parameter, ReflectionClass $scope): array
    {
        $hasDefault = $parameter->isDefaultValueAvailable();
        $usesConstantDefault = $hasDefault && $parameter->isDefaultValueConstant();

        return [
            'name' => $parameter->getName(),
            'type' => self::reflectionType($parameter->getType(), $scope),
            'byReference' => $parameter->isPassedByReference(),
            'variadic' => $parameter->isVariadic(),
            'optional' => $parameter->isOptional(),
            'default' => $hasDefault ? self::normalizeValue($parameter->getDefaultValue()) : null,
            'defaultConstant' => $usesConstantDefault ? $parameter->getDefaultValueConstantName() : null,
            'hasDefault' => $hasDefault,
        ];
    }

    private static function classKind(ReflectionClass $class): string
    {
        if ($class->isInterface()) {
            return 'interface';
        }

        if ($class->isTrait()) {
            return 'trait';
        }

        if (method_exists($class, 'isEnum') && $class->isEnum()) {
            return 'enum';
        }

        return 'class';
    }

    /**
     * @return list<string>
     */
    private static function interfaceNames(ReflectionClass $class): array
    {
        $interfaces = array_unique($class->getInterfaceNames());
        sort($interfaces);

        return array_values($interfaces);
    }

    private static function reflectionType(?ReflectionType $type, ReflectionClass $scope): ?string
    {
        if ($type === null) {
            return null;
        }

        if ($type instanceof ReflectionNamedType) {
            $name = self::normalizeTypeName($type->getName(), $scope);
            return $type->allowsNull() && $name !== 'mixed' && $name !== 'null' ? '?' . $name : $name;
        }

        if ($type instanceof ReflectionUnionType) {
            return implode(
                '|',
                array_map(
                    static fn (ReflectionType $inner): string => self::reflectionType($inner, $scope),
                    $type->getTypes()
                )
            );
        }

        if ($type instanceof ReflectionIntersectionType) {
            return implode(
                '&',
                array_map(
                    static fn (ReflectionType $inner): string => self::reflectionType($inner, $scope),
                    $type->getTypes()
                )
            );
        }

        return (string) $type;
    }

    private static function normalizeTypeName(string $name, ReflectionClass $scope): string
    {
        if ($name === 'self') {
            return $scope->getName();
        }

        if ($name === 'parent') {
            $parent = $scope->getParentClass();
            return $parent ? $parent->getName() : $name;
        }

        return $name;
    }

    private static function normalizeConstantValue(ReflectionClassConstant $constant): mixed
    {
        if ($constant->getDeclaringClass()->getName() === 'PostHog\\PostHog' && $constant->getName() === 'VERSION') {
            return '<version>';
        }

        return self::normalizeValue($constant->getValue());
    }

    private static function normalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = self::normalizeValue($item);
            }

            return $normalized;
        }

        if (is_object($value)) {
            return 'object(' . $value::class . ')';
        }

        if (is_resource($value)) {
            return 'resource(' . get_resource_type($value) . ')';
        }

        return $value;
    }

    private static function pathIsInside(string $path, string $directory): bool
    {
        $realPath = realpath($path);
        $realDirectory = realpath($directory);

        return $realPath !== false
            && $realDirectory !== false
            && str_starts_with($realPath, rtrim($realDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }

    private static function relativePath(string $path, string $root): string
    {
        $realRoot = realpath($root);
        $realPath = realpath($path);

        if ($realRoot === false || $realPath === false) {
            return $path;
        }

        if (!str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return substr($realPath, strlen($realRoot) + 1);
    }

    private static function unifiedDiff(string $expectedFile, string $actualFile, string $root): string
    {
        $command = sprintf(
            'git diff --no-index -- %s %s',
            escapeshellarg($expectedFile),
            escapeshellarg($actualFile)
        );
        exec($command, $output, $exitCode);

        if ($exitCode > 1 || $output === []) {
            return '';
        }

        $expectedLabel = self::relativePath($expectedFile, $root);
        $actualLabel = 'generated-public-api.json';

        return implode(
            PHP_EOL,
            array_map(
                static fn (string $line): string => str_replace(
                    ['a' . $expectedFile, 'b' . $actualFile, $expectedFile, $actualFile],
                    ['a/' . $expectedLabel, 'b/' . $actualLabel, $expectedLabel, $actualLabel],
                    $line
                ),
                $output
            )
        ) . PHP_EOL;
    }
}
