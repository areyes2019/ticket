<?php

namespace App\Services\Whatsapp\Herramientas;

use App\Models\WhatsappMensaje;
use App\Services\Constancia\ConstanciaException;
use App\Services\Constancia\ConstanciaFiscalService;
use App\Services\Whatsapp\ContextoTurno;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Facturación (041, regla 46): lee la constancia de situación fiscal (foto o
 * PDF) con el mismo servicio de 006. Solo archivos de esta conversación.
 */
class LeerConstancia extends HerramientaBase
{
    public function __construct(private ConstanciaFiscalService $constancias) {}

    public function nombre(): string
    {
        return 'leer_constancia';
    }

    public function descripcion(): string
    {
        return 'Lee la constancia de situación fiscal que mandó el cliente (foto o PDF, por su archivo_id) y regresa RFC, razón social, régimen fiscal y código postal. '
            .'Muéstrale los datos al cliente para que los confirme.';
    }

    public function esquema(): array
    {
        return self::objeto([
            'archivo_id' => ['type' => 'integer'],
        ]);
    }

    public function ejecutar(ContextoTurno $turno, array $entrada): ResultadoHerramienta
    {
        $datos = $this->validar($entrada, ['archivo_id' => ['required', 'integer']]);

        if ($datos instanceof ResultadoHerramienta) {
            return $datos;
        }

        $mensaje = $turno->conversacion->mensajes()->whereKey($datos['archivo_id'])->where('direccion', WhatsappMensaje::ENTRANTE)->whereNotNull('archivo_ruta')->first();

        if ($mensaje === null) {
            return ResultadoHerramienta::error('Ese archivo no es de esta conversación.');
        }

        $archivo = new UploadedFile(Storage::disk('local')->path($mensaje->archivo_ruta), $mensaje->archivo_nombre ?? 'constancia', $mensaje->archivo_mime, null, true);

        try {
            $resultado = $this->constancias->analizar($archivo, null);
        } catch (ConstanciaException $error) {
            return ResultadoHerramienta::error($error->getMessage().' Pide que la mande otra vez más clara, o los datos escritos.', 'no pudo leer la constancia');
        } catch (Throwable $error) {
            report($error);

            return ResultadoHerramienta::error('No se pudo leer la constancia. Pide los datos escritos.', 'no pudo leer la constancia');
        }

        return ResultadoHerramienta::ok([
            ...array_intersect_key($resultado->data, array_flip(['rfc', 'razon_social', 'regimen_fiscal', 'codigo_postal_fiscal'])),
            'aviso' => $resultado->aviso,
        ], 'leyó la constancia de '.$resultado->rfc());
    }
}
