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

namespace Vtinnovations\Migrator\Tests\Transfer;

use PHPUnit\Framework\TestCase;
use Vtinnovations\Migrator\Tests\TestKit;
use Vtinnovations\Migrator\Transfer\UpdaterJournal;

/**
 * Replay/idempotency state. It stores the minimum that makes an exact retry idempotent and a
 * forged retry detectable — a one-way body digest, never packet material, nonces or signatures.
 */
final class UpdaterJournalTest extends TestCase
{
    use TestKit;

    private UpdaterJournal $journal;

    protected function setUp(): void
    {
        $this->journal = new UpdaterJournal($this->paths());
    }

    protected function tearDown(): void
    {
        $this->cleanTmp();
    }

    public function testUnseenRequestIdHasNoPriorOutcome(): void
    {
        self::assertNull($this->journal->find('never-seen'));
    }

    public function testExactRetryIsRecognisedByBodyFingerprint(): void
    {
        $body = '{"action":"license_update","request_id":"req-1"}';
        $this->journal->record('req-1', $this->journal->fingerprint($body), 9, 1800000000);

        $prior = $this->journal->find('req-1');

        self::assertNotNull($prior);
        self::assertSame(9, $prior['version']);
        self::assertSame($this->journal->fingerprint($body), $prior['fingerprint']);
    }

    public function testSameRequestIdWithDifferentContentHasADifferentFingerprint(): void
    {
        $body = '{"action":"license_update","request_id":"req-1"}';
        $this->journal->record('req-1', $this->journal->fingerprint($body), 9, 1800000000);

        $prior = $this->journal->find('req-1');

        self::assertNotNull($prior);
        self::assertNotSame(
            $prior['fingerprint'],
            $this->journal->fingerprint('{"action":"license_update","request_id":"req-1","evil":true}'),
        );
    }

    public function testFingerprintIsAOneWayDigestAndNeverTheBody(): void
    {
        $body = '{"license_payload_b64":"c2VjcmV0"}';
        $fingerprint = $this->journal->fingerprint($body);

        self::assertSame(64, \strlen($fingerprint));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fingerprint);
        self::assertStringNotContainsString('c2VjcmV0', $fingerprint);
    }

    public function testEntriesAreRetainedBeyondTheReplayWindowAndPrunedByAge(): void
    {
        $now = 1800000000;
        $this->journal->record('old', 'f1', 1, $now - 2592001);
        $this->journal->record('recent', 'f2', 2, $now);

        self::assertNull($this->journal->find('old'), 'entries older than the retention window are pruned');
        self::assertNotNull($this->journal->find('recent'));
    }

    public function testJournalFileNeverContainsPacketMaterial(): void
    {
        $body = '{"license_payload_b64":"c2VjcmV0","nonce":"n-1"}';
        $this->journal->record('req-1', $this->journal->fingerprint($body), 9, 1800000000);

        $stored = (string) file_get_contents($this->paths()->updaterJournalFile());

        self::assertStringNotContainsString('license_payload_b64', $stored);
        self::assertStringNotContainsString('c2VjcmV0', $stored);
        self::assertStringNotContainsString('n-1', $stored);
    }
}
