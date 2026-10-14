<?php

namespace App\Services;

use App\Jobs\TranscribeCall;
use App\Models\CallRecording;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class Sipuni
{
    public function __construct(private CallLibrary $library) {}

    public function sync(): array
    {
        $lock = Cache::lock('sipuni:sync', 900);
        if (! $lock->get()) {
            throw new RuntimeException('Загрузка Sipuni уже выполняется.');
        }
        $state = Setting::find('sipuni_sync')?->value ?? [];
        $imported = 0;
        $skipped = 0;
        try {
            $c = $this->library->config();
            $owner = User::find($c['owner_id']);
            if (! $this->library->configured() || ! $owner || $owner->role !== 'admin') {
                throw new RuntimeException('Подключите Sipuni в настройках раздела «Звонки».');
            }
            $today = CarbonImmutable::now($c['timezone'])->startOfDay();
            $start = CarbonImmutable::parse($c['start_date'], $c['timezone'])->startOfDay();
            $from = isset($state['through']) ? CarbonImmutable::parse($state['through'], $c['timezone'])->addDay()->min($today->subDay())->max($start) : $start;
            $end = $from->addDays(6)->min($today);
            $state = [...$state, 'status' => 'running', 'started_at' => now()->toIso8601String(), 'error' => null];
            $this->saveState($state);
            foreach ($this->exportPeriod($from, $end, $c) as $row) {
                if ($row['talk_seconds'] <= $c['min_talk_seconds']) {
                    $skipped++;

                    continue;
                }
                $external = $c['account'].':'.$row['recording_id'];
                DB::transaction(function () use ($row, $external, $owner, &$imported): void {
                    $call = CallRecording::firstOrCreate(['external_id' => $external], [
                        'page_id' => null, 'user_id' => $owner->id, 'title' => 'Звонок Sipuni · '.$row['called_at']->format('d.m.Y H:i'),
                        'operator_name' => $row['operator_name'], 'source_url' => '', 'source_host' => 'sipuni.com', 'status' => 'queued',
                        'talk_seconds' => $row['talk_seconds'], 'called_at' => $row['called_at']->utc(), 'direction' => $row['direction'],
                    ]);
                    if (! $call->wasRecentlyCreated && $row['operator_name'] !== null && $call->operator_name !== $row['operator_name']) {
                        $call->update(['operator_name' => $row['operator_name']]);
                    }
                    if ($call->wasRecentlyCreated) {
                        TranscribeCall::dispatch($call->id)->afterCommit();
                        $imported++;
                    }
                });
            }
            $state['through'] = $end->toDateString();
            $state = [...$state, 'status' => 'ready', 'finished_at' => now()->toIso8601String(), 'imported' => $imported, 'skipped' => $skipped, 'error' => null];
            $this->saveState($state);

            return $state;
        } catch (\Throwable $e) {
            $message = $e::class === RuntimeException::class ? $e->getMessage() : 'Не удалось загрузить звонки Sipuni. Проверьте подключение и повторите.';
            $this->saveState([...$state, 'status' => 'failed', 'error' => $message, 'imported' => $imported, 'skipped' => $skipped]);
            throw new RuntimeException($message);
        } finally {
            $lock->release();
        }
    }

    private function saveState(array $state): void
    {
        Setting::updateOrCreate(['key' => 'sipuni_sync'], ['value' => $state]);
    }

    private function exportPeriod(CarbonImmutable $from, CarbonImmutable $to, array $c): array
    {
        $data = [
            'anonymous' => '1', 'crmLinks' => '0', 'dtmfUserAnswer' => '0', 'firstTime' => '0',
            'from' => $from->format('d.m.Y'), 'fromNumber' => '', 'hangupinitor' => '0', 'ignoreSpecChar' => '1',
            'names' => '1', 'numbersInvolved' => '0', 'numbersRinged' => '0', 'outgoingLine' => '0',
            'rating' => '', 'showTreeId' => '0', 'state' => '2', 'timeFrom' => '00:00', 'timeTo' => '23:59',
            'to' => $to->format('d.m.Y'), 'toAnswer' => '', 'toNumber' => '', 'tree' => '', 'type' => '0', 'user' => $c['account'],
        ];
        $data['hash'] = md5(implode('+', [...array_values($data), $c['secret']]));
        try {
            $response = Http::asForm()->connectTimeout(10)->timeout(60)->withOptions(['allow_redirects' => false])
                ->post('https://sipuni.com/api/statistic/export', $data);
        } catch (\Throwable) {
            throw new RuntimeException('Sipuni не ответил вовремя. Загрузка повторится при следующем запуске.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('Sipuni: HTTP '.$response->status().'. Проверьте номер кабинета и ключ статистики.');
        }

        return $this->parseCsv($response->body(), $c['timezone']);
    }

    public function parseCsv(string $csv, string $timezone): array
    {
        if (! mb_check_encoding($csv, 'UTF-8')) {
            $csv = mb_convert_encoding($csv, 'UTF-8', 'Windows-1251');
        }
        $csv = ltrim($csv, "\xEF\xBB\xBF\r\n");
        $line = strtok($csv, "\n") ?: '';
        $delimiter = substr_count($line, ';') >= substr_count($line, ',') ? ';' : ',';
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $csv);
        rewind($stream);
        try {
            $headers = fgetcsv($stream, 0, $delimiter, '"', '');
            if (! is_array($headers)) {
                throw new RuntimeException('Sipuni вернул пустой ответ вместо CSV со звонками.');
            }
            $headers = array_map(fn ($h) => mb_strtolower(trim($h)), $headers);
            $columns = [];
            foreach ([
                'talk' => ['длительность разговора, сек', 'длительность разговора', 'длительность разговора в секундах', 'время разговора', 'talk duration', 'billsec'],
                'id' => ['id записи', 'идентификатор записи', 'record id', 'recording_id', 'id звонка', 'call id'],
                'date' => ['время', 'дата', 'дата и время', 'время звонка', 'call time', 'date'],
                'type' => ['тип', 'тип звонка', 'type'],
                'from' => ['откуда', 'from'],
                'answered' => ['кто ответил', 'answered'],
            ] as $field => $aliases) {
                foreach ($aliases as $alias) {
                    $index = array_search($alias, $headers, true);
                    if ($index !== false) {
                        $columns[$field] = $index;
                        break;
                    }
                }
            }
            if (! isset($columns['talk'], $columns['id'], $columns['date'])) {
                throw new RuntimeException('В CSV Sipuni не найдены ID записи, время или длительность разговора. Общая длительность звонка не используется.');
            }
            $rows = [];
            while (($row = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                while (count($row) > count($headers) && end($row) === '') {
                    array_pop($row);
                }
                if (count($row) !== count($headers)) {
                    throw new RuntimeException('Sipuni вернул неполную строку CSV. Загрузка остановлена без пропуска периода.');
                }
                $id = trim($row[$columns['id']]);
                if ($id === '' || $id === '-') {
                    continue;
                }
                if (! preg_match('/^[a-zA-Z0-9._-]{1,160}$/', $id)) {
                    throw new RuntimeException('Sipuni вернул неизвестный формат ID записи.');
                }
                $talk = trim($row[$columns['talk']]);
                if (in_array($talk, ['', '-', '—'], true)) {
                    continue;
                }
                $duration = $this->seconds($talk);
                if ($duration === null) {
                    throw new RuntimeException('Sipuni вернул неизвестный формат длительности разговора.');
                }
                try {
                    $at = CarbonImmutable::parse(trim($row[$columns['date']]), $timezone);
                } catch (\Throwable) {
                    throw new RuntimeException('Sipuni вернул неизвестный формат даты звонка.');
                }
                $type = isset($columns['type']) ? mb_strtolower(trim($row[$columns['type']])) : '';
                $direction = match ($type) {
                    '1', 'входящий', 'входящие' => 'incoming',
                    '2', 'исходящий', 'исходящие' => 'outgoing',
                    '3', 'внутренний', 'внутренние' => 'internal',
                    default => 'unknown',
                };
                $employeeColumn = match ($direction) {
                    'outgoing', 'internal' => $columns['from'] ?? null,
                    'incoming' => $columns['answered'] ?? null,
                    default => null,
                };
                $operator = $employeeColumn === null ? null : $this->operatorName($row[$employeeColumn]);
                $rows[] = ['operator_name' => $operator, 'recording_id' => $id, 'talk_seconds' => $duration, 'called_at' => $at, 'direction' => $direction];
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    private function operatorName(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^([0-9]{1,5})\s*\(([^()]+)\)$/u', $value, $parts)) {
            return mb_substr(trim($parts[2]), 0, 255);
        }
        if (preg_match('/^[0-9]{1,5}$/', $value)) {
            return 'Сотрудник '.$value;
        }

        return null;
    }

    private function seconds(string $value): ?int
    {
        if (preg_match('/^\d{1,7}$/', $value)) {
            return (int) $value;
        }
        if (preg_match('/^(?:(\d{1,3}):)?([0-5]?\d):([0-5]\d)$/', $value, $m)) {
            return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
        }

        return null;
    }

    public function download(CallRecording $call, string $destination): void
    {
        $c = $this->library->config();
        [$account, $id] = explode(':', $call->external_id, 2);
        if ($account !== $c['account'] || ! $this->library->configured()) {
            throw new RuntimeException('Подключите исходный кабинет Sipuni, чтобы скачать эту запись.');
        }
        try {
            $response = Http::asForm()->connectTimeout(10)->timeout(55)->withOptions([
                'allow_redirects' => false, 'sink' => $destination,
                'on_headers' => function (ResponseInterface $response): void {
                    if ((int) $response->getHeaderLine('Content-Length') > CallAudio::MAX_BYTES) {
                        throw new RuntimeException('Запись больше 25 МБ.');
                    }
                },
                'progress' => function (float $total, float $downloaded): void {
                    if ($total > CallAudio::MAX_BYTES || $downloaded > CallAudio::MAX_BYTES) {
                        throw new RuntimeException('Запись больше 25 МБ.');
                    }
                },
            ])->post('https://sipuni.com/api/statistic/record', ['id' => $id, 'user' => $account, 'hash' => md5($id.'+'.$account.'+'.$c['secret'])]);
            if (! $response->successful()) {
                throw new RuntimeException('Запись недоступна.');
            }
        } catch (\Throwable) {
            @unlink($destination);
            throw new RuntimeException('Не удалось скачать запись Sipuni. Проверьте доступ и размер записи (до 25 МБ).');
        }
    }
}
