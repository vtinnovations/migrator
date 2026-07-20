<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class VtinnovationsMigratorBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    /**
     * Mirror the standalone recovery panel into the project's web root on every boot (idempotent
     * md5 compare). Shipping it as a real file in public/ is what makes it work when the Contao
     * backend itself is down — it is reachable without Contao routing or the backend firewall.
     * Failures here must never break the kernel, so everything is best-effort.
     */
    public function boot(): void
    {
        parent::boot();

        try {
            $projectDir = (string) $this->container->getParameter('kernel.project_dir');
            $webRoot = is_dir($projectDir.'/public') ? $projectDir.'/public' : (is_dir($projectDir.'/web') ? $projectDir.'/web' : null);

            if (null === $webRoot) {
                return;
            }

            $source = $this->getPath().'/src/Resources/recovery/_tcmig-recovery.php';
            $target = $webRoot.'/_tcmig-recovery.php';

            if (!is_file($source)) {
                return;
            }

            if (is_file($target) && md5_file($target) === md5_file($source)) {
                return;
            }

            @copy($source, $target);
        } catch (\Throwable) {
            // best-effort; recovery panel is a convenience, never a boot dependency
        }
    }
}
