<?php
namespace Deploy;

env('symfony_env', function () {
    return getTargetStage();
});

function getLocalSymfonyEnv()
{
    return getLocalStage();
}

function getSymfonyBaseCmd()
{
    return 'cd {{release_path}} && {{php_bin}} ./app/console';
}

function clearLocalCache() {
    clearCache(true);
}

function clearRemoteCache() {
    clearCache(false);
}

function clearCache($locally = false) {
    $cmd = "cd {{release_path}} && rm -rf app/cache/*";
    if ($locally) {
        runLocallyCustomized($cmd);
    } else {
        runSmartly($cmd);
    }
}

task('symfony:cache:clear', function() {
    clearCache(getTargetStage() == 'dev');
})->desc('Clear Symfony cache');

task('symfony:doctrine:migrations:status', function() {
    $cmd = sprintf('%s doctrine:migrations:status', getSymfonyBaseCmd());
    $result = runSmartly($cmd);
    writeln($result->getOutput());
})->desc('Show Doctrine migration status');

task('symfony:doctrine:migrations:migrate', function() {
    $cmd = sprintf('%s doctrine:migrations:migrate', getSymfonyBaseCmd());
    $result = runSmartly($cmd);
    writeln($result->getOutput());
})->desc('Run Doctrine migrate command');

function compress($uncompressedFilePath) {
    $pathinfo =  pathinfo($uncompressedFilePath);
    $compressedFileName = $pathinfo['basename'] . '.gz';
    runLocallyCustomized(sprintf("cd %s && gzip -c %s > %s", $pathinfo['dirname'], $pathinfo['basename'], $compressedFileName));
    return $pathinfo['dirname'] . '/' . $compressedFileName;
}

function uncompress($compressedFilePath) {
    $pathinfo =  pathinfo($compressedFilePath);
    $uncompressedFileName = str_replace('.gz', '', $pathinfo['basename']);
    runLocallyCustomized(sprintf("cd %s && gunzip -c %s > %s", $pathinfo['dirname'], $pathinfo['basename'], $uncompressedFileName));
    return $pathinfo['dirname'] . '/' . $uncompressedFileName;
}


