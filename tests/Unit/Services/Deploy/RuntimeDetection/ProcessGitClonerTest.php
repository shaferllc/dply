<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Deploy\RuntimeDetection\ProcessGitClonerTest;
use App\Services\Deploy\RuntimeDetection\GitCloneException;
use App\Services\Deploy\RuntimeDetection\ProcessGitCloner;
use Symfony\Component\Process\Process;
beforeEach(function () {
    $this->workDir = sys_get_temp_dir().'/dply-process-git-cloner-'.uniqid();
    mkdir($this->workDir);
});
afterEach(function () {
    removeDir($this->workDir);
});
test('throws when url is empty', function () {
    $this->expectException(GitCloneException::class);
    $this->expectExceptionMessage('Repository URL is required.');

    (new ProcessGitCloner)->shallowClone('', 'main', $this->workDir.'/dest');
});
test('throws when branch is empty', function () {
    $this->expectException(GitCloneException::class);
    $this->expectExceptionMessage('Branch is required.');

    (new ProcessGitCloner)->shallowClone('https://example.com/x.git', '', $this->workDir.'/dest');
});
test('clones local bare repo', function () {
    $bare = makeLocalBareRepo();
    $dest = $this->workDir.'/dest';

    (new ProcessGitCloner)->shallowClone($bare, 'main', $dest);

    expect($dest.'/.git')->toBeDirectory();
    expect($dest.'/README.md')->toBeFile();
});
test('throws with nonexistent branch', function () {
    $bare = makeLocalBareRepo();
    $dest = $this->workDir.'/dest';

    $this->expectException(GitCloneException::class);
    $this->expectExceptionMessageMatches('/branch nonexistent/');

    (new ProcessGitCloner)->shallowClone($bare, 'nonexistent', $dest);
});
test('redacts credentials from error message', function () {
    $dest = $this->workDir.'/dest';
    $cloner = new ProcessGitCloner;

    try {
        $cloner->shallowClone('https://user:s3cret-token@127.0.0.1:1/x.git', 'main', $dest);
        $this->fail('expected GitCloneException');
    } catch (GitCloneException $e) {
        $this->assertStringNotContainsString('s3cret-token', $e->getMessage());
        $this->assertStringNotContainsString('user:', $e->getMessage());
    }
});
/**
 * Build a bare local git repository with a single `main` branch and a
 * README, so we can test cloning without hitting the network.
 */
function makeLocalBareRepo(): string
{
    $work = $this->workDir.'/source-work';
    $bare = $this->workDir.'/source.git';
    mkdir($work);

    git(['git', 'init', '-q', '-b', 'main', $work]);

    // Local-only commit author so this works on machines without git config.
    git(['git', '-C', $work, 'config', 'user.email', 'test@example.com']);
    git(['git', '-C', $work, 'config', 'user.name', 'test']);
    file_put_contents($work.'/README.md', "# test\n");
    git(['git', '-C', $work, 'add', '.']);
    git(['git', '-C', $work, 'commit', '-q', '-m', 'init']);
    git(['git', 'clone', '-q', '--bare', $work, $bare]);

    return $bare;
}
/**
 * @param  list<string>  $command
 */
function git(array $command): void
{
    $process = new Process($command);
    $process->setTimeout(30);
    $process->mustRun();
}
function removeDir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir.'/'.$entry;
        is_dir($path) ? removeDir($path) : @unlink($path);
    }
    @rmdir($dir);
}
