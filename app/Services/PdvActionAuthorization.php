<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PdvActionAuthorization
{
    public function approve(User $actor, ?int $supervisorId, string $credential, string $ip): User
    {
        if(!$actor->is_active || !$actor->canAccess('pdv')) {
            abort(403,'Operador sem acesso ao PDV.');
        }
        $supervisor=User::query()->find($supervisorId ?? 0);
        if (!$supervisor || !$supervisor->is_active
            || !in_array($supervisor->role,['admin','manager'],true)
            || !$supervisor->canAccess('pdv')
            || (!in_array($actor->role,['admin','manager'],true)
                && $supervisor->id===$actor->id)) {
            throw ValidationException::withMessages([
                'authorizer_id'=>'Selecione um gerente ou administrador autorizado.',
            ]);
        }
        if (trim($credential)==='') {
            throw ValidationException::withMessages([
                'credential'=>'Informe a senha ou PIN do responsável pela autorização.',
            ]);
        }
        app(PdvOperatorAuthorization::class)->verify($supervisor,$credential,$ip);
        return $supervisor;
    }

    /** Must be called INSIDE the business transaction that changes the state. */
    public function record(string $action, int $actorId, int $approverId, string $reason,
                           ?int $saleId=null, ?int $fiscalJobId=null): void
    {
        DB::table('pdv_action_approvals')->insert([
            'action'=>$action,
            'performed_by'=>$actorId,
            'approved_by'=>$approverId,
            'sale_id'=>$saleId,
            'fiscal_document_job_id'=>$fiscalJobId,
            'reason'=>$reason,
            'created_at'=>now(),
        ]);
    }
}
