<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Forms;

use App\Livewire\Forms\SiteCreateForm;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Lock in the public shape of the scaffold-mode fields added to the
 * Site Create form. The scaffold view (PR 4 view slice) and the
 * scaffold pipeline action (PR 5/6) both bind to these fields; if
 * names or defaults move, both layers regress.
 *
 * Inspecting via Reflection because Livewire's Form base requires a
 * Component + propertyName at construction time and isn't instantiable
 * outside a live Livewire component lifecycle.
 */
class SiteCreateFormScaffoldFieldsTest extends TestCase
{
    private function defaultFor(string $property): mixed
    {
        $reflection = new ReflectionClass(SiteCreateForm::class);
        $this->assertTrue($reflection->hasProperty($property),
            "SiteCreateForm is missing expected property [{$property}]");

        $propertyDefaults = $reflection->getDefaultProperties();

        return $propertyDefaults[$property] ?? null;
    }

    public function test_default_mode_is_import(): void
    {
        $this->assertSame('import', $this->defaultFor('mode'));
    }

    public function test_scaffold_framework_defaults_to_empty(): void
    {
        $this->assertSame('', $this->defaultFor('scaffold_framework'));
    }

    public function test_scaffold_admin_email_defaults_to_empty(): void
    {
        $this->assertSame('', $this->defaultFor('scaffold_admin_email'));
    }

    public function test_existing_import_fields_unchanged_by_scaffold_addition(): void
    {
        // Sanity: the import-mode defaults must not have shifted when
        // the scaffold fields were added. Catches accidental field churn.
        $this->assertSame('php', $this->defaultFor('type'));
        $this->assertSame('/var/www/app/public', $this->defaultFor('document_root'));
        $this->assertSame(SiteCreateForm::DEFAULT_DEPLOY_PATH, $this->defaultFor('repository_path'));
        $this->assertSame('main', $this->defaultFor('git_branch'));
    }
}
