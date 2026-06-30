<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (function_exists('wp_clear_scheduled_hook')) {
    wp_clear_scheduled_hook('starcache_build_asset');
}
