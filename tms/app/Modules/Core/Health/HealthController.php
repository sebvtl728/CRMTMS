<?php

namespace App\Modules\Core\Health;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController
{
    public function __invoke(): JsonResponse
    {
        $db = $this->check(fn () => DB::select('select 1'));
        $redis = $this->check(fn () => Redis::connection()->ping());

        $healthy = $db === 'ok' && $redis === 'ok';

        return response()->json([
            'status' => $healthy ? 'ok' : 'error',
            'db' => $db,
            'redis' => $redis,
            'version' => config('app.version'),
        ], $healthy ? 200 : 503);
    }

    /**
     * @param  callable(): mixed  $probe
     */
    private function check(callable $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (Throwable $e) {
            report($e);

            return 'error';
        }
    }
}
