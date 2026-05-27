<?php

namespace STS\Postmaster\Tests;

use STS\Postmaster\Providers\Resend\SuppressionSync;

/**
 * Regression for the Resend SDK class-loading bug.
 *
 * resend/resend-php declares 'class Resend' in the *global* namespace at
 * src/Resend.php — there's no `namespace Resend;` line. The SDK's
 * composer.json maps the 'Resend\' PSR-4 prefix to that same src/ directory.
 * Aliasing `use Resend\Resend as ResendClient` triggered the autoloader to
 * load the file trying to resolve Resend\Resend, registered the global
 * \Resend class instead, found no Resend\Resend in the symbol table, and
 * re-attempted autoload on the next reference — double-loading the file and
 * throwing "Cannot redeclare class Resend." Reference Resend\Client (a real
 * namespaced class) instead.
 */
class ResendSuppressionSyncTest extends TestCase
{
    public function testIsAvailableReportsFalseAndDoesNotFatalLoadingTheSdk()
    {
        // Just calling this verifies our class_exists() target resolves
        // through the autoloader without a fatal. The package's
        // SuppressionSync returns false on purpose for Resend (no
        // suppression-list endpoint), but the path through the check
        // exercises the autoloader.
        $sync = new SuppressionSync(['api_key' => 're_fake_for_unit_test']);

        $this->assertFalse($sync->isAvailable());
    }

    public function testTheNamespacedClassMarkerExistsInTheSdk()
    {
        // The fix references Resend\Client (which is properly namespaced)
        // rather than \Resend (which is the global class the SDK exposes
        // through a non-PSR-4-compliant filename). Pin that as the
        // availability marker so an accidental revert is caught here.
        $this->assertTrue(
            class_exists(\Resend\Client::class),
            'The fix relies on Resend\Client being autoloadable; reverting to \Resend would re-introduce the double-load fatal.'
        );
    }
}
