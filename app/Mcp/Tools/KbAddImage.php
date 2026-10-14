<?php

namespace App\Mcp\Tools;

use App\Models\Page;
use App\Services\ImageAttachment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\UploadedFile;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class KbAddImage extends Tool
{
    protected string $name = 'kb_add_image';

    protected string $description = 'Upload a PNG, JPEG, WebP or GIF image up to 10 MiB and insert it into an existing page. Send image_base64 without a data URL prefix, filename, page_id, expected_revision, reason and a unique request_id. Optional title describes the image; after_block_id inserts after an observed top-level block, otherwise appends. Returns page_id, revision, block_id and asset metadata. Requires kb:write. Never publishes. Redact personal data before uploading. Retry identical requests with the same request_id.';

    public function schema(JsonSchema $s): array
    {
        return [
            'page_id' => $s->string()->required(),
            'filename' => $s->string()->required(),
            'image_base64' => $s->string()->required(),
            'title' => $s->string(),
            'after_block_id' => $s->string(),
            'expected_revision' => $s->integer()->required(),
            'reason' => $s->string()->required(),
            'request_id' => $s->string()->required(),
        ];
    }

    public function handle(Request $request, ImageAttachment $images): Response
    {
        try {
            return $this->attach($request, $images);
        } catch (HttpExceptionInterface $error) {
            $status = $error->getStatusCode();
            if (! in_array($status, [403, 404, 409, 422], true)) {
                throw $error;
            }
            $message = match ($status) {
                403 => 'Нужны kb:write и права редактирования статьи.',
                404 => 'Статья не найдена или недоступна.',
                default => $error->getMessage(),
            };

            return Response::error($status.': '.$message);
        }
    }

    private function attach(Request $request, ImageAttachment $images): Response
    {
        abort_unless($request->user()?->tokenCan('kb:write') && $request->user()->canEdit(), 403);
        $data = $request->validate([
            'page_id' => 'required|uuid',
            'filename' => 'required|string|max:255',
            'image_base64' => 'required|string',
            'title' => 'sometimes|string|max:180',
            'after_block_id' => 'sometimes|string|regex:/^[a-zA-Z0-9_-]{1,100}$/',
            'expected_revision' => 'required|integer|min:1',
            'reason' => 'required|string|max:500',
            'request_id' => 'required|string|min:8|max:100',
        ]);
        $page = Page::findOrFail($data['page_id']);
        $images->access->requireEdit($request->user(), $page);
        abort_if(strlen($data['image_base64']) > 4 * (int) ceil(ImageAttachment::MAX_BYTES / 3), 422, 'Изображение превышает 10 МиБ.');
        $bytes = base64_decode($data['image_base64'], true);
        abort_if($bytes === false || strlen($bytes) < 1 || strlen($bytes) > ImageAttachment::MAX_BYTES, 422, 'Некорректный base64 или изображение больше 10 МиБ.');
        $path = tempnam(sys_get_temp_dir(), 'kb-image-');
        abort_unless($path, 500, 'Не удалось подготовить изображение.');
        try {
            abort_if(file_put_contents($path, $bytes) !== strlen($bytes), 500, 'Не удалось подготовить изображение.');
            $filename = basename(str_replace('\\', '/', $data['filename']));
            $file = new UploadedFile($path, $filename, null, null, true);

            return Response::json($images->add(
                $request->user(), $page, $file, $data['title'] ?? $filename,
                $data['expected_revision'], $data['request_id'], $data['reason'],
                $data['after_block_id'] ?? null,
            ));
        } finally {
            @unlink($path);
        }
    }
}
