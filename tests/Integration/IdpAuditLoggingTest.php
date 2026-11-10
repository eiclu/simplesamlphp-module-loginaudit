<?php

declare(strict_types=1);

namespace SimpleSAML\Module\loginaudit\Tests\Integration;

/** The filter runs on the IdP side (authproc.idp / saml20-idp-hosted). */
final class IdpAuditLoggingTest extends AuditLoggingTestCase
{
    protected static function mode(): string
    {
        return 'idp';
    }
}
