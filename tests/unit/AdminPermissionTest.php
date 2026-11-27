<?php

declare(strict_types=1);

namespace GitDeploy\Tests;

use GitDeploy\AdminApi;

/**
 * DirectAdmin runs admin pages as the logged-in admin's own account; install.sh gives data/
 * to the main admin account. The admin page must say which of the two fixes applies.
 */
final class AdminPermissionTest extends TestCase
{
    public function testWritableNeedsNoFix(): void
    {
        self::assertNull(AdminApi::permissionFix(true, 'admin', 'admin'));
        self::assertNull(AdminApi::permissionFix(true, 'admin2', 'admin'));
    }

    public function testInstallScriptFixesSystemOwnersAndOwnAccount(): void
    {
        // A data/ from before the fix, still given to diradmin: re-running install.sh hands it over.
        self::assertSame('install', AdminApi::permissionFix(false, 'admin', 'diradmin'));
        self::assertSame('install', AdminApi::permissionFix(false, 'admin', 'root'));
        // Missing data/, or this account owns it but the modes are wrong.
        self::assertSame('install', AdminApi::permissionFix(false, 'admin', null));
        self::assertSame('install', AdminApi::permissionFix(false, 'admin', 'admin'));
    }

    public function testOtherAdminAccountsAreReadOnly(): void
    {
        self::assertSame('main_admin', AdminApi::permissionFix(false, 'admin2', 'admin'));
    }
}
