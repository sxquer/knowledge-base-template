<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role', 'can_view_calls', 'can_listen_calls'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'can_view_calls' => 'boolean', 'can_listen_calls' => 'boolean'];
    }

    public function canEdit(): bool
    {
        return in_array($this->role, ['admin', 'editor'], true);
    }

    public function canViewCalls(): bool
    {
        return $this->role === 'admin' || $this->can_view_calls;
    }

    public function canListenCalls(): bool
    {
        return $this->role === 'admin' || ($this->canViewCalls() && $this->can_listen_calls);
    }
}
