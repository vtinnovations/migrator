<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Config;

/**
 * Reconciles environment configuration after a migration so the moved site talks to the
 * NEW host's database and keeps the new host's own settings, while still carrying over the
 * source secrets that decrypt migrated data.
 *
 * The danger this guards against: the payload ships the source's config/ and .env.local, so
 * a blind extract would point the new server at the OLD database and clobber the
 * destination credentials. We snapshot the destination keys BEFORE extract and re-inject
 * them AFTER, but deliberately CARRY the source APP_SECRET / encryption key (without it,
 * encrypted DB fields are unrecoverable).
 */
final class ConfigReconciler
{
    /** Keys whose DESTINATION value must win (new host owns these). */
    public const DEST_OWNED = ['DATABASE_URL', 'TRUSTED_PROXIES', 'TRUSTED_HOSTS'];

    /** Keys CARRIED from the source (decrypt migrated data / preserve identity). */
    public const SOURCE_CARRIED = ['APP_SECRET', 'CONTAO_ENCRYPTION_KEY', 'DATABASE_ENCRYPTION_KEY'];

    public function __construct(private readonly string $projectDir)
    {
    }

    /**
     * Capture the keys the destination must keep, before any extract overwrites .env.local.
     *
     * @param array<string,string> $extra additional keys to capture (e.g. MAILER_DSN per policy)
     *
     * @return array<string,string>
     */
    public function captureDestination(EnvFileReader $env, array $extra = []): array
    {
        $keys = array_merge(self::DEST_OWNED, $extra);

        return $env->get($keys);
    }

    /**
     * Apply the reconciliation to .env.local: destination-owned keys restored, source-carried
     * secrets injected. Values are written/updated in place; other lines are left untouched.
     *
     * @param array<string,string> $destValues   captured before extract (win)
     * @param array<string,string> $sourceSecrets decrypted from the vault (carried)
     */
    public function apply(array $destValues, array $sourceSecrets): void
    {
        $envLocal = rtrim($this->projectDir, '/\\').'/.env.local';
        $lines = is_file($envLocal) ? (file($envLocal, FILE_IGNORE_NEW_LINES) ?: []) : [];

        $set = [];

        foreach ($destValues as $k => $v) {
            $set[$k] = $v;
        }

        foreach ($sourceSecrets as $k => $v) {
            if (\in_array($k, self::SOURCE_CARRIED, true)) {
                $set[$k] = $v;
            }
        }

        $lines = $this->upsertEnv($lines, $set);

        $tmp = $envLocal.'.tmp';

        if (false === file_put_contents($tmp, implode("\n", $lines)."\n", LOCK_EX) || !@rename($tmp, $envLocal)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not write reconciled .env.local.');
        }

        @chmod($envLocal, 0640);
    }

    /**
     * @param list<string>          $lines
     * @param array<string, string> $set
     *
     * @return list<string>
     */
    private function upsertEnv(array $lines, array $set): array
    {
        $seen = [];

        foreach ($lines as $i => $line) {
            $trim = ltrim($line);

            if ('' === $trim || str_starts_with($trim, '#')) {
                continue;
            }

            $eq = strpos($line, '=');

            if (false === $eq) {
                continue;
            }

            $key = trim(substr($line, 0, $eq));

            if (\array_key_exists($key, $set)) {
                $lines[$i] = $key.'='.$this->quote($set[$key]);
                $seen[$key] = true;
            }
        }

        foreach ($set as $key => $value) {
            if (!isset($seen[$key])) {
                $lines[] = $key.'='.$this->quote($value);
            }
        }

        return array_values($lines);
    }

    private function quote(string $value): string
    {
        // Quote when the value contains whitespace, # or characters dotenv would mis-split.
        if (preg_match('/[\s#"\']/', $value)) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return $value;
    }
}
