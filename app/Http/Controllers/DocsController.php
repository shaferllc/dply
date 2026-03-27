<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DocsController extends Controller
{
    public function index(): View
    {
        return view('docs.index');
    }

    public function connectProvider(): View
    {
        return view('docs.connect-provider');
    }

    public function createFirstServer(): View
    {
        return view('docs.create-first-server');
    }

    public function orgRolesAndLimits(): View
    {
        $path = base_path('docs/ORG_ROLES_AND_LIMITS.md');
        if (! File::isFile($path)) {
            throw new NotFoundHttpException;
        }

        $html = Str::markdown(File::get($path));

        return view('docs.markdown-doc', [
            'title' => 'Organization roles & plan limits',
            'html' => $html,
        ]);
    }

    public function sourceControl(): View
    {
        $path = base_path('docs/DEPLOYMENT_FLOW.md');
        if (! File::isFile($path)) {
            throw new NotFoundHttpException;
        }

        $html = Str::markdown(File::get($path));

        return view('docs.markdown-doc', [
            'title' => 'Source control & deploy flow',
            'html' => $html,
        ]);
    }
}
