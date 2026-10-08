<?php

namespace App\Http\Controllers;

use App\Models\Chapter;
use App\Services\SupabasePublicStorage;
use App\Services\TelegraphPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ChapterNotesController extends Controller
{
    public function publish(Chapter $chapter, TelegraphPublisher $publisher, SupabasePublicStorage $files): JsonResponse
    {
        try {
            $page = $publisher->publish($chapter, $files);
            return response()->json(['url' => $page['url'], 'path' => $page['path']]);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function uploadPdf(Request $request, Chapter $chapter, SupabasePublicStorage $files): JsonResponse
    {
        $data = $request->validate(['pdf' => ['required', 'file', 'mimes:pdf', 'max:20480']]);
        $oldPath = $chapter->pdf_file;
        $path = $files->upload($data['pdf'], 'chapter-pdfs');
        $chapter->forceFill(['pdf_file' => $path])->save();
        if ($oldPath) $files->delete($oldPath);
        return response()->json(['pdf_file' => $path, 'url' => $files->url($path)]);
    }

    public function deletePdf(Chapter $chapter, SupabasePublicStorage $files): JsonResponse
    {
        $files->delete($chapter->pdf_file);
        $chapter->forceFill(['pdf_file' => null])->save();
        return response()->json(['deleted' => true]);
    }
}
