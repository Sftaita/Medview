<?php

declare(strict_types=1);

namespace App\Service\Admin;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The deployed version, as baked into the production image at build time
 * (`APP_VERSION` build argument of backend/Dockerfile.prod → the RELEASE
 * file, docs/deployment.md). Deployments ship a `git archive`, with no
 * .git directory to ask, so this file is the only source; absent (dev,
 * an image built without the argument), the version is reported as
 * unknown rather than guessed.
 */
final class AppVersion
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/RELEASE')]
        private readonly string $releaseFile,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    public function version(): ?string
    {
        if (!is_file($this->releaseFile) || !is_readable($this->releaseFile)) {
            return null;
        }

        $value = trim((string) file_get_contents($this->releaseFile, length: 200));

        // A tag or a commit, nothing else: never echo arbitrary file content.
        return 1 === preg_match('/^[A-Za-z0-9._\-+]{1,64}$/', $value) && 'unknown' !== $value ? $value : null;
    }

    public function environment(): string
    {
        return $this->environment;
    }
}
