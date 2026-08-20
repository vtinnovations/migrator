<?php

/*
 * Contao Migrator
 *
 * Package: vtinnovations/migrator
 * Copyright: V&T Innovations Team
 * Licence: LGPL-3.0-or-later
 * Website: https://www.v-t.one
 */

declare(strict_types=1);

namespace Vtinnovations\Migrator\Job\Step;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\KernelInterface;
use Vtinnovations\Migrator\Config\Paths;
use Vtinnovations\Migrator\Job\Job;
use Vtinnovations\Migrator\Job\StepInterface;
use Vtinnovations\Migrator\Job\StepResult;

/**
 * Settles the destination after files + DB + config are in place.
 *
 * A migration is a pure MOVE, never an upgrade: the destination must end up running the EXACT
 * same Contao + extension versions as the source, even if it previously ran a newer release. The
 * archived composer.json + composer.lock carry those exact versions, so the destination is brought
 * in line by running `composer install` (honours the lock — downgrades included). That is an
 * operator/Contao-Manager action; this step deliberately does NOT run contao:migrate, because the
 * restored database already matches the source schema once the code is on the source version, and
 * running migrate against the (still newer, pre-composer-install) destination code would inject
 * tables/columns from a version we are about to downgrade away from — i.e. it would turn the move
 * into a partial upgrade.
 *
 * What this step DOES do automatically:
 *  - re-publish bundle web assets (assets:install): public/bundles/ holds generated symlinks/copies
 *    of each bundle's Resources/public (e.g. bundles/contaocore/backend.css); these are not part of
 *    the archived files/ tree, so a fresh destination has none and every bundle CSS/JS 404s, leaving
 *    both backend and frontend unstyled.
 *  - purge var/cache so the next request rebuilds the container against the reconciled .env.local.
 *
 * Both run IN-PROCESS via the booted kernel (no shell subprocess — the only thing guaranteed to work
 * on a locked-down Plesk host).
 */
final class PostMigrationStep implements StepInterface
{
    public function __construct(
        private readonly string $projectDir,
        private readonly KernelInterface $kernel,
        private readonly Paths $paths,
    ) {
    }

    public function name(): string
    {
        return 'post_migration';
    }

    public function run(Job $job, float $deadline): StepResult
    {
        $root = rtrim($this->projectDir, '/\\');

        // Composer-fix packages ship a portable composer.json override + the full vendor/ tree.
        $overrideApplied = $this->applyComposerOverride($job);
        $vendorShipped = is_file($root.'/vendor/autoload.php');

        [$assetsInstalled, $assetsMsg] = $this->runAssetsInstall($job);

        [$filesServed, $filesMsg] = $this->ensureUploadsServed($job);

        $cacheDir = rtrim($this->projectDir, '/\\').'/var/cache';
        $purged = 0;

        if (is_dir($cacheDir)) {
            foreach (glob($cacheDir.'/*', GLOB_ONLYDIR) ?: [] as $envDir) {
                $this->rrmdir($envDir);
                ++$purged;
            }
        }

        $job->meta['postMigration'] = [
            'cachePurged' => $purged,
            'assetsInstalled' => $assetsInstalled,
            'uploadsServed' => $filesServed,
            // vendor/ shipped → the destination already has the exact source code, no composer
            // install needed. Otherwise it must match the source versions via composer install.
            'composerInstallRequired' => !$vendorShipped,
            'vendorShipped' => $vendorShipped,
            'composerOverrideApplied' => $overrideApplied,
        ];

        if ($vendorShipped) {
            $job->addLog(
                'vendor/ shipped with the package — NO "composer install" needed; the destination '
                .'runs the exact source code and versions.'
                .($overrideApplied ? ' A portable composer.json override was applied.' : ''),
                'info'
            );
        } else {
            $job->addLog(
                'Pure move: run "composer install --no-dev" on the destination so its Contao + extensions '
                .'match the source versions from the migrated composer.lock (downgrades included). Do NOT run '
                .'contao:migrate — the restored database already matches the source schema.',
                'info'
            );
        }

        $assetsTail = $assetsInstalled
            ? 'Bundle web assets re-published.'
            : 'assets:install could NOT run automatically — run "vendor/bin/contao-console assets:install --symlink --relative public" manually or the backend/frontend stays unstyled. '.$assetsMsg;

        $composerTail = $vendorShipped
            ? 'vendor/ shipped — no composer install needed.'
            : 'Next: run "composer install --no-dev" so the destination matches the source versions.';

        return StepResult::completeStep(sprintf('Cache purged (%d env dir(s)). %s%s %s', $purged, $assetsTail, '' !== $filesMsg ? ' '.$filesMsg : '', $composerTail));
    }

