<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaController extends Controller
{
    public function update(Request $request, int $id): JsonResponse
    {
        $media = Media::findOrFail($id);

        $validated = $request->validate([
            'alt_text' => 'nullable|string|max:255',
        ]);

        $media->update($validated);

        return response()->json(['data' => $media->fresh()]);
    }
}
