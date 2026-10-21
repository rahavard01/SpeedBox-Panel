<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmergencyConfig;
use App\Services\EmergencyConfigParser;
use App\Services\EmergencyConfigurations;
use App\Services\RuntimeSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class EmergencyConfigController extends Controller
{
    public function index(EmergencyConfigurations $configs)
    {
        return response()->json(['data'=>EmergencyConfig::orderBy('sort')->orderBy('id')->get(), 'position'=>$configs->position()]);
    }

    public function show(EmergencyConfig $emergencyConfig)
    {
        return response()->json(['data'=>[...$emergencyConfig->toArray(), 'config_uri'=>$emergencyConfig->config_uri]])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, EmergencyConfigParser $parser)
    {
        $data = $this->validated($request, $parser);
        return DB::transaction(function () use ($data) {
            $this->lockOrder();
            $data['sort'] = (int) EmergencyConfig::max('sort') + 1;
            $config = EmergencyConfig::create($data);
            return response()->json(['data'=>$config], 201);
        });
    }

    public function update(Request $request, EmergencyConfig $emergencyConfig, EmergencyConfigParser $parser)
    {
        $emergencyConfig->update($this->validated($request, $parser, $emergencyConfig));
        return response()->json(['data'=>$emergencyConfig->fresh()]);
    }

    public function flags(Request $request, EmergencyConfig $emergencyConfig)
    {
        $emergencyConfig->update($request->validate(['show'=>'required|boolean']));
        return response()->json(['data'=>$emergencyConfig->fresh()]);
    }

    public function settings(Request $request)
    {
        $data = $request->validate(['position'=>'required|in:top,bottom']);
        RuntimeSetting::set('emergency_configs_position', $data['position'], 'subscribe');
        return response()->json($data);
    }

    public function order(Request $request)
    {
        $data = $request->validate(['ids'=>'required|array|max:1000', 'ids.*'=>'required|integer|distinct|min:1']);
        return DB::transaction(function () use ($data) {
            $this->lockOrder();
            $current = EmergencyConfig::orderBy('id')->lockForUpdate()->pluck('id')->map(fn ($id)=>(int) $id)->all();
            $ids = array_map('intval', $data['ids']);
            $sorted = $ids; sort($sorted);
            if ($sorted !== $current) throw ValidationException::withMessages(['ids'=>'لیست کانفیگ‌ها تغییر کرده است؛ صفحه را تازه‌سازی کنید.']);
            foreach ($ids as $index=>$id) EmergencyConfig::whereKey($id)->update(['sort'=>$index + 1]);
            return response()->json(['data'=>EmergencyConfig::orderBy('sort')->orderBy('id')->get()]);
        });
    }

    public function destroy(EmergencyConfig $emergencyConfig)
    {
        DB::transaction(function () use ($emergencyConfig) { $this->lockOrder(); $emergencyConfig->delete(); });
        return response()->json(['ok'=>true]);
    }

    private function validated(Request $request, EmergencyConfigParser $parser, ?EmergencyConfig $config = null): array
    {
        foreach (['name','config_uri'] as $field) if (is_string($request->input($field))) $request->merge([$field=>trim($request->input($field))]);
        $data = $request->validate([
            'name'=>['required','string','max:150', 'not_regex:/[\x00-\x1f\x7f]/', Rule::unique('speedbox_emergency_configs','name')->ignore($config?->id), Rule::unique('v2_server','name')],
            'config_uri'=>'required|string|max:16384', 'show'=>'required|boolean',
            'tags'=>'sometimes|array|max:20', 'tags.*'=>['required','string','max:100','distinct','not_regex:/[\x00-\x1f\x7f]/'],
        ], ['name.unique'=>'این نام قبلاً برای یک نود یا کانفیگ ثبت شده است.', 'name.required'=>'نام نمایشی را وارد کنید.', 'config_uri.required'=>'لینک کانفیگ را وارد کنید.']);
        $data['tags'] = array_values(array_unique(array_filter(array_map('trim', $data['tags'] ?? $config?->tags ?? []), fn ($tag) => $tag !== '')));
        try { $server = $parser->parse($data['config_uri'], $data['name']); }
        catch (\InvalidArgumentException $error) { throw ValidationException::withMessages(['config_uri'=>$error->getMessage()]); }
        return [...$data, 'protocol'=>$server['type'], 'host'=>$server['host'], 'port'=>$server['port']];
    }

    private function lockOrder(): void
    {
        DB::table('v2_settings')->insertOrIgnore(['name'=>'emergency_configs_position', 'value'=>'bottom', 'created_at'=>now(), 'updated_at'=>now()]);
        DB::table('v2_settings')->where('name','emergency_configs_position')->lockForUpdate()->first();
    }
}
