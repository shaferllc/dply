<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Deploy\RuntimeDetection;

use App\Services\Deploy\RuntimeDetection\GitCloneException;
use App\Services\Deploy\RuntimeDetection\ProcessGitCloner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class ProcessGitClonerTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = sys_get_temp_dir().'/dply-process-git-cloner-'.uniqid();
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->workDir);
        parent::tearDown();
    }

    public function test_throws_when_url_is_empty(): void
    {
        $this->expectException(GitCloneException::class);
        $this->expectExceptionMessage('Repository URL is required.');

        (new ProcessGitCloner)->shallowClone('', 'main', $this->workDir.'/dest');
    }

    public function test_throws_when_branch_is_empty(): void
    {
        $this->expectException(GitCloneException::class);
        $this->expectExceptionMessage('Branch is required.');

        (new ProcessGitCloner)->shallowClone('https://example.com/x.git', '', $this->workDir.'/dest');
    }

    public function test_clones_local_bare_repo(): void
    {
        $bare = $this->makeLocalBareRepo();
        $dest = $this->workDir.'/dest';

        (new ProcessGitCloner)->shallowClone($bare, 'main', $dest);

        $this->assertDirectoryExists($dest.'/.git');
        $this->assertFileExists($dest.'/README.md');
    }

    public function test_throws_with_nonexistent_branch(): void
    {
        $bare = $this->makeLocalBareRepo();
        $dest = $this->workDir.'/dest';

        $this->expectException(GitCloneException::class);
        $this->expectExceptionMessageMatches('/branch nonexistent/');

        (new ProcessGitCloner)->shallowClone($bare, 'nonexistent', $dest);
    }

    public function test_redacts_credentials_from_error_message(): void
    {
        $dest = $this->workDir.'/dest';
        $cloner = new ProcessGitCloner;

        try {
            $cloner->shallowClone('https://user:s3cret-token@127.0.0.1:1/x.git', 'main', $dest);
            $this->fail('expected GitCloneException');
        } catch (GitCloneException $e) {
            $this->assertStringNotContainsString('s3cret-token', $e->getMessage());
            $this->assertStringNotContainsString('user:', $e->getMessage());
        }
    }

    /**
     * Build a bare local git repository with a single `main` branch and a
     * README, so we can test cloning without hitting the network.
     */
    private function makeLocalBareRepo(): string
    {
        $work = $this->workDir.'/source-work';
        $bare = $this->workDir.'/source.git';
        mkdir($work);

        $this->git(['git', 'init', '-q', '-b', 'main', $work]);
        // Local-only commit author so this works on machines without git config.
        $this->git(['git', '-C', $work, 'config', 'user.email', 'test@example.com']);
        $this->git(['git', '-C', $work, 'config', 'user.name', 'test']);
        file_put_contents($work.'/README.md', "# test\n");
        $this->git(['git', '-C', $work, 'add', '.']);
        $this->git(['git', '-C', $work, 'commit', '-q', '-m', 'init']);
        $this->git(['git', 'clone', '-q', '--bare', $work, $bare]);

        return $bare;
    }

    /**
     * @param  list<string>  $command
     */
    private function git(array $command): void
    {
        $process = new Process($command);
        $process->setTimeout(30);
        $process->mustRun();
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
