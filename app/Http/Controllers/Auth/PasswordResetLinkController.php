<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Solicitudes permitidas por correo dentro de la ventana de tiempo.
     */
    public const MAXIMO_SOLICITUDES = 3;

    /**
     * Duración de la ventana de tiempo en segundos (15 minutos).
     */
    public const VENTANA_SEGUNDOS = 900;

    /**
     * Muestra el formulario "¿Olvidaste tu contraseña?".
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Envía el enlace para crear una contraseña nueva.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = Str::lower($request->string('email'));
        $clave = 'recuperar-contrasena:'.$email;

        if (RateLimiter::tooManyAttempts($clave, self::MAXIMO_SOLICITUDES)) {
            throw ValidationException::withMessages([
                'email' => __('passwords.throttled', [
                    'minutes' => (int) ceil(RateLimiter::availableIn($clave) / 60),
                ]),
            ]);
        }

        RateLimiter::hit($clave, self::VENTANA_SEGUNDOS);

        // Se responde igual exista o no el correo, para no revelar qué cuentas están registradas.
        Password::sendResetLink(['email' => $email]);

        return back()->with('status', __('passwords.sent'));
    }
}
