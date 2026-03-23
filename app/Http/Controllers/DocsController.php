<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

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
}
