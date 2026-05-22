<?php


namespace Tests\Unit\Support\GitRemoteRepositoryRefTest;
use App\Support\GitRemoteRepositoryRef;

test('parses github ssh', function () {
    $ref = GitRemoteRepositoryRef::parse('git@github.com:acme/app.git', 'github');
    expect($ref)->not->toBeNull();
    expect($ref->provider)->toBe('github');
    expect($ref->owner)->toBe('acme');
    expect($ref->repo)->toBe('app');
});

test('parses gitlab https', function () {
    $ref = GitRemoteRepositoryRef::parse('https://gitlab.com/group/sub/project.git', 'gitlab');
    expect($ref)->not->toBeNull();
    expect($ref->provider)->toBe('gitlab');
    expect($ref->gitlabProjectPath)->toBe('group/sub/project');
});

test('parses github https strips git suffix', function () {
    $ref = GitRemoteRepositoryRef::parse('https://github.com/acme/demo.git', 'github');
    expect($ref)->not->toBeNull();
    expect($ref->owner)->toBe('acme');
    expect($ref->repo)->toBe('demo');
});
