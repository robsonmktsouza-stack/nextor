<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AutomationRule extends Model
{
    public const KINDS = [
        'expiry_soon' => 'Licenças próximas do vencimento',
        'expired' => 'Licenças vencidas',
        'offline' => 'Instalações sem comunicação',
    ];

    protected $fillable = ['name', 'description', 'kind', 'enabled', 'days', 'notify_client_email'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'notify_client_email' => 'boolean',
            'days' => 'integer',
            'last_run_at' => 'datetime',
        ];
    }

    public function kindCode(): string
    {
        return (string) ($this->kind ?: $this->code);
    }
}
