<?php

namespace App\Support;

use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Saves a Livewire upload into a media collection by streaming it, never through
 * getRealPath(). A path only exists while Livewire keeps its temporary uploads on
 * this machine's disk; on the Vercel deploy they sit in the S3/R2 bucket (each
 * request may land on another instance, and /tmp is per instance), so a path
 * would point at nothing. A stream reads from wherever the upload is.
 */
class MediaUpload
{
    public static function attach(HasMedia $model, TemporaryUploadedFile $file, string $collection, string $fileName): Media
    {
        $stream = $file->readStream();

        try {
            return $model->addMediaFromStream($stream)
                // The phone's own name stays readable as the media's name; the stored file gets $fileName.
                ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: pathinfo($fileName, PATHINFO_FILENAME))
                ->usingFileName($fileName)
                ->toMediaCollection($collection);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
