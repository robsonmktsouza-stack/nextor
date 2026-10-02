<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission, string ...$alternatives): Response
    {
        $user=$request->user();
        $permissions=array_merge([$permission],$alternatives);
        $allowed=$user && $user->is_active && collect($permissions)->contains(fn($item)=>$user->canAccess($item));

        abort_unless($allowed,403,'Você não possui permissão para acessar esta área.');

        return $next($request);
    }
}
