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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vtinnovations\Migrator\Config\EntitlementState;

/**
 * Structural audit of the paid boundary.
 *
 * {@see \Vtinnovations\Migrator\Tests\Config\EntitlementCapabilityTest} proves the grant matrix is
 * right; this proves the code actually asks. The failure these guard against is silent: a gate
 * that checks the wrong thing, or an action added later with no gate at all, gives the paid feature
 * away without breaking a single behavioural test.
 */
final class FeatureBoundaryTest extends TestCase
{
    /**
     * Every server-side path into a direct transfer, and the capability each must name. Adding a
     * new entry point means adding it here too — that is the point.
     *
     * @var array<string, string>
     */
    private const DIRECT_ENTRY_POINTS = [
        // Receiving side: both endpoints a remote source can reach.
        'src/Controller/IngestController.php' => 'CAP_DIRECT',
        // Sending side plus the pairing mint, refused at the action boundary.
        'src/BackendModule/MigratorModule.php' => 'CAP_DIRECT',
        // The standalone panel can mint a pairing while the back end is down.
        'src/Resources/recovery/_tcmig-recovery.php' => 'CAP_DIRECT',
        // The pipeline itself, so a bypassed request boundary still cannot advance paid work.
        'src/Job/Job.php' => 'CAP_DIRECT',
    ];

    #[DataProvider('directEntryPoints')]
    public function testEveryDirectTransferEntryPointNamesThePaidCapability(string $relative, string $constant): void
    {
        self::assertStringContainsString(
            $constant,
            $this->read($relative),
            sprintf('%s can reach a direct transfer without naming the paid capability.', $relative),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function directEntryPoints(): array
    {
        $cases = [];

        foreach (self::DIRECT_ENTRY_POINTS as $relative => $constant) {
            $cases[$relative] = [$relative, $constant];
        }

        return $cases;
    }

    /**
     * The paid capability must be granted in exactly one place. A second grant site is how a tier
     * split rots: the policy says Free, and something else quietly says otherwise.
     */
    public function testOnlyThePolicyDeclaresGrantTables(): void
    {
        $granting = [];

        foreach ($this->sourceFiles() as $relative => $code) {
            if (preg_match('/const\s+\w*GRANTS\w*/i', $code)) {
                $granting[] = $relative;
            }
        }

        sort($granting);

        self::assertSame(['src/Config/EntitlementModelPolicy.php'], $granting);
    }

    /**
     * Advancing a job is the protected operation, so the controller must ask for the capability
     * that specific job needs — not merely for the base tier, which every licence has.
     */
    public function testTickAsksForTheCapabilityOfTheJobItWouldAdvance(): void
    {
        foreach (['src/Controller/MigratorController.php', 'src/Job/JobRunner.php'] as $relative) {
            self::assertStringContainsString(
                'requiredCapability()',
                $this->read($relative),
                sprintf('%s must gate on the capability the job itself requires.', $relative),
            );
        }
    }

    /**
     * No mutating recovery action may be reachable without a capability. The panel dispatches on
     * a query parameter, so a new action is one `if` away from being publicly callable with only
     * the operator token — which is not a licence.
     */
    public function testEveryMutatingRecoveryActionIsMappedToACapability(): void
    {
        $code = $this->read('src/Resources/recovery/_tcmig-recovery.php');

        preg_match_all("/'([a-z_]+)' === \\\$action/", $code, $handled);
        preg_match_all("/'([a-z_]+)' => \\\$cap(?:Archive|Direct),/", $code, $mapped);

        $handledActions = array_diff($handled[1], ['status']); // status is read-only by design
        $mappedActions = $mapped[1];

        self::assertNotEmpty($mappedActions, 'the action → capability map disappeared');
        self::assertSame(
            [],
            array_values(array_diff($handledActions, $mappedActions)),
            'these recovery actions mutate state but name no capability',
        );
    }

    /**
     * The status endpoint stays readable on any tier — an operator whose licence lapsed must still
     * be able to see what a stalled job is doing in order to clean it up.
     */
    public function testRecoveryStatusIsAnsweredBeforeTheCapabilityGate(): void
    {
        $code = $this->read('src/Resources/recovery/_tcmig-recovery.php');

        self::assertLessThan(
            strpos($code, '$actionNeeds = ['),
            strpos($code, "'status' === \$action"),
            'status must be answered before the gate so diagnosis never needs a licence',
        );
    }

    /** The vocabulary is shared with V-T.ONE through signed license_features; it cannot drift. */
    public function testCapabilityIdentifiersAreStable(): void
    {
        self::assertSame('transfer.archive', EntitlementState::CAP_ARCHIVE);
        self::assertSame('transfer.direct', EntitlementState::CAP_DIRECT);
        self::assertSame(
            [EntitlementState::CAP_ARCHIVE, EntitlementState::CAP_DIRECT],
            EntitlementState::CAPABILITIES,
        );
    }

    /** There must be no boolean to flip: "licensed" is derived from the grant set. */
    public function testTheStateCarriesNoLicensedFlag(): void
    {
        self::assertStringNotContainsString(
            'readonly bool $licensed',
            $this->read('src/Config/EntitlementState.php'),
            'a licensed flag is one edit away from unlocking every gate',
        );
    }

    private function read(string $relative): string
    {
        $path = \dirname(__DIR__, 2).'/'.$relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * @return array<string, string> repo-relative path => contents
     */
    private function sourceFiles(): array
    {
        $root = \dirname(__DIR__, 2);
        $files = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root.'/src', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($it as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $files[str_replace($root.'/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
            }
        }

        self::assertNotEmpty($files);

        return $files;
    }
}
