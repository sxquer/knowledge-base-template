<?php

namespace App\Models;

use Database\Factories\PasswordEntryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PasswordEntry extends Model
{
    /** @use HasFactory<PasswordEntryFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['title', 'username', 'secret', 'url', 'notes', 'created_by'];

    protected $hidden = ['username', 'secret', 'url', 'notes'];

    protected function casts(): array
    {
        return ['username' => 'encrypted', 'secret' => 'encrypted', 'url' => 'encrypted', 'notes' => 'encrypted'];
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'password_entry_user');
    }
}
