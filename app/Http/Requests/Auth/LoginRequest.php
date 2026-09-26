<?php

namespace App\Http\Requests\Auth;

use App\Enums\ResultadoAcceso;
use App\Models\User;
use App\Services\HistorialAccesos;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Intentos fallidos permitidos antes de bloquear el acceso.
     */
    public const MAXIMO_INTENTOS = 5;

    /**
     * Segundos que dura el bloqueo.
     */
    public const SEGUNDOS_BLOQUEO = 60;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Intenta autenticar al usuario con las credenciales de la petición.
     *
     * @throws ValidationException
     */
    public function authenticate(HistorialAccesos $historial): void
    {
        $this->ensureIsNotRateLimited();

        $email = $this->string('email')->lower()->toString();
        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($this->string('password')->toString(), $user->password)) {
            RateLimiter::hit($this->throttleKey(), self::SEGUNDOS_BLOQUEO);
            $historial->registrar($this, $email, $user, ResultadoAcceso::Fallido);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        if (! $user->estaActivo()) {
            $historial->registrar($this, $email, $user, ResultadoAcceso::Suspendido);

            throw ValidationException::withMessages([
                'email' => __('auth.suspended'),
            ]);
        }

        Auth::login($user, $this->boolean('remember'));

        RateLimiter::clear($this->throttleKey());
        $historial->registrar($this, $email, $user, ResultadoAcceso::Exitoso);
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAXIMO_INTENTOS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', ['seconds' => $seconds]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
