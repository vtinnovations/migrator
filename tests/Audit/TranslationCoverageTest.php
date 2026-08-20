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

namespace Vtinnovations\Migrator\Tests\Audit;

use PHPUnit\Framework\TestCase;

/**
 * Translation coverage. Every administrator-visible string lives in contao/languages/<lang>/, both
 * languages carry the same keys with compatible placeholders, and no class ships text of its own.
 */
final class TranslationCoverageTest extends TestCase
{
    private const LANGUAGES = ['en', 'de'];

    /**
     * @return array<string, string>
     */
    private function catalog(string $language): array
    {
        $file = \dirname(__DIR__, 2).'/contao/languages/'.$language.'/tcmig.php';
        self::assertFileExists($file);

        $GLOBALS['TL_LANG']['tcmig'] = [];
        include $file;
        $catalog = $GLOBALS['TL_LANG']['tcmig'];
        $GLOBALS['TL_LANG']['tcmig'] = [];

        self::assertIsArray($catalog);

        return $catalog;
    }

    public function testBothLanguagesDefineExactlyTheSameKeys(): void
    {
        $en = $this->catalog('en');
        $de = $this->catalog('de');

        self::assertNotEmpty($en);
        self::assertSame([], array_keys(array_diff_key($en, $de)), 'keys missing from the German catalog');
        self::assertSame([], array_keys(array_diff_key($de, $en)), 'keys missing from the English catalog');
    }

    public function testNoTranslationIsEmptyAndPlaceholdersMatch(): void
    {
        $en = $this->catalog('en');
        $de = $this->catalog('de');

        foreach ($en as $key => $text) {
            self::assertIsString($text);
            self::assertNotSame('', trim($text), sprintf('en.%s is empty', $key));
            self::assertNotSame('', trim((string) $de[$key]), sprintf('de.%s is empty', $key));

            self::assertSame(
                substr_count($text, '%s'),
                substr_count((string) $de[$key], '%s'),
                sprintf('placeholder count differs for "%s" — sprintf() would break', $key),
            );
        }
    }

    public function testGermanIsActuallyTranslatedNotCopied(): void
    {
        $en = $this->catalog('en');
        $de = $this->catalog('de');

        // Some values are legitimately identical (product names, "Import", placeholders). Guard the
        // ratio instead: a catalog that is mostly copied English is not a translation.
        $identical = 0;

        foreach ($en as $key => $text) {
            if ($text === $de[$key]) {
                ++$identical;
            }
        }

        self::assertLessThan(
            \count($en) * 0.25,
            $identical,
            sprintf('%d of %d German values are identical to English', $identical, \count($en)),
        );
    }

    /**
     * Every key the code asks for must exist, or the UI silently renders the raw key.
     */
    public function testEveryTranslationKeyUsedInTheCodeExists(): void
    {
        $en = $this->catalog('en');
        $missing = [];

        foreach ($this->sourceFiles() as $file => $code) {
            // Literal lookups: t('key') / t('key', ...). A trailing "." means the key is built
            // dynamically (t('cw_'.$code)); those are checked as prefix families below.
            preg_match_all("/\\\$(?:this->)?t\\(\s*'([a-z0-9_]+)'\s*[,)]/", $code, $matches);

            foreach ($matches[1] as $key) {
                if (!isset($en[$key])) {
                    $missing[] = $file.': '.$key;
                }
            }

            // Dynamic families must still resolve to something.
            preg_match_all("/\\\$(?:this->)?t\\(\s*'([a-z0-9_]+_)'\s*\./", $code, $dynamic);

            foreach ($dynamic[1] as $prefix) {
                $found = array_filter(array_keys($en), static fn (string $k): bool => str_starts_with($k, $prefix));

                if ([] === $found) {
                    $missing[] = $file.': '.$prefix.'* (dynamic family has no keys)';
                }
            }
        }

        self::assertSame([], $missing, 'translation keys used but never defined');
    }

    /**
     * The reverse guard: the catalog must not rot into a graveyard of keys nothing renders.
     */
    public function testCatalogHasNoLargeBlockOfUnusedKeys(): void
    {
        $en = $this->catalog('en');
        $all = implode("\n", $this->sourceFiles());
        $unused = [];

        foreach (array_keys($en) as $key) {
            if (!str_contains($all, "'".$key."'")) {
                $unused[] = $key;
            }
        }

        self::assertLessThan(
            \count($en) * 0.2,
            \count($unused),
            sprintf('%d unused keys: %s', \count($unused), implode(', ', \array_slice($unused, 0, 15))),
        );
    }

    /**
     * No user-facing German text may be embedded in a class: it belongs in the catalog. Messages is
     * excluded because it IS a catalog (the display-time translator for pipeline log lines).
     */
    public function testNoGermanTextIsHardcodedInTheSource(): void
    {
        foreach ($this->sourceFiles() as $file => $code) {
            if (str_ends_with($file, 'Support/Messages.php')) {
                continue;
            }

            preg_match_all("/'[^'\\n]*[äöüßÄÖÜ][^'\\n]*'/u", $code, $matches);

            self::assertSame(
                [],
                $matches[0],
                sprintf('%s hardcodes German text instead of using the catalog', $file),
            );
        }
    }

    /**
     * The licence surface in particular must be fully catalogued.
     */
    public function testLicenceSurfaceCarriesNoInlineStringTable(): void
    {
        $summary = (string) file_get_contents(\dirname(__DIR__, 2).'/src/BackendModule/EntitlementSummary.php');
        $fields = (string) file_get_contents(\dirname(__DIR__, 2).'/src/EventListener/DataContainer/EntitlementFields.php');
        $module = (string) file_get_contents(\dirname(__DIR__, 2).'/src/BackendModule/MigratorModule.php');

        self::assertStringNotContainsString('LABELS', $summary, 'the status block must not carry its own strings');
        self::assertStringContainsString("System::loadLanguageFile('tcmig')", $summary);
        self::assertStringContainsString("System::loadLanguageFile('tcmig')", $fields);
        self::assertStringContainsString("System::loadLanguageFile('tcmig')", $module);
        self::assertStringNotContainsString('private function strings()', $module);
    }

    /**
     * The standalone recovery panel has no kernel, so it reads the catalog files directly — it must
     * not fall back to its own German copy.
     */
    public function testRecoveryPanelReadsTheSharedCatalog(): void
    {
        $code = (string) file_get_contents(\dirname(__DIR__, 2).'/src/Resources/recovery/_tcmig-recovery.php');

        self::assertStringContainsString("/contao/languages/'.\$load.'/tcmig.php", $code);
        self::assertStringContainsString('$T = (array) ($GLOBALS[\'TL_LANG\'][\'tcmig\'] ?? []);', $code);
        self::assertStringContainsString('json_encode($T', $code, 'the browser side needs the same table');
        self::assertMatchesRegularExpression('/\$lang = .*(de|en)/', $code, 'language must be resolved, not fixed');
    }

    /**
     * @return array<string, string>
     */
    private function sourceFiles(): array
    {
        $root = \dirname(__DIR__, 2).'/src';
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $files[substr($file->getPathname(), \strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }

        self::assertNotEmpty($files);

        return $files;
    }
}
