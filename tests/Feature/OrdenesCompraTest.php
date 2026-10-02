<?php

use App\Enums\EstadoOrdenCompra;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Cuenta;
use App\Models\OrdenCompra;
use App\Models\Proveedor;
use App\Models\User;

/**
 * Artículo de lista $200 en un catálogo con 10% de descuento y 25% de
 * utilidad: costo $180, venta $225.
 */
function articuloDelProveedor(Proveedor $proveedor, array $atributos = []): Articulo
{
    $catalogo = Catalogo::factory()->for($proveedor)->conDescuento(10)->conUtilidad(25)->create();

    return Articulo::factory()->for($catalogo)->create(['precio_proveedor' => '200.00', ...$atributos]);
}

/**
 * @param  array<string, mixed>  $cambios
 * @return array<string, mixed>
 */
function lineaOrden(array $cambios = []): array
{
    return [
        'articulo_id' => null,
        'cantidad' => 2,
        'descripcion' => 'Tinta azul',
        'modelo' => 'T-AZ',
        'precio_unitario' => '180.00',
        'tasa_iva' => '16',
        ...$cambios,
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->proveedor = Proveedor::factory()->for($this->user)->create(['correo' => 'ventas@proveedor.mx', 'telefono' => '+524491234567']);
    $this->articulo = articuloDelProveedor($this->proveedor);
    $this->crear = fn (array $datos) => $this->actingAs($this->user)->post('/ordenes-compra', [
        'proveedor_id' => $this->proveedor->id,
        'lineas' => [lineaOrden(['articulo_id' => $this->articulo->id])],
        ...$datos,
    ]);
});

describe('alta', function () {
    it('crea la orden en borrador con folio propio y totales del servidor', function () {
        Cotizacion::factory()->count(3)->create(['user_id' => $this->user->id, 'cliente_id' => Cliente::factory()->for($this->user)]);

        ($this->crear)(['total' => '1.00', 'estado' => 'pagada', 'folio' => 99])->assertSessionHasNoErrors();

        $orden = $this->user->ordenesCompra()->sole();

        expect($orden)
            ->folio->toBe(1)
            ->folio_formateado->toBe('OC-0001')
            ->estado->toBe(EstadoOrdenCompra::Borrador)
            ->subtotal->toBe('360.00')
            ->total_iva_16->toBe('57.60')
            ->total->toBe('417.60')
            ->and($orden->lineas->sole()->importe)->toBe('360.00');
    });

    it('guarda fecha de entrega esperada, observaciones y líneas libres', function () {
        ($this->crear)([
            'lineas' => [lineaOrden(['articulo_id' => $this->articulo->id]), lineaOrden(['descripcion' => 'Flete', 'modelo' => null, 'cantidad' => 1, 'precio_unitario' => '150.00'])],
            'fecha_entrega_esperada' => '2026-10-20',
            'observaciones' => 'Entregar en bodega',
        ])->assertSessionHasNoErrors();

        $orden = $this->user->ordenesCompra()->sole();

        expect($orden->fecha_entrega_esperada->toDateString())->toBe('2026-10-20')
            ->and($orden->observaciones)->toBe('Entregar en bodega')
            ->and($orden->lineas)->toHaveCount(2)
            ->and($orden->lineas[1]->articulo_id)->toBeNull();
    });

    it('rechaza un artículo de otro proveedor', function () {
        $otro = articuloDelProveedor(Proveedor::factory()->for($this->user)->create());

        ($this->crear)(['lineas' => [lineaOrden(['articulo_id' => $otro->id])]])
            ->assertSessionHasErrors(['lineas.0.articulo_id' => 'El artículo de la línea 1 no pertenece a un catálogo de este proveedor.']);

        expect(OrdenCompra::count())->toBe(0);
    });

    it('rechaza proveedores y artículos ajenos', function () {
        $ajeno = Proveedor::factory()->create();

        ($this->crear)(['proveedor_id' => $ajeno->id])->assertSessionHasErrors('proveedor_id');
        ($this->crear)(['lineas' => [lineaOrden(['articulo_id' => articuloDelProveedor($ajeno)->id])]])->assertSessionHasErrors('lineas.0.articulo_id');
    });

    it('editar el costo no toca el catálogo', function () {
        ($this->crear)(['lineas' => [lineaOrden(['articulo_id' => $this->articulo->id, 'precio_unitario' => '170.00'])]])->assertSessionHasNoErrors();

        expect($this->articulo->fresh())
            ->precio_proveedor->toBe('200.00')
            ->costo_con_descuento->toBe('180.00')
            ->precio_unitario_sin_iva->toBe('225.00')
            ->and($this->user->ordenesCompra()->sole()->lineas->sole()->precio_unitario)->toBe('170.00');
    });

    it('no reutiliza el folio de una orden borrada', function () {
        ($this->crear)([]);
        $this->actingAs($this->user)->delete('/ordenes-compra/'.$this->user->ordenesCompra()->sole()->id);
        ($this->crear)([]);

        expect($this->user->ordenesCompra()->sole()->folio)->toBe(2);
    });
});

