<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Preflight;

use Composer\InstalledVersions;

/**
 * Captures the runtime environment so the destination can run a compatibility gate:
 * PHP/Contao/Symfony versions, every installed composer package, loaded PHP extensions.
 *
 * Everything is guarded with class_exists/function_exists so probing never fatals on a
 * stripped-down host.
 */
final class EnvironmentProbe
{
    /**
     * @return array<string, mixed>
     */
    public function probe(): array
    {
        return [
            'php' => \PHP_VERSION,
            'phpInt' => \PHP_VERSION_ID,
            'os' => \PHP_OS_FAMILY,
            'contao' => $this->packageVersion('contao/core-bundle'),
            'symfony' => $this->packageVersion('symfony/http-kernel'),
            'self' => $this->packageVersion('vtinnovations/migrator'),
            'packages' => $this->packages(),
            'extensions' => $this->extensions(),
            'limits' => [
                'memory_limit' => \ini_get('memory_limit'),
                'max_execution_time' => \ini_get('max_execution_time'),
                'post_max_size' => \ini_get('post_max_size'),
                'upload_max_filesize' => \ini_get('upload_max_filesize'),
            ],
        ];
    }

    private function packageVersion(string $package): ?string
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

    /**
     * @return array<string, string>
     */
    private function packages(): array
    {
        if (!class_exists(InstalledVersions::class)) {
            return [];
        }

        $out = [];

        try {
            foreach (InstalledVersions::getInstalledPackages() as $name) {
                $out[$name] = (string) (InstalledVersions::getPrettyVersion($name) ?? '');
            }
        } catch (\Throwable) {
            return $out;
        }

        ksort($out);

        return $out;
    }

    /**
     * @return list<string>
     */
    private function extensions(): array
    {
        $ext = get_loaded_extensions();
        sort($ext);

        return $ext;
    }
}
