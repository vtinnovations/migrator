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

namespace Vtinnovations\Migrator\Tests\Manifest;

use PHPUnit\Framework\TestCase;
use Vtinnovations\Migrator\Manifest\CanonicalJson;

/**
 * Fixed byte vectors for `vt-one/canonical-json-v1`. Both sides must produce identical bytes or no
 * signature can ever verify, so these are exact-string assertions, not structural ones.
 */
final class CanonicalJsonTest extends TestCase
{
    private CanonicalJson $canonical;

    protected function setUp(): void
    {
        $this->canonical = new CanonicalJson();
    }

    public function testSortsObjectKeysRecursivelyAndPreservesListOrder(): void
    {
        self::assertSame(
            '{"a":1,"b":[3,1,2],"z":{"a":null,"k":false}}',
            $this->canonical->encode(['z' => ['k' => false, 'a' => null], 'b' => [3, 1, 2], 'a' => 1]),
        );
    }

    public function testStripsOnlyTheTopLevelSignatureField(): void
    {
        self::assertSame(
            '{"inner":{"signature":"kept"},"v":1}',
            $this->canonical->encodeDocument(['signature' => 'dropped', 'v' => 1, 'inner' => ['signature' => 'kept']]),
        );
    }

    public function testDoesNotEscapeSlashesOrUnicode(): void
    {
        self::assertSame(
            '{"d":"exämple.de","u":"https://www.v-t.one/api/v1/verify"}',
            $this->canonical->encode(['u' => 'https://www.v-t.one/api/v1/verify', 'd' => 'exämple.de']),
        );
    }

    public function testPreservesScalarTypes(): void
    {
        self::assertSame(
            '{"f":false,"n":null,"s":"false","z":0}',
            $this->canonical->encode(['n' => null, 'z' => 0, 'f' => false, 's' => 'false']),
        );
    }

    public function testEmptyListAndEmptyObjectAreDistinct(): void
    {
        self::assertSame('{"features":[]}', $this->canonical->encode(['features' => []]));
        self::assertSame('{"features":{}}', $this->canonical->encode(['features' => new \stdClass()]));
    }

    public function testWhitespaceMutationChangesTheCanonicalBytes(): void
    {
        $document = ['license_key' => 'AAAAA-BBBBB', 'license_version' => 7];

        self::assertNotSame(
            $this->canonical->encode($document),
            $this->canonical->encode(['license_key' => 'AAAAA-BBBBB ', 'license_version' => 7]),
        );
    }
}
