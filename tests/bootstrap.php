<?php

declare(strict_types=1);

$craftBootstrap = dirname(__DIR__, 3) . '/bootstrap.php';

if (is_file($craftBootstrap)) {
    require $craftBootstrap;
    require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
} else {
    require dirname(__DIR__) . '/vendor/autoload.php';
}
