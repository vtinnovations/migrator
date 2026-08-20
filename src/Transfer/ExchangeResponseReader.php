<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Transfer;

use Vtinnovations\Migrator\Config\EntitlementRecord;
use Vtinnovations\Migrator\Manifest\CanonicalJson;
use Vtinnovations\Migrator\Manifest\DetachedVerifier;
use Vtinnovations\Migrator\Manifest\TrustRing;

/**
 * Opens a signed V-T.ONE exchange response (activation, refresh, or push update) and returns an
 * authenticated {@see EntitlementRecord}, or throws {@see ExchangeException} with a safe category.
 *
 * The cryptographic order is fixed and fails closed at every step:
 *   1. strict Base64 decode of `license_payload_b64` to the EXACT licence bytes;
 *   2. resolve the envelope `key_id` + `signature_algorithm` in the pinned ring (Ed25519 only);
 *   3. verify the integrity-envelope signature over canonical envelope bytes;
 *   4. constant-time compare the envelope MD5 against MD5 of the exact decoded bytes;
 *   5. parse the licence JSON WITHOUT reserializing (the MD5/doc signature bind the raw bytes);
 *   6. verify the licence-document signature against every usable record-purpose key.
 *
 * Schema/project/domain/date/model checks are policy and run in the evaluation layer, not here.
 */
final class ExchangeResponseReader
{
    private const ALGO_ED25519 = 'ed25519';

    public function __construct(
        private readonly TrustRing $ring,
        private readonly DetachedVerifier $verifier,
        private readonly CanonicalJson $canonical,
    ) {
    }

    /**
     * @param array<string, mixed> $response the decoded JSON body of a verify/updater response
     */
    public function open(array $response, ?int $now = null): EntitlementRecord
    {
        if (!$this->ring->isReady()) {
            // Reached signature verification with no usable pinned key: fail closed, never trust
            // the MD5 alone or accept the packet unsigned.
            throw new ExchangeException('signing_key_store_empty');
        }

        $payloadB64 = (string) ($response['license_payload_b64'] ?? '');
        $envelope = $response['integrity'] ?? null;

        if ('' === $payloadB64 || !\is_array($envelope)) {
            throw new ExchangeException('malformed_response');
        }

        $rawBytes = base64_decode($payloadB64, true);

        if (false === $rawBytes || '' === $rawBytes) {
            throw new ExchangeException('bad_base64');
        }

        // --- integrity envelope ------------------------------------------------------------
        $algo = strtolower((string) ($envelope['signature_algorithm'] ?? ''));

        if (self::ALGO_ED25519 !== $algo) {
            throw new ExchangeException('unknown_algorithm');
        }

        $keyId = (string) ($envelope['key_id'] ?? '');
        $envelopeKey = $this->ring->rawKey($keyId, TrustRing::PURPOSE_ENVELOPE, $now);

        if (null === $envelopeKey) {
            throw new ExchangeException('unknown_signing_key');
        }

        $envelopeSig = (string) ($envelope['signature'] ?? '');
        $envelopeMsg = $this->canonical->encodeDocument($envelope);

        if ('' === $envelopeSig || !$this->verifier->verify($envelopeMsg, $envelopeSig, $envelopeKey)) {
            throw new ExchangeException('bad_envelope_signature');
        }

        // --- exact-byte MD5 tripwire (only trusted AFTER the envelope signature) ------------
        $expectedMd5 = (string) ($envelope['license_md5'] ?? '');
        $actualMd5 = md5($rawBytes);

        if ('' === $expectedMd5 || !hash_equals(strtolower($expectedMd5), $actualMd5)) {
            throw new ExchangeException('md5_mismatch');
        }

        // --- licence document (parse the exact bytes; do not reserialize) -------------------
        $document = json_decode($rawBytes, true);

        if (!\is_array($document)) {
            throw new ExchangeException('malformed_document');
        }

        $docSig = (string) ($document['signature'] ?? '');
        $docMsg = $this->canonical->encodeDocument($document);
        $recordKeys = $this->ring->usableKeys(TrustRing::PURPOSE_RECORD, $now);

        if ('' === $docSig || null === $this->verifier->verifyAny($docMsg, $docSig, $recordKeys)) {
            throw new ExchangeException('bad_document_signature');
        }

        return EntitlementRecord::fromBytes($rawBytes, $envelope);
    }
}
