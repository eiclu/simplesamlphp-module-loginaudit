<?php

declare(strict_types=1);

namespace SimpleSAML\Module\loginaudit\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SimpleSAML\Module\loginaudit\AuditLog;
use SimpleSAML\Module\loginaudit\Tests\Support\HttpClient;
use SimpleSAML\Module\loginaudit\Tests\Support\TestEnvironment;

/**
 * Starts a SimpleSAMLphp test installation once per test class and offers a browser-style
 * SAML login against it.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected static TestEnvironment $env;


    /** 'idp' or 'sp': where the loginaudit filter is attached. */
    abstract protected static function mode(): string;


    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('openssl') || !extension_loaded('curl')) {
            self::markTestSkipped('The openssl and curl extensions are required.');
        }
        self::$env = TestEnvironment::start(static::mode());
    }


    public static function tearDownAfterClass(): void
    {
        self::$env->stop();
    }


    protected function setUp(): void
    {
        self::$env->resetLogs();
    }


    /**
     * Runs a full SP-initiated SAML login: SP -> IdP -> login form -> IdP response -> SP.
     *
     * @return bool Whether the SP accepted the login and sent the browser back to ReturnTo.
     */
    protected function login(string $user, string $password): bool
    {
        $base = self::$env->baseUrl;
        $returnTo = $base . 'index.php';
        $client = new HttpClient();

        $response = $client->get($base . 'module.php/saml/sp/login/test-sp?' . http_build_query(['ReturnTo' => $returnTo]));
        $credentialsSent = false;

        for ($step = 0; $step < 12; $step++) {
            if ($response['location'] !== null) {
                if (str_starts_with($response['location'], $returnTo)) {
                    return true;
                }
                $response = $client->get($response['location']);
                continue;
            }

            $form = HttpClient::form($response);
            self::assertNotNull($form, "Unexpected page at {$response['url']} (status {$response['status']}):\n" . $response['body']);

            if (isset($form['fields']['SAMLResponse'])) {
                $response = $client->submitForm($response);
            } elseif (isset($form['fields']['password']) && !$credentialsSent) {
                $credentialsSent = true;
                $response = $client->submitForm($response, ['username' => $user, 'password' => $password]);
            } else {
                return false; // login form shown again, i.e. wrong credentials
            }
        }

        self::fail('The login flow did not finish within 12 steps.');
    }


    /**
     * @return list<array{date: string, level: string, track: string, user: string, name: string, email: string, idp: string, ip: string, file: string}>
     */
    protected function auditEntries(): array
    {
        return (new AuditLog(self::$env->logDir, TestEnvironment::LOG_FILE))->entries();
    }


    /**
     * @param array<string, string> $query
     * @return array{status: int, location: ?string, body: string, url: string}
     */
    protected function rotate(string $token = 'test-token'): array
    {
        return (new HttpClient())->get(self::$env->baseUrl . 'module.php/loginaudit/rotate?' . http_build_query(['token' => $token]));
    }
}
