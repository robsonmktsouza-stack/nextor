<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireCompanyConfigured
{
    public function handle(Request $request, Closure $next): Response
    {
        $ready=\App\Support\CompanyReadiness::configured();

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
