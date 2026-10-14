<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Page;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MeetingRecording
{
    public function __construct(public Knowledge $knowledge, public Access $access) {}

    public function add(User $user, Page $page, UploadedFile $file, string $title, int $expectedRevision, string $requestId): array
    {
        $this->access->requireEdit($user, $page);
        abort_if($page->archived_at, 422, 'Нельзя добавлять запись в архив.');
        if (! $file->isValid() || $file->getSize() < 1 || $file->getSize() > 200 * 1024 * 1024 || ! in_array($file->getMimeType(), ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/flac', 'audio/x-flac', 'audio/ogg', 'application/ogg', 'audio/mp4', 'video/mp4', 'audio/webm', 'video/webm', 'audio/aac', 'audio/x-m4a'], true)) {
            throw ValidationException::withMessages(['file' => 'Нужен аудиофайл MP3, WAV, FLAC, OGG, M4A, WebM, AAC или видео MP4/WebM до 200 МБ.']);
        }

        $fingerprint = ['page_id' => $page->id, 'title' => $title, 'expected_revision' => $expectedRevision, 'file_hash' => hash_file('sha256', $file->getRealPath())];

        return $this->knowledge->operation($user, $requestId, $fingerprint, function () use ($user, $page, $file, $title, $expectedRevision): array {
            $page = $page->fresh();
            abort_if($page->archived_at, 422, 'Нельзя добавлять запись в архив.');
            abort_if($page->revision !== $expectedRevision, 409, 'Документ уже изменён. Откройте новую версию.');
            $disk = config('filesystems.knowledge_disk', 'local');
            $path = $file->store('assets', $disk);
            if (! $path) {
                abort(500, 'Не удалось сохранить запись.');
            }
            try {
                $asset = Asset::create(['page_id' => $page->id, 'user_id' => $user->id, 'name' => $file->getClientOriginalName(), 'mime' => $file->getMimeType(), 'disk' => $disk, 'path' => $path, 'size' => $file->getSize()]);
                $doc = $page->document;
                $doc['content'][] = ['type' => 'media', 'attrs' => ['assetId' => $asset->id, 'kind' => 'meeting', 'title' => $title]];
                $saved = $this->knowledge->save($user, [...$page->toArray(), 'document' => $doc, 'expected_revision' => $expectedRevision, 'reason' => 'Добавлена запись планёрки «'.$title.'»'], $page);

                return ['page' => $this->knowledge->present($saved), 'asset' => $asset->only(['id', 'name', 'mime', 'size'])];
            } catch (\Throwable $error) {
                Storage::disk($disk)->delete($path);
                throw $error;
            }
        });
    }
}
