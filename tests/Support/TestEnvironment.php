<?php

declare(strict_types=1);

namespace SimpleSAML\Module\loginaudit\Tests\Support;

use RuntimeException;
use SimpleSAML\Configuration;

/**
 * A throwaway SimpleSAMLphp installation, served by PHP's built-in web server, that acts as
 * both a test SAML IdP and a test SAML SP with the loginaudit authproc filter attached.
 */
final class TestEnvironment
{
    public const USER = 'alice';
    public const PASSWORD = 'secret';
    public const NAME = 'Alice Test';
    public const EMAIL = 'alice@example.org';
    public const ADMIN_PASSWORD = 'admin-secret';
    public const LOG_FILE = 'simplesamlphp.log';

    public readonly string $baseUrl;
    public readonly string $idpEntityId;
    public readonly string $logDir;

    /** @var resource */
    private $process;
    private string $root;


    /**
     * @param string $mode 'idp' attaches the filter to the IdP, 'sp' to the SP.
     */
    public static function start(string $mode, string $rotateToken = 'test-token'): self
    {
        return new self($mode, $rotateToken);
    }


    private function __construct(string $mode, string $rotateToken)
    {
        $port = self::freePort();
        $this->baseUrl = "http://127.0.0.1:$port/";
        $this->idpEntityId = $this->baseUrl . 'test-idp';
        $spEntityId = $this->baseUrl . 'test-sp';

        $this->root = sys_get_temp_dir() . '/loginaudit-it-' . bin2hex(random_bytes(4));
        $this->logDir = $this->root . '/log';
        foreach (['config', 'metadata', 'cert', 'log', 'tmp', 'data'] as $dir) {
            mkdir($this->root . '/' . $dir, 0777, true);
        }

        $sspDir = self::sspDir();
        self::linkModule($sspDir);

        $certData = $this->generateCertificate();
        $this->writeConfig($mode, $rotateToken, $spEntityId, $certData);

        $this->process = $this->startServer($port, $sspDir);
        $this->waitUntilReady($port);
    }


