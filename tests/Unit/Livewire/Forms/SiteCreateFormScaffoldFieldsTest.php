<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Forms\SiteCreateFormScaffoldFieldsTest;
use App\Livewire\Forms\SiteCreateForm;
function defaultFor(string $property): mixed
{
    $reflection = new ReflectionClass(SiteCreateForm::class);
    expect($reflection->hasProperty($property))->toBeTrue("SiteCreateForm is missing expected property [{$property}]");

    $propertyDefaults = $reflection->getDefaultProperties();

    return $propertyDefaults[$property] ?? null;
}
test('default mode is import', function () {
    expect(defaultFor('mode'))->toBe('import');
});
test('scaffold framework defaults to empty', function () {
    expect(defaultFor('scaffold_framework'))->toBe('');
});
test('scaffold admin email defaults to empty', function () {
    expect(defaultFor('scaffold_admin_email'))->toBe('');
});
test('existing import fields unchanged by scaffold addition', function () {
    // Sanity: the import-mode defaults must not have shifted when
    // the scaffold fields were added. Catches accidental field churn.
    expect(defaultFor('type'))->toBe('php');
    expect(defaultFor('document_root'))->toBe('/var/www/app/public');
    expect(defaultFor('repository_path'))->toBe(SiteCreateForm::DEFAULT_DEPLOY_PATH);
    expect(defaultFor('git_branch'))->toBe('main');
});
