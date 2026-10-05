<?php

namespace App\Models;

use App\Enums\DestinoCobro;
use App\Enums\EstadoCotizacion;
use App\Enums\EstadoFactura;
use App\Enums\TipoDescuento;
use App\Enums\TipoPago;
use App\Models\Concerns\CalculaUtilidadVenta;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use Carbon\CarbonImmutable;
use Database\Factories\CotizacionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * folio y estado no son asignables: el folio lo pone CotizacionController al
 * crear (con el contador del usuario) y el estado solo cambia por las
 * acciones del ciclo (enviar, pagar, entregar, editar). duplicada_de_id lo
 * pone solo CotizacionController::duplicar.
 *
 * "Facturada" no es un estado: es tener una factura vigente (no cancelada)
 * vinculada por facturas.cotizacion_id.
 *
 * "Aceptada" sí es un estado: la cotización tiene venta (un Pedido con
 * pedidos.cotizacion_id). Desde 029 la venta nace con el primer pago, solo
 * para un cliente que no es distribuidor y con algo de producción
 * (destinoAlCobrar()); sus pagos siguen en la cotización y la venta se corrige
 * editando la cotización. Las ventas de "Aceptar" (021) cobran en la venta.
 * aceptada_en lo escriben solo marcarAceptada() y revertirAceptacion().
 *
 * Los totales los escribe solo aplicarTotales(), con la calculadora.
 *
 * descuento_cliente_porcentaje (023) es la copia congelada del descuento
 * permanente del cliente al capturarla: la escribe solo
 * congelarDescuentoCliente() y nunca llega del formulario. Es contexto; el
 * cálculo sigue saliendo del descuento de cada línea.
 *
 * datos_bancarios (027) es la foto de los bancos visibles al crearla (o al
 * duplicarla): la escribe solo congelarDatosBancarios() y no se vuelve a
 * tomar al editar, enviar ni reimprimir. null en las anteriores a 027.
 */
#[Fillable([
    'cliente_id',
    'descuento_global_tipo',
    'descuento_global_valor',
])]
class Cotizacion extends Model
{
    use CalculaUtilidadVenta;

    /** @use HasFactory<CotizacionFactory> */
    use HasFactory;

