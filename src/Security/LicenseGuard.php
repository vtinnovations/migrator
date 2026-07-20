<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Security;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Gate check + standard response helper injected into controllers. Paid-only product, so there is
 * a single gate (isLicensed) and the paid-only no-license message — no Pro tier, no deniedResponse.
 */
final class LicenseGuard
{
    public function __construct(private readonly LicenseManager $licenseManager)
    {
    }

    public function isLicensed(): bool
    {
        return $this->licenseManager->isLicensed();
    }

    public function noLicenseResponsePaidOnly(): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'reason' => 'no_license',
            'error' => 'This plugin requires a license. Get your free license at v-t.one.',
            'error_de' => 'Dieses Plugin benötigt eine Lizenz. Hol dir deine kostenlose Lizenz auf v-t.one.',
            'cta_url' => 'https://v-t.one',
        ]);
    }
}
