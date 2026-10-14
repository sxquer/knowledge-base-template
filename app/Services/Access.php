<?php

namespace App\Services;

use App\Models\Page;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class Access
{
    public function read(User $user, Page $page): bool
    {
        if ($user->role === 'admin') {
            return true;
        }
        $visited = [];
        do {
            if (isset($visited[$page->id])) {
                return false;
            }
            $visited[$page->id] = true;
            if ($page->visibility === 'restricted' && $page->created_by !== $user->id && ! $page->grants()->where('users.id', $user->id)->exists()) {
                return false;
            }
            $page = $page->parent_id ? Page::find($page->parent_id) : null;
        } while ($page);

        return true;
    }

    public function requireRead(User $user, Page $page): void
    {
        abort_unless($this->read($user, $page), 404);
    }

    public function requireEdit(User $user, Page $page): void
    {
        $this->requireRead($user, $page);
        abort_unless($user->canEdit(), 403);
    }

    public function pages(User $user, bool $archived = false, bool $withContent = false)
    {
        $query = Page::query()->orderBy('position')->orderBy('title');
        if (! $withContent) {
            $query->select(['id', 'parent_id', 'title', 'subtitle', 'kind', 'status', 'visibility', 'created_by', 'owner_label', 'revision', 'position', 'archived_at', 'updated_at']);
        }
        $all = $query->get()->keyBy('id');
        $grants = $user->role === 'admin' ? [] : array_flip(DB::table('page_user')->where('user_id', $user->id)->pluck('page_id')->all());

        return $all->filter(function ($page) use ($user, $archived, $all, $grants) {
            if (! $archived && $page->archived_at) {
                return false;
            }
            if ($user->role === 'admin') {
                return true;
            }
            $visited = [];
            do {
                if (isset($visited[$page->id])) {
                    return false;
                }
                $visited[$page->id] = true;
                if ($page->visibility === 'restricted' && $page->created_by !== $user->id && ! isset($grants[$page->id])) {
                    return false;
                }
                $page = $page->parent_id ? $all->get($page->parent_id) : null;
            } while ($page);

            return true;
        })->values();
    }
}
