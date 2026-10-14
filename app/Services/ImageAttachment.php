<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Page;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ImageAttachment
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(public Knowledge $knowledge, public Access $access) {}

    public function add(User $user, Page $page, UploadedFile $file, string $title, int $expectedRevision, string $requestId, string $reason, ?string $afterBlockId = null): array
    {
        $this->access->requireEdit($user, $page);
        abort_if($page->archived_at, 422, 'Нельзя добавлять изображение в архив.');
        $extensions = ['image/png' => ['png'], 'image/jpeg' => ['jpg', 'jpeg'], 'image/webp' => ['webp'], 'image/gif' => ['gif']];
        $mime = $file->getMimeType();
        $size = $file->getSize();
        $dimensions = @getimagesize($file->getRealPath());
        if (! $file->isValid() || $size < 1 || $size > self::MAX_BYTES || ! isset($extensions[$mime]) || ! in_array(strtolower($file->getClientOriginalExtension()), $extensions[$mime], true) || ! $dimensions || $dimensions[0] < 1 || $dimensions[1] < 1 || ($dimensions['mime'] ?? '') !== $mime) {
            throw ValidationException::withMessages(['file' => 'Нужно изображение PNG, JPEG, WebP или GIF до 10 МиБ с соответствующим расширением.']);
        }
        $fingerprint = [
            'action' => 'add_image', 'page_id' => $page->id,
            'filename' => $file->getClientOriginalName(), 'title' => $title,
            'expected_revision' => $expectedRevision, 'reason' => $reason,
            'after_block_id' => $afterBlockId, 'file_hash' => hash_file('sha256', $file->getRealPath()),
        ];

        return $this->knowledge->operation($user, $requestId, $fingerprint, function () use ($user, $page, $file, $title, $expectedRevision, $reason, $afterBlockId, $mime, $size): array {
            $page = $page->fresh();
            $this->access->requireEdit($user, $page);
            abort_if($page->archived_at, 422, 'Нельзя добавлять изображение в архив.');
            abort_if($page->revision !== $expectedRevision, 409, 'Документ уже изменён. Откройте новую версию.');
            $doc = $page->document;
            $position = count($doc['content'] ?? []);
            if ($afterBlockId !== null) {
                $position = null;
                foreach ($doc['content'] ?? [] as $index => $node) {
                    if (($node['attrs']['id'] ?? null) === $afterBlockId) {
                        $position = $index + 1;
                        break;
                    }
                }
                abort_if($position === null, 422, 'Указанный блок верхнего уровня не найден. Перечитайте статью.');
            }
            $disk = config('filesystems.knowledge_disk', 'local');
            $path = $file->store('assets', $disk);
            abort_unless($path, 500, 'Не удалось сохранить изображение.');
            try {
                $asset = Asset::create(['page_id' => $page->id, 'user_id' => $user->id, 'name' => $file->getClientOriginalName(), 'mime' => $mime, 'disk' => $disk, 'path' => $path, 'size' => $size]);
                $blockId = (string) Str::uuid();
                $doc['content'] ??= [];
                array_splice($doc['content'], $position, 0, [[
                    'type' => 'media',
                    'attrs' => ['id' => $blockId, 'assetId' => $asset->id, 'kind' => 'image', 'title' => $title],
                ]]);
                $saved = $this->knowledge->save($user, [...$page->toArray(), 'document' => $doc, 'expected_revision' => $expectedRevision, 'reason' => $reason], $page);

                return ['page_id' => $saved->id, 'revision' => $saved->revision, 'block_id' => $blockId, 'asset' => $asset->only(['id', 'name', 'mime', 'size'])];
            } catch (\Throwable $error) {
                Storage::disk($disk)->delete($path);
                throw $error;
            }
        });
    }
}
