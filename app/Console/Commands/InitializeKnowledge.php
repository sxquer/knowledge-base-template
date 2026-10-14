<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Models\User;
use App\Models\WidgetDefinition;
use App\Services\Knowledge;
use Illuminate\Console\Command;

class InitializeKnowledge extends Command
{
    protected $signature = 'kb:initialize {--user= : Existing administrator id}';

    protected $description = 'Create empty base sections and reusable widgets idempotently';

    public function handle(Knowledge $kb): int
    {
        $user = $this->option('user') ? User::find($this->option('user')) : User::where('role', 'admin')->first();
        if (! $user) {
            $this->error('Create administrator first through /login locally or kb:admin.');

            return self::FAILURE;
        }
        foreach ([
            ['key' => 'checklist', 'name' => 'Чеклист обработки', 'description' => 'Проверка обязательных шагов', 'view' => 'checklist', 'fields' => [['key' => 'card', 'label' => 'Проверить карточку лида', 'type' => 'boolean'], ['key' => 'contact', 'label' => 'Зафиксировать результат контакта', 'type' => 'boolean'], ['key' => 'next', 'label' => 'Назначить следующий шаг', 'type' => 'boolean']]],
            ['key' => 'call_review', 'name' => 'Разбор звонка', 'description' => 'Обезличенная карточка разговора', 'view' => 'fields', 'fields' => [['key' => 'topic', 'label' => 'Тема разговора', 'type' => 'text'], ['key' => 'result', 'label' => 'Результат', 'type' => 'textarea'], ['key' => 'next_step', 'label' => 'Следующий шаг', 'type' => 'text']]],
            ['key' => 'unit_economics', 'name' => 'Экономика кампании', 'description' => 'Стоимость обращения и конверсия в продажу', 'view' => 'calculator', 'fields' => [['key' => 'budget', 'label' => 'Бюджет, ₽', 'type' => 'number'], ['key' => 'leads', 'label' => 'Обращения', 'type' => 'number'], ['key' => 'sales', 'label' => 'Продажи', 'type' => 'number']]],
        ] as $d) {
            WidgetDefinition::firstOrCreate(['key' => $d['key']], $d);
        }
        $sections = ['01' => 'Стратегия и исследования', '02' => 'Аудитория и CJM', '03' => 'Продукт и позиционирование', '04' => 'Контент и копирайтинг', '05' => 'Каналы и перформанс', '06' => 'CRM и воронки', '07' => 'Аналитика и метрики', '08' => 'Кейсы и эксперименты', '09' => 'Шаблоны и чеклисты', '10' => 'Инструменты и автоматизация'];
        foreach ($sections as $key => $name) {
            $source = 'section:'.$key;
            if (! Page::where('source_key', $source)->exists()) {
                $page = $kb->save($user, ['title' => $name, 'kind' => 'section', 'document' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]], 'reason' => 'Создание раздела по структуре базы']);
                $page->update(['source_key' => $source, 'position' => (int) $key]);
            }
        }
        $this->info('Empty sections and widgets are ready. Re-running does not duplicate or overwrite knowledge.');

        return self::SUCCESS;
    }
}
