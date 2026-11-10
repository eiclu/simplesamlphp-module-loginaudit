<?php

declare(strict_types=1);

namespace SimpleSAML\Module\loginaudit\Tests\Integration;

/** The filter runs on the SP side (authproc of a saml:SP authsource). */
final class SpAuditLoggingTest extends AuditLoggingTestCase
{
    protected static function mode(): string
    {
        return 'sp';
    }
}
