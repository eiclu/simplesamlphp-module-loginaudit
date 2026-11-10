<?php

declare(strict_types=1);

namespace SimpleSAML\Module\loginaudit\Controller;

use SimpleSAML\Configuration;
use SimpleSAML\Locale\Translate;
use SimpleSAML\Module\admin\Controller\Menu;
use SimpleSAML\Module\loginaudit\AuditLog;
use SimpleSAML\Module\loginaudit\IpLocator;
use SimpleSAML\Utils;
use SimpleSAML\XHTML\Template;
use Symfony\Component\HttpFoundation\Request;

/**
 * The "Login audit" tab in the admin interface.
 */
class Audit
{
    public function main(Request $request): Template
    {
        $config = Configuration::getInstance();

        $authUtils = new Utils\Auth();
        $authUtils->requireAdmin();

        $moduleConfig = Configuration::getOptionalConfig('module_loginaudit.php');

        $logDir = rtrim(
            $moduleConfig->getOptionalString('logdir', null)
                ?? $config->getPathValue('loggingdir', sys_get_temp_dir()),
            '/',
        );
        $logFile = $moduleConfig->getOptionalString('logfile', null)
            ?? $config->getOptionalString('logging.logfile', 'simplesamlphp.log');
        $cacheDb = $moduleConfig->getOptionalString('cache_db', null)
            ?? rtrim($config->getPathValue('tempdir', sys_get_temp_dir()), '/') . '/loginaudit_ipcache.sqlite';
        $pageSize = max(1, $moduleConfig->getOptionalInteger('page_size', 50));

        $log = new AuditLog($logDir, $logFile);
        $files = $log->files();
        $entries = $log->entries();
        $totalAll = count($entries);

        // Search across all visible fields
        $query = trim($request->query->getString('q'));
        if ($query !== '') {
            $entries = array_values(array_filter($entries, function (array $row) use ($query): bool {
                $haystack = implode(' ', [$row['date'], $row['ip'], $row['name'], $row['email'], $row['user'], $row['idp']]);
                return mb_stripos($haystack, $query) !== false;
            }));
        }

        $total = count($entries);
        $pages = max(1, (int) ceil($total / $pageSize));
        $page = min($pages, max(1, $request->query->getInt('page', 1)));
        $rows = array_slice($entries, ($page - 1) * $pageSize, $pageSize);

        // Only resolve the IPs shown on this page
        $locator = new IpLocator($cacheDb, $moduleConfig->getOptionalBoolean('geo_lookup', true));
        $locations = $locator->locate(array_column($rows, 'ip'));

        foreach ($rows as &$row) {
            $geo = $locations[$row['ip']] ?? ['city' => '', 'country_code' => '', 'internal' => false];
            if ($geo['internal']) {
                $row['location'] = 'Internal network';
                $row['flag'] = '';
            } else {
                $parts = array_filter([$geo['city'], $geo['country_code']]);
                $row['location'] = $parts !== [] ? implode(', ', $parts) : 'Unknown';
                $row['flag'] = IpLocator::flag($geo['country_code']);
            }
            $row['idp_host'] = parse_url($row['idp'], PHP_URL_HOST) ?: $row['idp'];
        }
        unset($row);

        $t = new Template($config, 'loginaudit:audit.twig');
        $t->addTemplatesFromModule('admin');
        $t->data = [
            'rows' => $rows,
            'q' => $query,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'total_all' => $totalAll,
            'file_count' => count($files),
            'log_dir' => $logDir,
            'log_missing' => $files === [],
            'logouturl' => $authUtils->getAdminLogoutURL(),
        ];

        $menu = new Menu();
        $menu->addOption('logout', $t->data['logouturl'], Translate::noop('Log out'));

        return $menu->insert($t);
    }
}
