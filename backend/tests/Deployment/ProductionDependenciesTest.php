<?php

declare(strict_types=1);

namespace App\Tests\Deployment;

use PHPUnit\Framework\TestCase;

/**
 * The production image installs with `composer install --no-dev`. A class
 * imported by `src/` that only reaches `vendor/` as a transitive dependency
 * of a dev package works everywhere except in production: that is how
 * `symfony/process` (used by OrToolsPlanningSolver) made every production
 * generation fail with `Class "Symfony\Component\Process\Process" not found`
 * while the whole suite stayed green. This pins that every vendor namespace
 * imported by `src/` belongs to a non-dev package of composer.lock.
 */
final class ProductionDependenciesTest extends TestCase
{
    public function testEveryVendorClassImportedBySrcIsAProductionDependency(): void
    {
        $backend = \dirname(__DIR__, 2);
        $lock = json_decode((string) file_get_contents($backend.'/composer.lock'), true, flags: \JSON_THROW_ON_ERROR);

        $prefixes = [];
        foreach (['packages' => false, 'packages-dev' => true] as $section => $dev) {
            foreach ($lock[$section] as $package) {
                foreach (array_keys($package['autoload']['psr-4'] ?? []) as $prefix) {
                    if ('' !== $prefix) {
                        $prefixes[$prefix] = ['name' => $package['name'], 'dev' => $dev];
                    }
                }
            }
        }
        // Longest prefix wins (e.g. Symfony\Component\Process\ over Symfony\).
        uksort($prefixes, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        $devOnly = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($backend.'/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }
            preg_match_all('/^use\s+(?:function\s+|const\s+)?([A-Za-z_\\\\][\w\\\\]*)/m', (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $class) {
                $class = ltrim($class, '\\');
                foreach ($prefixes as $prefix => $package) {
                    if (str_starts_with($class.'\\', $prefix)) {
                        if ($package['dev']) {
                            $devOnly[] = \sprintf('%s (%s) in %s', $class, $package['name'], $file->getFilename());
                        }
                        break;
                    }
                }
            }
        }

        self::assertSame([], $devOnly, 'Imported by src/ but only installed with dev dependencies: add them to "require".');
    }
}
