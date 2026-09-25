<?php

declare(strict_types=1);

/**
 * `docs/AGENTS.md` rule 6 / `BLUEPRINT.md` §19.1: no float for money, anywhere. This module has no
 * single `Money` class to point a reflection test at (see `docs/handoff/025-rental-module.md`
 * "Key decisions" for why — every amount is a plain `int` of minor units), so this test instead
 * walks every class file in this package's `Rental` and asserts none of them declares a `float`
 * type on any property, parameter, or return type. A raw source-text search for the word "float"
 * would false-positive on this very docblock and others like it; reflecting over actual type
 * declarations does not.
 */
it('never declares a float type on any property, parameter, or return type in Rental', function (): void {
    $root = dirname(__DIR__, 3) . '/src/Rental';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    $floatOffenders = [];

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        expect($contents)->not->toBeFalse();
        assert(is_string($contents));

        if (! preg_match('/^(?:final\s+|readonly\s+|abstract\s+)*(?:class|interface|enum)\s+(\w+)/m', $contents, $matches)) {
            continue;
        }

        $className = 'Kern\\TaxRomania\\Rental\\' . classNamespaceSuffix($file->getPathname(), $root) . $matches[1];

        if (! class_exists($className) && ! interface_exists($className) && ! enum_exists($className)) {
            continue;
        }

        $reflection = new ReflectionClass($className);

        foreach ($reflection->getProperties() as $property) {
            if (declaresFloat($property->getType())) {
                $floatOffenders[] = "{$className}::\${$property->getName()}";
            }
        }

        foreach ($reflection->getMethods() as $method) {
            if (declaresFloat($method->getReturnType())) {
                $floatOffenders[] = "{$className}::{$method->getName()}() return type";
            }

            foreach ($method->getParameters() as $parameter) {
                if (declaresFloat($parameter->getType())) {
                    $floatOffenders[] = "{$className}::{$method->getName()}(\${$parameter->getName()})";
                }
            }
        }
    }

    expect($floatOffenders)->toBe([]);
});

function declaresFloat(?ReflectionType $type): bool
{
    if ($type instanceof ReflectionNamedType) {
        return strtolower($type->getName()) === 'float';
    }

    if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
        foreach ($type->getTypes() as $inner) {
            if (declaresFloat($inner)) {
                return true;
            }
        }
    }

    return false;
}

function classNamespaceSuffix(string $path, string $root): string
{
    $relative = ltrim(str_replace($root, '', dirname($path)), '/');

    if ($relative === '') {
        return '';
    }

    return str_replace('/', '\\', $relative) . '\\';
}