    /**
     * Guarantee the Contao upload directory is reachable under the web root, so /files/* (uploaded
     * images AND the theme/layout stylesheets that live there) is served on the destination.
     *
     * Contao keeps uploads in <root>/files but the web root is <root>/public, and contao:setup does
     * NOT create public/files — so a fresh destination can end up with the uploads present at the
     * project root yet 404 under /files/, leaving the frontend unstyled (no grids, full width). We
     * only ACT when the web-root entry is missing or an empty leftover dir; a populated dir/symlink
     * is left untouched. Symlink first (relative), copy as a fallback for hosts that can't symlink.
     *
     * @return array{0: bool, 1: string} [served, short message]
     */
    private function ensureUploadsServed(Job $job): array
    {
        $root = rtrim($this->projectDir, '/\\');
        $webDir = is_dir($root.'/public') ? $root.'/public' : (is_dir($root.'/web') ? $root.'/web' : null);

        if (null === $webDir) {
            return [false, ''];
        }

        $uploadPath = $this->uploadPath();
        $src = $root.'/'.$uploadPath;
        $dst = $webDir.'/'.$uploadPath;

        // Nothing to serve if the upload dir is absent or empty.
        if (!is_dir($src) || $this->isEmptyDir($src)) {
            return [false, ''];
        }

        // Already served: a symlink, or a non-empty directory extracted in place.
        if (is_link($dst) || (is_dir($dst) && !$this->isEmptyDir($dst))) {
            return [true, ''];
        }

        // Empty leftover dir → drop it so a symlink can take its place.
        if (is_dir($dst)) {
            @rmdir($dst);
        }

        if (file_exists($dst)) {
            return [false, ''];
        }

        // Relative symlink first (../files style), so the tree is not duplicated on disk.
        if (@symlink('../'.$uploadPath, $dst) && is_dir($dst)) {
            $job->addLog(sprintf('Linked %s/%s → ../%s so /%s/ is served on the destination.', basename($webDir), $uploadPath, $uploadPath, $uploadPath), 'info');

            return [true, sprintf('Web-root /%s/ link created.', $uploadPath)];
        }

        // Fallback: copy the tree into the web root (hosts that forbid symlinks).
        @unlink($dst);

        if ($this->copyTree($src, $dst)) {
            $job->addLog(sprintf('Copied uploads into %s/%s (symlink unavailable) so /%s/ is served.', basename($webDir), $uploadPath, $uploadPath), 'info');

            return [true, sprintf('Web-root /%s/ populated (copy).', $uploadPath)];
        }

        $job->addLog(sprintf('Could not make /%s/ web-served automatically — create a symlink %s/%s → ../%s on the destination, or the frontend stays unstyled.', $uploadPath, basename($webDir), $uploadPath, $uploadPath), 'warning');

        return [false, sprintf('⚠ /%s/ not web-served — link %s/%s → ../%s manually.', $uploadPath, basename($webDir), $uploadPath, $uploadPath)];
    }

    /** Contao upload path (default "files"), read from the booted container when available. */
    private function uploadPath(): string
    {
        try {
            $container = $this->kernel->getContainer();

            if ($container->hasParameter('contao.upload_path')) {
                $p = (string) $container->getParameter('contao.upload_path');

                if ('' !== $p && !str_contains($p, '/') && !str_contains($p, '\\')) {
                    return $p;
                }
            }
        } catch (\Throwable) {
            // fall through to default
        }

        return 'files';
    }

    private function isEmptyDir(string $dir): bool
    {
        foreach (new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS) as $ignored) {
            return false;
        }

        return true;
    }

    private function copyTree(string $src, string $dst): bool
    {
        if (!@mkdir($dst, 0775, true) && !is_dir($dst)) {
            return false;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($items as $item) {
            $target = $dst.'/'.substr($item->getPathname(), \strlen($src) + 1);

            if ($item->isDir()) {
                if (!is_dir($target) && !@mkdir($target, 0775, true) && !is_dir($target)) {
                    return false;
                }
            } elseif (!@copy($item->getPathname(), $target)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Overwrite the destination composer.json with the portable override shipped in the package
     * (staging/overrides/composer.json), if present. Returns whether it was applied.
     */
    private function applyComposerOverride(Job $job): bool
    {
        $staging = (string) ($job->payload['staging'] ?? $this->paths->stagingDir($job->id));
        $override = rtrim($staging, '/\\').'/overrides/composer.json';

        if (!is_file($override)) {
            return false;
        }

        if (@copy($override, rtrim($this->projectDir, '/\\').'/composer.json')) {
            $job->addLog('Applied portable composer.json override from the package.', 'debug');

            return true;
        }

        $job->addLog('Could not apply composer.json override (copy failed).', 'warning');

        return false;
    }

    /**
     * Re-publish each bundle's Resources/public into <webroot>/bundles. Uses --symlink --relative
     * (Symfony falls back to a hard copy when the filesystem can't symlink, e.g. some Plesk setups).
     *
     * @return array{0: bool, 1: string} [success, short message for the log]
     */
    private function runAssetsInstall(Job $job): array
    {
        if (!class_exists(Application::class)) {
            return [false, 'Symfony console not available.'];
        }

        $root = rtrim($this->projectDir, '/\\');
        $webDir = is_dir($root.'/public') ? 'public' : (is_dir($root.'/web') ? 'web' : 'public');

        try {
            $app = new Application($this->kernel);
            $app->setAutoExit(false);

            $output = new BufferedOutput();
            $code = $app->run(new ArrayInput([
                'command' => 'assets:install',
                'target' => $webDir,
                '--symlink' => true,
                '--relative' => true,
                '--no-interaction' => true,
            ]), $output);

            $text = trim($output->fetch());

            foreach (array_slice(array_filter(explode("\n", $text)), -6) as $line) {
                $job->addLog('assets: '.trim($line), 'debug');
            }

            if (0 !== $code) {
                return [false, sprintf('assets:install exited with code %d.', $code)];
            }

            return [true, ''];
        } catch (\Throwable $e) {
            $job->addLog('assets:install failed: '.$e->getMessage(), 'warning');

            return [false, $e->getMessage()];
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }
}
