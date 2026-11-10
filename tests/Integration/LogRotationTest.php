<?php

declare(strict_types=1);

namespace SimpleSAML\Module\loginaudit\Tests\Integration;

use SimpleSAML\Module\loginaudit\Tests\Support\HttpClient;
use SimpleSAML\Module\loginaudit\Tests\Support\TestEnvironment;

/**
 * Log rotation through the webcron endpoint, on logs written by real logins.
 */
final class LogRotationTest extends IntegrationTestCase
{
    protected static function mode(): string
    {
        return 'idp';
    }


    private function archive(): string
    {
        return self::$env->logDir . '/simplesamlphp-' . date('Y-m-d') . '.log';
    }


    private function auditLinesIn(string $file): int
    {
        return substr_count((string) @file_get_contents($file), 'AUDIT-LOGIN:');
    }


    public function testRotationMovesTheLogIntoTodaysArchive(): void
    {
        self::assertTrue($this->login(TestEnvironment::USER, TestEnvironment::PASSWORD));
        self::assertSame(1, $this->auditLinesIn(self::$env->logPath()));

        $response = $this->rotate();

        self::assertSame(200, $response['status']);
        self::assertStringContainsString('Rotated: simplesamlphp-' . date('Y-m-d') . '.log', $response['body']);
        self::assertSame(0, $this->auditLinesIn(self::$env->logPath()), 'active log must be truncated');
        self::assertSame(1, $this->auditLinesIn($this->archive()));
    }


    public function testLoginsStayVisibleAcrossRotationAndNewLoginsGoToTheActiveLog(): void
    {
        self::assertTrue($this->login(TestEnvironment::USER, TestEnvironment::PASSWORD));
        $this->rotate();
        self::assertTrue($this->login(TestEnvironment::USER, TestEnvironment::PASSWORD));

        $entries = $this->auditEntries();
        self::assertCount(2, $entries);
        self::assertSame(
            [TestEnvironment::LOG_FILE, 'simplesamlphp-' . date('Y-m-d') . '.log'],
            array_column($entries, 'file'),
            'newest first: active log, then the archive',
        );
    }


    public function testRotatingTwiceInOneDayAppendsInsteadOfOverwriting(): void
    {
        self::assertTrue($this->login(TestEnvironment::USER, TestEnvironment::PASSWORD));
        $this->rotate();
        self::assertTrue($this->login(TestEnvironment::USER, TestEnvironment::PASSWORD));
        $this->rotate();

        self::assertSame(2, $this->auditLinesIn($this->archive()));
        self::assertCount(1, glob(self::$env->logDir . '/simplesamlphp-*.log'));
    }


    public function testRotatingAnEmptyLogDoesNothing(): void
    {
        $response = $this->rotate();

        self::assertSame(200, $response['status']);
        self::assertSame(0, $this->auditLinesIn($this->archive()));
        self::assertFileDoesNotExist($this->archive() . '.tmp');
    }


    public function testArchivesOlderThanTheRetentionPeriodArePruned(): void
    {
        $dir = self::$env->logDir;
        $old = $dir . '/simplesamlphp-2000-01-01.log';
        $edge = $dir . '/simplesamlphp-edge.log';
        $recent = $dir . '/simplesamlphp-recent.log';
        $unrelated = $dir . '/other-application.log';
        foreach ([$old, $edge, $recent, $unrelated] as $file) {
            file_put_contents($file, "x\n");
        }
        touch($old, time() - 200 * 86400);
        touch($edge, time() - 121 * 86400);
        touch($recent, time() - 119 * 86400);
        touch($unrelated, time() - 500 * 86400);

        $response = $this->rotate();

        self::assertSame(200, $response['status']);
        self::assertFileDoesNotExist($old);
        self::assertFileDoesNotExist($edge);
        self::assertFileExists($recent);
        self::assertFileExists($unrelated, 'files that are not simplesamlphp-*.log archives are never touched');
    }


    public function testWrongTokenIsForbiddenAndChangesNothing(): void
    {
        self::assertTrue($this->login(TestEnvironment::USER, TestEnvironment::PASSWORD));

        self::assertSame(403, $this->rotate('wrong')['status']);
        self::assertSame(403, $this->rotate('')['status']);
        self::assertSame(1, $this->auditLinesIn(self::$env->logPath()));
        self::assertFileDoesNotExist($this->archive());
    }


    public function testEndpointIsDisabledWithoutAConfiguredToken(): void
    {
        $env = TestEnvironment::start('idp', '');
        try {
            $client = new HttpClient();

            self::assertSame(404, $client->get($env->baseUrl . 'module.php/loginaudit/rotate?token=')['status']);
            self::assertSame(404, $client->get($env->baseUrl . 'module.php/loginaudit/rotate')['status']);
        } finally {
            $env->stop();
        }
    }
}
