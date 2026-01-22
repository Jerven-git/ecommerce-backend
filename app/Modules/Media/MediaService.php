<?php

namespace App\Modules\Media;

use App\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class MediaService
{
    /**
     * Upload a file and associate it with a model.
     *
     * This method:
     * - Stores the uploaded file in the public disk (default: `storage/app/public/{model}`)
     * - Generates a unique hash and saves file metadata
     * - Creates a related `Media` record associated with the given model
     *
     * @param  UploadedFile  $file  The file being uploaded
     * @param  Model  $model  The Eloquent model to associate the media with
     * @param  string|null  $directory  Optional directory override (default is the model name)
     * @return Media  The created Media model instance
     */
    public function upload(UploadedFile $file, Model $model, ?string $directory = null): Media
    {
        $directory = $directory ?? strtolower(class_basename($model));

        Storage::disk('public')->makeDirectory($directory);

        $path = Storage::disk('public')->put($directory, $file);

        $hash = md5_file($file->getRealPath());

        $media = new Media([
            'hash' => $hash,
            'path' => $path,
            'format' => $file->getClientOriginalExtension(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]);

        $model->media()->save($media);

        return $media;
    }
}
