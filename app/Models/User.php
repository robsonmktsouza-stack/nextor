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

    /**
     * Honor commercial subscriptions when choosing the first page after login.
     * Employee permissions still apply; an unlicensed module is never chosen
     * merely because it is first in the user's permissions list.
     */
    public function licensedHomeRouteName(): string
    {
        if (!\App\Support\ManagedLicenseConfig::enabled()) {
            return $this->homeRouteName();
        }
        $state = app(\App\Services\License\CentralLicenseService::class)->state();
        if (($state['status'] ?? null) !== 'active') {
            return $this->homeRouteName();
        }

        $choices = [
            'dashboard' => ['dashboard', null],
            'finance' => ['finance.dashboard', 'finance'],
            'sales' => ['sales.index', 'pdv'],
            'pdv' => ['pdv.index', 'pdv'],
            'products' => ['products.index', 'products'],
            'services' => ['services.index', 'services'],
            'customers' => ['customers.index', 'pdv'],
            'stock' => ['stock.index', 'products'],
            'returns' => ['sales.returns.index', 'pdv'],
            'fiscal' => ['fiscal.index', 'fiscal'],
            'settings' => ['settings.index', null],
        ];
        foreach ($choices as $permission => [$route, $module]) {
            if ($this->canAccess($permission)
                && ($module === null || in_array($module, $state['modules'] ?? [], true))) {
                return $route;
            }
        }

        // Users with fiscal permissions must still reach their legally
        // required fiscal regularization documents when not subscribed.
        if ($this->canAccess('fiscal')) {
            return 'fiscal.index';
        }

        return $this->homeRouteName();
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
