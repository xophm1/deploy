<?php
/**
 * The following lines must be included at the end of method getInitializer() in vendor/composer/autoload_static.php
 * This file is COPIED to vendor/ by composer post-update hook
 */
$fixSymlinkedVendorDir = function($path) {
    return preg_replace('#/shared/vendor/[^/]+/composer/../../#', '/current/', $path);
};
$loader->fallbackDirsPsr4 = array_map($fixSymlinkedVendorDir, $loader->fallbackDirsPsr4);
$loader->classMap = array_map($fixSymlinkedVendorDir, $loader->classMap);