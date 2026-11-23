<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\RuntimeSetting;
use App\Services\StreisandSmartSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StreisandSmartController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => StreisandSmartSettings::get()]);
    }

    public function update(Request $request): JsonResponse
    {
        $flags = $request->validate(array_fill_keys(array_keys(StreisandSmartSettings::DEFAULTS), 'required|boolean'));
        $flags = array_map(static fn ($value) => (bool) $value, $flags);
        RuntimeSetting::set(StreisandSmartSettings::KEY, $flags);

        return response()->json(['data' => $flags, 'message' => 'تنظیمات سرورهای هوشمند ذخیره شد.']);
    }
}
