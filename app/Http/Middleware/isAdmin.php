<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class IsAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();

        if (!$user || $user->role->role !== 'admin') {
            return response()->json([
                'error' => 'Não autorizado para acesso administrativo'
            ], 403);
        }

        return $next($request);
    }
}