describe('edición y borrado', function () {
    it('editar una enviada la regresa a borrador', function () {
        $orden = OrdenCompra::factory()->for($this->proveedor)->conLinea()->enEstado(EstadoOrdenCompra::Enviada)->create();

        $this->actingAs($this->user)->put("/ordenes-compra/{$orden->id}", [
            'proveedor_id' => $this->proveedor->id,
            'lineas' => [lineaOrden()],
        ])->assertRedirect(route('ordenes-compra.show', $orden))->assertSessionHas('exito');

        expect($orden->fresh())->estado->toBe(EstadoOrdenCompra::Borrador)->total->toBe('417.60');
    });

    it('acepta en la edición un artículo ya guardado que cambió de proveedor', function () {
        ($this->crear)([]);
        $orden = $this->user->ordenesCompra()->sole();
        $this->articulo->forceFill(['proveedor_id' => Proveedor::factory()->for($this->user)->create()->id])->saveQuietly();

        $this->actingAs($this->user)->put("/ordenes-compra/{$orden->id}", [
            'proveedor_id' => $this->proveedor->id,
            'lineas' => [lineaOrden(['articulo_id' => $this->articulo->id, 'cantidad' => 5])],
        ])->assertSessionHasNoErrors();
    });

    it('no se edita pagada ni recibida', function (EstadoOrdenCompra $estado, string $motivo) {
        $orden = OrdenCompra::factory()->for($this->proveedor)->conLinea()->enEstado($estado)->create();

        $this->actingAs($this->user)->get("/ordenes-compra/{$orden->id}/editar")->assertForbidden()->assertSee($motivo);
        $this->actingAs($this->user)->put("/ordenes-compra/{$orden->id}", ['proveedor_id' => $this->proveedor->id, 'lineas' => [lineaOrden()]])->assertForbidden();
    })->with([
        'pagada' => [EstadoOrdenCompra::Pagada, 'Una orden pagada no se edita: cancela el pago primero.'],
        'recibida' => [EstadoOrdenCompra::Recibida, 'Una orden recibida ya no se edita.'],
    ]);

    it('solo se elimina en borrador', function () {
        $borrador = OrdenCompra::factory()->for($this->proveedor)->conLinea()->create();
        $enviada = OrdenCompra::factory()->for($this->proveedor)->conLinea()->enEstado(EstadoOrdenCompra::Enviada)->create();

        $this->actingAs($this->user)->delete("/ordenes-compra/{$borrador->id}")->assertRedirect(route('ordenes-compra.index'));
        $this->actingAs($this->user)->delete("/ordenes-compra/{$enviada->id}")->assertSessionHas('error', 'Solo se elimina una orden en borrador.');

        expect(OrdenCompra::pluck('id')->all())->toBe([$enviada->id]);
    });
});

