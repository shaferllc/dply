<?php

namespace Tests\Unit\Support;

use App\Support\GitRemoteRepositoryRef;
use PHPUnit\Framework\TestCase;

class GitRemoteRepositoryRefTest extends TestCase
{
    public function test_parses_github_ssh(): void
    {
        $ref = GitRemoteRepositoryRef::parse('git@github.com:acme/app.git', 'github');
        $this->assertNotNull($ref);
        $this->assertSame('github', $ref->provider);
        $this->assertSame('acme', $ref->owner);
        $this->assertSame('app', $ref->repo);
    }

    public function test_parses_gitlab_https(): void
    {
        $ref = GitRemoteRepositoryRef::parse('https://gitlab.com/group/sub/project.git', 'gitlab');
        $this->assertNotNull($ref);
        $this->assertSame('gitlab', $ref->provider);
        $this->assertSame('group/sub/project', $ref->gitlabProjectPath);
    }

    public function test_parses_github_https_strips_git_suffix(): void
    {
        $ref = GitRemoteRepositoryRef::parse('https://github.com/acme/demo.git', 'github');
        $this->assertNotNull($ref);
        $this->assertSame('acme', $ref->owner);
        $this->assertSame('demo', $ref->repo);
    }
}
