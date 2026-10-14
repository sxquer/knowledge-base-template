<?php

namespace App\Http\Controllers;

use App\Jobs\ImportSipuni;
use App\Models\CallRecording;
use App\Models\Page;
use App\Models\Setting;
use App\Services\Access;
use App\Services\CallLibrary;
use App\Services\Knowledge;
use App\Services\OpenRouter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CallLibraryController extends Controller
{
    public function index(Request $request, Access $access): array
    {
        abort_unless($request->user()->canViewCalls(), 403);
        $data = $request->validate([
            'q' => 'nullable|string|max:180', 'state' => 'nullable|in:ready,transcribed,pending,failed', 'page' => 'nullable|integer|min:1',
            'operator' => 'nullable|string|max:255', 'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'review' => 'nullable|in:reviewed,unreviewed',
        ]);
        $config = app(CallLibrary::class)->config();
        $allowed = $access->pages($request->user())->pluck('id');
        $query = CallRecording::query()->where(fn ($q) => $q->whereNull('page_id')->orWhereIn('page_id', $allowed));
        $operators = (clone $query)->whereNotNull('operator_name')->distinct()->orderBy('operator_name')->pluck('operator_name');
        if (! empty($data['operator'])) {
            $query->where('operator_name', $data['operator']);
        }
        if (! empty($data['date_from'])) {
            $query->whereRaw('COALESCE(called_at, created_at) >= ?', [CarbonImmutable::parse($data['date_from'], $config['timezone'])->startOfDay()->utc()]);
        }
        if (! empty($data['date_to'])) {
            $query->whereRaw('COALESCE(called_at, created_at) < ?', [CarbonImmutable::parse($data['date_to'], $config['timezone'])->addDay()->startOfDay()->utc()]);
        }
        $reviews = DB::table('call_reviews')->select('reviewed_at')->whereColumn('call_recording_id', 'call_recordings.id')->where('user_id', $request->user()->id);
        if (! empty($data['review'])) {
            $query->whereExists(clone $reviews, 'and', $data['review'] === 'unreviewed');
        }
        if (! empty($data['q'])) {
            $query->where('title', 'like', '%'.$data['q'].'%');
        }
        if (($data['state'] ?? '') === 'ready') {
            $query->where('status', 'ready')->where('evaluation_status', 'ready');
        } elseif (($data['state'] ?? '') === 'transcribed') {
            $query->where('status', 'ready');
        } elseif (($data['state'] ?? '') === 'failed') {
            $query->where(fn ($q) => $q->where('status', 'failed')->orWhere('evaluation_status', 'failed'));
        } elseif (($data['state'] ?? '') === 'pending') {
            $query->where(fn ($q) => $q->whereNotIn('status', ['ready', 'failed', 'cancelled'])->when($config['evaluation_enabled'], fn ($q) => $q->orWhereIn('evaluation_status', ['pending', 'queued', 'evaluating', 'retrying', 'waiting_config'])));
        }
        $result = $query->orderByRaw('COALESCE(called_at, created_at) DESC')->orderByDesc('id')->select(['id', 'page_id', 'title', 'status', 'evaluation_status', 'evaluation_error', 'score', 'talk_seconds', 'called_at', 'duration', 'direction', 'operator_name', 'created_at'])->addSelect(['reviewed_at' => $reviews])->paginate(30);

        return ['operators' => $operators, 'timezone' => $config['timezone'], 'evaluation_enabled' => $config['evaluation_enabled'], 'rubric_version' => $config['rubric']['version'], 'data' => $result->items(), 'current_page' => $result->currentPage(), 'last_page' => $result->lastPage(), 'total' => $result->total()];
    }

    public function review(Request $request, CallRecording $recording, CallLibrary $library): array
    {
        $library->requireRead($request->user(), $recording);
        $data = $request->validate(['reviewed' => 'required|boolean']);
        $identity = ['user_id' => $request->user()->id, 'call_recording_id' => $recording->id];
        if ($data['reviewed']) {
            DB::table('call_reviews')->upsert([...$identity, 'reviewed_at' => now()], ['user_id', 'call_recording_id'], ['reviewed_at']);
        } else {
            DB::table('call_reviews')->where($identity)->delete();
        }

        return ['id' => $recording->id, 'reviewed_at' => DB::table('call_reviews')->where($identity)->value('reviewed_at')];
    }

    public function settings(Request $request, CallLibrary $library): array
    {
        abort_unless($request->user()->role === 'admin', 403);

        return $library->publicConfig();
    }

    public function configure(Request $request, CallLibrary $library): array
    {
        abort_unless($request->user()->role === 'admin', 403);
        $d = $request->validate([
            'enabled' => 'required|boolean', 'evaluation_enabled' => 'required|boolean', 'account' => 'nullable|string|regex:/^[0-9]{3,20}$/', 'secret' => 'nullable|string|max:1000',
            'min_talk_seconds' => 'required|integer|min:0|max:86400', 'start_date' => 'required|date_format:Y-m-d|before_or_equal:today', 'timezone' => 'required|timezone',
            'rubric' => 'required|array', 'rubric.title' => 'required|string|max:180', 'rubric.instructions' => 'required|string|max:10000', 'rubric.jev_context' => 'required|string|max:400', 'rubric.provisional' => 'required|boolean',
            'rubric.version' => 'required|integer|min:1', 'rubric.criteria' => 'required|array|min:1|max:30',
            'rubric.criteria.*.id' => 'required|string|regex:/^[a-z0-9_]{1,60}$/|distinct',
            'rubric.criteria.*.title' => 'required|string|max:180', 'rubric.criteria.*.description' => 'required|string|max:3000', 'rubric.criteria.*.weight' => 'required|integer|min:1|max:100',
            'rubric.criteria.*.jev_question' => 'required|string|max:1000',
        ]);
        $lock = Cache::lock('sipuni:sync', 900);
        abort_unless($lock->get(), 409, 'Дождитесь завершения загрузки Sipuni перед изменением настроек.');
        try {
            return DB::transaction(function () use ($library, $d, $request): array {
                $c = $library->config();
                abort_unless($d['rubric']['version'] === $c['rubric']['version'], 409, 'Эталон изменён в другом окне. Обновите настройки.');
                $rubric = collect($d['rubric'])->only(['version', 'title', 'instructions', 'jev_context', 'provisional', 'criteria'])->all();
                $rubric['criteria'] = array_map(fn ($r) => collect($r)->only(['id', 'title', 'description', 'jev_question', 'weight'])->all(), $rubric['criteria']);
                if ($rubric != $c['rubric']) {
                    $rubric['version'] = $c['rubric']['version'] + 1;
                }
                $account = $d['account'] ?? '';
                $secret = ($d['secret'] ?? '') ?: ($account === $c['account'] ? $c['secret'] : '');
                if ($d['enabled'] && ($account === '' || $secret === '')) {
                    throw ValidationException::withMessages(['account' => 'Для автозагрузки укажите номер кабинета и ключ статистики Sipuni.']);
                }
                $next = ['enabled' => $d['enabled'], 'evaluation_enabled' => $d['evaluation_enabled'], 'account' => $account, 'secret' => $secret, 'owner_id' => $request->user()->id,
                    'min_talk_seconds' => $d['min_talk_seconds'], 'start_date' => $d['start_date'], 'timezone' => $d['timezone'], 'rubric' => $rubric];
                Setting::updateOrCreate(['key' => 'call_library'], ['value' => $next]);
                if ($account !== $c['account'] || $d['start_date'] !== $c['start_date'] || $d['min_talk_seconds'] !== $c['min_talk_seconds'] || $d['timezone'] !== $c['timezone']) {
                    Setting::whereKey('sipuni_sync')->delete();
                }

                return $library->publicConfig();
            });
        } finally {
            $lock->release();
        }
    }

    public function sync(Request $request, CallLibrary $library): array
    {
        abort_unless($request->user()->role === 'admin', 403);
        abort_unless($library->configured(), 422, 'Сначала подключите Sipuni в настройках звонков.');
        ImportSipuni::dispatch();

        return ['queued' => true];
    }

    public function evaluate(Request $request, CallRecording $recording, CallLibrary $library): array
    {
        $library->requireEdit($request->user(), $recording);
        abort_unless($recording->status === 'ready', 422, 'Дождитесь готовности расшифровки.');
        abort_unless(app(OpenRouter::class)->ready(), 422, 'Добавьте ключ OpenRouter в настройках.');
        $library->queueEvaluation($recording, true, $request->user());

        return $recording->fresh()->snapshot();
    }

    public function attach(Request $request, Page $page, CallLibrary $library, Access $access, Knowledge $kb): array
    {
        $access->requireEdit($request->user(), $page);
        $d = $request->validate(['recording_id' => 'required|uuid', 'expected_revision' => 'required|integer', 'request_id' => 'required|string|min:8|max:100']);
        $call = CallRecording::findOrFail($d['recording_id']);
        $library->requireRead($request->user(), $call);
        abort_unless($call->status === 'ready', 422, 'Выберите звонок с готовой расшифровкой.');

        return $kb->operation($request->user(), $d['request_id'], ['page_id' => $page->id, ...$d], function () use ($request, $page, $d, $call, $kb): array {
            $page = $page->fresh();
            abort_if($page->archived_at, 422, 'Нельзя добавлять звонок в архив.');
            $doc = $page->document;
            $doc['content'][] = ['type' => 'callReference', 'attrs' => ['recordingId' => $call->id]];
            $saved = $kb->save($request->user(), [...$page->toArray(), 'document' => $doc, 'expected_revision' => $d['expected_revision'], 'reason' => 'Добавлена ссылка на разобранный звонок'], $page);

            return ['page' => $kb->present($saved)];
        });
    }
}
