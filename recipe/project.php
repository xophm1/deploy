<?php
namespace Deploy;

task('project:display_info', function () {
    $stage = getTargetStage();

    writeln(sprintf('Starting project build on stage <fg=yellow;options=bold>%s</>',
        $stage
    ));
})->desc('Display project build info')
    ->setPrivate();

task('project:writable', function () {
    $dirs = join(' ', getWriteableDirs());
    // For the meaning of "|| :" see http://unix.stackexchange.com/questions/118217/chmod-silent-mode-how-force-exit-code-0-in-spite-of-error
    runSmartly(sprintf('cd {{release_path}} && find -H %s -type d -print0 2>&1 | xargs -0 chmod -f 775 || :', $dirs));
})->desc('Make some directories writable')
    ->setPrivate();

/**
 * Create symlinks for shared directories and files.
 */
task('project:symlink_shared', function () {
    $sharedPath = "{{deploy_path}}/shared";

    foreach (get('shared_dirs') as $dir) {
        // Create shared dir if it does not exist.
        runSmartly("mkdir -p $sharedPath/$dir");

        // Rsync shared paths with the versioned directory tree then remove the versioned directory
        runSmartly("if [ -d $(echo {{release_path}}/$dir) ]; then rm -rf {{release_path}}/$dir; fi");

        // Create path to shared dir in release dir if it does not exist.
        // (symlink will not create the path and will fail otherwise)
        runSmartly("mkdir -p `dirname {{release_path}}/$dir`");

        // Symlink shared dir to release dir
        runSmartly("ln -nfs $sharedPath/$dir {{release_path}}/$dir");
    }

    foreach (get('shared_files') as $file) {
        $dirname = dirname($file);
        // Remove from source.
        runSmartly("if [ -f $(echo {{release_path}}/$file) ]; then rm -rf {{release_path}}/$file; fi");
        // Ensure dir is available in release
        runSmartly("if [ ! -d $(echo {{release_path}}/$dirname) ]; then mkdir -p {{release_path}}/$dirname;fi");

        // Create dir of shared file
        runSmartly("mkdir -p $sharedPath/" . $dirname);

        // Create a copy of the dist file if the shared file does not exist
        runSmartly("if [ ! -f $(echo $sharedPath/$file) ]; then cp {{release_path}}/$file-dist $sharedPath/$file; fi");

        // Symlink shared dir to release dir
        runSmartly("ln -nfs $sharedPath/$file {{release_path}}/$file");
    }

})->desc('Creating symlinks for shared files')
    ->setPrivate();
