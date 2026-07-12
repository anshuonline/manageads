<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\UserPlaylist;

class LegacyPlaylistController extends Controller
{
    public function handle(Request $request)
    {
        $action = $request->query('action', '');

        switch ($action) {
            case 'getPlaylists':
                return $this->getPlaylists($request);
            case 'getPublicPlaylist':
                return $this->getPublicPlaylist($request);
            case 'getAllPublicPlaylists':
                return $this->getAllPublicPlaylists($request);
            case 'createPlaylist':
                return $this->createPlaylist($request);
            case 'updatePlaylist':
                return $this->updatePlaylist($request);
            case 'deletePlaylist':
                return $this->deletePlaylist($request);
            case 'getPublicPlaylists':
                return $this->getPublicPlaylists($request);
            case 'savePlaylist':
                return $this->savePlaylist($request);
            case 'unsavePlaylist':
                return $this->unsavePlaylist($request);
            default:
                return response()->json(["status" => "error", "message" => "Invalid action"]);
        }
    }

    private function generatePlaylistId() {
        return 'pl_' . substr(md5(uniqid(mt_rand(), true)), 0, 16);
    }

    private function getPlaylists(Request $request) {
        $email = $request->query('email', '');
        
        if (empty($email)) {
            return response()->json(["status" => "error", "message" => "Email is required"]);
        }

        $sql = "
            SELECT p.playlist_id, p.playlist_name, p.is_public, p.songs, p.created_at, p.updated_at, 1 as is_owner 
            FROM user_playlists p 
            WHERE p.email = ?
            UNION
            SELECT p.playlist_id, p.playlist_name, p.is_public, p.songs, s.saved_at as created_at, p.updated_at, 0 as is_owner 
            FROM saved_playlists s
            JOIN user_playlists p ON s.playlist_id = p.playlist_id
            WHERE s.email = ?
            ORDER BY created_at DESC
        ";
        
        $results = DB::select($sql, [$email, $email]);

        $playlists = [];
        foreach ($results as $row) {
            $playlists[] = [
                "playlist_id" => $row->playlist_id,
                "playlist_name" => $row->playlist_name,
                "is_public" => (bool)$row->is_public,
                "songs" => json_decode($row->songs),
                "is_owner" => (bool)$row->is_owner,
                "created_at" => $row->created_at,
                "updated_at" => $row->updated_at
            ];
        }

        return response()->json([
            "status" => "success",
            "data" => $playlists
        ]);
    }

    private function getPublicPlaylist(Request $request) {
        $playlist_id = $request->query('playlist_id', '');
        $email = $request->query('email', '');
        
        if (empty($playlist_id)) {
            return response()->json(["status" => "error", "message" => "Playlist ID is required"]);
        }

        $sql = "SELECT p.playlist_id, p.email, p.playlist_name, p.is_public, p.songs, p.created_at, p.updated_at, u.display_name 
                FROM user_playlists p 
                LEFT JOIN user_profiles u ON p.email = u.email 
                WHERE p.playlist_id = ?";
                
        $result = DB::select($sql, [$playlist_id]);

        if (count($result) > 0) {
            $row = $result[0];
            if ((bool)$row->is_public || $row->email === $email) {
                $ownerName = !empty($row->display_name) ? $row->display_name : explode('@', $row->email)[0];
                return response()->json([
                    "status" => "success",
                    "data" => [
                        "playlist_id" => $row->playlist_id,
                        "playlist_name" => $row->playlist_name,
                        "is_public" => (bool)$row->is_public,
                        "songs" => json_decode($row->songs),
                        "owner" => $ownerName, 
                        "owner_email" => $row->email,
                        "created_at" => $row->created_at,
                        "updated_at" => $row->updated_at
                    ]
                ]);
            } else {
                return response()->json(["status" => "error", "message" => "This playlist is private"]);
            }
        } else {
            return response()->json(["status" => "error", "message" => "Playlist not found"]);
        }
    }

    private function getAllPublicPlaylists(Request $request) {
        $queryParam = $request->query('q', '');
        
        $sql = "SELECT p.playlist_id, p.email, p.playlist_name, p.is_public, p.songs, p.created_at, p.updated_at, u.display_name 
                FROM user_playlists p 
                LEFT JOIN user_profiles u ON p.email = u.email 
                WHERE p.is_public = 1";
                
        $bindings = [];
        if (!empty($queryParam)) {
            $sql .= " AND p.playlist_name LIKE ?";
            $bindings[] = '%' . $queryParam . '%';
        }
        
        $sql .= " ORDER BY p.created_at DESC LIMIT 50";
        
        $results = DB::select($sql, $bindings);
        
        $playlists = [];
        foreach ($results as $row) {
            $ownerName = !empty($row->display_name) ? $row->display_name : explode('@', $row->email)[0];
            $playlists[] = [
                "playlist_id" => $row->playlist_id,
                "playlist_name" => $row->playlist_name,
                "is_public" => (bool)$row->is_public,
                "songs" => json_decode($row->songs),
                "owner" => $ownerName,
                "created_at" => $row->created_at,
                "updated_at" => $row->updated_at
            ];
        }
        
        return response()->json([
            "status" => "success",
            "data" => $playlists
        ]);
    }

