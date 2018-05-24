<?php

require_once 'recipe/common.php';
require_once __DIR__ . '/project.php';
require_once __DIR__ . '/symfony.php';

use Symfony\Component\Console\Input\InputOption;
use Deployer\Task\Context;

option('--vendor-force', null, InputOption::VALUE_NONE, "Force rsync of vendor dir even if a directory with the right hash name exists");
option('--debug', 'd', InputOption::VALUE_NONE, "Step by step execution");
option('--files', 'F', InputOption::VALUE_OPTIONAL, "List of files to upload");
option('--use-cache', false, InputOption::VALUE_NONE, "Use cache during database copy to local ?");

set('start_time', microtime(true));
set('release_name', date('Y-m-d_H-i-s'));

/**
 * Override the default release name (YYYYMMDDHHMMSS)
 */
env('release_name', function () {
    return get('release_name');
});

/**
 * Return release path (overridden)
 */
env('release_path', function () {
    return str_replace("\n", '', run("readlink {{deploy_path}}/release || readlink {{deploy_path}}/current"));
});

/**
 * Return list of releases on server. Overriden to take into account the release name custom format
 */
env('releases_list', function () {
    $list = runCustomized('find {{deploy_path}}/releases -maxdepth 1 -mindepth 1 -type d')->toArray();

    foreach ($list as $key => $item) {
        $item = basename($item);

        if (!preg_match("/^[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{2}-[0-9]{2}-[0-9]{2}$/", $item)) {
            unset($list[$key]);
            continue;
        }

        $list[$key] = $item;
    }

    rsort($list);

    return $list;
});

env('remote_cache_path', function() {
    return "{{deploy_path}}/shared/cached-copy";
});

/**
 * Get local stage
 * @return string
 */
function getLocalStage()
{
    return 'dev';
}

/**
 * Get target stage
 * @return mixed
 */
function getTargetStage()
{
    $stage = input()->getArgument('stage');
    if (!$stage) {
        $stage = get('default_stage');
    }

    return $stage;
}

function getLocalCurrentBranch()
{
    return trim(`git branch --list | grep "^*" | sed 's/[*] //'`);
}

/**
 * Run is allowed: in debug mode (-d), ask for each command line if it must be executed
 * @param $cmd
 * @return bool
 */
function runIsAllowed($cmd, $remotely)
{
    if (!input()->getOption('debug')) {
        return true;
    }
    $response = '-';
    while (!in_array($response, array('y', 'n', 'a', ''))) {
        writeln(sprintf('Preparing to %s execute command: <comment>%s</comment>',
            $remotely ? 'remotely' : 'locally',
            $cmd
        ));
        writeln('Execute ([y]es, [n]o, [a]bort) ?  |y|');
        $response = readline();
    }
    switch ($response) {
        case 'a':
            die('Aborted');
        case 'n':
            return false;
        default:
            break;
    }

    return true;
}

/**
 * Improved version of run(). Supports step by step execution.
 * @param $cmd
 * @return \Deployer\Type\Result|null
 */
function runCustomized($cmd)
{
    if (!runIsAllowed($cmd, true)) {
        return null;
    }

    $cmd = str_replace('{{php_bin}}', '/usr/local/php7.0/bin/php', $cmd);
    return run($cmd);
}

/**
 * Improved version of runLocally. Supports step by step execution.
 * @param $cmd
 * @param null $timeout
 * @return \Deployer\Type\Result|null
 */
function runLocallyCustomized($cmd, $timeout = null)
{
    $cmd = str_replace('{{release_path}}', get('local_root_dir'), $cmd);
    $cmd = str_replace('{{deploy_path}}', get('local_root_dir'), $cmd);
    $cmd = str_replace('{{php_bin}}', 'php', $cmd);

    if (!runIsAllowed($cmd, false)) {
        return null;
    }

    return runLocally($cmd, $timeout);
}

/**
 * Choose to run remotely or locally, depending on the context of execution.
 * @param $cmd
 * @return \Deployer\Type\Result|null
 */
function runSmartly($cmd)
{
    if (getTargetStage() == 'dev') {
        return runLocallyCustomized($cmd);
    }

    return runCustomized($cmd);
}

/**
 * Remote file exists
 * @param $filePath
 * @return bool
 */
function remoteFileExists($filePath)
{
    return runCustomized(sprintf("if [ -e '%s' ]; then echo -n 'true'; else echo -n 'false'; fi", $filePath))->toBool();
}

/**
 * Get rsync command
 * @param $dirPath
 * @param $destination
 * @param $mergeFilePath
 * @return string
 */
function getRsyncCommand($dirPath, $destination, $mergeFilePath = null)
{
    $cmd = sprintf("cd %s && rsync -a%s --delete --delete-excluded ./ %s%s",
        $dirPath,
        isVerbose() ? 'v' : '',
        $destination,
        $mergeFilePath === null ? '' : sprintf(" --filter='merge %s'", $mergeFilePath)
    );

    return $cmd;
}

/**
 * Rsync a local directory to a remote one
 * @param $localDirPath
 * @param $remoteDirPath
 * @param $mergeFilePath
 */
function rsyncToRemote($localDirPath, $remoteDirPath, $mergeFilePath)
{
    $serverConfig = Context::get()->getServer()->getConfiguration();
    $destination = sprintf('%s@%s:%s', $serverConfig->getUser(), $serverConfig->getHost(), $remoteDirPath);
    runLocallyCustomized(getRsyncCommand($localDirPath, $destination, $mergeFilePath));
}

/**
 * @return string
 */
function getRevision()
{
    return trim(`git log -n 1 --pretty=format:"%H"`);
}

