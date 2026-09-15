<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;

/**
 * What the package promises, and whether the promise can actually be kept.
 *
 * A class carries `@internal` when it is the package's own machinery and nothing outside is expected to hold one.
 * Everything else is the public surface, and the surface is only worth anything if a caller can use all of it
 * without ever being handed something marked internal. A public method that returns an internal type has made that
 * type public whatever its docblock says, and the next release that changes it breaks a caller who was following
 * the rules.
 *
 * So this walks the public signatures and fails when one of them names an internal class. The other half of the
 * boundary, that the application only reaches for what is on this surface, is asserted from the application's own
 * suite, where the application's tree is something a test can see.
 */
class PublicSurfaceTest extends TestCase
{
    private const NAMESPACE_PREFIX = 'Cardano\\Transaction\\';

    /**
     * Every class, interface and enum the package ships, in declaration order by path.
     *
     * @return list<string>
     */
    public static function classes(): array
    {
        $root = dirname(__DIR__).'/src';
        $names = [];

        $tree = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($tree as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            $names[] = self::NAMESPACE_PREFIX.str_replace(['/', '.php'], ['\\', ''], $relative);
        }

        sort($names);

        return $names;
    }

    /**
     * @return list<array{string}>
     */
    public static function publicClasses(): array
    {
        return array_map(
            static fn (string $class): array => [$class],
            array_values(array_filter(self::classes(), static fn (string $c): bool => ! self::isInternal($c)))
        );
    }

    private static function isInternal(string $class): bool
    {
        $doc = (new ReflectionClass($class))->getDocComment();

        return is_string($doc) && str_contains($doc, '@internal');
    }

    /**
     * Every class named by a type in a signature, restricted to this package.
     *
     * @return list<string>
     */
    private static function typesIn(?ReflectionType $type): array
    {
        if ($type === null) {
            return [];
        }

        $parts = $type instanceof ReflectionNamedType ? [$type] : $type->getTypes();
        $names = [];

        foreach ($parts as $part) {
            if ($part instanceof ReflectionNamedType && ! $part->isBuiltin()) {
                $names[] = $part->getName();
            }
        }

        return $names;
    }

    /**
     * The package ships something, and it is all reachable by name.
     */
    public function test_the_package_ships_a_surface_and_some_machinery_behind_it(): void
    {
        $classes = self::classes();

        foreach ($classes as $class) {
            $this->assertTrue(
                class_exists($class) || interface_exists($class) || enum_exists($class),
                $class.' cannot be autoloaded from the package root.'
            );
        }

        $internal = array_values(array_filter($classes, self::isInternal(...)));

        $this->assertNotSame([], $internal, 'Nothing is marked @internal, so the surface is the whole package.');
        $this->assertLessThan(
            count($classes),
            count($internal),
            'Everything is marked @internal, so the package has no public surface at all.'
        );
    }

    /**
     * The closure property. A caller who only ever names public classes must never be handed an internal one.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('publicClasses')]
    public function test_no_public_signature_hands_out_something_marked_internal(string $class): void
    {
        $reflection = new ReflectionClass($class);
        $leaks = [];

        $record = function (array $types, string $where) use (&$leaks): void {
            foreach ($types as $type) {
                if (str_starts_with($type, self::NAMESPACE_PREFIX) && self::isInternal($type)) {
                    $leaks[] = $where.' '.$type;
                }
            }
        };

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            $record(self::typesIn($method->getReturnType()), $class.'::'.$method->getName().'() returns');

            foreach ($method->getParameters() as $parameter) {
                if ($method->isConstructor() && $parameter->isPromoted()) {
                    $promoted = $reflection->getProperty($parameter->getName());

                    if (! $promoted->isPublic()) {
                        continue;
                    }
                }

                $record(
                    self::typesIn($parameter->getType()),
                    $class.'::'.$method->getName().'($'.$parameter->getName().') takes'
                );
            }
        }

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            $record(self::typesIn($property->getType()), $class.'::$'.$property->getName().' is');
        }

        foreach ($reflection->getInterfaceNames() as $interface) {
            $record([$interface], $class.' implements');
        }

        if ($parent = $reflection->getParentClass()) {
            $record([$parent->getName()], $class.' extends');
        }

        $this->assertSame([], $leaks, implode("\n", array_merge(
            ['A public class hands out something the package calls internal:'],
            $leaks,
            ['', 'Either the type belongs on the public surface, or the signature does not belong in public.'],
        )));
    }

    /**
     * An internal class that nothing internal uses is not machinery, it is a class somebody forgot to delete or
     * mislabelled. Either way the label is wrong, and a wrong label is worse than none because the boundary test
     * in the application's suite is reading it.
     */
    public function test_every_internal_class_is_used_by_the_package_itself(): void
    {
        $sources = [];

        $tree = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__).'/src', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($tree as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }

        $unused = [];

        foreach (self::classes() as $class) {
            if (! self::isInternal($class)) {
                continue;
            }

            $short = substr($class, strrpos($class, '\\') + 1);
            $namespace = substr($class, 0, strrpos($class, '\\'));
            $callers = 0;

            foreach ($sources as $path => $source) {
                if (str_contains($path, '/'.$short.'.php')) {
                    continue;
                }

                // Named outright, imported, or reachable as a bare short name from the same namespace.
                $sameNamespace = preg_match('/^namespace\s+'.preg_quote($namespace, '/').';/m', $source) === 1;

                if (str_contains($source, $class)) {
                    $callers++;

                    continue;
                }

                if ($sameNamespace && preg_match('/\b'.preg_quote($short, '/').'\b/', $source) === 1) {
                    $callers++;
                }
            }

            if ($callers === 0) {
                $unused[] = $class;
            }
        }

        $this->assertSame([], $unused, implode("\n", array_merge(
            ['A class is marked @internal and nothing in the package uses it:'],
            $unused,
        )));
    }
}
