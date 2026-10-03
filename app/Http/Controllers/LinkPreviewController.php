<?php

namespace App\Http\Controllers;

use App\Services\LinkPreviewer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LinkPreviewController extends Controller
{
    /**
     * Return the title, text and image of a link for the post preview.
     */
    public function __invoke(Request $request, LinkPreviewer $previewer): JsonResponse
    {
        $validated = $request->validate(['url' => ['required', 'url:http,https', 'max:2000']]);

        $preview = $previewer->preview($validated['url']);

        if ($preview === null || ($preview['title'] === null && $preview['description'] === null && $preview['image'] === null)) {
            return response()->json(['message' => 'No preview available for this link.'], 404);
        }

        return response()->json($preview);
    }
}
