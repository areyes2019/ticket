<?php

namespace App\Services\Whatsapp;

use App\Models\User;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappUsoClaude;
use App\Services\Whatsapp\Claude\RespuestaClaude;

/**
 * Gasto de la API de Claude (041, reglas 72 a 74).
 */
class RegistroUso
{
    public const AVISO_PORCENTAJE = 80;

    public function registrar(User $user, ?WhatsappConversacion $conversacion, RespuestaClaude $respuesta): void
    {
        WhatsappUsoClaude::create([
            'user_id' => $user->id,
            'whatsapp_conversacion_id' => $conversacion?->id,
            'modelo' => $respuesta->modelo,
            'tokens_entrada' => $respuesta->uso['entrada'],
            'tokens_salida' => $respuesta->uso['salida'],
            'tokens_cache_lectura' => $respuesta->uso['cache_lectura'],
            'tokens_cache_escritura' => $respuesta->uso['cache_escritura'],
            'costo_usd' => self::costo($respuesta->modelo, $respuesta->uso),
        ]);
    }

    /**
     * US$ estimados con los precios de config/whatsapp.php. Un modelo sin
     * precio usa el del modelo configurado (mejor sobrestimar que no contar).
     *
     * @param  array{entrada: int, salida: int, cache_lectura: int, cache_escritura: int}  $uso
     */
    public static function costo(string $modelo, array $uso): float
    {
        $precios = config('whatsapp.claude.precios');
        $precio = $precios[$modelo] ?? $precios[config('whatsapp.claude.modelo')] ?? reset($precios);

        return round((
            $uso['entrada'] * $precio['entrada']
            + $uso['salida'] * $precio['salida']
            + $uso['cache_lectura'] * $precio['cache_lectura']
            + $uso['cache_escritura'] * $precio['cache_escritura']
        ) / 1_000_000, 6);
    }

    public function gastoDelMes(User $user): float
    {
        $inicio = now(config('app.zona_negocio'))->startOfMonth()->utc();

        return (float) WhatsappUsoClaude::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $inicio)
            ->sum('costo_usd');
    }

    public function topeAlcanzado(User $user, float|string $tope): bool
    {
        return $this->gastoDelMes($user) >= (float) $tope;
    }

    public function porcentaje(User $user, float|string $tope): int
    {
        return (float) $tope <= 0 ? 100 : (int) floor($this->gastoDelMes($user) / (float) $tope * 100);
    }
}
