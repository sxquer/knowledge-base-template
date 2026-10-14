<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Services\MeetingRecording;
use Illuminate\Http\Request;

class MeetingRecordingController extends Controller
{
    public function store(Request $request, Page $page, MeetingRecording $meetings): array
    {
        $data = $request->validate(['file' => 'required|file|max:204800', 'title' => 'required|string|max:180', 'expected_revision' => 'required|integer', 'request_id' => 'required|string|min:8|max:100']);

        return $meetings->add($request->user(), $page, $request->file('file'), $data['title'], $data['expected_revision'], $data['request_id']);
    }
}
