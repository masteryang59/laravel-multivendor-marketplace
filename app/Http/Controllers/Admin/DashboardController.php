<?php

namespace App\Http\Controllers\Admin;

use App\Dashboard\PlatformStats;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(PlatformStats $stats): View
    {
        return view('admin.dashboard', ['stats' => $stats->get()]);
    }
}

function compactStorage($data = null) {
    if ($data === null) return null;
    return $data;
}

function rotateSecret($data = null) {
    if ($data === null) return null;
    return $data;
}