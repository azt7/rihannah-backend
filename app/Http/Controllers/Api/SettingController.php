<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /**
     * Get all settings or by group.
     */
    public function index(Request $request): JsonResponse
    {
        if ($group = $request->get('group')) {
            $settings = Setting::getByGroup($group);
        } else {
            $settings = Setting::all()->mapWithKeys(function ($setting) {
                return [$setting->key => Setting::get($setting->key)];
            });
        }

        return response()->json(['settings' => $settings]);
    }

    /**
     * Update settings.
     */
    public function update(Request $request): JsonResponse
    {
        $this->authorize('update', Setting::class);

        $request->validate([
            'settings' => 'required|array',
            'settings.*.key' => 'required|string',
            'settings.*.value' => 'present',
            'settings.*.type' => 'nullable|in:string,text,json,boolean',
            'settings.*.group' => 'nullable|string',
        ]);

        foreach ($request->settings as $item) {
            Setting::set(
                $item['key'],
                $item['value'],
                $item['type'] ?? 'string',
                $item['group'] ?? 'general'
            );
        }

        return response()->json(['message' => 'Settings updated successfully']);
    }

    /**
     * Get WhatsApp templates.
     */
    public function whatsappTemplates(): JsonResponse
    {
        return response()->json([
            'templates' => [
                'ar' => Setting::get('whatsapp_template'),
                'en' => Setting::get('whatsapp_template_en'),
            ],
        ]);
    }

    /**
     * Update WhatsApp templates.
     */
    public function updateWhatsappTemplates(Request $request): JsonResponse
    {
        $this->authorize('update', Setting::class);

        $request->validate([
            'ar' => 'nullable|string',
            'en' => 'nullable|string',
        ]);

        if ($request->has('ar')) {
            Setting::set('whatsapp_template', $request->ar, 'text', 'whatsapp');
        }

        if ($request->has('en')) {
            Setting::set('whatsapp_template_en', $request->en, 'text', 'whatsapp');
        }

        return response()->json(['message' => 'WhatsApp templates updated successfully']);
    }
}
