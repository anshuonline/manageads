<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LegacyAdController extends Controller
{
    public function handle(Request $request)
    {
        $action = $request->query('action', '');

        if ($action === 'prices') {
            $results = DB::table('ads')->select('placeholder_id', 'price_per_hour')->get();
            $prices = [];
            foreach ($results as $row) {
                $prices[$row->placeholder_id] = (int)$row->price_per_hour;
            }
            return response()->json($prices);
        } else {
            $placeholder = $request->query('placeholder', 'bottom_player_banner');
            $row = DB::table('ads')->where('placeholder_id', $placeholder)->first();

            if ($row) {
                $imageUrl = $row->image_path;
                if ($imageUrl && !filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                    $imageUrl = request()->getSchemeAndHttpHost() . '/manageads/' . ltrim($imageUrl, '/');
                }

                return response()->json([
                    "isActive" => (bool)$row->is_active,
                    "imageUrl" => $imageUrl,
                    "linkUrl" => $row->link_url,
                    "customCode" => $row->custom_code,
                    "pricePerHour" => (int)$row->price_per_hour
                ]);
            } else {
                return response()->json([
                    "isActive" => false, 
                    "imageUrl" => "", 
                    "linkUrl" => "", 
                    "customCode" => "", 
                    "pricePerHour" => 0
                ]);
            }
        }
    }
}
