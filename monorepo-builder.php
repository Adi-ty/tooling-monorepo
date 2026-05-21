<?php

declare(strict_types=1);

use Symplify\MonorepoBuilder\Config\MBConfig;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\AddTagToChangelogReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\PushNextDevReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\PushTagReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\SetCurrentMutualDependenciesReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\SetNextMutualDependenciesReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\TagVersionReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\UpdateBranchAliasReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\UpdateReplaceReleaseWorker;

return static function (MBConfig $mbConfig): void {
    $mbConfig->packageDirectories([__DIR__ . '/composer-packages']);

    $mbConfig->packageAliasFormat('<major>.<minor>-dev');

    $mbConfig->dataToAppend([
        'require-dev' => [
            'phpunit/phpunit' => '^9.6',
            'phpstan/phpstan' => '^2.0',
            'squizlabs/php_codesniffer' => '^3.10',
        ],
    ]);

    $mbConfig->workers([
        // Updates composer.json in all packages to depend on "1.0.0" of each other
        SetCurrentMutualDependenciesReleaseWorker::class,
        
        // Updates the "replace" section in the root composer.json
        UpdateReplaceReleaseWorker::class,
        
        // Add a changelog entry (if you maintain one)
        AddTagToChangelogReleaseWorker::class,
        
        // Creates the Git tag locally
        TagVersionReleaseWorker::class,
        
        // Pushes the tag to your remote (this triggers your GitHub Action to split!)
        PushTagReleaseWorker::class,
        
        // Bumps mutual dependencies to the next dev version (e.g., ^1.1@dev)
        SetNextMutualDependenciesReleaseWorker::class,
        
        // Updates branch alias
        UpdateBranchAliasReleaseWorker::class,
        
        // Pushes the dev bump commits
        PushNextDevReleaseWorker::class,
    ]);
};
