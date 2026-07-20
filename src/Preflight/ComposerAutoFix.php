<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Preflight;

use Composer\InstalledVersions;

/**
 * Produces a destination-portable copy of the project composer.json: dev constraints
 * (`@dev` / `dev-*`) in require/require-dev are pinned to the exact version Composer actually
 * installed, so the file is reproducible on the destination. The live source composer.json is
 * never touched — the result is shipped as an override inside the migration package and applied
 * on import. Path repositories are left in place (their packages/ dirs travel with the file
 * archive), and with the composer-fix path also shipping vendor/, no composer install is even
 * required on the destination.
 */
final class ComposerAutoFix
{
    public function __construct(private readonly string $projectDir)
    {
    }

    /**
     * @return string|null the rewritten composer.json (pretty JSON) or null if nothing changed
     *                     / the file is unreadable
     */
    public function buildPortableComposerJson(): ?string
    {
        $file = $this->projectDir.'/composer.json';

        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (!\is_array($data)) {
            return null;
        }

        $changed = false;

        foreach (['require', 'require-dev'] as $section) {
            if (!isset($data[$section]) || !\is_array($data[$section])) {
                continue;
            }

            foreach ($data[$section] as $pkg => $constraint) {
                $c = (string) $constraint;

                if (!str_contains($c, '@dev') && !str_contains($c, 'dev-') && !str_starts_with($c, 'dev ')) {
                    continue;
                }

                $pinned = $this->installedVersion((string) $pkg);

                // Only pin when we have a concrete, non-dev version to pin to.
                if (null !== $pinned && '' !== $pinned && !str_starts_with($pinned, 'dev-')) {
                    $data[$section][$pkg] = $pinned;
                    $changed = true;
                }
            }
        }

        if (!$changed) {
            return null;
        }

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;
    }

    private function installedVersion(string $package): ?string
    {
        if (!class_exists(InstalledVersions::class)) {
            return null;
        }

        try {
            if (!InstalledVersions::isInstalled($package)) {
                return null;
            }

            return InstalledVersions::getPrettyVersion($package);
        } catch (\Throwable) {
            return null;
        }
    }
}
