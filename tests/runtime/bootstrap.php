<?php

declare(strict_types=1);

$nextcloudRoot = getenv('NEXTCLOUD_ROOT');
if (!$nextcloudRoot || !is_file($nextcloudRoot . '/lib/composer/autoload.php')) {
	throw new RuntimeException('Set NEXTCLOUD_ROOT to an extracted Nextcloud server release.');
}

require_once $nextcloudRoot . '/3rdparty/autoload.php';
$loader = require $nextcloudRoot . '/lib/composer/autoload.php';
$loader->addPsr4('OCA\\RiotChat\\', dirname(__DIR__, 2) . '/lib');
require_once $nextcloudRoot . '/lib/OC.php';
