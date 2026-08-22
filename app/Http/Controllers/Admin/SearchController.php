<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\GlobalSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The header search box's endpoint.
 *
 * JSON rather than an Inertia page: the box answers while you type, and a
 * partial reload would replace the page under the cursor. Thin by the usual
 * rule — every decision about what a staff member may see, field-office
 * scoping included, lives in GlobalSearch.
 */
class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(
            GlobalSearch::results($request->user(), $request->string('q')->toString())
        );
    }
}
