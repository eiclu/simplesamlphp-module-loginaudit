<?php

declare(strict_types=1);

use SimpleSAML\Module;
use SimpleSAML\XHTML\Template;

/**
 * Adds a "Login audit" tab to the admin menu, right before "Log out".
 */
function loginaudit_hook_adminmenu(Template &$template): void
{
    if (!isset($template->data['menu']) || !is_array($template->data['menu'])) {
        return;
    }

    $logout = $template->data['menu']['logout'] ?? null;
    unset($template->data['menu']['logout']);

    $template->data['menu']['loginaudit'] = [
        'url' => Module::getModuleURL('loginaudit/'),
        'name' => 'Login audit',
    ];

    if ($logout !== null) {
        $template->data['menu']['logout'] = $logout;
    }
}
