<?php

namespace App\Http\Middleware;

use App\Models\CompanySetting;
use App\Support\InstanceIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireCompanyConfigured
{
    public function handle(Request $request, Closure $next): Response
    {
        // Nenhuma página extra: o cadastro continua nas Configurações existentes.
        // A identificação básica basta para abrir os módulos. Pré-requisitos
        // fiscais específicos continuam sob responsabilidade do motor fiscal.
        $company=CompanySetting::query()->first([
            'document','legal_name','state','city',
        ]);

        $document=preg_replace('/\D/','',(string)($company?->document ?? ''));
        $ready=strlen($document)===14
            && filled(trim((string)($company?->legal_name ?? '')))
            && filled(trim((string)($company?->state ?? '')))
            && filled(trim((string)($company?->city ?? '')))
            && InstanceIdentity::matches($company?->document);

        if($ready) return $next($request);

        $message='Antes de utilizar este módulo, configure o CNPJ, a razão social, a UF e o município em Configurações > Dados da empresa.';

        if($request->expectsJson()) {
            return response()->json([
                'message'=>$message,
                'code'=>'company_not_configured',
                'settings_url'=>$request->user()?->canAccess('settings')
                    ? route('settings.index',['tab'=>'general'])
                    : null,
            ],409);
        }

        if($request->user()?->canAccess('settings')) {
            return redirect()->route('settings.index',['tab'=>'general'])
                ->with('warning',$message);
        }

        abort(403,'Empresa não configurada. Solicite ao administrador que preencha os dados da empresa em Configurações.');
    }
}
