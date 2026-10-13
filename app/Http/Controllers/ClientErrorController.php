<?php

namespace App\Http\Controllers;

use App\Services\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives failures seen only by the browser (components.rw-client-error-reporter)
 * and files them in the Error Log. Signed-in users only, rate-limited per user:
 * an anonymous endpoint that writes rows would be a free spam sink.
 */
class ClientErrorController extends Controller
{
    public function store(Request $request): Response
    {
        $report = $request->validate([
            'kind' => ['required', 'string', 'in:error,rejection,livewire,network'],
            'message' => ['required', 'string', 'max:2000'],
            'page' => ['nullable', 'string', 'max:2000'],
            'source' => ['nullable', 'string', 'max:2000'],
            'line' => ['nullable', 'integer', 'min:0'],
            'column' => ['nullable', 'integer', 'min:0'],
            'stack' => ['nullable', 'string', 'max:20000'],
            'status' => ['nullable', 'integer', 'min:0', 'max:999'],
            'client' => ['nullable', 'array', 'max:20'],
            'client.*' => ['nullable', 'string', 'max:500'],
        ]);

        ErrorLogService::recordClient($report);

        return response()->noContent();
    }
}
