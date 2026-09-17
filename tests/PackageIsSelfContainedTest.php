<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Whether this directory can be lifted out into a repository of its own and still work.
 *
 * It sits inside an application today and is reached through a path repository, which is the arrangement most
 * likely to hide a dependency on the application: an autoloader that already has App\ in it, a base_path() that
 * happens to resolve, a helper Laravel registered before the test ran. None of that exists in the package's own
 * repository, so anything here that leans on it is a failure that would arrive on the day of the move and not
 * before. These assertions move that day forward.
 */
class PackageIsSelfContainedTest extends TestCase
{
    /**
     * Things that only exist because an application is hosting this code.
     *
     * Laravel's helpers are the dangerous half, because they resolve in the application's suite and are a fatal
     * error anywhere else.
     */
    private const APPLICATION_ONLY = [
        '/\bApp\\\\[A-Z]/' => 'the application namespace',
        '/\bIlluminate\\\\/' => 'the framework namespace',
        '/\bTests\\\\TestCase\b/' => "the application's test case",
        '/(?<![\w$>:])(?<!function )(base|app|config|storage|resource|database|public)_path\s*\(/' => "one of Laravel's path helpers",
        '/(?<![\w$>:])(?<!function )(config|env|app|resolve|logger|report|abort|dispatch|cache)\s*\(/' => 'a Laravel global helper',
        '/\b(Log|Cache|Http|Storage|Config|DB|Crypt|Str|Arr)::/' => 'a Laravel facade',
    ];

    /**
     * The root namespace of every third-party import, and the requirement that has to carry it.
     */
    private const VENDOR_NAMESPACES = [
        'Brick' => 'brick/math',
        'CardanoPhp' => 'cardano-php/bech32',
        'PHPUnit' => 'phpunit/phpunit',
    ];

    /**
     * Function prefixes that are an extension rather than the language.
     *
     * ext-json is left out on purpose. It has been compiled in and impossible to disable since PHP 8.0, so
     * requiring it says nothing and its absence is not a gap.
     */
    private const EXTENSION_PREFIXES = [
        'bc' => 'ext-bcmath',
        'sodium_' => 'ext-sodium',
        'mb_' => 'ext-mbstring',
        'gmp_' => 'ext-gmp',
        'openssl_' => 'ext-openssl',
        'curl_' => 'ext-curl',
    ];

    private const HEADER = "// SPDX-FileCopyrightText: 2026 Adam Dean\n// SPDX-License-Identifier: Apache-2.0\n";

    private static function root(): string
    {
        return dirname(__DIR__);
    }

    /**
     * A file with its comments and its string contents taken out.
     *
     * Both of those hold the names this test is hunting for, as prose and as data: the forbidden-call list in
     * TransactionScopeTest is a list of strings naming exactly what must not be called, and this file's own
     * docblock discusses the helpers by name. Scanning raw text makes every such file a violation of itself.
     * Tokenising leaves the function names, class names and imports, which is where a real dependency would be.
     */
    private static function codeOnly(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_string($token)) {
                $code .= $token;

                continue;
            }

