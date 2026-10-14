<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\CallRecording;
use App\Models\PasswordEntry;
use App\Models\User;
use App\Models\WidgetDefinition;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Documents
{
    const NODES = ['doc', 'paragraph', 'text', 'heading', 'blockquote', 'bulletList', 'orderedList', 'listItem', 'taskList', 'taskItem', 'codeBlock', 'hardBreak', 'horizontalRule', 'table', 'tableRow', 'tableCell', 'tableHeader', 'image', 'youtube', 'media', 'kbWidget', 'callRecording', 'callReference', 'passwordReference'];

    public function __construct(public PasswordVault $passwords) {}

    public function validate(array $doc, ?string $pageId = null, ?User $user = null): array
    {
        if (($doc['type'] ?? '') !== 'doc' || strlen(json_encode($doc)) > 1500000) {
            throw ValidationException::withMessages(['document' => 'Документ должен быть JSON doc размером до 1,5 МБ.']);
        }
        $ids = [];
        $count = 0;
        $walk = function (array $node, int $depth = 0) use (&$walk, &$ids, &$count, $pageId, $user) {
            if (++$count > 15000 || $depth > 30 || ! in_array($node['type'] ?? '', self::NODES, true)) {
                throw ValidationException::withMessages(['document' => 'Неподдерживаемый блок или слишком глубокая структура.']);
            }
            $type = $node['type'];
            $out = ['type' => $type];
            $attrs = $node['attrs'] ?? [];
            if (! is_array($attrs) || isset($node['content']) && ! is_array($node['content']) || isset($node['marks']) && ! is_array($node['marks'])) {
                throw ValidationException::withMessages(['document' => 'Некорректный формат блока.']);
            }
            if (isset($attrs['id']) && (! is_string($attrs['id']) || ! preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $attrs['id']))) {
                throw ValidationException::withMessages(['document' => 'Некорректный id блока.']);
            }
            if ($type === 'text') {
                $out['text'] = (string) ($node['text'] ?? '');
            }
            if (! in_array($type, ['doc', 'text', 'hardBreak'])) {
                $id = $attrs['id'] ?? (string) Str::uuid();
                if (isset($ids[$id])) {
                    $id = (string) Str::uuid();
                } $ids[$id] = true;
                $out['attrs']['id'] = $id;
            }
            $allowed = ['callReference' => ['recordingId'], 'callRecording' => ['recordingId'], 'passwordReference' => ['passwordEntryId'], 'heading' => ['level'], 'orderedList' => ['start'], 'taskItem' => ['checked'], 'codeBlock' => ['language'], 'tableCell' => ['colspan', 'rowspan', 'colwidth'], 'tableHeader' => ['colspan', 'rowspan', 'colwidth'], 'image' => ['assetId', 'alt', 'title'], 'media' => ['assetId', 'kind', 'title'], 'youtube' => ['src'], 'kbWidget' => ['definition', 'schemaVersion', 'data']];
            foreach ($allowed[$type] ?? [] as $a) {
                if (array_key_exists($a, $attrs)) {
                    $out['attrs'][$a] = $attrs[$a];
                }
            }
            if ($type === 'callRecording') {
                $call = CallRecording::find($attrs['recordingId'] ?? '');
                if (! $call || ! $pageId || $call->page_id !== $pageId) {
                    throw ValidationException::withMessages(['document' => 'Звонок не принадлежит этой странице.']);
                }
            }
            if ($type === 'callReference') {
                $call = CallRecording::find($attrs['recordingId'] ?? '');
                if (! $call || ! $user) {
                    throw ValidationException::withMessages(['document' => 'Звонок недоступен.']);
                }
                app(CallLibrary::class)->requireRead($user, $call);
            }
            if ($type === 'passwordReference') {
                $entry = PasswordEntry::find($attrs['passwordEntryId'] ?? '');
                if (! $entry || ! $user || ! $this->passwords->canReveal($user, $entry)) {
                    throw ValidationException::withMessages(['document' => 'Нет доступа к данным, которые вы пытаетесь добавить в статью.']);
                }
            }
            if (in_array($type, ['image', 'media'])) {
                $asset = Asset::find($attrs['assetId'] ?? '');
                if (! $asset || ! $pageId || $asset->page_id !== $pageId) {
                    throw ValidationException::withMessages(['document' => 'Файл не принадлежит этой странице.']);
                }
                if ($type === 'media' && ($attrs['kind'] ?? '') === 'meeting' && (! is_string($attrs['title'] ?? null) || trim($attrs['title']) === '' || mb_strlen($attrs['title']) > 180 || ! str_starts_with($asset->mime, 'audio/') && ! in_array($asset->mime, ['video/mp4', 'video/webm', 'application/ogg'], true))) {
                    throw ValidationException::withMessages(['document' => 'Некорректная запись планёрки.']);
                }
                if ($type === 'media' && ($attrs['kind'] ?? '') === 'meeting') {
                    $out['attrs']['mime'] = $asset->mime;
                }
            }
            if ($type === 'youtube' && ! preg_match('~^https://(www\.)?(youtube\.com/(watch\?|embed/)|youtu\.be/)[\w?=&/-]+$~', $attrs['src'] ?? '')) {
                throw ValidationException::withMessages(['document' => 'Нужна HTTPS-ссылка на YouTube.']);
            }
            if ($type === 'kbWidget') {
                $def = WidgetDefinition::where('key', $attrs['definition'] ?? '')->first();
                if (! $def || (int) ($attrs['schemaVersion'] ?? 0) !== $def->schema_version) {
                    throw ValidationException::withMessages(['document' => 'Неизвестная версия виджета.']);
                }
                $data = $attrs['data'] ?? [];
                $clean = [];
                foreach ($def->fields as $f) {
                    $value = $data[$f['key']] ?? ($f['type'] === 'boolean' ? false : '');
                    if (($f['required'] ?? false) && $value === '') {
                        throw ValidationException::withMessages(['document' => 'Заполните поле «'.$f['label'].'».']);
                    }
                    if ($f['type'] === 'number' && $value !== '' && ! is_numeric($value)) {
                        throw ValidationException::withMessages(['document' => 'Поле «'.$f['label'].'» должно быть числом.']);
                    }
                    if (! is_scalar($value) || (is_string($value) && mb_strlen($value) > 10000)) {
                        throw ValidationException::withMessages(['document' => 'Недопустимое значение виджета.']);
                    }
                    $clean[$f['key']] = $f['type'] === 'boolean' ? (bool) $value : $value;
                }
                $out['attrs']['data'] = $clean;
            }
            foreach ($node['marks'] ?? [] as $m) {
                if (in_array($m['type'] ?? '', ['bold', 'italic', 'strike', 'code', 'underline'])) {
                    $out['marks'][] = ['type' => $m['type']];
                }
                if (($m['type'] ?? '') === 'link' && preg_match('~^(https?://|mailto:|/p/|/app/)~', $m['attrs']['href'] ?? '')) {
                    $out['marks'][] = ['type' => 'link', 'attrs' => ['href' => $m['attrs']['href'], 'target' => '_blank', 'rel' => 'noopener noreferrer']];
                }
            }
            if (isset($node['content'])) {
                $out['content'] = array_map(fn ($n) => $walk($n, $depth + 1), $node['content']);
            }

            return $out;
        };

        return $walk($doc);
    }

    public function text(array $node): string
    {
        if (($node['type'] ?? '') === 'text') {
            return $node['text'] ?? '';
        }
        if (($node['type'] ?? '') === 'callRecording') {
            $call = $node['attrs']['snapshot'] ?? CallRecording::find($node['attrs']['recordingId'] ?? '')?->snapshot();

            return 'Звонок: '.($call['title'] ?? '')."\n".($call['transcript'] ?? '');
        }
        if (($node['type'] ?? '') === 'passwordReference') {
            return '';
        }
        if (in_array($node['type'] ?? '', ['image', 'media'])) {
            $asset = Asset::find($node['attrs']['assetId'] ?? '');
            $kind = $node['type'] === 'image' ? 'image' : ($node['attrs']['kind'] ?? 'file');
            $label = ['image' => 'Изображение', 'video' => 'Видео', 'audio' => 'Аудио', 'meeting' => 'Планёрка', 'file' => 'Вложение'][$kind] ?? 'Вложение';

            return $label.': '.($kind === 'meeting' ? ($node['attrs']['title'] ?? '') : ($asset?->name ?? 'Файл'));
        }
        if (($node['type'] ?? '') === 'youtube') {
            return 'Видео YouTube: '.($node['attrs']['src'] ?? '');
        }
        if (($node['type'] ?? '') === 'hardBreak') {
            return "\n";
        }
        if (($node['type'] ?? '') === 'kbWidget') {
            $def = WidgetDefinition::where('key', $node['attrs']['definition'] ?? '')->first();
            $rows = [$def?->name ?? 'Виджет'];
            foreach ($def?->fields ?? [] as $f) {
                $rows[] = $f['label'].': '.json_encode($node['attrs']['data'][$f['key']] ?? '', JSON_UNESCAPED_UNICODE);
            }

            return implode("\n", $rows);
        }
        $sep = in_array($node['type'] ?? '', ['paragraph', 'heading']) ? '' : "\n";

        return implode($sep, array_map(fn ($n) => $this->text($n), $node['content'] ?? []));
    }

    public function assetIds(array $doc): array
    {
        $ids = [];
        $walk = function ($n) use (&$walk, &$ids) {
            if (($n['type'] ?? '') === 'callRecording' && ! empty($n['attrs']['snapshot']['asset_id'])) {
                $ids[] = $n['attrs']['snapshot']['asset_id'];
            }
            if (isset($n['attrs']['assetId'])) {
                $ids[] = $n['attrs']['assetId'];
            } foreach ($n['content'] ?? [] as $c) {
                $walk($c);
            }
        };
        $walk($doc);

        return array_unique($ids);
    }

    public function recordingIds(array $document): array
    {
        $ids = [];
        $walk = function (array $node) use (&$walk, &$ids): void {
            if (($node['type'] ?? '') === 'callRecording') {
                $ids[] = $node['attrs']['recordingId'] ?? '';
            }
            foreach ($node['content'] ?? [] as $child) {
                $walk($child);
            }
        };
        $walk($document);

        return array_values(array_unique($ids));
    }

    public function snapshot(array $document): array
    {
        if (($document['type'] ?? '') === 'callRecording') {
            $document['attrs']['snapshot'] = CallRecording::find($document['attrs']['recordingId'] ?? '')?->snapshot();
        }
        if (isset($document['content'])) {
            $document['content'] = array_map(fn (array $node) => $this->snapshot($node), $document['content']);
        }

        return $document;
    }

    public function redactCalls(array $document): array
    {
        if (in_array($document['type'] ?? '', ['callRecording', 'callReference'], true)) {
            return ['type' => 'paragraph', 'attrs' => ['id' => $document['attrs']['id'] ?? md5(json_encode($document))], 'content' => [['type' => 'text', 'text' => 'Звонок: доступ ограничен']]];
        }
        if (isset($document['content'])) {
            $document['content'] = array_map(fn (array $node): array => $this->redactCalls($node), $document['content']);
        }

        return $document;
    }

    public function diff(array $before, array $after): array
    {
        $a = collect($before['content'] ?? [])->keyBy(fn ($n) => $n['attrs']['id'] ?? md5(json_encode($n)));
        $b = collect($after['content'] ?? [])->keyBy(fn ($n) => $n['attrs']['id'] ?? md5(json_encode($n)));
        $out = [];
        foreach ($a->keys()->merge($b->keys())->unique() as $id) {
            $old = $a->get($id);
            $new = $b->get($id);
            if ($old !== $new) {
                $out[] = ['block_id' => $id, 'type' => ! $old ? 'added' : (! $new ? 'removed' : 'changed'), 'before' => $old ? $this->text($old) : '', 'after' => $new ? $this->text($new) : ''];
            }
        }

        return $out;
    }

    public function html(array $node, string $publication = ''): string
    {
        $type = $node['type'] ?? '';
        $a = $node['attrs'] ?? [];
        if ($type === 'text') {
            $html = e($node['text'] ?? '');
            foreach ($node['marks'] ?? [] as $m) {
                $tag = ['bold' => 'strong', 'italic' => 'em', 'strike' => 's', 'underline' => 'u', 'code' => 'code'][$m['type']] ?? null;
                if ($tag) {
                    $html = "<$tag>$html</$tag>";
                }
                if ($m['type'] === 'link' && preg_match('~^(https?://|mailto:|/p/|/app/)~', $m['attrs']['href'] ?? '')) {
                    $html = '<a href="'.e($m['attrs']['href']).'" target="_blank" rel="noopener noreferrer">'.$html.'</a>';
                }
            }

            return $html;
        }
        $children = implode('', array_map(fn ($n) => $this->html($n, $publication), $node['content'] ?? []));
        if ($type === 'callRecording') {
            return view('call-recording', ['call' => $a['snapshot'] ?? [], 'publication' => $publication])->render();
        }
        if ($type === 'callReference') {
            return '<p><a href="/app/calls?call='.rawurlencode($a['recordingId'] ?? '').'">Открыть разбор звонка (для сотрудников)</a></p>';
        }
        if ($type === 'passwordReference') {
            return '';
        }
        if ($type === 'hardBreak') {
            return '<br>';
        }
        if ($type === 'horizontalRule') {
            return '<hr>';
        }
        if (in_array($type, ['media', 'image'])) {
            $url = '/media/'.rawurlencode($a['assetId'] ?? '').'?publication='.rawurlencode($publication);
            $kind = $type === 'image' ? 'image' : ($a['kind'] ?? 'file');

            return match ($kind) {
                'image' => '<img src="'.$url.'" alt="'.e($a['alt'] ?? '').'" loading="lazy">','video' => '<video src="'.$url.'" controls preload="metadata"></video>','audio' => '<audio src="'.$url.'" controls preload="metadata"></audio>', 'meeting' => '<section class="call-widget"><strong>'.e($a['title'] ?? 'Планёрка').'</strong><p>Запись планёрки</p>'.(str_starts_with($a['mime'] ?? '', 'video/') ? '<video src="'.$url.'" controls preload="metadata"></video>' : '<audio src="'.$url.'" controls preload="metadata"></audio>').'</section>',default => '<a href="'.$url.'" target="_blank" rel="noopener">Открыть вложение</a>'
            };
        }
        if ($type === 'youtube') {
            $url = parse_url($a['src'] ?? '');
            parse_str($url['query'] ?? '', $query);
            $id = $query['v'] ?? basename($url['path'] ?? '');

            return '<iframe src="https://www.youtube-nocookie.com/embed/'.rawurlencode($id).'" title="Видеопроигрыватель" allowfullscreen loading="lazy"></iframe>';
        }
        if ($type === 'kbWidget') {
            $def = WidgetDefinition::where('key', $a['definition'] ?? '')->first();
            if (! $def) {
                return '';
            }
            $html = '<div class="widget-block"><div class="widget-heading"><strong>'.e($def->name).'</strong><span class="widget-label">Виджет</span></div><div class="widget-fields '.e($def->view).'">';
            foreach ($def->fields as $f) {
                $v = $a['data'][$f['key']] ?? '';
                $html .= $f['type'] === 'boolean' ? '<label class="check-row"><input type="checkbox" disabled '.($v ? 'checked' : '').'><span>'.e($f['label']).'</span></label>' : '<label><span class="field-label">'.e($f['label']).'</span><span class="field-value">'.e((string) $v).'</span></label>';
            }
            $html .= '</div>';
            if ($def->view === 'calculator') {
                $leads = (float) ($a['data']['leads'] ?? 0);
                $budget = (float) ($a['data']['budget'] ?? 0);
                $cost = $leads > 0 ? number_format($budget / $leads, 0, ',', ' ').' ₽' : '—';
                $html .= '<div class="calculated"><span>Стоимость обращения</span><strong>'.$cost.'</strong></div>';
            }

            return $html.'<footer>'.e($def->description).'</footer></div>';
        }
        $tags = ['doc' => 'div', 'paragraph' => 'p', 'blockquote' => 'blockquote', 'bulletList' => 'ul', 'orderedList' => 'ol', 'listItem' => 'li', 'taskList' => 'ul', 'taskItem' => 'li', 'codeBlock' => 'pre', 'table' => 'table', 'tableRow' => 'tr', 'tableCell' => 'td', 'tableHeader' => 'th'];
        $tag = $type === 'heading' ? 'h'.max(1, min(6, (int) ($a['level'] ?? 2))) : ($tags[$type] ?? 'div');
        $attrs = isset($a['id']) ? ' id="'.e($a['id']).'"' : '';
        if ($type === 'orderedList' && isset($a['start'])) {
            $attrs .= ' start="'.(int) $a['start'].'"';
        }
        if (in_array($type, ['tableCell', 'tableHeader'])) {
            foreach (['colspan', 'rowspan'] as $key) {
                if (isset($a[$key])) {
                    $attrs .= ' '.$key.'="'.max(1, min(100, (int) $a[$key])).'"';
                }
            }
        }
        if (in_array($type, ['taskList', 'taskItem'])) {
            $attrs .= ' data-type="'.$type.'"';
        }
        if ($type === 'taskItem') {
            $children = '<input type="checkbox" disabled '.(! empty($a['checked']) ? 'checked' : '').'>'.$children;
        }

        return '<'.$tag.$attrs.'>'.$children.'</'.$tag.'>';
    }
}
