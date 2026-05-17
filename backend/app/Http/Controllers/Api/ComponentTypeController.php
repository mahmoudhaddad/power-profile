<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComponentType;
use Illuminate\Http\Request;

class ComponentTypeController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => ComponentType::orderBy('is_preset', 'desc')->orderBy('name')->get(),
        ]);
    }
}
