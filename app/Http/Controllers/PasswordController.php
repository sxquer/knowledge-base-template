<?php

namespace App\Http\Controllers;

use App\Models\PasswordEntry;
use App\Models\User;
use App\Services\PasswordVault;
use Illuminate\Http\Request;

class PasswordController extends Controller
{
    public function __construct(public PasswordVault $vault) {}

    public function index(Request $request): array
    {
        $user = $request->user();

        return ['entries' => $this->vault->entries($user), 'users' => $user->role === 'admin' ? User::orderBy('name')->get(['id', 'name', 'email', 'role']) : []];
    }

    public function store(Request $request): array
    {
        $this->vault->requireManage($request->user());
        $data = $this->validated($request, true);
        $entry = PasswordEntry::create(['title' => $data['title'], 'username' => $data['username'] ?? null, 'secret' => $data['password'], 'url' => $data['url'] ?? null, 'notes' => $data['notes'] ?? null, 'created_by' => $request->user()->id]);
        $entry->users()->sync($data['user_ids']);

        return $this->vault->summary($entry->fresh());
    }

    public function update(Request $request, PasswordEntry $passwordEntry): array
    {
        $this->vault->requireManage($request->user());
        $data = $this->validated($request, false);
        $entryData = ['title' => $data['title']];
        foreach (['username', 'url', 'notes'] as $field) {
            if ($request->filled($field)) {
                $entryData[$field] = $data[$field];
            }
        }
        if ($request->filled('password')) {
            $entryData['secret'] = $data['password'];
        }
        $passwordEntry->update($entryData);
        $passwordEntry->users()->sync($data['user_ids']);

        return $this->vault->summary($passwordEntry->fresh());
    }

    public function reveal(Request $request, PasswordEntry $passwordEntry)
    {
        $this->vault->requireReveal($request->user(), $passwordEntry);

        return response()->json($this->vault->reveal($passwordEntry))->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'title' => 'required|string|max:180',
            'username' => 'nullable|string|max:2000',
            'password' => ($creating ? 'required' : 'nullable').'|string|max:4000',
            'url' => 'nullable|url:http,https|max:2000',
            'notes' => 'nullable|string|max:10000',
            'user_ids' => 'present|array|max:100',
            'user_ids.*' => 'integer|distinct|exists:users,id',
        ]);
    }
}
