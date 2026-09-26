<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    /**
     * Muestra el formulario para crear una contraseña nueva, o un aviso si el enlace no sirve.
     */
    public function create(Request $request, string $token): View
    {
        $email = Str::lower((string) $request->query('email'));
        $user = $email !== '' ? User::where('email', $email)->first() : null;

        if (! $user || ! Password::tokenExists($user, $token)) {
            return view('auth.enlace-invalido');
        }

        return view('auth.reset-password', ['token' => $token, 'email' => $email]);
    }

    /**
     * Guarda la contraseña nueva e inicia la sesión del usuario.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => Str::lower((string) $request->input('email'))]);

        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $usuarioRestablecido = null;

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request, &$usuarioRestablecido) {
                $user->forceFill([
                    'password' => $request->string('password')->toString(),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));

                $usuarioRestablecido = $user;
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return redirect()->route('password.request')
                ->withErrors(['email' => __($status)]);
        }

        if (! $usuarioRestablecido->estaActivo()) {
            return redirect()->route('login')
                ->withErrors(['email' => __('auth.suspended')]);
        }

        Auth::login($usuarioRestablecido);

        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', __($status));
    }
}
