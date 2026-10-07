<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmailImageRequest;
use App\Models\EmailImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmailImageController extends Controller
{
    /** Upload an image; the response carries the {{image:ID}} token to put in a template body. */
    public function store(EmailImageRequest $request): JsonResponse
    {
        $file = $request->file('image');
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        // Random name: the client's filename is kept only as metadata.
        $path = $file->storeAs(EmailImage::DIRECTORY, Str::uuid().'.'.$extension, EmailImage::DISK);

        $image = EmailImage::create([
            'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 180, ''),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json(['data' => [
            'id' => $image->id,
            'token' => $image->token(),
            'name' => $image->original_name,
            'url' => route('email-images.show', $image, absolute: false),
        ]], 201);
    }

    /** Authenticated view of an image (editor and send-dialog previews). */
    public function show(EmailImage $emailImage): StreamedResponse
    {
        abort_unless(Storage::disk(EmailImage::DISK)->exists($emailImage->path), 404);

        return Storage::disk(EmailImage::DISK)->response($emailImage->path, $emailImage->original_name, [
            'Content-Type' => $emailImage->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
