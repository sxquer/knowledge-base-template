<?php

namespace App\Mcp\Tools;

use App\Models\Page;
use App\Services\MeetingRecording;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\UploadedFile;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class KbAddMeeting extends Tool
{
    protected string $name = 'kb_add_meeting';

    protected string $description = 'Attach an audio or video recording of a meeting to an existing page, without transcription. Send base64 file content, title, filename, page ID, expected revision and a unique request ID. Maximum file size: 25 MiB. Requires kb:write.';

    public function schema(JsonSchema $s): array
    {
        return ['page_id' => $s->string()->required(), 'title' => $s->string()->required(), 'filename' => $s->string()->required(), 'audio_base64' => $s->string()->required(), 'expected_revision' => $s->integer()->required(), 'request_id' => $s->string()->required()];
    }

    public function handle(Request $request, MeetingRecording $meetings): Response
    {
        abort_unless($request->user()?->tokenCan('kb:write') && $request->user()->canEdit(), 403);
        $data = $request->validate(['page_id' => 'required|uuid', 'title' => 'required|string|max:180', 'filename' => 'required|string|max:255', 'audio_base64' => 'required|string', 'expected_revision' => 'required|integer', 'request_id' => 'required|string|min:8|max:100']);
        abort_if(strlen($data['audio_base64']) > 35 * 1024 * 1024, 422, 'Файл превышает 25 МБ.');
        $bytes = base64_decode($data['audio_base64'], true);
        abort_if($bytes === false || strlen($bytes) > 25 * 1024 * 1024, 422, 'Некорректный файл или размер больше 25 МБ.');
        $path = tempnam(sys_get_temp_dir(), 'kb-meeting-');
        abort_unless($path, 500, 'Не удалось подготовить файл.');
        try {
            file_put_contents($path, $bytes);
            $filename = basename(str_replace('\\', '/', $data['filename']));
            $file = new UploadedFile($path, $filename, null, null, true);
            $result = $meetings->add($request->user(), Page::findOrFail($data['page_id']), $file, $data['title'], $data['expected_revision'], $data['request_id']);

            return Response::json($result);
        } finally {
            @unlink($path);
        }
    }
}
