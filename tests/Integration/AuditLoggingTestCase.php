<?php

declare(strict_types=1);

namespace SimpleSAML\Module\loginaudit\Tests\Integration;

use SimpleSAML\Module\loginaudit\Tests\Support\HttpClient;
use SimpleSAML\Module\loginaudit\Tests\Support\TestEnvironment;

/**
 * Log writing: a real SAML login against SimpleSAMLphp must produce an AUDIT-LOGIN line.
 */
abstract class AuditLoggingTestCase extends IntegrationTestCase
{
    public function testSuccessfulLoginIsLogged(): void
    {
        self::assertTrue($this->login(TestEnvironment::USER, TestEnvironment::PASSWORD));

        $entries = $this->auditEntries();
        self::assertCount(1, $entries);
        self::assertSame(TestEnvironment::USER, $entries[0]['user']);
        self::assertSame(TestEnvironment::NAME, $entries[0]['name']);
        self::assertSame(TestEnvironment::EMAIL, $entries[0]['email']);
        self::assertSame(self::$env->idpEntityId, $entries[0]['idp']);
        self::assertSame('127.0.0.1', $entries[0]['ip']);
        self::assertSame('NOTICE', $entries[0]['level']);
        self::assertSame(TestEnvironment::LOG_FILE, $entries[0]['file']);
    }


    public function testFailedLoginIsNotLogged(): void
    {
        self::assertFalse($this->login(TestEnvironment::USER, 'wrong-password'));

        self::assertSame([], $this->auditEntries());
    }


    public function testEveryLoginIsLogged(): void
    {
        self::assertTrue($this->login(TestEnvironment::USER, TestEnvironment::PASSWORD));
        self::assertTrue($this->login(TestEnvironment::USER, TestEnvironment::PASSWORD));

        self::assertCount(2, $this->auditEntries());
    }


    public function testLoginsAreShownInTheAdminTab(): void
    {
        self::assertTrue($this->login(TestEnvironment::USER, TestEnvironment::PASSWORD));

        $client = new HttpClient();
        $response = $client->get(self::$env->baseUrl . 'module.php/loginaudit/');
        for ($i = 0; $i < 6 && $response['location'] !== null; $i++) {
            $response = $client->get($response['location']);
        }

        // Admin login form, then back to the audit tab.
        $response = $client->submitForm($response, ['password' => TestEnvironment::ADMIN_PASSWORD]);
        for ($i = 0; $i < 6 && $response['location'] !== null; $i++) {
            $response = $client->get($response['location']);
        }

        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString(TestEnvironment::EMAIL, $response['body']);
        self::assertStringContainsString(TestEnvironment::NAME, $response['body']);
    }
}