    public function __destruct()
    {
        $this->stop();
    }


    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
        if (isset($this->root) && is_dir($this->root)) {
            self::removeDir($this->root);
        }
    }


    public function logPath(): string
    {
        return $this->logDir . '/' . self::LOG_FILE;
    }


    /**
     * Empties the log directory between tests.
     */
    public function resetLogs(): void
    {
        foreach (glob($this->logDir . '/*') ?: [] as $file) {
            unlink($file);
        }
    }


    private static function sspDir(): string
    {
        $file = (new \ReflectionClass(Configuration::class))->getFileName();
        // <ssp>/src/SimpleSAML/Configuration.php
        return dirname($file, 3);
    }


    private static function linkModule(string $sspDir): void
    {
        $link = $sspDir . '/modules/loginaudit';
        $target = dirname(__DIR__, 2);
        if (is_link($link) && realpath($link) === realpath($target)) {
            return;
        }
        if (file_exists($link) || is_link($link)) {
            throw new RuntimeException("$link exists and is not a link to this module");
        }
        if (!symlink($target, $link)) {
            throw new RuntimeException("Could not link $link to the module");
        }
    }


    private function generateCertificate(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'loginaudit-test-idp'], $key);
        $cert = openssl_csr_sign($csr, null, $key, 2);

        openssl_pkey_export($key, $keyPem);
        openssl_x509_export($cert, $certPem);
        file_put_contents($this->root . '/cert/idp.key', $keyPem);
        file_put_contents($this->root . '/cert/idp.crt', $certPem);

        return preg_replace('/-----[A-Z ]+-----|\s+/', '', $certPem);
    }


    private function writeConfig(string $mode, string $rotateToken, string $spEntityId, string $certData): void
    {
        $filter = var_export([90 => ['class' => 'loginaudit:Audit']], true);
        $idpAuthproc = $mode === 'idp' ? $filter : '[]';
        $spAuthproc = $mode === 'sp' ? $filter : '[]';
        $r = var_export($this->root, true);
        $base = var_export($this->baseUrl, true);
        $idp = var_export($this->idpEntityId, true);
        $sp = var_export($spEntityId, true);
        $user = var_export(self::USER . ':' . self::PASSWORD, true);
        $attributes = var_export([
            'uid' => [self::USER],
            'displayName' => [self::NAME],
            'mail' => [self::EMAIL],
        ], true);

        file_put_contents($this->root . '/config/config.php', <<<PHP
        <?php
        \$config = [
            'baseurlpath' => $base,
            'certdir' => $r . '/cert/',
            'loggingdir' => $r . '/log/',
            'tempdir' => $r . '/tmp/',
            'datadir' => $r . '/data/',
            'secretsalt' => 'integration-test-salt',
            'auth.adminpassword' => 'unused',
            'technicalcontact_email' => 'test@example.org',
            'enable.saml20-idp' => true,
            'module.enable' => ['exampleauth' => true, 'saml' => true, 'core' => true, 'admin' => true, 'loginaudit' => true],
            'logging.level' => \SimpleSAML\Logger::NOTICE, // the default: filter output must survive it
            'logging.handler' => 'file',
            'logging.logfile' => 'simplesamlphp.log',
            'session.cookie.samesite' => null,
            'metadata.sources' => [['type' => 'flatfile', 'directory' => $r . '/metadata']],
        ];
        PHP);

        file_put_contents($this->root . '/config/authsources.php', <<<PHP
        <?php
        \$config = [
            'admin' => ['core:AdminPassword'],
            'test-users' => [
                'exampleauth:UserPass',
                'users' => [$user => $attributes],
            ],
            'test-sp' => [
                'saml:SP',
                'entityID' => $sp,
                'idp' => $idp,
                'authproc' => $spAuthproc,
            ],
        ];
        PHP);

        file_put_contents($this->root . '/config/module_loginaudit.php', sprintf(
            "<?php\n\$config = ['geo_lookup' => false, 'rotate_token' => %s, 'retention_days' => 120];\n",
            var_export($rotateToken, true),
        ));

        file_put_contents($this->root . '/metadata/saml20-idp-hosted.php', <<<PHP
        <?php
        \$metadata[$idp] = [
            'host' => '__DEFAULT__',
            'privatekey' => 'idp.key',
            'certificate' => 'idp.crt',
            'auth' => 'test-users',
            'authproc' => $idpAuthproc,
        ];
        PHP);

        file_put_contents($this->root . '/metadata/saml20-idp-remote.php', <<<PHP
        <?php
        \$metadata[$idp] = [
            'entityid' => $idp,
            'SingleSignOnService' => [[
                'Binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                'Location' => {$base} . 'module.php/saml/idp/singleSignOnService',
            ]],
            'certData' => '$certData',
        ];
        PHP);

        file_put_contents($this->root . '/metadata/saml20-sp-remote.php', <<<PHP
        <?php
        \$metadata[$sp] = [
            'entityid' => $sp,
            'AssertionConsumerService' => [[
                'index' => 0,
                'Binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                'Location' => {$base} . 'module.php/saml/sp/saml2-acs.php/test-sp',
            ]],
        ];
        PHP);

        // The admin password is hashed by the admin source; set it through the config instead.
        $config = file_get_contents($this->root . '/config/config.php');
        file_put_contents(
            $this->root . '/config/config.php',
            str_replace("'unused'", var_export(self::ADMIN_PASSWORD, true), $config),
        );
    }


    /**
     * @return resource
     */
    private function startServer(int $port, string $sspDir)
    {
        $env = array_merge(getenv(), ['SIMPLESAMLPHP_CONFIG_DIR' => $this->root . '/config']);
        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $sspDir . '/public'],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $this->root . '/server.out', 'w'],
                2 => ['file', $this->root . '/server.out', 'w'],
            ],
            $pipes,
            $sspDir,
            $env,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the PHP built-in web server');
        }

        return $process;
    }


    private function waitUntilReady(int $port): void
    {
        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(100_000);
        }

        throw new RuntimeException('The test web server did not start: ' . @file_get_contents($this->root . '/server.out'));
    }


    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException("Could not find a free port: $errstr");
        }
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    }


    private static function removeDir(string $dir): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
