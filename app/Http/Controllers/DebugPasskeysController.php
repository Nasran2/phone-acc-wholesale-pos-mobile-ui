<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passkeys\Passkey;

class DebugPasskeysController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'user_agent' => $request->userAgent(),
            'passkeys' => Passkey::all()->map(fn (Passkey $passkey): array => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'credential_id' => $passkey->credential_id,
                'user_id' => $passkey->user_id,
            ])->toArray(),
        ]);
    }
}