            $code .= match ($token[0]) {
                T_COMMENT, T_DOC_COMMENT => ' ',
                T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML => "''",
                default => $token[1],
            };
        }

        return $code;
    }

    /**
     * @return array<string, string> relative path => contents
     */
    private static function phpFiles(string ...$directories): array
    {
        $files = [];

        foreach ($directories as $directory) {
            $tree = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(self::root().'/'.$directory, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($tree as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $relative = substr($file->getPathname(), strlen(self::root()) + 1);
                    $files[$relative] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    private static function manifest(): array
    {
        $contents = (string) file_get_contents(self::root().'/composer.json');

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_no_file_in_the_package_reaches_into_the_application_hosting_it(): void
    {
        $found = [];

        foreach (self::phpFiles('src', 'tests') as $path => $source) {
            $code = self::codeOnly($source);

            foreach (self::APPLICATION_ONLY as $pattern => $what) {
                if (preg_match($pattern, $code, $match) === 1) {
                    $found[] = $path.' uses '.$what.' ('.trim($match[0]).')';
                }
            }
        }

        $this->assertSame([], $found, implode("\n", array_merge(
            ['The package leans on the application it currently sits inside:'],
            $found,
        )));
    }

    /**
     * Every class is where PSR-4 says it is, so the autoload root in composer.json is the whole story and nothing
     * is being found by an application classmap that happens to have scanned the directory.
     */
    public function test_every_class_sits_where_the_autoload_root_says_it_does(): void
    {
        $wrong = [];

        foreach (self::phpFiles('src') as $path => $source) {
            preg_match('/^namespace\s+([^;]+);/m', $source, $namespace);
            preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|enum|trait)\s+(\w+)/m', $source, $name);

            $this->assertNotEmpty($namespace, $path.' declares no namespace.');
            $this->assertNotEmpty($name, $path.' declares no class, interface, enum or trait.');

            $expected = 'src/'.str_replace('\\', '/', substr($namespace[1].'\\'.$name[1], strlen('Cardano\\Transaction\\'))).'.php';

            if ($expected !== $path) {
                $wrong[] = $path.' declares '.$namespace[1].'\\'.$name[1].', which PSR-4 puts at '.$expected;
            }
        }

        $this->assertSame([], $wrong, implode("\n", array_merge(
            ['A class is not at the path its namespace requires:'],
            $wrong,
        )));
    }

    /**
     * An import the package does not require is a package that happens to be installed because something else in
     * the application asked for it. It disappears the moment this is installed on its own.
     */
    public function test_every_third_party_import_is_a_declared_requirement(): void
    {
        $manifest = self::manifest();
        $required = array_merge($manifest['require'], $manifest['require-dev']);
        $undeclared = [];
        $seen = [];

        foreach (self::phpFiles('src', 'tests') as $path => $source) {
            preg_match_all('/^use\s+(?:function\s+)?([^\s;]+)/m', $source, $imports);

            foreach ($imports[1] as $import) {
                $root = strtok($import, '\\');

                if ($root === 'Cardano') {
                    continue;
                }

                if (! str_contains($import, '\\')) {
                    // A global-namespace import is the language's own, and it either exists or it does not.
                    $this->assertTrue(
                        class_exists($import) || interface_exists($import),
                        $path.' imports '.$import.', which PHP does not define.'
                    );

                    continue;
                }

                if (! isset(self::VENDOR_NAMESPACES[$root])) {
                    $undeclared[] = $path.' imports '.$import.', whose root namespace maps to no requirement';

                    continue;
                }

                $package = self::VENDOR_NAMESPACES[$root];
                $seen[$package] = true;

                if (! isset($required[$package])) {
                    $undeclared[] = $path.' imports '.$import.', which needs '.$package;
                }
            }
        }

        $this->assertSame([], $undeclared, implode("\n", array_merge(
            ['The package imports something it does not require:'],
            $undeclared,
        )));

        // And the other direction, so the requirement list does not accumulate packages nothing uses.
        $unused = array_diff(array_values(self::VENDOR_NAMESPACES), array_keys($seen));

        $this->assertSame(
            [],
            array_values(array_intersect($unused, array_keys($required))),
            'The package requires something no file in it imports.'
        );
    }

    public function test_the_extensions_the_code_calls_are_the_extensions_it_requires(): void
    {
        $required = array_keys(array_filter(
            self::manifest()['require'],
            static fn (string $package): bool => str_starts_with($package, 'ext-'),
            ARRAY_FILTER_USE_KEY,
        ));

        $used = [];

        foreach (self::phpFiles('src') as $source) {
            $code = self::codeOnly($source);

            foreach (self::EXTENSION_PREFIXES as $prefix => $extension) {
                if (preg_match('/(?<![\w$>:])(?<!function )'.preg_quote($prefix, '/').'[a-z_0-9]*\s*\(/', $code) === 1) {
                    $used[$extension] = true;
                }
            }
        }

        $used = array_keys($used);
        sort($used);
        sort($required);

        $this->assertSame($required, $used, 'The declared extensions and the ones the source calls have drifted apart.');
    }

    public function test_every_source_and_test_file_carries_the_licence_header(): void
    {
        $missing = [];

        foreach (self::phpFiles('src', 'tests') as $path => $source) {
            if (! str_starts_with($source, "<?php\n\n".self::HEADER)) {
                $missing[] = $path;
            }
        }

        $this->assertSame([], $missing, implode("\n", array_merge(
            ['A file does not open with the SPDX header:', '', self::HEADER, 'Files:'],
            $missing,
        )));
    }

    public function test_the_package_is_apache_licensed_and_says_so_in_every_place_that_matters(): void
    {
        $this->assertSame('Apache-2.0', self::manifest()['license']);
        $this->assertSame('cardano-php/transaction', self::manifest()['name']);

        $this->assertFileExists(self::root().'/LICENSE');
        $this->assertFileExists(self::root().'/NOTICE');

        $this->assertStringContainsString(
            'Apache License',
            (string) file_get_contents(self::root().'/LICENSE')
        );
        $this->assertStringContainsString(
            'cardano-php/transaction',
            (string) file_get_contents(self::root().'/NOTICE')
        );
    }

    /**
     * The workflow is the package's, not the application's, and it has to name the three versions the ecosystem's
     * other package already tests on. A package that only proves itself on the version the application happens to
     * deploy is a package nobody else can safely install.
     */
    public function test_the_package_tests_on_every_supported_php_version(): void
    {
        $workflow = (string) file_get_contents(self::root().'/.github/workflows/phpunit-tests.yml');

        foreach (['8.2', '8.3', '8.4'] as $version) {
            $this->assertStringContainsString("'".$version."'", $workflow, 'The workflow does not test on PHP '.$version);
        }

        $this->assertStringContainsString('composer install', $workflow);
        $this->assertStringContainsString('phpunit', $workflow);
    }
}
