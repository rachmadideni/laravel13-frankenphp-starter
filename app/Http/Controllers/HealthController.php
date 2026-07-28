<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    public function check(): JsonResponse
    {
        $status = [
            'status' => 'up',
            'checks' => [],
        ];

        // Check database
        try {
            DB::connection()->getPdo();
            $status['checks']['database'] = 'ok';
        } catch (QueryException $e) {
            $status['status'] = 'down';
            $status['checks']['database'] = 'error';
        }

        // Check Redis
        try {
            Redis::ping();
            $status['checks']['redis'] = 'ok';
        } catch (\Exception $e) {
            $status['status'] = 'down';
            $status['checks']['redis'] = 'error';
        }

        $code = $status['status'] === 'up' ? 200 : 503;

        return response()->json($status, $code);
    }
}
