<?php

namespace App\Services;

use App\Enums\ResultadoAcceso;
use App\Models\IntentoAcceso;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class HistorialAccesos
{
    /**
     * Guarda un intento de inicio de sesión en el historial.
     */
    public function registrar(Request $request, string $email, ?User $user, ResultadoAcceso $resultado): IntentoAcceso
    {
        $userAgent = (string) $request->userAgent();

        return IntentoAcceso::create([
            'email' => $email,
            'user_id' => $user?->id,
            'resultado' => $resultado,
            'ip_address' => $request->ip(),
            'navegador' => $this->navegador($userAgent),
            'dispositivo' => $this->dispositivo($userAgent),
            'user_agent' => $userAgent !== '' ? $userAgent : null,
        ]);
    }

    public function navegador(string $userAgent): string
    {
        return match (true) {
            Str::contains($userAgent, 'Edg/') => 'Edge',
            Str::contains($userAgent, ['OPR/', 'Opera']) => 'Opera',
            Str::contains($userAgent, ['Chrome/', 'CriOS/']) => 'Chrome',
            Str::contains($userAgent, ['Firefox/', 'FxiOS/']) => 'Firefox',
            Str::contains($userAgent, 'Safari/') => 'Safari',
            default => 'Desconocido',
        };
    }

    public function dispositivo(string $userAgent): string
    {
        $tipo = match (true) {
            Str::contains($userAgent, ['iPad', 'Tablet']) => 'Tablet',
            Str::contains($userAgent, ['Mobile', 'iPhone', 'Android']) => 'Móvil',
            default => 'Escritorio',
        };

        $sistema = match (true) {
            Str::contains($userAgent, 'Windows') => 'Windows',
            Str::contains($userAgent, ['iPhone', 'iPad']) => 'iOS',
            Str::contains($userAgent, 'Android') => 'Android',
            Str::contains($userAgent, 'Mac OS') => 'macOS',
            Str::contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return $sistema ? "{$tipo} · {$sistema}" : $tipo;
    }
}