/**
 * @return string
 */
function getVersion()
{
    return trim(`git describe --tags`);
}

/**
 * @return string
 */
function getVersionAsSeenByApache()
{
    $cmd = sprintf('curl -L -s "http://%s/VERSION?t=%s"', env('application_domain_name'), time());
    $result = runLocallyCustomized($cmd);
    return trim($result->getOutput());
}

task('deploy:display_info', function () {
    writeln(sprintf('Starting deploy of branch <fg=yellow;options=bold>%s</> to the stage <fg=yellow;options=bold>%s</>',
        getLocalCurrentBranch(),
        getTargetStage()
    ));
})->desc('Display deploy info')
    ->setPrivate();

task('deploy:update_code', function() {
    if (isVerbose()) {
        writeln('<comment>Rsyncing local dir to remote dir</comment>');
    }

    $cachedCopyDirPath = env('deploy_path') . "/shared/cached-copy";
    rsyncToRemote("{{release_path}}", $cachedCopyDirPath, "{{release_path}}/.rsync-filter-deploy");

    if (isVerbose()) {
        writeln('<comment>Creating release from remote cache dir</comment>');
    }
    runCustomized(getRsyncCommand("{{remote_cache_path}}", "{{release_path}}"));

    $revision = getRevision();
    runCustomized(sprintf("echo %s > {{release_path}}/REVISION", $revision));
})->desc('Updating code');

/**
 * Success message
 */
task('deploy:success', function () {
    $startTime = get('start_time');
    $durationInSeconds = microtime(true) - $startTime;
    $durationInMinutes = floor($durationInSeconds / 60);
    $secondsPart = ($durationInSeconds - $durationInMinutes * 60) % 60;
    $releaseName = get('release_name');
    writeln(sprintf('<info>Successfully deployed release</info> <comment>%s</comment> <info>in %.2f min %.2f s!</info>',
        $releaseName,
        $durationInMinutes,
        $secondsPart
    ));
})
    ->once()
    ->setPrivate();

task('check:releases_count', function() {
    $releases = env('releases_list');
    $keep = get('keep_releases');

    if (count($releases) > $keep + 10) {
        writeln(sprintf(
            'There are <error>%d</> releases in ' . env('deploy_path') . '/releases', count($releases)
        ));
        writeln(sprintf('Please run <info>bin/dep cleanup</info> to keep only %d releases', $keep));
    }
})->setPrivate();

/**
 * Cleanup old releases.
 */
task('cleanup', function () {
    $releases = env('releases_list');
    $keep = get('keep_releases');
    $result = run("readlink {{deploy_path}}/develop; readlink {{deploy_path}}/master; readlink {{deploy_path}}/current");
    $releasesToKeep = explode("\n", trim($result->getOutput()));
    $releasesToKeep = array_map(function ($fullPathRelease) {
        return str_replace(env('deploy_path') . '/releases/', '', $fullPathRelease);
    }, $releasesToKeep);

    array_filter($releases, function ($release) use ($releasesToKeep) {
        return !in_array($release, $releasesToKeep);
    });

    while ($keep > 0) {
        array_shift($releases);
        --$keep;
    }

    foreach ($releases as $release) {
        run("rm -rf {{deploy_path}}/releases/$release");
    }
    run("cd {{deploy_path}} && if [ -e release ]; then rm release; fi");
    run("cd {{deploy_path}} && if [ -h release ]; then rm release; fi");
})->desc('Cleaning up old releases');

task('deploy:clear_apache_cache', function() {
    $releases = env('releases_list');
    array_shift($releases);
    $secondToLastReleasePath = '{{deploy_path}}/releases/' . $releases[0];

    $expectedVersion = getVersion();

    for ($i = 0; $i < 3; $i++) {
        writeln(sprintf("Clearing Apache cache... Attempt %d", $i + 1));
        sleep($i);
        # Rename second to last release so that apache can't find it
        runCustomized(sprintf("mv %s %s_", $secondToLastReleasePath, $secondToLastReleasePath));

        # Trigger a 404 error so that apache cache is cleared
        $cmd = sprintf('curl -IL -s "http://%s/VERSION?t=%s" | grep HTTP', env('application_domain_name'), time());
        $result = runLocallyCustomized($cmd);

        # Restore release name
        runCustomized(sprintf("mv %s_ %s", $secondToLastReleasePath, $secondToLastReleasePath));

        $versionAsSeenByApache = getVersionAsSeenByApache();

        writeln(sprintf("Local version: %s. Version as seen by apache: %s", $expectedVersion, $versionAsSeenByApache));

        if ($versionAsSeenByApache == $expectedVersion) {
            writeln("Apache cache successfully cleared !");
            return;
        }
    }
    writeln("Failed to clear Apache cache");
});

task('deploy:upload', function () {
    $filesListAsString = input()->getOption('files');
    if ($filesListAsString === null) {
        $result = runLocallyCustomized('git diff --name-only --diff-filter=ACMRTUXB');
        $files = explode("\n", trim($result->getOutput()));
    } else {
        $files = explode(",", $filesListAsString);
    }
    array_walk($files, function($relativeFilePath) {
        if (!file_exists($relativeFilePath)) {
            throw new Exception(sprintf('File not found: %s', $relativeFilePath));
        }
        $releaseDir = sprintf("{{deploy_path}}/current");
        upload($relativeFilePath, $releaseDir . '/' . $relativeFilePath);
        runCustomized(sprintf("echo %s >> %s/%s",
            $relativeFilePath,
            $releaseDir,
            'UPLOADED_FILES'
        ));
    });
})->desc('Updload individual files to remote host');