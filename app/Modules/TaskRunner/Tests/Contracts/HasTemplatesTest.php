<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Contracts\HasTemplates;
use Tests\TestCase;

uses(TestCase::class);

describe('HasTemplates Contract', function () {
    describe('interface contract', function () {
        it('defines all required methods', function () {
            $reflection = new ReflectionClass(HasTemplates::class);
            $methods = $reflection->getMethods();

            $methodNames = array_map(fn ($method) => $method->getName(), $methods);
            expect($methodNames)->toContain(
                'isTemplatesEnabled',
                'getAvailableTemplates',
                'getTemplate',
                'createFromTemplate',
                'saveAsTemplate',
                'updateTemplate',
                'deleteTemplate',
                'getTemplateMetadata',
                'validateTemplateParameters',
                'getTemplateParametersSchema',
                'listTemplates',
                'searchTemplates',
                'getTemplateCategories',
                'getTemplatesByCategory',
                'importTemplate',
                'exportTemplate',
                'getTemplateUsageStats',
                'getPopularTemplates',
                'getRecentTemplates',
                'getTemplateRecommendations',
                'cloneTemplate',
                'getTemplateVersionHistory',
                'restoreTemplateVersion',
                'getTemplateDependencies',
                'checkTemplateCompatibility'
            );
        });

        it('has correct isTemplatesEnabled method signature', function () {
            $reflection = new ReflectionClass(HasTemplates::class);
            $method = $reflection->getMethod('isTemplatesEnabled');

            expect($method->getReturnType()->getName())->toBe('bool');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct getAvailableTemplates method signature', function () {
            $reflection = new ReflectionClass(HasTemplates::class);
            $method = $reflection->getMethod('getAvailableTemplates');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct getTemplate method signature', function () {
            $reflection = new ReflectionClass(HasTemplates::class);
            $method = $reflection->getMethod('getTemplate');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getReturnType()->allowsNull())->toBeTrue();
            expect($method->getParameters())->toHaveCount(1);

            $templateNameParam = $method->getParameters()[0];
            expect($templateNameParam->getName())->toBe('templateName');
            expect($templateNameParam->getType()->getName())->toBe('string');
        });

        it('has correct createFromTemplate method signature', function () {
            $reflection = new ReflectionClass(HasTemplates::class);
            $method = $reflection->getMethod('createFromTemplate');

            expect($method->getReturnType()->getName())->toBe(HasTemplates::class);
            expect($method->getParameters())->toHaveCount(2);

            $templateNameParam = $method->getParameters()[0];
            expect($templateNameParam->getName())->toBe('templateName');
            expect($templateNameParam->getType()->getName())->toBe('string');

            $parametersParam = $method->getParameters()[1];
            expect($parametersParam->getName())->toBe('parameters');
            expect($parametersParam->getType()->getName())->toBe('array');
            expect($parametersParam->isOptional())->toBeTrue();
        });

        it('has correct saveAsTemplate method signature', function () {
            $reflection = new ReflectionClass(HasTemplates::class);
            $method = $reflection->getMethod('saveAsTemplate');

            expect($method->getReturnType()->getName())->toBe('bool');
            expect($method->getParameters())->toHaveCount(2);

            $templateNameParam = $method->getParameters()[0];
            expect($templateNameParam->getName())->toBe('templateName');
            expect($templateNameParam->getType()->getName())->toBe('string');

            $metadataParam = $method->getParameters()[1];
            expect($metadataParam->getName())->toBe('metadata');
            expect($metadataParam->getType()->getName())->toBe('array');
            expect($metadataParam->isOptional())->toBeTrue();
        });

        it('has correct updateTemplate method signature', function () {
            $reflection = new ReflectionClass(HasTemplates::class);
            $method = $reflection->getMethod('updateTemplate');

            expect($method->getReturnType()->getName())->toBe('bool');
            expect($method->getParameters())->toHaveCount(2);

            $templateNameParam = $method->getParameters()[0];
            expect($templateNameParam->getName())->toBe('templateName');
            expect($templateNameParam->getType()->getName())->toBe('string');

            $dataParam = $method->getParameters()[1];
            expect($dataParam->getName())->toBe('data');
            expect($dataParam->getType()->getName())->toBe('array');
        });

        it('has correct deleteTemplate method signature', function () {
            $reflection = new ReflectionClass(HasTemplates::class);
            $method = $reflection->getMethod('deleteTemplate');

            expect($method->getReturnType()->getName())->toBe('bool');
            expect($method->getParameters())->toHaveCount(1);

            $templateNameParam = $method->getParameters()[0];
            expect($templateNameParam->getName())->toBe('templateName');
            expect($templateNameParam->getType()->getName())->toBe('string');
        });

        it('has correct getTemplateMetadata method signature', function () {
            $reflection = new ReflectionClass(HasTemplates::class);
            $method = $reflection->getMethod('getTemplateMetadata');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(1);

            $templateNameParam = $method->getParameters()[0];
            expect($templateNameParam->getName())->toBe('templateName');
            expect($templateNameParam->getType()->getName())->toBe('string');
        });

        it('has correct validateTemplateParameters method signature', function () {
            $reflection = new ReflectionClass(HasTemplates::class);
            $method = $reflection->getMethod('validateTemplateParameters');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(2);

            $templateNameParam = $method->getParameters()[0];
            expect($templateNameParam->getName())->toBe('templateName');
            expect($templateNameParam->getType()->getName())->toBe('string');

            $parametersParam = $method->getParameters()[1];
            expect($parametersParam->getName())->toBe('parameters');
            expect($parametersParam->getType()->getName())->toBe('array');
        });

        it('has correct getTemplateParametersSchema method signature', function () {
            $reflection = new ReflectionClass(HasTemplates::class);
            $method = $reflection->getMethod('getTemplateParametersSchema');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(1);

            $templateNameParam = $method->getParameters()[0];
            expect($templateNameParam->getName())->toBe('templateName');
            expect($templateNameParam->getType()->getName())->toBe('string');
        });

        it('has correct listTemplates method signature', function () {
            $reflection = new ReflectionClass(HasTemplates::class);
            $method = $reflection->getMethod('listTemplates');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct searchTemplates method signature', function () {
            $reflection = new ReflectionClass(HasTemplates::class);
            $method = $reflection->getMethod('searchTemplates');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(1);

            $queryParam = $method->getParameters()[0];
            expect($queryParam->getName())->toBe('query');
            expect($queryParam->getType()->getName())->toBe('string');
        });

        it('has correct getTemplateCategories method signature', function () {
            $reflection = new ReflectionClass(HasTemplates::class);
            $method = $reflection->getMethod('getTemplateCategories');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct getTemplatesByCategory method signature', function () {
            $reflection = new ReflectionClass(HasTemplates::class);
            $method = $reflection->getMethod('getTemplatesByCategory');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(1);

            $categoryParam = $method->getParameters()[0];
            expect($categoryParam->getName())->toBe('category');
            expect($categoryParam->getType()->getName())->toBe('string');
        });

        it('has correct importTemplate method signature', function () {
            $reflection = new ReflectionClass(HasTemplates::class);
            $method = $reflection->getMethod('importTemplate');

            expect($method->getReturnType()->getName())->toBe('bool');
            expect($method->getParameters())->toHaveCount(1);

            $filePathParam = $method->getParameters()[0];
            expect($filePathParam->getName())->toBe('filePath');
            expect($filePathParam->getType()->getName())->toBe('string');
        });

        it('has correct exportTemplate method signature', function () {
            $reflection = new ReflectionClass(HasTemplates::class);
            $method = $reflection->getMethod('exportTemplate');

            expect($method->getReturnType()->getName())->toBe('string');
            expect($method->getParameters())->toHaveCount(2);

            $templateNameParam = $method->getParameters()[0];
            expect($templateNameParam->getName())->toBe('templateName');
            expect($templateNameParam->getType()->getName())->toBe('string');

            $formatParam = $method->getParameters()[1];
            expect($formatParam->getName())->toBe('format');
            expect($formatParam->getType()->getName())->toBe('string');
            expect($formatParam->isOptional())->toBeTrue();
        });

        it('has correct getTemplateUsageStats method signature', function () {
            $reflection = new ReflectionClass(HasTemplates::class);
            $method = $reflection->getMethod('getTemplateUsageStats');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(1);

            $templateNameParam = $method->getParameters()[0];
            expect($templateNameParam->getName())->toBe('templateName');
            expect($templateNameParam->getType()->getName())->toBe('string');
        });

        it('has correct getPopularTemplates method signature', function () {
            $reflection = new ReflectionClass(HasTemplates::class);
            $method = $reflection->getMethod('getPopularTemplates');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(1);

            $limitParam = $method->getParameters()[0];
            expect($limitParam->getName())->toBe('limit');
            expect($limitParam->getType()->getName())->toBe('int');
            expect($limitParam->isOptional())->toBeTrue();
        });

        it('has correct getRecentTemplates method signature', function () {
            $reflection = new ReflectionClass(HasTemplates::class);
            $method = $reflection->getMethod('getRecentTemplates');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(1);

            $limitParam = $method->getParameters()[0];
            expect($limitParam->getName())->toBe('limit');
            expect($limitParam->getType()->getName())->toBe('int');
            expect($limitParam->isOptional())->toBeTrue();
        });

        it('has correct getTemplateRecommendations method signature', function () {
            $reflection = new ReflectionClass(HasTemplates::class);
            $method = $reflection->getMethod('getTemplateRecommendations');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct cloneTemplate method signature', function () {
            $reflection = new ReflectionClass(HasTemplates::class);
            $method = $reflection->getMethod('cloneTemplate');

            expect($method->getReturnType()->getName())->toBe('bool');
            expect($method->getParameters())->toHaveCount(2);

            $sourceTemplateParam = $method->getParameters()[0];
            expect($sourceTemplateParam->getName())->toBe('sourceTemplate');
            expect($sourceTemplateParam->getType()->getName())->toBe('string');

            $newTemplateNameParam = $method->getParameters()[1];
            expect($newTemplateNameParam->getName())->toBe('newTemplateName');
            expect($newTemplateNameParam->getType()->getName())->toBe('string');
        });

        it('has correct getTemplateVersionHistory method signature', function () {
            $reflection = new ReflectionClass(HasTemplates::class);
            $method = $reflection->getMethod('getTemplateVersionHistory');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(1);

            $templateNameParam = $method->getParameters()[0];
            expect($templateNameParam->getName())->toBe('templateName');
            expect($templateNameParam->getType()->getName())->toBe('string');
        });

        it('has correct restoreTemplateVersion method signature', function () {
            $reflection = new ReflectionClass(HasTemplates::class);
            $method = $reflection->getMethod('restoreTemplateVersion');

            expect($method->getReturnType()->getName())->toBe('bool');
            expect($method->getParameters())->toHaveCount(2);

            $templateNameParam = $method->getParameters()[0];
            expect($templateNameParam->getName())->toBe('templateName');
            expect($templateNameParam->getType()->getName())->toBe('string');

            $versionParam = $method->getParameters()[1];
            expect($versionParam->getName())->toBe('version');
            expect($versionParam->getType()->getName())->toBe('string');
        });

        it('has correct getTemplateDependencies method signature', function () {
            $reflection = new ReflectionClass(HasTemplates::class);
            $method = $reflection->getMethod('getTemplateDependencies');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(1);

            $templateNameParam = $method->getParameters()[0];
            expect($templateNameParam->getName())->toBe('templateName');
            expect($templateNameParam->getType()->getName())->toBe('string');
        });

        it('has correct checkTemplateCompatibility method signature', function () {
            $reflection = new ReflectionClass(HasTemplates::class);
            $method = $reflection->getMethod('checkTemplateCompatibility');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(1);

            $templateNameParam = $method->getParameters()[0];
            expect($templateNameParam->getName())->toBe('templateName');
            expect($templateNameParam->getType()->getName())->toBe('string');
        });
    });

    describe('method accessibility', function () {
        it('ensures all methods are public', function () {
            $reflection = new ReflectionClass(HasTemplates::class);
            $methods = $reflection->getMethods();

            foreach ($methods as $method) {
                expect($method->isPublic())->toBeTrue();
                expect($method->isAbstract())->toBeTrue();
            }
        });
    });

    describe('return type validation', function () {
        it('validates boolean return types', function () {
            $reflection = new ReflectionClass(HasTemplates::class);

            $booleanMethods = [
                'isTemplatesEnabled',
                'saveAsTemplate',
                'updateTemplate',
                'deleteTemplate',
                'importTemplate',
                'cloneTemplate',
                'restoreTemplateVersion',
            ];

            foreach ($booleanMethods as $methodName) {
                $method = $reflection->getMethod($methodName);
                expect($method->getReturnType()->getName())->toBe('bool');
            }
        });

        it('validates array return types', function () {
            $reflection = new ReflectionClass(HasTemplates::class);

            $arrayMethods = [
                'getAvailableTemplates',
                'getTemplate',
                'getTemplateMetadata',
                'validateTemplateParameters',
                'getTemplateParametersSchema',
                'listTemplates',
                'searchTemplates',
                'getTemplateCategories',
                'getTemplatesByCategory',
                'getTemplateUsageStats',
                'getPopularTemplates',
                'getRecentTemplates',
                'getTemplateRecommendations',
                'getTemplateVersionHistory',
                'getTemplateDependencies',
                'checkTemplateCompatibility',
            ];

            foreach ($arrayMethods as $methodName) {
                $method = $reflection->getMethod($methodName);
                expect($method->getReturnType()->getName())->toBe('array');
            }
        });

        it('validates string return types', function () {
            $reflection = new ReflectionClass(HasTemplates::class);

            $exportTemplate = $reflection->getMethod('exportTemplate');
            expect($exportTemplate->getReturnType()->getName())->toBe('string');
        });

        it('validates self return types', function () {
            $reflection = new ReflectionClass(HasTemplates::class);

            $createFromTemplate = $reflection->getMethod('createFromTemplate');
            expect($createFromTemplate->getReturnType()->getName())->toBe(HasTemplates::class);
        });
    });

    describe('parameter validation', function () {
        it('validates string parameters', function () {
            $reflection = new ReflectionClass(HasTemplates::class);

            $stringMethods = [
                'getTemplate' => 'templateName',
                'createFromTemplate' => 'templateName',
                'saveAsTemplate' => 'templateName',
                'updateTemplate' => 'templateName',
                'deleteTemplate' => 'templateName',
                'getTemplateMetadata' => 'templateName',
                'validateTemplateParameters' => 'templateName',
                'getTemplateParametersSchema' => 'templateName',
                'searchTemplates' => 'query',
                'getTemplatesByCategory' => 'category',
                'importTemplate' => 'filePath',
                'exportTemplate' => 'templateName',
                'getTemplateUsageStats' => 'templateName',
                'cloneTemplate' => 'sourceTemplate',
                'getTemplateVersionHistory' => 'templateName',
                'restoreTemplateVersion' => 'templateName',
                'getTemplateDependencies' => 'templateName',
                'checkTemplateCompatibility' => 'templateName',
            ];

            foreach ($stringMethods as $methodName => $paramName) {
                $method = $reflection->getMethod($methodName);
                $param = $method->getParameters()[0];
                expect($param->getName())->toBe($paramName);
                expect($param->getType()->getName())->toBe('string');
            }
        });

        it('validates integer parameters', function () {
            $reflection = new ReflectionClass(HasTemplates::class);

            $getPopularTemplates = $reflection->getMethod('getPopularTemplates');
            $limitParam = $getPopularTemplates->getParameters()[0];
            expect($limitParam->getType()->getName())->toBe('int');

            $getRecentTemplates = $reflection->getMethod('getRecentTemplates');
            $limitParam = $getRecentTemplates->getParameters()[0];
            expect($limitParam->getType()->getName())->toBe('int');
        });

        it('validates array parameters', function () {
            $reflection = new ReflectionClass(HasTemplates::class);

            $createFromTemplate = $reflection->getMethod('createFromTemplate');
            $parametersParam = $createFromTemplate->getParameters()[1];
            expect($parametersParam->getType()->getName())->toBe('array');

            $saveAsTemplate = $reflection->getMethod('saveAsTemplate');
            $metadataParam = $saveAsTemplate->getParameters()[1];
            expect($metadataParam->getType()->getName())->toBe('array');

            $updateTemplate = $reflection->getMethod('updateTemplate');
            $dataParam = $updateTemplate->getParameters()[1];
            expect($dataParam->getType()->getName())->toBe('array');

            $validateTemplateParameters = $reflection->getMethod('validateTemplateParameters');
            $parametersParam = $validateTemplateParameters->getParameters()[1];
            expect($parametersParam->getType()->getName())->toBe('array');
        });
    });
});
