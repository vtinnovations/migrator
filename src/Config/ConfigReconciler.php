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
     * Dest-owned keys the destination did NOT provide (e.g. a fresh host with no DATABASE_URL of
     * its own yet) are a trap: the extracted source .env.local carries the SOURCE value for them,
     * so leaving it would point the moved site at the source's database. Such lines are neutralized
     * (commented out) and returned so the caller can tell the operator to set the new host's value.
     *
     * @param array<string,string> $destValues   captured before extract (win)
     * @param array<string,string> $sourceSecrets decrypted from the vault (carried)
     *
     * @return list<string> dest-owned keys whose leftover source value was neutralized (need operator input)
     */
    public function apply(array $destValues, array $sourceSecrets): array
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

        // Dest-owned keys the destination could not supply: neutralize any leftover source line.
        $neutralize = array_values(array_filter(
            self::DEST_OWNED,
            static fn (string $k): bool => !\array_key_exists($k, $set),
        ));

        [$lines, $neutralized] = $this->upsertEnv($lines, $set, $neutralize);

        $tmp = $envLocal.'.tmp';

        if (false === file_put_contents($tmp, implode("\n", $lines)."\n", LOCK_EX) || !@rename($tmp, $envLocal)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not write reconciled .env.local.');
        }

        @chmod($envLocal, 0640);

        return $neutralized;
    }

    /**
     * @param list<string>          $lines
     * @param array<string, string> $set       key => value to upsert
     * @param list<string>          $neutralize keys whose existing (source) line must be commented out
     *
     * @return array{0: list<string>, 1: list<string>} [rewritten lines, keys actually neutralized]
     */
    private function upsertEnv(array $lines, array $set, array $neutralize = []): array
    {
        $seen = [];
        $neutralized = [];

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
            } elseif (\in_array($key, $neutralize, true)) {
                // Leftover source value for a dest-owned key: comment it out so the site does not
                // boot against the source host. The operator must set the new host's value.
                $lines[$i] = '# '.$line.'   # neutralized by migrator — set this to the NEW host value';
                $neutralized[] = $key;
            }
        }

        foreach ($set as $key => $value) {
            if (!isset($seen[$key])) {
                $lines[] = $key.'='.$this->quote($value);
            }
        }

        return [array_values($lines), array_values(array_unique($neutralized))];
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
