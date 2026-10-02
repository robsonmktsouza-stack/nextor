<?php
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
class User extends Authenticatable {
    use Notifiable;
    protected $fillable = ['name','email','password','role','is_active','permissions'];
    protected $hidden = ['password','remember_token'];
    protected function casts(): array {
        return [
            'password'=>'hashed',
            'is_active'=>'boolean',
            'permissions'=>'array',
        ];
    }

    public function canAccess(string $permission): bool
    {
        if($this->role==='admin') return true;
        return in_array($permission,$this->permissions ?? [],true);
    }
}
