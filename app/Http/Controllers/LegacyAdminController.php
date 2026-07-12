<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LegacyAdminController extends Controller
{
    public function handle(Request $request)
    {
        $action = $request->query('action', '');

        if ($action === 'login' && $request->isMethod('post')) {
            return $this->login($request);
        } elseif ($action === 'get_sections') {
            return $this->getSettings('custom_sections');
        } elseif ($action === 'save_sections' && $request->isMethod('post')) {
            return $this->saveSettings($request, 'custom_sections', 'sectionsData');
        } elseif ($action === 'get_playlists') {
            return $this->getSettings('custom_playlists');
        } elseif ($action === 'save_playlists' && $request->isMethod('post')) {
            return $this->saveSettings($request, 'custom_playlists', 'playlistsData');
        } elseif ($action === 'get_header') {
            return $this->getSettings('custom_header');
        } elseif ($action === 'save_header' && $request->isMethod('post')) {
            return $this->saveSettings($request, 'custom_header', 'headerData');
        } elseif ($action === 'upload_image' && $request->isMethod('post')) {
            return $this->uploadImage($request);
        } else {
            return response()->json(["status" => "error", "message" => "Invalid action"], 400);
        }
    }

    private function login(Request $request)
    {
        $username = $request->json('username');
        $password = $request->json('password');
        
        $admin = DB::table('admins')->where('username', $username)->first();
        
        if ($admin && md5($password) === $admin->password) {
            return response()->json(["status" => "success", "token" => "gt-auth-token-" . time()]);
        }
        
        return response()->json(["status" => "error", "message" => "Invalid credentials"], 401);
    }

    private function getSettings($key)
    {
        $setting = DB::table('app_settings')->where('setting_key', $key)->first();
        if ($setting) {
            return response($setting->setting_value)->header('Content-Type', 'application/json');
        }
        return response()->json(new \stdClass());
    }

    private function saveSettings(Request $request, $key, $dataKey)
    {
        $data = $request->json($dataKey);
        if (!$data) {
            return response()->json(["status" => "error", "message" => "Invalid payload"], 400);
        }
        
        DB::table('app_settings')->updateOrInsert(
            ['setting_key' => $key],
            ['setting_value' => json_encode($data)]
        );
        
        return response()->json(["status" => "success", "message" => "Saved successfully!"]);
    }

    private function uploadImage(Request $request)
    {
        if (!$request->hasFile('image') || !$request->file('image')->isValid()) {
            return response()->json(["status" => "error", "message" => "No image uploaded or upload error"], 400);
        }

        $file = $request->file('image');
        $filename = uniqid('img_') . '.' . $file->getClientOriginalExtension();
        
        // Save to public/uploads
        $file->move(public_path('uploads'), $filename);
        
        return response()->json([
            "status" => "success", 
            "url" => $request->getSchemeAndHttpHost() . "/manageads-laravel/public/uploads/" . $filename
        ]);
    }
}