describe('recepción y duplicado', function () {
    it('solo una pagada se marca como recibida', function (EstadoOrdenCompra $estado, EstadoOrdenCompra $final) {
        $orden = OrdenCompra::factory()->for($this->proveedor)->enEstado($estado)->create();

        $this->actingAs($this->user)->post("/ordenes-compra/{$orden->id}/recibir");

        expect($orden->fresh()->estado)->toBe($final);
    })->with([
        'pagada' => [EstadoOrdenCompra::Pagada, EstadoOrdenCompra::Recibida],
        'borrador' => [EstadoOrdenCompra::Borrador, EstadoOrdenCompra::Borrador],
        'enviada' => [EstadoOrdenCompra::Enviada, EstadoOrdenCompra::Enviada],
    ]);

    it('duplica en borrador sin pago ni fecha esperada', function () {
        $original = OrdenCompra::factory()->for($this->proveedor)->conLinea(3)->enEstado(EstadoOrdenCompra::Recibida)->create([
            'observaciones' => 'Urgente',
            'fecha_entrega_esperada' => '2026-10-20',
            'descuento_global_tipo' => 'monto',
            'descuento_global_valor' => '10.00',
        ]);

        $this->actingAs($this->user)->post("/ordenes-compra/{$original->id}/duplicar")->assertSessionHas('exito');

        $copia = OrdenCompra::whereKeyNot($original->id)->sole();

        expect($copia)
            ->folio->toBe(2)
            ->estado->toBe(EstadoOrdenCompra::Borrador)
            ->proveedor_id->toBe($this->proveedor->id)
            ->observaciones->toBe('Urgente')
            ->descuento_global_valor->toBe('10.00')
            ->fecha_entrega_esperada->toBeNull()
            ->cuenta_id->toBeNull()
            ->duplicada_de_id->toBe($original->id)
            ->and($copia->lineas->sole()->cantidad)->toBe(3);
    });
});

describe('listado', function () {
    it('filtra por proveedor, RFC, folio y estado', function () {
        $otro = Proveedor::factory()->for($this->user)->create(['nombre_comercial' => 'Papelera Sur', 'rfc' => 'PSU010101AB1']);
        $a = OrdenCompra::factory()->for($this->proveedor)->create();
        $b = OrdenCompra::factory()->for($otro)->enEstado(EstadoOrdenCompra::Enviada)->create();

        $this->actingAs($this->user)->get('/ordenes-compra?proveedor=papelera')->assertSee($b->folio_formateado)->assertDontSee($a->folio_formateado);
        $this->actingAs($this->user)->get('/ordenes-compra?rfc=psu01')->assertSee($b->folio_formateado)->assertDontSee($a->folio_formateado);
        $this->actingAs($this->user)->get('/ordenes-compra?folio=OC-0001')->assertSee($a->folio_formateado)->assertDontSee($b->folio_formateado);
        $this->actingAs($this->user)->get('/ordenes-compra?folio=2')->assertSee($b->folio_formateado)->assertDontSee($a->folio_formateado);
        $this->actingAs($this->user)->get('/ordenes-compra?estado=enviada')->assertSee($b->folio_formateado)->assertDontSee($a->folio_formateado);
    });

    it('muestra este mes por defecto y acepta un rango', function () {
        $vieja = OrdenCompra::factory()->for($this->proveedor)->create(['created_at' => now()->subMonths(2)]);
        $nueva = OrdenCompra::factory()->for($this->proveedor)->create();
        $dia = $vieja->created_at->setTimezone(config('app.zona_negocio'))->toDateString();

        $this->actingAs($this->user)->get('/ordenes-compra')->assertSee($nueva->folio_formateado)->assertDontSee($vieja->folio_formateado);
        $this->actingAs($this->user)->get("/ordenes-compra?fecha_desde={$dia}&fecha_hasta={$dia}")->assertSee($vieja->folio_formateado)->assertDontSee($nueva->folio_formateado);
    });

    it('responde el fragmento para la búsqueda dinámica', function () {
        $orden = OrdenCompra::factory()->for($this->proveedor)->create();

        $this->actingAs($this->user)->get('/ordenes-compra/buscar', cabecerasAjax())
            ->assertOk()->assertSee('ordenes-filas', false)->assertSee($orden->folio_formateado);
    });

    it('no muestra órdenes de otro usuario', function () {
        $ajena = OrdenCompra::factory()->conLinea()->create();

        $this->actingAs($this->user)->get('/ordenes-compra?periodo=mes')->assertDontSee($ajena->proveedor->nombre_comercial);
    });
});

