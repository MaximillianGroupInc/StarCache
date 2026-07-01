<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/StarAssetMinifier.php';
require_once __DIR__ . '/StarPluginLifecycle.php';

\StarCache\StarPluginLifecycle::uninstall();
