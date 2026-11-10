<?php

declare(strict_types=1);

namespace SimpleSAML\Module\loginaudit\Controller;

use SimpleSAML\Configuration;
use SimpleSAML\Module\loginaudit\LogRotator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Webcron endpoint: /module.php/loginaudit/rotate?token=...
 */
class Rotate
{
    public function main(Request $request): Response
    {
        $config = Configuration::getInstance();
        $moduleConfig = Configuration::getOptionalConfig('module_loginaudit.php');

        $token = (string) $moduleConfig->getOptionalString('rotate_token', '');
        if ($token === '') {
            return new Response('Log rotation endpoint is disabled.', 404, ['Content-Type' => 'text/plain']);
        }
        if (!hash_equals($token, $request->query->getString('token'))) {
            return new Response('Forbidden', 403, ['Content-Type' => 'text/plain']);
        }

        $logDir = rtrim(
            $moduleConfig->getOptionalString('logdir', null)
                ?? $config->getPathValue('loggingdir', sys_get_temp_dir()),
            '/',
        );
        $logFile = $moduleConfig->getOptionalString('logfile', null)
            ?? $config->getOptionalString('logging.logfile', 'simplesamlphp.log');
        $days = max(1, $moduleConfig->getOptionalInteger('retention_days', 120));

        $result = (new LogRotator($logDir, $logFile, $days))->run();

        $body = 'Rotated: ' . ($result['rotated'] ?? 'nothing to rotate') . "\n"
            . 'Pruned: ' . count($result['pruned']) . "\n";

        return new Response($body, 200, ['Content-Type' => 'text/plain']);
    }
}
