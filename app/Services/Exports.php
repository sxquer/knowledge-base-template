<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Page;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class Exports
{
    public function __construct(public Access $access, public Documents $documents) {}

    public function download(User $user, Page $page, string $format): StreamedResponse
    {
        $this->access->requireRead($user, $page);
        $directory = $this->temporaryDirectory();

        try {
            $docx = $this->createDocx($directory, $this->pages($user, $page));
            $path = $format === 'pdf' ? $this->convertToPdf($docx, $directory) : $docx;
        } catch (ExportException $exception) {
            $this->deleteDirectory($directory);
            abort(503, $exception->getMessage());
        } catch (\Throwable $exception) {
            $this->deleteDirectory($directory);
            report($exception);
            abort(500, 'Не удалось подготовить файл для скачивания.');
        }

        $name = $this->fileName($page->title, $format);
        $contentType = $format === 'pdf'
            ? 'application/pdf'
            : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

        return response()->streamDownload(function () use ($path, $directory): void {
            readfile($path);
            $this->deleteDirectory($directory);
        }, $name, ['Content-Type' => $contentType, 'Cache-Control' => 'private, no-store']);
    }

    /**
     * @return array<int, array{page: Page, depth: int}>
     */
    private function pages(User $user, Page $root): array
    {
        $accessible = $this->access->pages($user, false, true)->keyBy('id');
        $root = $accessible->get($root->id);
        abort_unless($root, 404);

        $entries = [];
        $walk = function (Page $current, int $depth) use (&$walk, &$entries, $accessible): void {
            $entries[] = ['page' => $current, 'depth' => $depth];
            $accessible
                ->filter(fn (Page $candidate) => $candidate->parent_id === $current->id)
                ->sortBy(fn (Page $candidate) => sprintf('%010d-%s', $candidate->position, $candidate->title))
                ->each(fn (Page $child) => $walk($child, $depth + 1));
        };

        if ($root->kind === 'section') {
            $walk($root, 0);
        } else {
            $entries[] = ['page' => $root, 'depth' => 0];
        }

        if (! $user->canViewCalls()) {
            foreach ($entries as &$entry) {
                $entry['page']->document = $this->documents->redactCalls($entry['page']->document);
            }
            unset($entry);
        }

        return $entries;
    }

    /**
     * @param  array<int, array{page: Page, depth: int}>  $entries
     */
    private function createDocx(string $directory, array $entries): string
    {
        $path = $directory.'/export.docx';
        $images = $this->imageReferences($entries);
        $links = $this->linkReferences($entries, count($images) + 3);
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new ExportException('Не удалось создать DOCX-файл.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRelationships());
        $zip->addFromString('word/_rels/document.xml.rels', $this->documentRelationships($images, $links));
        foreach ($images as $image) {
            $zip->addFromString('word/media/'.$image['filename'], $image['contents']);
        }
        $zip->addFromString('word/document.xml', $this->document($entries, $images, $links));
        $zip->addFromString('word/styles.xml', $this->styles());
        $zip->addFromString('word/settings.xml', $this->settings());
        $zip->close();

        return $path;
    }

    /**
     * @param  array<int, array{page: Page, depth: int}>  $entries
     */
    private function document(array $entries, array $images, array $links): string
    {
        $body = '';
        $bookmarkId = 1;
        $bookmarks = [];

        foreach ($entries as $entry) {
            $bookmarks[$entry['page']->id] = ['id' => $bookmarkId, 'name' => 'page'.str_replace('-', '', $entry['page']->id)];
            $bookmarkId++;
        }

        $root = $entries[0]['page'];
        if (count($entries) > 1) {
            $body .= $this->paragraph($this->runs($root->title, ['bold' => true, 'size' => 36]), 'Title');
            if ($root->subtitle) {
                $body .= $this->paragraph($this->runs($root->subtitle, ['size' => 22]), 'Subtitle');
            }
            $body .= $this->paragraph($this->runs('Экспортировано '.now()->format('d.m.Y H:i')), 'Subtitle');
            $body .= $this->paragraph($this->runs('Оглавление', ['bold' => true, 'size' => 28]), 'Heading1');
            foreach ($entries as $entry) {
                $bookmark = $bookmarks[$entry['page']->id];
                $indent = $entry['depth'] * 360;
                $body .= '<w:p><w:pPr><w:ind w:left="'.$indent.'"/></w:pPr><w:hyperlink w:anchor="'.$bookmark['name'].'" w:history="1">'.$this->runs($entry['page']->title).'</w:hyperlink></w:p>';
            }
            $body .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
        }

        foreach ($entries as $index => $entry) {
            $page = $entry['page'];
            $bookmark = $bookmarks[$page->id];
            if ($index > 0) {
                $body .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
            }
            $heading = max(1, min(4, $entry['depth'] + 1));
            $body .= '<w:p><w:pPr><w:pStyle w:val="Heading'.$heading.'"/></w:pPr><w:bookmarkStart w:id="'.$bookmark['id'].'" w:name="'.$bookmark['name'].'"/>'.$this->runs($page->title, ['bold' => true]).'<w:bookmarkEnd w:id="'.$bookmark['id'].'"/></w:p>';
            if ($page->subtitle) {
                $body .= $this->paragraph($this->runs($page->subtitle, ['size' => 22]), 'Subtitle');
            }
            $body .= $this->metadata($page);
            $body .= $this->blocks($page->document['content'] ?? [], $images, $links);
            $body .= $this->sources($page, $links);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><w:body>'.$body.'<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134"/></w:sectPr></w:body></w:document>';
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private function blocks(array $nodes, array $images, array $links, int $listLevel = 0, bool $tableHeader = false): string
    {
        $blocks = '';
        foreach ($nodes as $node) {
            $type = $node['type'] ?? '';
            if ($type === 'paragraph') {
                $blocks .= $this->paragraph($this->inlineRuns($node['content'] ?? [], $links, $tableHeader));
            } elseif ($type === 'heading') {
                $level = max(1, min(4, (int) ($node['attrs']['level'] ?? 2)));
                $blocks .= $this->paragraph($this->inlineRuns($node['content'] ?? [], $links), 'Heading'.$level);
            } elseif ($type === 'blockquote') {
                $blocks .= $this->paragraph($this->inlineRuns($node['content'] ?? [], $links), 'Quote');
            } elseif (in_array($type, ['bulletList', 'orderedList'], true)) {
                foreach ($node['content'] ?? [] as $number => $item) {
                    $prefix = $type === 'bulletList' ? '• ' : ($number + 1).'. ';
                    $blocks .= $this->listItem($item, $prefix, $images, $links, $listLevel);
                }
            } elseif ($type === 'taskList') {
                foreach ($node['content'] ?? [] as $item) {
                    $prefix = ! empty($item['attrs']['checked']) ? '☑ ' : '☐ ';
                    $blocks .= $this->listItem($item, $prefix, $images, $links, $listLevel);
                }
            } elseif ($type === 'table') {
                $blocks .= $this->table($node, $images, $links);
            } elseif (($type === 'image' || ($type === 'media' && ($node['attrs']['kind'] ?? '') === 'image')) && isset($images[$node['attrs']['assetId'] ?? ''])) {
                $blocks .= $this->image($node, $images[$node['attrs']['assetId']]);
            } elseif ($type === 'horizontalRule') {
                $blocks .= $this->paragraph($this->runs('— — —'));
            } elseif ($type === 'codeBlock') {
                $blocks .= $this->paragraph($this->runs($this->documents->text($node), ['code' => true]), 'Code');
            } elseif ($type !== 'hardBreak') {
                $text = trim($this->documents->text($node));
                if ($text !== '') {
                    $blocks .= $this->paragraph($this->runs($text));
                }
            }
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function listItem(array $item, string $prefix, array $images, array $links, int $listLevel): string
    {
        $content = $item['content'] ?? [];
        $first = array_shift($content);
        $runs = $this->runs($prefix).($first ? $this->inlineRuns($first['content'] ?? [], $links) : '');
        $indent = 360 + ($listLevel * 360);
        $blocks = '<w:p><w:pPr><w:ind w:left="'.$indent.'" w:hanging="180"/></w:pPr>'.$runs.'</w:p>';
        foreach ($content as $node) {
            $blocks .= $this->blocks([$node], $images, $links, $listLevel + 1);
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function table(array $node, array $images, array $links): string
    {
        $rows = '';
        $columnCount = max(array_map(fn (array $row) => count($row['content'] ?? []), $node['content'] ?? [['content' => []]]));
        $width = max(1, intdiv(9972, $columnCount));
        foreach ($node['content'] ?? [] as $index => $row) {
            $cells = '';
            $isHeader = $index === 0 && collect($row['content'] ?? [])->contains(fn (array $cell) => ($cell['type'] ?? '') === 'tableHeader');
            foreach ($row['content'] ?? [] as $cell) {
                $header = ($cell['type'] ?? '') === 'tableHeader';
                $cellBlocks = $this->blocks($cell['content'] ?? [], $images, $links, 0, $header);
                $shading = $header ? '<w:shd w:val="clear" w:fill="EAF0FF"/>' : '';
                $cells .= '<w:tc><w:tcPr><w:tcW w:w="'.$width.'" w:type="dxa"/>'.$shading.'<w:tcMar><w:top w:w="110" w:type="dxa"/><w:left w:w="110" w:type="dxa"/><w:bottom w:w="110" w:type="dxa"/><w:right w:w="110" w:type="dxa"/></w:tcMar><w:vAlign w:val="center"/></w:tcPr>'.$cellBlocks.'</w:tc>';
            }
            $rows .= '<w:tr>'.($isHeader ? '<w:trPr><w:tblHeader/></w:trPr>' : '').$cells.'</w:tr>';
        }

        return '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/><w:tblLayout w:type="autofit"/><w:tblBorders><w:top w:val="single" w:sz="4" w:color="D9D9D9"/><w:left w:val="single" w:sz="4" w:color="D9D9D9"/><w:bottom w:val="single" w:sz="4" w:color="D9D9D9"/><w:right w:val="single" w:sz="4" w:color="D9D9D9"/><w:insideH w:val="single" w:sz="4" w:color="D9D9D9"/><w:insideV w:val="single" w:sz="4" w:color="D9D9D9"/></w:tblBorders></w:tblPr><w:tblGrid>'.str_repeat('<w:gridCol w:w="'.$width.'"/>', $columnCount).'</w:tblGrid>'.$rows.'</w:tbl>';
    }

    private function metadata(Page $page): string
    {
        $status = ['draft' => 'Черновик', 'review' => 'На проверке', 'current' => 'Актуально', 'archived' => 'Архив'][$page->status] ?? $page->status;
        $rows = ['Статус: '.$status, 'Владелец: '.($page->owner_label ?: 'не указан'), 'Область: '.($page->area ?: 'не указана'), 'Версия: '.$page->revision, 'Последнее изменение: '.$page->updated_at?->format('d.m.Y')];

        return implode('', array_map(fn (string $row) => $this->paragraph($this->runs($row, ['size' => 18]), 'Subtitle'), $rows));
    }

    private function sources(Page $page, array $links): string
    {
        if (! count($page->sources ?? [])) {
            return '';
        }
        $blocks = $this->paragraph($this->runs('Источники', ['bold' => true]), 'Heading3');
        foreach ($page->sources as $source) {
            $line = $source['title'] ?? 'Источник';
            if (! empty($source['author'])) {
                $line .= ' · '.$source['author'];
            }
            if (! empty($source['published_at'])) {
                $line .= ' · опубликовано '.$source['published_at'];
            }
            if (! empty($source['checked_at'])) {
                $line .= ' · проверено '.$source['checked_at'];
            }
            if (! empty($source['url'])) {
                $line .= ' · '.$source['url'];
            }
            $blocks .= $this->paragraph($this->runs('• '.$line).(! empty($source['url']) && isset($links[$source['url']]) ? '<w:hyperlink r:id="'.$links[$source['url']].'">'.$this->runs(' Открыть источник', ['underline' => true]).'</w:hyperlink>' : ''));
        }

        return $blocks;
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private function inlineRuns(array $nodes, array $links, bool $forceBold = false): string
    {
        $runs = '';
        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === 'text') {
                $marks = collect($node['marks'] ?? [])->pluck('type')->all();
                $options = [
                    'bold' => $forceBold || in_array('bold', $marks, true),
                    'italic' => in_array('italic', $marks, true),
                    'underline' => in_array('underline', $marks, true),
                    'strike' => in_array('strike', $marks, true),
                    'code' => in_array('code', $marks, true),
                ];
                $href = collect($node['marks'] ?? [])->first(fn (array $mark) => ($mark['type'] ?? '') === 'link')['attrs']['href'] ?? null;
                if ($href && isset($links[$href])) {
                    $options['underline'] = true;
                    $runs .= '<w:hyperlink r:id="'.$links[$href].'">'.$this->runs((string) ($node['text'] ?? ''), $options).'</w:hyperlink>';
                } else {
                    $runs .= $this->runs((string) ($node['text'] ?? ''), $options);
                }
            } elseif (($node['type'] ?? '') === 'hardBreak') {
                $runs .= '<w:r><w:br/></w:r>';
            } else {
                $runs .= $this->inlineRuns($node['content'] ?? [], $links, $forceBold);
            }
        }

        return $runs;
    }

    /**
     * @param  array{bold?: bool, italic?: bool, underline?: bool, strike?: bool, code?: bool, size?: int}  $options
     */
    private function runs(string $text, array $options = []): string
    {
        $properties = '';
        if ($options['bold'] ?? false) {
            $properties .= '<w:b/>';
        }
        if ($options['italic'] ?? false) {
            $properties .= '<w:i/>';
        }
        if ($options['underline'] ?? false) {
            $properties .= '<w:u w:val="single"/>';
        }
        if ($options['strike'] ?? false) {
            $properties .= '<w:strike/>';
        }
        if ($options['code'] ?? false) {
            $properties .= '<w:rFonts w:ascii="Courier New" w:hAnsi="Courier New" w:cs="Courier New"/>';
        }
        if (isset($options['size'])) {
            $properties .= '<w:sz w:val="'.$options['size'].'"/><w:szCs w:val="'.$options['size'].'"/>';
        }
        $properties = $properties ? '<w:rPr>'.$properties.'</w:rPr>' : '';
        $parts = preg_split('/(\\r\\n|\\n|\\r)/u', $text) ?: [''];

        return implode('<w:r><w:br/></w:r>', array_map(fn (string $part) => '<w:r>'.$properties.'<w:t xml:space="preserve">'.$this->xml($part).'</w:t></w:r>', $parts));
    }

    private function paragraph(string $runs, ?string $style = null): string
    {
        $properties = $style ? '<w:pPr><w:pStyle w:val="'.$style.'"/></w:pPr>' : '';

        return '<w:p>'.$properties.($runs ?: '<w:r><w:t></w:t></w:r>').'</w:p>';
    }

    private function convertToPdf(string $docx, string $directory): string
    {
        $binary = (string) config('services.exports.soffice_binary', 'soffice');
        $profile = $directory.'/libreoffice-profile';
        if (! mkdir($profile, 0700, true) && ! is_dir($profile)) {
            throw new ExportException('Не удалось подготовить LibreOffice для PDF-экспорта.');
        }
        $profileUri = 'file://'.$profile;
        $process = @proc_open([$binary, '--headless', '-env:UserInstallation='.$profileUri, '--convert-to', 'pdf:writer_pdf_Export', '--outdir', $directory, $docx], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new ExportException('Экспорт PDF недоступен: на сервере не найден LibreOffice.');
        }

        $started = microtime(true);
        do {
            $status = proc_get_status($process);
            if (microtime(true) - $started > 45) {
                proc_terminate($process);
                throw new ExportException('Подготовка PDF заняла слишком много времени. Попробуйте экспортировать меньший раздел.');
            }
            usleep(100000);
        } while ($status['running']);

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        $exitCode = proc_close($process);
        $pdf = $directory.'/export.pdf';
        if ($exitCode !== 0 || ! is_file($pdf)) {
            throw new ExportException('Не удалось подготовить PDF. Проверьте, что LibreOffice доступен на сервере.');
        }

        return $pdf;
    }

    /**
     * @param  array<int, array{page: Page, depth: int}>  $entries
     * @return array<string, array{relationship: string, filename: string, contents: string, width: int, height: int}>
     */
    private function imageReferences(array $entries): array
    {
        $assetIds = [];
        foreach ($entries as $entry) {
            foreach ($this->documents->assetIds($entry['page']->document ?? []) as $assetId) {
                $assetIds[] = $assetId;
            }
        }

        $assets = Asset::query()->whereIn('id', array_unique($assetIds))->get()->keyBy('id');
        $images = [];
        foreach (array_unique($assetIds) as $assetId) {
            $asset = $assets->get($assetId);
            if (! $asset || ! str_starts_with((string) $asset->mime, 'image/')) {
                continue;
            }

            try {
                $contents = Storage::disk($asset->disk)->get($asset->path);
            } catch (\Throwable) {
                continue;
            }

            $dimensions = @getimagesizefromstring($contents);
            $extension = match ($asset->mime) {
                'image/jpeg', 'image/jpg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                default => null,
            };
            if (! $dimensions || ! $extension) {
                continue;
            }

            [$pixelWidth, $pixelHeight] = $dimensions;
            $maxWidth = 6.65 * 914400;
            $maxHeight = 8.5 * 914400;
            $width = $pixelWidth * 914400 / 96;
            $height = $pixelHeight * 914400 / 96;
            $scale = min(1, $maxWidth / $width, $maxHeight / $height);
            $images[$assetId] = [
                'relationship' => 'rId'.(count($images) + 3),
                'filename' => 'image'.(count($images) + 1).'.'.$extension,
                'contents' => $contents,
                'width' => max(1, (int) round($width * $scale)),
                'height' => max(1, (int) round($height * $scale)),
            ];
        }

        return $images;
    }

    /**
     * @param  array<int, array{page: Page, depth: int}>  $entries
     * @return array<string, string>
     */
    private function linkReferences(array $entries, int $firstId): array
    {
        $urls = [];
        $walk = function (array $nodes) use (&$walk, &$urls): void {
            foreach ($nodes as $node) {
                foreach ($node['marks'] ?? [] as $mark) {
                    $href = $mark['type'] === 'link' ? ($mark['attrs']['href'] ?? null) : null;
                    if (is_string($href) && $this->isExportLink($href)) {
                        $urls[] = $href;
                    }
                }
                if (is_array($node['content'] ?? null)) {
                    $walk($node['content']);
                }
            }
        };

        foreach ($entries as $entry) {
            $walk($entry['page']->document['content'] ?? []);
            foreach ($entry['page']->sources ?? [] as $source) {
                if (is_string($source['url'] ?? null) && $this->isExportLink($source['url'])) {
                    $urls[] = $source['url'];
                }
            }
        }

        return collect($urls)->unique()->values()->mapWithKeys(fn (string $url, int $index) => [$url => 'rId'.($firstId + $index)])->all();
    }

    private function isExportLink(string $url): bool
    {
        return (filter_var($url, FILTER_VALIDATE_URL) !== false && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true))
            || str_starts_with($url, 'mailto:');
    }

    /**
     * @param  array<string, array{relationship: string, filename: string, contents: string, width: int, height: int}>  $images
     * @param  array<string, string>  $links
     */
    private function documentRelationships(array $images, array $links): string
    {
        $relationships = '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/>';
        foreach ($images as $image) {
            $relationships .= '<Relationship Id="'.$image['relationship'].'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/'.$this->xml($image['filename']).'"/>';
        }
        foreach ($links as $url => $relationship) {
            $relationships .= '<Relationship Id="'.$relationship.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="'.$this->xml($url).'" TargetMode="External"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relationships.'</Relationships>';
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array{relationship: string, filename: string, contents: string, width: int, height: int}  $image
     */
    private function image(array $node, array $image): string
    {
        $name = $node['attrs']['title'] ?? $node['attrs']['alt'] ?? $image['filename'];
        $description = $node['attrs']['alt'] ?? $name;
        $id = max(1, (int) sprintf('%u', crc32($image['filename'])));
        $width = $image['width'];
        $height = $image['height'];

        return '<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="'.$width.'" cy="'.$height.'"/><wp:docPr id="'.$id.'" name="'.$this->xml($name).'" descr="'.$this->xml($description).'"/><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="'.$this->xml($name).'"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="'.$image['relationship'].'"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$width.'" cy="'.$height.'"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
    }

    private function temporaryDirectory(): string
    {
        $directory = storage_path('app/private/exports/'.Str::uuid());
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new ExportException('Не удалось подготовить временное хранилище для экспорта.');
        }

        return $directory;
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($directory);
    }

    private function fileName(string $title, string $format): string
    {
        $name = Str::slug($title, '-', 'ru') ?: 'marketingbro-export';

        return $name.'.'.$format;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="jpg" ContentType="image/jpeg"/><Default Extension="png" ContentType="image/png"/><Default Extension="gif" ContentType="image/gif"/><Default Extension="webp" ContentType="image/webp"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/></Types>';
    }

    private function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/><w:sz w:val="22"/></w:rPr></w:rPrDefault></w:docDefaults><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:pPr><w:spacing w:after="160" w:line="276" w:lineRule="auto"/></w:pPr></w:style><w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:pPr><w:spacing w:after="280"/></w:pPr><w:rPr><w:b/><w:sz w:val="36"/><w:color w:val="000000"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Subtitle"><w:name w:val="Subtitle"/><w:pPr><w:spacing w:after="120"/></w:pPr><w:rPr><w:color w:val="555555"/><w:sz w:val="22"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:pPr><w:spacing w:before="320" w:after="180"/><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:b/><w:sz w:val="30"/><w:color w:val="000000"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:pPr><w:spacing w:before="260" w:after="140"/><w:outlineLvl w:val="1"/></w:pPr><w:rPr><w:b/><w:sz w:val="26"/><w:color w:val="000000"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Heading3"><w:name w:val="heading 3"/><w:pPr><w:spacing w:before="220" w:after="120"/><w:outlineLvl w:val="2"/></w:pPr><w:rPr><w:b/><w:sz w:val="24"/><w:color w:val="000000"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Heading4"><w:name w:val="heading 4"/><w:pPr><w:spacing w:before="180" w:after="100"/><w:outlineLvl w:val="3"/></w:pPr><w:rPr><w:b/><w:sz w:val="22"/><w:color w:val="000000"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Quote"><w:name w:val="Quote"/><w:pPr><w:ind w:left="360"/><w:spacing w:before="80" w:after="120"/></w:pPr><w:rPr><w:i/><w:color w:val="555555"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Code"><w:name w:val="Code"/><w:pPr><w:spacing w:after="120"/></w:pPr><w:rPr><w:rFonts w:ascii="Courier New" w:hAnsi="Courier New"/><w:sz w:val="18"/></w:rPr></w:style></w:styles>';
    }

    private function settings(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:themeFontLang w:val="ru-RU"/></w:settings>';
    }
}

class ExportException extends \RuntimeException {}
