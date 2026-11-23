<?php

namespace App\Services\Whatsapp;

use App\Models\User;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappMensaje;
use App\Models\WhatsappPlantilla;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Manda mensajes a una conversación y los guarda como salientes (041). Un
 * mensaje libre exige la ventana de 24 horas abierta (adición 2); una
 * plantilla no.
 */
class EnviadorWhatsapp
{
    public function __construct(private ClienteMeta $meta) {}

    /**
     * @param  string  $autor  ventas, facturacion, usuario o sistema
     */
    public function texto(WhatsappConversacion $conversacion, string $texto, string $autor, ?User $user = null): WhatsappMensaje
    {
        $this->exigirVentana($conversacion);

        return $this->registrar($conversacion, $autor, $user, 'texto', ['texto' => $texto], fn () => $this->meta->enviarTexto($conversacion->numero, $conversacion->wa_id, $texto));
    }

    /**
     * @param  array<string, mixed>  $datos  enlaces a documentos del sistema (cotizacion_id, factura_id)
     */
    public function documento(WhatsappConversacion $conversacion, string $contenido, string $nombre, string $mime, string $autor, ?User $user = null, ?string $pie = null, array $datos = []): WhatsappMensaje
    {
        $this->exigirVentana($conversacion);

        $ruta = $this->guardarArchivo($conversacion, $contenido, $nombre);
        $esImagen = str_starts_with($mime, 'image/');

        return $this->registrar($conversacion, $autor, $user, $esImagen ? 'imagen' : 'documento', [
            'texto' => $pie,
            'archivo_ruta' => $ruta,
            'archivo_nombre' => $nombre,
            'archivo_mime' => $mime,
            'datos' => $datos === [] ? null : $datos,
        ], fn () => $esImagen
            ? $this->meta->enviarImagen($conversacion->numero, $conversacion->wa_id, $contenido, $nombre, $mime, $pie)
            : $this->meta->enviarDocumento($conversacion->numero, $conversacion->wa_id, $contenido, $nombre, $mime, $pie));
    }

    /**
     * @param  list<string>  $variables
     */
    public function plantilla(WhatsappConversacion $conversacion, WhatsappPlantilla $plantilla, array $variables, User $user): WhatsappMensaje
    {
        return $this->registrar($conversacion, 'usuario', $user, 'plantilla', [
            'texto' => $plantilla->texto($variables),
            'datos' => ['plantilla' => $plantilla->nombre, 'idioma' => $plantilla->idioma, 'variables' => $variables],
        ], fn () => $this->meta->enviarPlantilla($conversacion->numero, $conversacion->wa_id, $plantilla->nombre, $plantilla->idioma, $variables));
    }

    /**
     * Aviso o marca del sistema que el cliente no recibe (pase a humano,
     * reapertura). Se ve centrado en la bandeja.
     */
    public static function nota(WhatsappConversacion $conversacion, string $texto): WhatsappMensaje
    {
        return $conversacion->mensajes()->create([
            'direccion' => WhatsappMensaje::INTERNO,
            'autor' => 'sistema',
            'tipo' => 'texto',
            'texto' => $texto,
            'respondido' => true,
            'enviado_en' => now(),
        ]);
    }

    public function guardarArchivo(WhatsappConversacion $conversacion, string $contenido, string $nombre): string
    {
        $extension = pathinfo($nombre, PATHINFO_EXTENSION);
        $ruta = 'whatsapp/'.$conversacion->id.'/'.Str::uuid().($extension === '' ? '' : '.'.strtolower($extension));

        Storage::disk('local')->put($ruta, $contenido);

        return $ruta;
    }

    private function exigirVentana(WhatsappConversacion $conversacion): void
    {
        if (! $conversacion->ventanaAbierta()) {
            throw new VentanaCerrada;
        }
    }

    /**
     * Se guarda aunque Meta lo rechace, marcado como no entregado, para que
     * se vea en la bandeja; después se relanza el error.
     *
     * @param  array<string, mixed>  $campos
     * @param  callable(): string  $envio
     */
    private function registrar(WhatsappConversacion $conversacion, string $autor, ?User $user, string $tipo, array $campos, callable $envio): WhatsappMensaje
    {
        $mensaje = $conversacion->mensajes()->make([
            ...$campos,
            'direccion' => WhatsappMensaje::SALIENTE,
            'autor' => $autor,
            'user_id' => $user?->id,
            'tipo' => $tipo,
            'respondido' => true,
            'enviado_en' => now(),
        ]);

        try {
            $mensaje->wamid = $envio();
            $mensaje->estado_envio = 'enviado';
        } catch (MetaException $error) {
            $mensaje->estado_envio = 'fallido';
            $mensaje->error_envio = Str::limit($error->getMessage(), 250);
            $mensaje->save();

            throw $error;
        }

        $mensaje->save();
        $conversacion->forceFill(['ultimo_mensaje_en' => now()])->save();

        return $mensaje;
    }
}