    /**
     * Str::plural no conoce el español: inferiría "cotizacions".
     */
    protected $table = 'cotizaciones';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'estado' => 'borrador',
        'descuento_cliente_porcentaje' => '0.00',
    ];

    /**
     * Días sin movimiento tras los que una cotización editable y sin pagos se
     * elimina sola. Único lugar donde se define.
     */
    public const DIAS_CADUCIDAD = 30;

    /**
     * Días antes del borrado automático en que se empieza a avisar.
     */
    public const DIAS_AVISO_CADUCIDAD = 7;

    /**
     * Copia el descuento permanente vigente del cliente de la cotización.
     */
    public function congelarDescuentoCliente(): void
    {
        $this->descuento_cliente_porcentaje = Cliente::withTrashed()->whereKey($this->cliente_id)->value('descuento_permanente') ?? '0.00';
    }

    /**
     * Copia los datos bancarios que hoy se muestran en cotizaciones, en su
     * orden. Del logo se guarda la ruta: su archivo nunca se sobrescribe.
     */
    public function congelarDatosBancarios(): void
    {
        $this->datos_bancarios = DatoBancario::query()->paraCotizacion()->get()
            ->map(fn (DatoBancario $dato) => $dato->foto())
            ->all();
    }

    /**
     * Alto del icono de banco en el PDF: la altura del renglón.
     */
    public const ALTO_LOGO_BANCO_MM = 5;

    /**
     * La foto con cada logo incrustado (data URI y ancho proporcional en mm),
     * para que la vista no toque el disco. Un archivo que ya no está deja el
     * banco sin icono: el PDF nunca falla por un logo.
     *
     * @return list<array<string, mixed>>
     */
    public function datosBancariosParaPdf(): array
    {
        return collect($this->datos_bancarios ?? [])->map(function (array $banco) {
            $banco['logo'] = null;
            $banco['logo_ancho_mm'] = null;
            $ruta = $banco['logo_ruta'] ?? null;

            if ($ruta === null) {
                return $banco;
            }

            $contenido = Storage::disk('local')->exists($ruta) ? Storage::disk('local')->get($ruta) : null;
            $medidas = $contenido === null ? false : @getimagesizefromstring($contenido);

            if ($medidas === false || $medidas[1] <= 0) {
                Log::warning('No se pudo leer el logo de un banco para el PDF de la cotización.', ['cotizacion' => $this->id, 'ruta' => $ruta]);

                return $banco;
            }

            $banco['logo'] = 'data:image/webp;base64,'.base64_encode($contenido);
            $banco['logo_ancho_mm'] = round(self::ALTO_LOGO_BANCO_MM * $medidas[0] / $medidas[1], 2);

            return $banco;
        })->values()->all();
    }

    public function tieneDescuentoCliente(): bool
    {
        return (float) $this->descuento_cliente_porcentaje > 0;
    }

    /**
     * Valor del filtro de estado que pide las que ya muestran el aviso de
     * caducidad (scope porCaducar).
     */
    public const POR_CADUCAR = 'por_caducar';

    /**
     * Valores del filtro de estado que piden las que tienen factura vigente y
     * las que se pueden facturar y todavía no la tienen.
     */
    public const FACTURADAS = 'facturadas';

    public const POR_FACTURAR = 'por_facturar';

    /**
     * Estados en los que una cotización se puede facturar.
     */
    public const ESTADOS_FACTURABLES = [EstadoCotizacion::Enviada, EstadoCotizacion::Aceptada, EstadoCotizacion::Pagada, EstadoCotizacion::ProductoEntregado];

    /**
     * Columnas que se pueden filtrar desde el listado (además de las fechas).
     */
    public const FILTROS = ['cliente', 'rfc', 'folio', 'estado'];

    /**
     * Columnas de totales que escribe la calculadora.
     */
    public const TOTALES = ['subtotal', 'total_descuento', 'base_iva_16', 'total_iva_16', 'base_iva_0', 'base_exento', 'total'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Incluye los clientes eliminados, para que la cotización siga mostrando
     * el nombre de su cliente.
     *
     * @return BelongsTo<Cliente, $this>
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class)->withTrashed();
    }

    /**
     * @return HasMany<CotizacionLinea, $this>
     */
    public function lineas(): HasMany
    {
        return $this->hasMany(CotizacionLinea::class)->orderBy('orden');
    }

    /**
     * @return HasMany<CotizacionPago, $this>
     */
    public function pagos(): HasMany
    {
        return $this->hasMany(CotizacionPago::class)->orderBy('fecha_pago')->orderBy('id');
    }

    /**
     * @return HasMany<Factura, $this>
     */
    public function facturas(): HasMany
    {
        return $this->hasMany(Factura::class);
    }

    /**
     * La factura que la marca como facturada: cualquiera que no esté
     * cancelada, aunque todavía no se haya timbrado.
     *
     * @return HasOne<Factura, $this>
     */
    public function facturaVigente(): HasOne
    {
        return $this->hasOne(Factura::class)->where('estado', '!=', EstadoFactura::Cancelada->value);
    }

    /**
     * La venta que nació con su primer pago (029) o al aceptarla (021).
     *
     * @return HasOne<Pedido, $this>
     */
    public function venta(): HasOne
    {
        return $this->hasOne(Pedido::class);
    }

    /**
     * @return BelongsTo<Cotizacion, $this>
     */
    public function duplicadaDe(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class, 'duplicada_de_id');
    }

    /**
     * Escribe los totales calculados (no guarda).
     *
     * @param  array<string, mixed>  $totales  resultado de CalculadoraTotalesDocumento::calcular()
     */
    public function aplicarTotales(array $totales): void
    {
        foreach (self::TOTALES as $columna) {
            $this->{$columna} = $totales[$columna];
        }
    }

    /**
     * Usa pagos_sum_monto cuando el listado lo precargó con withSum().
     */
    public function totalPagado(): string
    {
        $suma = array_key_exists('pagos_sum_monto', $this->attributes)
            ? $this->attributes['pagos_sum_monto']
            : ($this->relationLoaded('pagos') ? $this->pagos->sum('monto') : $this->pagos()->sum('monto'));

        return CalculadoraTotalesDocumento::pesos(CalculadoraTotalesDocumento::centavos($suma ?? 0));
    }

    public function saldoPendiente(): string
    {
        return CalculadoraTotalesDocumento::pesos(
            CalculadoraTotalesDocumento::centavos($this->total) - CalculadoraTotalesDocumento::centavos($this->totalPagado())
        );
    }

    public function tienePagos(): bool
    {
        if (array_key_exists('pagos_count', $this->attributes)) {
            return $this->attributes['pagos_count'] > 0;
        }

        return $this->relationLoaded('pagos') ? $this->pagos->isNotEmpty() : $this->pagos()->exists();
    }

    public function tieneAnticipo(): bool
    {
        return $this->relationLoaded('pagos')
            ? $this->pagos->contains('tipo', TipoPago::Anticipo)
            : $this->pagos()->where('tipo', TipoPago::Anticipo)->exists();
    }

    /**
     * Una cotización facturada ya no cambia: la factura salió de ella. Una
     * aceptada se edita mientras su venta cobre aquí (029), no esté entregada
     * ni facturada: los cambios se copian a la venta.
     */
    public function esEditable(): bool
    {
        if ($this->estaFacturada()) {
            return false;
        }

        if ($this->estado->esEditable()) {
            return true;
        }

        $venta = $this->ventaQueCobraAqui();

        return $venta !== null && ! $venta->estaEntregado() && $venta->facturaVigente === null;
    }

    /**
     * Su venta, si nació con el primer pago (029) y lee de aquí sus pagos.
     */
    public function ventaQueCobraAqui(): ?Pedido
    {
        if (! $this->estaAceptada()) {
            return null;
        }

        $venta = $this->venta;

        return $venta?->cobro_en_cotizacion ? $venta : null;
    }

    /**
     * Qué nace con el pago que se va a registrar; null si no es el primero
     * (ya tiene pagos o venta). Solo suministros o un distribuidor no crean
     * nada; lo demás crea la venta y su orden de trabajo.
     */
    public function destinoAlCobrar(): ?DestinoCobro
    {
        if ($this->tienePagos() || $this->venta !== null) {
            return null;
        }

        return match (true) {
            $this->lineasDeProduccion()->isEmpty() => DestinoCobro::SinVentaSuministros,
            (bool) $this->cliente?->es_distribuidor => DestinoCobro::SinVentaDistribuidor,
            default => DestinoCobro::VentaYOrden,
        };
    }

    /**
     * Las líneas libres y las de artículos de un catálogo de producción.
     *
     * @return Collection<int, CotizacionLinea>
     */
    public function lineasDeProduccion(): Collection
    {
        $this->loadMissing('lineas.articulo.catalogo');

        return $this->lineas->filter(fn (CotizacionLinea $linea) => $linea->esProduccion())->values();
    }

    /**
     * Usa factura_vigente_exists cuando el listado lo precargó con withExists().
     */
    public function estaFacturada(): bool
    {
        if (array_key_exists('factura_vigente_exists', $this->attributes)) {
            return (bool) $this->attributes['factura_vigente_exists'];
        }

        return $this->relationLoaded('facturaVigente') ? $this->facturaVigente !== null : $this->facturaVigente()->exists();
    }

    /**
     * Por estado y sin factura vigente, ni suya ni de su venta (una sola
     * factura entre las dos, 021). Las líneas se revisan aparte
     * (motivoNoFacturable).
     */
    public function esFacturable(): bool
    {
        return in_array($this->estado, self::ESTADOS_FACTURABLES, true)
            && ! $this->estaFacturada()
            && $this->facturaDeLaVenta() === null;
    }

    /**
     * La factura vigente de su venta (autofactura), si la aceptó y ya se
     * facturó por ahí.
     */
    public function facturaDeLaVenta(): ?Factura
    {
        if ($this->estado !== EstadoCotizacion::Aceptada) {
            return null;
        }

        return $this->venta?->facturaVigente;
    }

    /**
     * Líneas que no pueden pasar a una factura: las libres y las de artículos
     * eliminados (el CFDI necesita las claves SAT del artículo).
     *
     * @return Collection<int, CotizacionLinea>
     */
    public function lineasNoFacturables(): Collection
    {
        $this->loadMissing('lineas.articulo');

        return $this->lineas->filter(fn (CotizacionLinea $linea) => $linea->articulo === null || $linea->articulo->trashed())->values();
    }

    /**
     * Por qué no se puede facturar; null si se puede.
     */
    public function motivoNoFacturable(): ?string
    {
        if (! in_array($this->estado, self::ESTADOS_FACTURABLES, true)) {
            return 'Una cotización en '.mb_strtolower($this->estado->etiqueta()).' no se puede facturar: envíala primero.';
        }

        $this->loadMissing('facturaVigente');

        if ($this->facturaVigente !== null) {
            return "Esta cotización ya tiene la factura {$this->facturaVigente->folioVisible()}.";
        }

        $facturaDeLaVenta = $this->facturaDeLaVenta();

        if ($facturaDeLaVenta !== null) {
            return "Su venta {$this->venta->folio_formateado} ya se facturó en {$facturaDeLaVenta->folioVisible()}.";
        }

        $lineas = $this->lineasNoFacturables();

        if ($lineas->isNotEmpty()) {
            return 'Tiene líneas que no vienen del catálogo ('.$lineas->pluck('orden')->implode(', ').'). Corrígelas para poder facturar.';
        }

        return null;
    }

    /**
     * Líneas cuyo precio cotizado ya no es el del catálogo, como aviso al
     * facturar ("Tornillo M8: $100.00 en la cotización, $120.00 hoy en el
     * catálogo"). La factura conserva el precio cotizado.
     *
     * @return list<string>
     */
    public function avisosDePrecio(): array
    {
        $this->loadMissing('lineas.articulo');
        $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);

        return $this->lineas
            ->filter(fn (CotizacionLinea $linea) => $linea->articulo !== null
                && CalculadoraTotalesDocumento::centavos($linea->precio_unitario) !== CalculadoraTotalesDocumento::centavos($linea->articulo->precio_unitario_sin_iva))
            ->map(fn (CotizacionLinea $linea) => "{$linea->descripcion}: {$pesos($linea->precio_unitario)} en la cotización, {$pesos($linea->articulo->precio_unitario_sin_iva)} hoy en el catálogo.")
            ->values()
            ->all();
    }

    /**
     * Solo en borrador o enviada (una aceptada con venta tampoco se borra).
     */
    public function puedeEliminarse(): bool
    {
        return $this->estado->esEditable() && ! $this->estaFacturada() && ! $this->tienePagos();
    }

    /**
     * Con saldo: en borrador o enviada (el primer pago también en borrador,
     * 029), o en aceptada mientras su venta cobre aquí y no se haya entregado.
     */
    public function puedeRegistrarPago(): bool
    {
        if (CalculadoraTotalesDocumento::centavos($this->saldoPendiente()) <= 0) {
            return false;
        }

        if ($this->estado->esEditable()) {
            return true;
        }

        $venta = $this->ventaQueCobraAqui();

        return $venta !== null && ! $venta->estaEntregado();
    }

    /**
     * Monto que se registraría para un pago: el elegido en un anticipo, el
     * saldo pendiente en saldo y pago total (el que venga se ignora).
     */
    public function montoDePago(TipoPago $tipo, float|string|null $monto): string
    {
        return $tipo === TipoPago::Anticipo
            ? CalculadoraTotalesDocumento::pesos(CalculadoraTotalesDocumento::centavos($monto))
            : $this->saldoPendiente();
    }

    /**
     * Por qué no se puede registrar ese pago; null si se puede. La usan el
     * Form Request y el controlador (otra vez, con la fila bloqueada).
     */
    public function motivoRechazoPago(TipoPago $tipo, float|string|null $monto): ?string
    {
        if (! $this->puedeRegistrarPago()) {
            $venta = $this->estaAceptada() ? $this->venta : null;

            return match (true) {
                CalculadoraTotalesDocumento::centavos($this->saldoPendiente()) <= 0 => 'La cotización ya está pagada por completo.',
                $venta !== null && $venta->estaEntregado() => "La venta {$venta->folio_formateado} ya se entregó.",
                $venta !== null => "Los pagos se registran en su venta {$venta->folio_formateado}.",
                default => 'Solo se registran pagos en una cotización en borrador o enviada.',
            };
        }

        return match (true) {
            $tipo === TipoPago::Anticipo && $this->tieneAnticipo() => 'La cotización ya tiene un anticipo: registra el saldo.',
            $tipo === TipoPago::PagoTotal && $this->tieneAnticipo() => 'La cotización ya tiene un anticipo: registra el saldo.',
            $tipo === TipoPago::Saldo && ! $this->tieneAnticipo() => 'Sin anticipo previo, registra el pago total.',
            CalculadoraTotalesDocumento::centavos($this->montoDePago($tipo, $monto)) > CalculadoraTotalesDocumento::centavos($this->saldoPendiente()) => 'El pago no puede ser mayor al saldo pendiente ($'.number_format((float) $this->saldoPendiente(), 2).').',
            default => null,
        };
    }

    public function puedeEntregarse(): bool
    {
        return $this->estado === EstadoCotizacion::Pagada;
    }

    public function estaAceptada(): bool
    {
        return $this->estado === EstadoCotizacion::Aceptada;
    }

    /**
     * Aviso de la ventana del primer pago cuando nace la venta: líneas de
     * catálogo sin existencia suficiente. No bloquea; la venta deja el
     * faltante (021, 029).
     *
     * @return list<string>
     */
    public function faltantesAlCrearVenta(): array
    {
        $this->loadMissing('lineas');

        $existencias = Existencia::whereIn('articulo_id', $this->lineas->pluck('articulo_id')->filter())
            ->pluck('existencia', 'articulo_id');

        return $this->lineas
            ->filter(fn (CotizacionLinea $linea) => $linea->articulo_id !== null
                && (int) $existencias->get($linea->articulo_id, 0) < $linea->cantidad)
            ->map(fn (CotizacionLinea $linea) => ($linea->modelo ?: $linea->descripcion)
                .' (faltan '.($linea->cantidad - (int) $existencias->get($linea->articulo_id, 0)).')')
            ->values()
            ->all();
    }

    /**
     * No guarda.
     */
    public function marcarAceptada(): void
    {
        $this->estado = EstadoCotizacion::Aceptada;
        $this->aceptada_en = now();
    }

    /**
     * Al borrar su venta: vuelve a enviada y la caducidad cuenta desde hoy
     * (save() renueva updated_at).
     */
    public function revertirAceptacion(): void
    {
        $this->estado = EstadoCotizacion::Enviada;
        $this->aceptada_en = null;
        $this->updateTimestamps();
        $this->save();
    }

    /**
     * Pasa de borrador a enviada. En cualquier otro estado solo registra el
     * movimiento: reenviar reinicia el plazo de caducidad.
     */
    public function marcarEnviada(): void
    {
        if ($this->estado === EstadoCotizacion::Borrador) {
            $this->estado = EstadoCotizacion::Enviada;
            $this->save();

            return;
        }

        $this->touch();
    }

    /**
     * Fecha en que el comando de caducidad la borrará; null si no caduca
     * (estado no editable o con pagos).
     */
    public function caducaEl(): ?CarbonImmutable
    {
        if (! $this->puedeEliminarse() || $this->updated_at === null) {
            return null;
        }

        return $this->updated_at->toImmutable()->addDays(self::DIAS_CADUCIDAD);
    }

    /**
     * Días calendario en la zona del negocio entre hoy y el borrado (0 = hoy).
     */
    public function diasParaCaducar(): ?int
    {
        $caducaEl = $this->caducaEl();

        if ($caducaEl === null) {
            return null;
        }

        $zona = config('app.zona_negocio');
        $hoy = CarbonImmutable::now($zona)->startOfDay();

        return max(0, (int) $hoy->diffInDays($caducaEl->setTimezone($zona)->startOfDay(), false));
    }

    public function mostrarAvisoCaducidad(): bool
    {
        $dias = $this->diasParaCaducar();

        return $dias !== null && $dias <= self::DIAS_AVISO_CADUCIDAD;
    }

    /**
     * "Se elimina en 5 días" / "Se elimina mañana" / "Se elimina hoy".
     */
    public function textoCaducidad(): string
    {
        return match ($dias = $this->diasParaCaducar()) {
            null => '',
            0 => 'Se elimina hoy',
            1 => 'Se elimina mañana',
            default => "Se elimina en {$dias} días",
        };
    }

    /**
     * "COT-0012".
     *
     * @return Attribute<string, never>
     */
    protected function folioFormateado(): Attribute
    {
        return Attribute::get(fn (): string => 'COT-'.str_pad((string) $this->folio, 4, '0', STR_PAD_LEFT));
    }

    /**
     * Aplica los filtros del listado (combinados con Y). Los vacíos se ignoran.
     *
     * @param  Builder<self>  $consulta
     *                                   El texto busca en el folio (si parece uno), la razón social, el nombre
     *                                   comercial y el RFC del cliente. El estado "por_caducar" no es un estado:
     *                                   son las que ya muestran el aviso de caducidad.
     * @param  array{texto?: string, folio?: int|null, estado?: string, desde?: CarbonImmutable|null, hasta?: CarbonImmutable|null}  $filtros
     */
    #[Scope]
    protected function filtrar(Builder $consulta, array $filtros): void
    {
        $texto = trim($filtros['texto'] ?? '');
        $folio = $filtros['folio'] ?? null;
        $estado = $filtros['estado'] ?? '';

        if ($texto !== '') {
            $rfc = strtoupper((string) preg_replace('/\s+/', '', $texto));

            $consulta->where(function (Builder $coincidencias) use ($texto, $rfc, $folio) {
                $coincidencias->whereHas('cliente', fn (Builder $clientes) => $clientes->withTrashed()->where(
                    fn (Builder $datos) => $datos->where('razon_social', 'like', "%{$texto}%")
                        ->orWhere('nombre_comercial', 'like', "%{$texto}%")
                        ->orWhere('rfc', 'like', "%{$rfc}%")
                ));

                if ($folio !== null) {
                    $coincidencias->orWhere('folio', $folio);
                }
            });
        }

        if ($estado === self::POR_CADUCAR) {
            $consulta->porCaducar();
        } elseif ($estado === self::FACTURADAS) {
            $consulta->facturadas();
        } elseif ($estado === self::POR_FACTURAR) {
            $consulta->porFacturar();
        } elseif ($estado !== '') {
            $consulta->where('estado', $estado);
        }

        if (($filtros['desde'] ?? null) !== null) {
            $consulta->where('created_at', '>=', $filtros['desde']->utc());
        }

        if (($filtros['hasta'] ?? null) !== null) {
            $consulta->where('created_at', '<=', $filtros['hasta']->utc());
        }
    }

    /**
     * Las que ya muestran el aviso de caducidad (mostrarAvisoCaducidad()):
     * movida el día D, se borra el D + 30 y el aviso sale si D + 30 − hoy ≤ 7,
     * es decir, si el último movimiento fue antes del inicio de hoy − 22
     * (días calendario en la zona del negocio).
     *
     * @param  Builder<self>  $consulta
     */
    #[Scope]
    protected function porCaducar(Builder $consulta): void
    {
        $limite = CarbonImmutable::now(config('app.zona_negocio'))->startOfDay()
            ->subDays(self::DIAS_CADUCIDAD - self::DIAS_AVISO_CADUCIDAD - 1);

        $consulta->whereIn('estado', [EstadoCotizacion::Borrador, EstadoCotizacion::Enviada])
            ->doesntHave('pagos')
            ->doesntHave('facturaVigente')
            ->where('updated_at', '<', $limite->utc());
    }

    /**
     * Las que el comando de caducidad borra: editables, sin pagos, sin
     * factura y sin movimiento en DIAS_CADUCIDAD días.
     *
     * @param  Builder<self>  $consulta
     */
    #[Scope]
    protected function vencidas(Builder $consulta): void
    {
        $consulta->whereIn('estado', [EstadoCotizacion::Borrador, EstadoCotizacion::Enviada])
            ->doesntHave('pagos')
            ->doesntHave('facturaVigente')
            ->where('updated_at', '<', now()->subDays(self::DIAS_CADUCIDAD));
    }

    /**
     * @param  Builder<self>  $consulta
     */
    #[Scope]
    protected function facturadas(Builder $consulta): void
    {
        $consulta->has('facturaVigente');
    }

    /**
     * Se pueden facturar por estado y aún no tienen factura. Incluye las que
     * tienen líneas libres, para que se vean y se corrijan.
     *
     * @param  Builder<self>  $consulta
     */
    #[Scope]
    protected function porFacturar(Builder $consulta): void
    {
        $consulta->whereIn('estado', self::ESTADOS_FACTURABLES)
            ->doesntHave('facturaVigente')
            ->whereDoesntHave('venta.facturaVigente');
    }

    /**
     * Todas sus líneas vienen de un artículo que sigue en el catálogo (la
     * misma regla que lineasNoFacturables, en SQL).
     *
     * @param  Builder<self>  $consulta
     */
    #[Scope]
    protected function soloLineasDeCatalogo(Builder $consulta): void
    {
        $consulta->whereDoesntHave('lineas', fn (Builder $lineas) => $lineas->where(
            fn (Builder $noFacturables) => $noFacturables->whereNull('articulo_id')
                ->orWhereHas('articulo', fn (Builder $articulos) => $articulos->onlyTrashed())
        ));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado' => EstadoCotizacion::class,
            'descuento_global_tipo' => TipoDescuento::class,
            'descuento_global_valor' => 'decimal:2',
            'descuento_cliente_porcentaje' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total_descuento' => 'decimal:2',
            'base_iva_16' => 'decimal:2',
            'total_iva_16' => 'decimal:2',
            'base_iva_0' => 'decimal:2',
            'base_exento' => 'decimal:2',
            'total' => 'decimal:2',
            'aceptada_en' => 'immutable_datetime',
            'datos_bancarios' => 'array',
        ];
    }
}