describe('detalle y PDF', function () {
    it('muestra el detalle con el botón de compartir', function () {
        $orden = OrdenCompra::factory()->for($this->proveedor)->conLinea()->create();

        $this->actingAs($this->user)->get("/ordenes-compra/{$orden->id}")
            ->assertOk()
            ->assertSee('Orden de compra OC-0001')
            ->assertSee('data-archivo="orden-compra-OC-0001.pdf"', false)
            ->assertSee('data-telefono="524491234567"', false);
    });

    it('pinta el formulario con el selector de proveedor y el costo', function () {
        $this->actingAs($this->user)->get('/ordenes-compra/crear')
            ->assertOk()
            ->assertSee('data-proveedor-orden', false)
            ->assertSee('precio=costo', false)
            ->assertSee('Costo unitario sin IVA');

        $orden = OrdenCompra::factory()->for($this->proveedor)->conLinea()->enEstado(EstadoOrdenCompra::Enviada)->create();

        $this->actingAs($this->user)->get("/ordenes-compra/{$orden->id}/editar")
            ->assertOk()
            ->assertSee('Al guardar regresa a borrador');
    });

    it('en una orden pagada muestra el pago y sus acciones', function () {
        $cuenta = Cuenta::factory()->for($this->user)->create(['nombre' => 'Caja chica']);
        $orden = OrdenCompra::factory()->for($this->proveedor)->conLinea()->enEstado(EstadoOrdenCompra::Pagada)->create();
        $orden->forceFill(['cuenta_id' => $cuenta->id, 'fecha_pago' => '2026-10-01'])->save();

        $this->actingAs($this->user)->get("/ordenes-compra/{$orden->id}")
            ->assertOk()
            ->assertSee('Caja chica')
            ->assertSee('Cancelar pago')
            ->assertSee('Marcar como recibida')
            ->assertDontSee('Registrar pago');
    });

    it('genera el PDF, también con el proveedor eliminado', function () {
        $orden = OrdenCompra::factory()->for($this->proveedor)->conLinea()->enEstado(EstadoOrdenCompra::Recibida)->create();
        $this->proveedor->delete();

        $this->actingAs($this->user)->get("/ordenes-compra/{$orden->id}/pdf?descargar=1")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertDownload('orden-compra-OC-0001.pdf');

        $this->actingAs($this->user)->get("/ordenes-compra/{$orden->id}")->assertOk()->assertSee($this->proveedor->nombre_comercial);
    });

    it('lo ajeno responde 404', function () {
        $ajena = OrdenCompra::factory()->conLinea()->create();

        foreach (["/ordenes-compra/{$ajena->id}", "/ordenes-compra/{$ajena->id}/editar", "/ordenes-compra/{$ajena->id}/pdf"] as $url) {
            $this->actingAs($this->user)->get($url)->assertNotFound();
        }

        $this->actingAs($this->user)->delete("/ordenes-compra/{$ajena->id}")->assertNotFound();
        $this->actingAs($this->user)->post("/ordenes-compra/{$ajena->id}/duplicar")->assertNotFound();
        $this->actingAs($this->user)->post("/ordenes-compra/{$ajena->id}/recibir")->assertNotFound();
    });
});

describe('sugerencias de artículos', function () {
    it('filtra por proveedor y devuelve el costo', function () {
        $otro = articuloDelProveedor(Proveedor::factory()->for($this->user)->create(), ['nombre' => 'Sello otro proveedor']);
        $this->articulo->update(['nombre' => 'Sello del proveedor']);

        $respuesta = $this->actingAs($this->user)->getJson('/articulos/sugerencias?q=Sello&proveedor_id='.$this->proveedor->id.'&precio=costo')->assertOk();

        expect($respuesta->json())->toHaveCount(1)
            ->and($respuesta->json('0.id'))->toBe($this->articulo->id)
            ->and($respuesta->json('0.precio_unitario'))->toBe('180.00');

        $sinParametros = $this->actingAs($this->user)->getJson('/articulos/sugerencias?q=Sello')->json();

        expect(collect($sinParametros)->pluck('id')->sort()->values()->all())->toBe(collect([$this->articulo->id, $otro->id])->sort()->values()->all())
            ->and(collect($sinParametros)->firstWhere('id', $this->articulo->id)['precio_unitario'])->toBe('225.00');
    });
});
