<?php

namespace App\Http\Controllers;

use App\Jobs\ExportUtilityUsagesJob;
use App\Models\Export;
use App\Models\Property;
use Filament\Notifications\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class UtilityExportController extends Controller
{
    /**
     * Queue a utility-usage export and answer immediately.
     *
     * This used to build the file in-request by calling `$job->handle()`
     * directly ("Run synchronously"), which for the PDF format meant spawning a
     * Node + headless-Chrome process (~150-250MB, seconds of wall time) inside a
     * PHP-FPM worker. The job already ships the finished file as a database
     * notification with a download button, so the request has nothing left to
     * wait for. Callers get 202 + the export id instead of a file download.
     */
    public function export(Request $request, int $propertyId): JsonResponse
    {
        $property = Property::findOrFail($propertyId);
        $user = auth()->user();

        // Simple authorization check: user must own the property or be platform staff
        if (! $user->isPlatformStaff() && $property->landlord_id !== $user->effectiveLandlordId()) {
            abort(403, 'Unauthorized action.');
        }

        $validated = $request->validate([
            'time_period' => 'required|string|in:all,this_year,last_year,custom',
            'from_date' => 'nullable|date',
            'until_date' => 'nullable|date',
            'utility_types' => 'nullable|array',
            'format' => 'required|string|in:csv,xlsx,pdf',
        ]);

        $export = Export::create([
            'user_id' => auth()->id(),
            'file_name' => 'utility_export_'.$propertyId.'_'.time().'.'.$validated['format'],
            'status' => 'pending',
        ]);

        ExportUtilityUsagesJob::dispatch($export, $propertyId, $validated);

        $message = __('Your export is being prepared. You will get a notification with a download link when it is ready.');

        // Same acknowledgement shape as the queued batch invoice PDF. If no
        // worker is running the Export row stays `pending` and download() keeps
        // answering "not ready" — visible, not silent.
        Notification::make()
            ->title(__('Preparing your export'))
            ->body($message)
            ->info()
            ->send();

        return response()->json([
            'queued' => true,
            'export_id' => $export->getKey(),
            'message' => $message,
        ], 202);
    }

    public function download(string $fileId): BinaryFileResponse
    {
        $export = Export::findOrFail($fileId);

        // Security check: only the user who requested the export can download it
        if ($export->user_id !== auth()->id()) {
            abort(403, 'Unauthorized access to export file.');
        }

        if ($export->status !== 'completed' || ! $export->file_path) {
            abort(404, 'Export file is not ready or has failed.');
        }

        $fullPath = storage_path('app/'.$export->file_path);

        if (! file_exists($fullPath)) {
            abort(404, 'File not found on storage.');
        }

        return response()->download($fullPath, $export->file_name);
    }
}
