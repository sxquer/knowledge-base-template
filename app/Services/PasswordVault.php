<?php

namespace App\Services;

use App\Models\PasswordEntry;
use App\Models\User;
use Illuminate\Support\Collection;

class PasswordVault
{
    public function canReveal(User $user, PasswordEntry $entry): bool
    {
        return $user->role === 'admin' || $entry->users()->whereKey($user->id)->exists();
    }

    public function requireReveal(User $user, PasswordEntry $entry): void
    {
        abort_unless($this->canReveal($user, $entry), 404);
    }

    public function requireManage(User $user): void
    {
        abort_unless($user->role === 'admin', 403);
    }

    public function entries(User $user): Collection
    {
        $query = PasswordEntry::query()->with('users:id')->orderBy('title');
        if ($user->role !== 'admin') {
            $query->whereHas('users', fn ($users) => $users->whereKey($user->id));
        }

        return $query->get()->map(function (PasswordEntry $entry) use ($user): array {
            $summary = $this->summary($entry);
            if ($user->role === 'admin') {
                $summary['user_ids'] = $entry->users->pluck('id')->all();
            }

            return $summary;
        });
    }

    public function summary(PasswordEntry $entry): array
    {
        return ['id' => $entry->id, 'title' => $entry->title, 'updated_at' => $entry->updated_at];
    }

    public function reveal(PasswordEntry $entry): array
    {
        return ['id' => $entry->id, 'title' => $entry->title, 'username' => $entry->username, 'password' => $entry->secret, 'url' => $entry->url, 'notes' => $entry->notes];
    }
}
