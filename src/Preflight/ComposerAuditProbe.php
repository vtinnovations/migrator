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

namespace Vtinnovations\Migrator\Preflight;

/**
 * Pre-send audit of the project composer.json for things that commonly break a `composer install`
 * on a fresh destination host: repositories that only exist locally / need credentials, dev
 * stability constraints, a disabled Packagist, and path-repo packages that ship without a pinned
 * "version".
 *
 * Warnings are returned STRUCTURED — `{code, args}` — not as finished sentences, so the display
 * layer (backend module / recovery panel) can render them in the operator's language. Codes and
 * their args:
 *   missing            {}
 *   invalid_json       {}
 *   repo_path          {label, url}
 *   repo_vcs           {label, type, url}
 *   repo_custom        {label, url}
 *   packagist_disabled {}
 *   dev_constraint     {pkg, constraint}
 *   min_stability      {value}
 *   path_no_version    {name, url}
 */
final class ComposerAuditProbe
{
    public function __construct(private readonly string $projectDir)
    {
    }

    /**
     * @return list<array{code: string, args: array<string, string>}>
     */
    public function audit(): array
    {
        $file = $this->projectDir.'/composer.json';

        if (!is_file($file)) {
            return [['code' => 'missing', 'args' => []]];
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (!\is_array($data)) {
            return [['code' => 'invalid_json', 'args' => []]];
        }

        $warnings = [];
        $repositories = (array) ($data['repositories'] ?? []);

        // 1. Repositories that will not resolve the same way on a fresh destination host.
        foreach ($repositories as $key => $repo) {
            if (('packagist' === $key || 'packagist.org' === $key) && false === $repo) {
                $warnings[] = ['code' => 'packagist_disabled', 'args' => []];

                continue;
            }

            if (!\is_array($repo)) {
                continue;
            }

            $type = (string) ($repo['type'] ?? '');
            $url = (string) ($repo['url'] ?? '');
            $label = \is_string($key) ? $key : ($url !== '' ? $url : $type);

            if ('path' === $type) {
                $warnings[] = ['code' => 'repo_path', 'args' => ['label' => $label, 'url' => $url]];
            } elseif (\in_array($type, ['vcs', 'git', 'github', 'gitlab', 'bitbucket'], true)) {
                $warnings[] = ['code' => 'repo_vcs', 'args' => ['label' => $label, 'type' => $type, 'url' => $url]];
            } elseif ('composer' === $type && '' !== $url && !preg_match('~^https://(repo\.)?packagist\.org~', $url)) {
                $warnings[] = ['code' => 'repo_custom', 'args' => ['label' => $label, 'url' => $url]];
            }

            if (isset($repo['packagist.org']) && false === $repo['packagist.org']) {
                $warnings[] = ['code' => 'packagist_disabled', 'args' => []];
            }
        }

        // 2. Non-reproducible version constraints in require / require-dev.
        foreach (['require', 'require-dev'] as $section) {
            foreach ((array) ($data[$section] ?? []) as $pkg => $constraint) {
                $c = (string) $constraint;

                if (str_contains($c, '@dev') || str_contains($c, 'dev-') || str_starts_with($c, 'dev ')) {
                    $warnings[] = ['code' => 'dev_constraint', 'args' => ['pkg' => (string) $pkg, 'constraint' => $c]];
                }
            }
        }

        $minStability = (string) ($data['minimum-stability'] ?? '');

        if ('' !== $minStability && 'stable' !== $minStability) {
            $warnings[] = ['code' => 'min_stability', 'args' => ['value' => $minStability]];
        }

        // 3. Path-repo packages that ship without a pinned "version" (composer often refuses them).
        foreach ($repositories as $repo) {
            if (!\is_array($repo) || 'path' !== ($repo['type'] ?? '')) {
                continue;
            }

            $url = (string) ($repo['url'] ?? '');

            if ('' === $url || str_contains($url, '*')) {
                continue;
            }

            $cj = $this->resolvePath($url).'/composer.json';

            if (!is_file($cj)) {
                continue;
            }

            $pd = json_decode((string) file_get_contents($cj), true);

            if (\is_array($pd) && !isset($pd['version'])) {
                $name = (string) ($pd['name'] ?? $url);
                $warnings[] = ['code' => 'path_no_version', 'args' => ['name' => $name, 'url' => $url]];
            }
        }

        return $warnings;
    }

    private function resolvePath(string $url): string
    {
        if (preg_match('~^([a-z]:[\\\\/]|/)~i', $url)) {
            return rtrim($url, '/\\');
        }

        return rtrim($this->projectDir.'/'.$url, '/\\');
    }
}