    private function createPlaylist(Request $request) {
        $email = $request->json('email');
        $playlist_name = $request->json('playlist_name', 'New Playlist');
        $is_public = (int)$request->json('is_public', 0);
        $songs = json_encode($request->json('songs', []));
        
        if (empty($email)) {
            return response()->json(["status" => "error", "message" => "Email is required"]);
        }

        $playlist_id = $this->generatePlaylistId();
        
        DB::table('user_playlists')->insert([
            'playlist_id' => $playlist_id,
            'email' => $email,
            'playlist_name' => $playlist_name,
            'is_public' => $is_public,
            'songs' => $songs,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        return response()->json([
            "status" => "success", 
            "message" => "Playlist created successfully",
            "data" => [
                "playlist_id" => $playlist_id,
                "playlist_name" => $playlist_name,
                "is_public" => (bool)$is_public,
                "songs" => json_decode($songs),
                "created_at" => now()->toDateTimeString(),
                "updated_at" => now()->toDateTimeString()
            ]
        ]);
    }

    private function updatePlaylist(Request $request) {
        $playlist_id = $request->json('playlist_id');
        $email = $request->json('email');
        
        if (empty($playlist_id) || empty($email)) {
            return response()->json(["status" => "error", "message" => "Playlist ID and Email are required"]);
        }

        $updates = [];
        if ($request->has('playlist_name')) {
            $updates['playlist_name'] = $request->json('playlist_name');
        }
        if ($request->has('is_public')) {
            $updates['is_public'] = (int)$request->json('is_public');
        }
        if ($request->has('songs')) {
            $updates['songs'] = json_encode($request->json('songs'));
        }

        if (empty($updates)) {
            return response()->json(["status" => "error", "message" => "No fields to update"]);
        }

        $updates['updated_at'] = now();

        $affected = DB::table('user_playlists')
            ->where('playlist_id', $playlist_id)
            ->where('email', $email)
            ->update($updates);

        if ($affected > 0 || !empty($updates)) {
            $row = DB::table('user_playlists')->where('playlist_id', $playlist_id)->first();
            
            return response()->json([
                "status" => "success", 
                "message" => "Playlist updated successfully",
                "data" => [
                    "playlist_id" => $row->playlist_id,
                    "playlist_name" => $row->playlist_name,
                    "is_public" => (bool)$row->is_public,
                    "songs" => json_decode($row->songs),
                    "created_at" => $row->created_at,
                    "updated_at" => $row->updated_at
                ]
            ]);
        } else {
            return response()->json(["status" => "error", "message" => "Error updating playlist"]);
        }
    }

    private function deletePlaylist(Request $request) {
        $playlist_id = $request->json('playlist_id');
        $email = $request->json('email');
        
        if (empty($playlist_id) || empty($email)) {
            return response()->json(["status" => "error", "message" => "Playlist ID and Email are required"]);
        }

        $affected = DB::table('user_playlists')
            ->where('playlist_id', $playlist_id)
            ->where('email', $email)
            ->delete();

        if ($affected > 0) {
            return response()->json(["status" => "success", "message" => "Playlist deleted successfully"]);
        } else {
            return response()->json(["status" => "error", "message" => "Playlist not found or you don't have permission"]);
        }
    }

    private function getPublicPlaylists(Request $request) {
        $sql = "SELECT p.playlist_id, p.playlist_name, p.email, p.songs, p.created_at, p.updated_at, u.display_name 
                FROM user_playlists p 
                LEFT JOIN user_profiles u ON p.email = u.email 
                WHERE p.is_public = 1 
                ORDER BY p.created_at DESC LIMIT 50";
        $results = DB::select($sql);
        
        $playlists = [];
        foreach ($results as $row) {
            $ownerName = !empty($row->display_name) ? $row->display_name : explode('@', $row->email)[0];
            $playlists[] = [
                "playlist_id" => $row->playlist_id,
                "playlist_name" => $row->playlist_name,
                "creator" => $ownerName,
                "songs" => json_decode($row->songs),
                "created_at" => $row->created_at,
                "updated_at" => $row->updated_at
            ];
        }
        
        return response()->json([
            "status" => "success",
            "data" => $playlists
        ]);
    }

    private function savePlaylist(Request $request) {
        $playlist_id = $request->json('playlist_id');
        $email = $request->json('email');
        
        if (empty($playlist_id) || empty($email)) {
            return response()->json(["status" => "error", "message" => "Playlist ID and Email are required"]);
        }

        try {
            DB::table('saved_playlists')->insert([
                'email' => $email,
                'playlist_id' => $playlist_id,
                'saved_at' => now()
            ]);
            return response()->json(["status" => "success", "message" => "Playlist saved successfully"]);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1062) {
                return response()->json(["status" => "success", "message" => "Playlist already saved"]);
            }
            return response()->json(["status" => "error", "message" => "Error saving playlist: " . $e->getMessage()]);
        }
    }

    private function unsavePlaylist(Request $request) {
        $playlist_id = $request->json('playlist_id');
        $email = $request->json('email');
        
        if (empty($playlist_id) || empty($email)) {
            return response()->json(["status" => "error", "message" => "Playlist ID and Email are required"]);
        }

        DB::table('saved_playlists')
            ->where('email', $email)
            ->where('playlist_id', $playlist_id)
            ->delete();

        return response()->json(["status" => "success", "message" => "Playlist unsaved successfully"]);
    }
}
