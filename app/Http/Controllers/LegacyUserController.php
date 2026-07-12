<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\UserProfile;

class LegacyUserController extends Controller
{
    public function handle(Request $request)
    {
        $action = $request->query('action', '');

        if ($action === 'getProfile') {
            return $this->getProfile($request);
        } elseif ($action === 'updateProfile') {
            return $this->updateProfile($request);
        } elseif ($action === 'updateUsername') {
            return $this->updateUsername($request);
        } elseif ($action === 'getAllUsers') {
            return $this->getAllUsers();
        } else {
            return response()->json(["status" => "error", "message" => "Invalid action"]);
        }
    }

    private function getProfile(Request $request)
    {
        $email = $request->query('email', '');

        if (empty($email)) {
            return response()->json(["status" => "error", "message" => "Email is required"]);
        }

        $user = UserProfile::where('email', $email)->first();

        if ($user) {
            return response()->json([
                "status" => "success",
                "display_name" => $user->display_name,
                "preferred_languages" => json_decode($user->preferred_languages ?? '[]'),
                "liked_songs" => json_decode($user->liked_songs ?? '[]'),
                "recent_plays" => json_decode($user->recent_plays ?? '[]'),
                "listening_preferences" => json_decode($user->listening_preferences ?? '[]')
            ]);
        } else {
            return response()->json([
                "status" => "success",
                "message" => "User not found, returning defaults.",
                "display_name" => null,
                "preferred_languages" => null,
                "liked_songs" => null,
                "recent_plays" => null,
                "listening_preferences" => null
            ]);
        }
    }

    private function updateProfile(Request $request)
    {
        $email = $request->json('email');
        if (empty($email)) {
            return response()->json(["status" => "error", "message" => "Email is required"]);
        }

        $user = UserProfile::firstOrNew(['email' => $email]);
        
        if ($request->has('preferred_languages')) {
            $user->preferred_languages = json_encode($request->json('preferred_languages'));
        }
        if ($request->has('liked_songs')) {
            $user->liked_songs = json_encode($request->json('liked_songs'));
        }
        if ($request->has('recent_plays')) {
            $user->recent_plays = json_encode($request->json('recent_plays'));
        }
        if ($request->has('listening_preferences')) {
            $user->listening_preferences = json_encode($request->json('listening_preferences'));
        }

        try {
            $user->save();
            return response()->json(["status" => "success", "message" => "Profile updated successfully"]);
        } catch (\Exception $e) {
            return response()->json(["status" => "error", "message" => "Error updating profile: " . $e->getMessage()]);
        }
    }

    private function updateUsername(Request $request)
    {
        $email = $request->json('email');
        $display_name = $request->json('display_name');

        if (empty($email) || empty($display_name)) {
            return response()->json(["status" => "error", "message" => "Email and display_name are required"]);
        }

        try {
            $user = UserProfile::firstOrNew(['email' => $email]);
            $user->display_name = $display_name;
            $user->save();
            return response()->json(["status" => "success", "message" => "Username updated in DB"]);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1062) {
                return response()->json(["status" => "error", "message" => "Username is already taken by another user."]);
            }
            return response()->json(["status" => "error", "message" => "Error updating username: " . $e->getMessage()]);
        }
    }

    private function getAllUsers()
    {
        $users = UserProfile::orderBy('created_at', 'desc')->get()->map(function($user) {
            return [
                'email' => $user->email,
                'display_name' => $user->display_name,
                'preferred_languages' => json_decode($user->preferred_languages ?? '[]'),
                'liked_songs' => json_decode($user->liked_songs ?? '[]'),
                'recent_plays' => json_decode($user->recent_plays ?? '[]'),
                'listening_preferences' => json_decode($user->listening_preferences ?? '[]'),
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at
            ];
        });

        return response()->json([
            "status" => "success",
            "data" => $users
        ]);
    }
}
