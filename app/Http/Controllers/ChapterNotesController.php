<?php

namespace App\Http\Controllers;

use App\Models\Chapter;
use App\Services\TelegraphPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ChapterNotesController extends Controller
{
    public function publish(Chapter $chapter, TelegraphPublisher $publisher): JsonResponse
    {
        try {
            $page = $publisher->publish($chapter);
            return response()->json(['url' => $page['url'], 'path' => $page['path']]);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function uploadPdf(Request $request, Chapter $chapter): JsonResponse
    {
        $data = $request->validate(['pdf' => ['required', 'file', 'mimes:pdf', 'max:20480']]);
        if ($chapter->pdf_file && ! str_starts_with($chapter->pdf_file, 'http')) Storage::disk('public')->delete($chapter->pdf_file);
        $path = $data['pdf']->store('chapter-pdfs', 'public');
        $chapter->forceFill(['pdf_file' => $path])->save();
        return response()->json(['pdf_file' => $path, 'url' => Storage::disk('public')->url($path)]);
    }

    public function deletePdf(Chapter $chapter): JsonResponse
    {
        if ($chapter->pdf_file && ! str_starts_with($chapter->pdf_file, 'http')) Storage::disk('public')->delete($chapter->pdf_file);
        $chapter->forceFill(['pdf_file' => null])->save();
        return response()->json(['deleted' => true]);
    }
}
