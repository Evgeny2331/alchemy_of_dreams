<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

abstract class Controller
{
    protected function apiRequestId(): string
    {
        return (string) Str::uuid();
    }

    protected function apiError(
        string $code,
        string $message,
        int $status,
        array $details = []
    ): JsonResponse {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return response()->json([
            'error' => $error,
            'meta' => [
                'request_id' => $this->apiRequestId(),
            ],
        ], $status);
    }
}
