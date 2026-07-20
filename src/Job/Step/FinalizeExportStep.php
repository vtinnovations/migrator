<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Job\Step;

use Vtinnovations\Migrator\Config\ConfigStore;
use Vtinnovations\Migrator\Config\Paths;
use Vtinnovations\Migrator\Job\Job;
use Vtinnovations\Migrator\Job\StepInterface;
use Vtinnovations\Migrator\Job\StepResult;
use Vtinnovations\Migrator\Manifest\Manifest;
use Vtinnovations\Migrator\Manifest\ManifestStore;
use Vtinnovations\Migrator\Preflight\ComposerAutoFix;
use Vtinnovations\Migrator\Security\TokenManager;

/**
 * Final export step. Signs the manifest with an HMAC secret derived from the export
 * passphrase (so the destination, given the same passphrase, can verify integrity), writes
 * checksums.json, prunes old backups, and records the download coordinates on the job.
 *
 * The single downloadable .tcmig is NOT materialised here — it is streamed on demand by the
 * download controller straight from the backup dir, avoiding a second full-size copy on disk
 * and sidestepping the "can't resume a half-written tar" problem.
 */
final class FinalizeExportStep implements StepInterface
{
    public function __construct(
        private readonly ManifestStore $manifests,
        private readonly TokenManager $tokens,
        private readonly Paths $paths,
        private readonly ConfigStore $config,
        private readonly ComposerAutoFix $composerFix,
    ) {
    }

    public function name(): string
    {
        return 'finalize_export';
    }

    public function run(Job $job, float $deadline): StepResult
    {
        $manifest = $this->manifests->load($job->id);

        if (null === $manifest) {
            return StepResult::fail('No manifest to finalize.');
        }

        $passphraseFile = $this->paths->jobSecretFile($job->id);
        $passphrase = is_file($passphraseFile) ? trim((string) file_get_contents($passphraseFile)) : '';
        $secret = '' !== $passphrase
            ? hash('sha256', 'tcmig-sign|'.$passphrase)
            : $this->tokens->operatorToken();

        $manifest->set('signedWith', '' !== $passphrase ? 'passphrase' : 'operator-token');

        // Composer-fix export: vendor/ ships in the file archive (ArchiveFilesStep) and a portable
        // composer.json is written as an override the import applies. Record the flag on the signed
        // manifest so the destination can trust it.
        $composerFixed = !empty($job->meta['composerFix']);

        if ($composerFixed) {
            $manifest->set('composerFix', true);
        }

        $manifest->sign(fn (string $payload): string => $this->tokens->sign($payload, $secret));
        $this->manifests->save($job->id, $manifest);

        $this->writeChecksums($job->id, $manifest);

        if ($composerFixed) {
            $this->writeComposerOverride($job->id);
        }

        // Burn the transient passphrase now that signing is done.
        @unlink($passphraseFile);

        $job->meta['download'] = [
            'ready' => true,
            'filename' => sprintf('contao-migration-%s.tcmig', substr($job->id, 0, 8)),
            'backupDir' => $this->paths->backupDir($job->id),
        ];

        $this->prune();

        return StepResult::completeStep('Export finalized — package ready for download.');
    }

    /**
     * Write the portable composer.json into overrides/ inside the backup dir so it ships in the
     * package (PackageBuilder archives the whole backup dir). The import applies it after
     * extracting the file tree. Nothing to write if there were no dev constraints to pin.
     */
    private function writeComposerOverride(string $jobId): void
    {
        $portable = $this->composerFix->buildPortableComposerJson();

        if (null === $portable) {
            return;
        }

        $dir = $this->paths->backupDir($jobId).'/overrides';

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        file_put_contents($dir.'/composer.json', $portable, LOCK_EX);
    }

    private function writeChecksums(string $jobId, Manifest $manifest): void
    {
        $checksums = [
            'manifest' => hash('sha256', $manifest->canonicalPayload()),
            'database' => [],
            'files' => [],
        ];

        foreach (($manifest->get('database')['tables'] ?? []) as $table => $info) {
            $checksums['database'][$table] = $info['checksum'] ?? null;
        }

        foreach (($manifest->get('files')['chunks'] ?? []) as $chunk) {
            $checksums['files'][$chunk['file']] = $chunk['sha256'] ?? null;
        }

        $file = $this->paths->backupDir($jobId).'/checksums.json';
        file_put_contents($file, json_encode($checksums, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}', LOCK_EX);
    }

    /**
     * Keep the newest retention_backups directories; remove older ones.
     */
    private function prune(): void
    {
        $keep = max(1, (int) $this->config->get('retention_backups', 3));
        $dirs = glob($this->paths->backupsDir().'/*', GLOB_ONLYDIR) ?: [];

        if (\count($dirs) <= $keep) {
            return;
        }

        usort($dirs, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        foreach (\array_slice($dirs, $keep) as $old) {
            $this->rrmdir($old);
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
