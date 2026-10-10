<?php
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
class User extends Authenticatable {
    use Notifiable;
    protected $fillable = ['name','username','email','password','role','is_active','permissions'];
    protected $hidden = ['password','remember_token'];

    protected $attributes = [
        'role'=>'admin',
        'is_active'=>true,
        'permissions'=>null,
    ];
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

    public function homeRouteName(): string
    {
        foreach([
            'dashboard'=>'dashboard',
            'finance'=>'finance.dashboard',
            'sales'=>'sales.index',
            'pdv'=>'pdv.index',
            'products'=>'products.index',
            'services'=>'services.index',
            'customers'=>'customers.index',
            'stock'=>'stock.index',
            'returns'=>'sales.returns.index',
            'fiscal'=>'fiscal.index',
            'settings'=>'settings.index',
        ] as $permission=>$route) {
            if($this->canAccess($permission)) return $route;
        }

        return 'dashboard';
    }
}
