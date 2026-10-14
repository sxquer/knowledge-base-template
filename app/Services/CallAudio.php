<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\CallRecording;
use App\Models\Page;
use App\Models\User;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class CallAudio
{
    public const MAX_BYTES = 25 * 1024 * 1024;

    public function validateUrl(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (! filter_var($url, FILTER_VALIDATE_URL) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || ($parts['port'] ?? 443) !== 443 || ! $host || str_contains($url, "\r") || str_contains($url, "\n") || strlen($url) > 4096) {
            throw new RuntimeException('Нужна HTTPS-ссылка на аудиозапись без логина и пароля в адресе.');
        }
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) && ! $this->publicIp(trim($host, '[]'))) {
            throw new RuntimeException('Ссылка должна вести на доступный в интернете аудиофайл.');
        }
        if (! str_contains($host, '.') || str_ends_with($host, '.local') || str_ends_with($host, '.localhost')) {
            throw new RuntimeException('Локальные адреса не поддерживаются.');
        }

        return $host;
    }

    public function publicIp(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)
            && ! str_starts_with(strtolower($ip), '::ffff:')
            && (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || (int) explode('.', $ip)[0] < 224);
    }

    public function resolve(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $records = dns_get_record($host, DNS_A | DNS_AAAA);
            $ips = array_values(array_filter(array_map(fn (array $row) => $row['ip'] ?? $row['ipv6'] ?? null, $records ?: [])));
        }
        if (! $ips || array_filter($ips, fn (string $ip) => ! $this->publicIp($ip))) {
            throw new RuntimeException('Не удалось безопасно получить запись по этому адресу.');
        }

        return $ips[0];
    }

    public function acquire(CallRecording $call, Access $access): Asset
    {
        $disk = Storage::disk('local');
        $path = 'calls/'.$call->id.'/audio';
        $disk->makeDirectory('calls/'.$call->id);
        $url = $call->source_url;
        $copied = false;
        if ($call->external_id) {
            app(Sipuni::class)->download($call, $disk->path($path));
            $copied = true;
        }
        $host = $copied ? 'sipuni.com' : $this->validateUrl($url);
        if (in_array($host, ['drive.google.com', 'docs.google.com'])) {
            preg_match('~/d/([a-zA-Z0-9_-]+)~', $url, $match);
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
            $driveId = $match[1] ?? $query['id'] ?? '';
            if (! preg_match('/^[a-zA-Z0-9_-]{10,200}$/', $driveId)) {
                throw new RuntimeException('Нужна ссылка на аудиофайл Google Drive.');
            }
            $sourcePage = Page::where('source_key', 'drive:'.$driveId)->first();
            if ($sourcePage) {
                $access->requireRead(User::findOrFail($call->user_id), $sourcePage);
                $source = Asset::where('page_id', $sourcePage->id)->where('mime', 'like', 'audio/%')->first();
                if ($source && $source->size <= self::MAX_BYTES) {
                    $stream = Storage::disk($source->disk)->readStream($source->path);
                    try {
                        $copied = $disk->put($path, $stream);
                    } finally {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }
                }
            }
            $url = 'https://drive.google.com/uc?export=download&id='.rawurlencode($driveId);
        }
        if (! $copied) {
            $this->download($url, $disk->path($path));
        }
        $size = $disk->size($path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($disk->path($path));
        $extensions = ['audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/flac' => 'flac', 'audio/x-flac' => 'flac', 'audio/ogg' => 'ogg', 'application/ogg' => 'ogg', 'audio/mp4' => 'm4a', 'video/mp4' => 'm4a', 'audio/webm' => 'webm', 'video/webm' => 'webm', 'audio/aac' => 'aac', 'audio/x-m4a' => 'm4a'];
        if (! isset($extensions[$mime]) || $size === 0 || $size > self::MAX_BYTES) {
            $disk->delete($path);
            throw new RuntimeException('По ссылке не найден аудиофайл до 25 МБ. Для закрытого Drive используйте уже импортированную запись.');
        }
        $extension = $extensions[$mime];
        $canonical = ['mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'flac' => 'audio/flac', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4', 'webm' => 'audio/webm', 'aac' => 'audio/aac'][$extension];

        return Asset::create(['page_id' => $call->page_id, 'user_id' => $call->user_id, 'disk' => 'local', 'path' => $path, 'name' => $call->title.'.'.$extension, 'mime' => $canonical, 'size' => $size]);
    }

    public function download(string $url, string $destination): void
    {
        try {
            for ($redirect = 0; $redirect <= 4; $redirect++) {
                $host = $this->validateUrl($url);
                $ip = $this->resolve($host);
                $pinned = str_contains($ip, ':') ? '['.$ip.']' : $ip;
                $response = Http::connectTimeout(8)->timeout(55)->withHeaders(['User-Agent' => 'MarketingBro-KB/1.0', 'Accept' => 'audio/*,application/octet-stream'])->withOptions([
                    'allow_redirects' => false, 'sink' => $destination, 'proxy' => '',
                    'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$pinned]],
                    'on_headers' => function (ResponseInterface $response): void {
                        if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BYTES) {
                            throw new RuntimeException('Запись больше 25 МБ.');
                        }
                    },
                    'progress' => function (float $total, float $downloaded): void {
                        if ($total > self::MAX_BYTES || $downloaded > self::MAX_BYTES) {
                            throw new RuntimeException('Запись больше 25 МБ.');
                        }
                    },
                ])->get($url);
                if (in_array($response->status(), [301, 302, 303, 307, 308])) {
                    $url = (string) UriResolver::resolve(new Uri($url), new Uri($response->header('Location')));

                    continue;
                }
                if (! $response->successful()) {
                    throw new RuntimeException('Сервис не отдал запись.');
                }

                return;
            }
        } catch (\Throwable) {
            @unlink($destination);
            throw new RuntimeException('Не удалось скачать аудио. Проверьте, что ссылка открывает запись и срок её действия не истёк.');
        }
        @unlink($destination);
        throw new RuntimeException('Слишком много переадресаций в ссылке на запись.');
    }
}
