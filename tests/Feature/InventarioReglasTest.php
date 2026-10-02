<?php

use App\Enums\MotivoMovimientoInventario;
use App\Enums\TipoMovimientoInventario;
use App\Models\Existencia;
use App\Models\MovimientoInventario;
use App\Models\OrdenCompra;
use App\Models\User;
use App\Services\Inventario\RegistradorInventario;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->articulo = articuloFacturable($this->user);
    $this->inventario = app(RegistradorInventario::class);
    $this->orden = OrdenCompra::factory()->for($this->articulo->proveedor)->create();
});

describe('las tres reglas', function () {
    it('una entrada salda primero el faltante y solo el resto sube la existencia', function (int $faltante, int $entran, array $esperado) {
        expect(RegistradorInventario::entrar(0, $faltante, $entran))->toBe($esperado);
    })->with([
        'cubre el faltante' => [3, 10, [7, 0]],
        'no alcanza' => [3, 2, [0, 1]],
        'sin faltante' => [0, 4, [4, 0]],
    ]);

    it('una salida mayor a lo disponible deja 0 y acumula el resto como faltante', function () {
        expect(RegistradorInventario::salir(2, 0, 5))->toBe([0, 3])
            ->and(RegistradorInventario::salir(2, 3, 1))->toBe([1, 3])
            ->and(RegistradorInventario::salir(12, 0, 5))->toBe([7, 0]);
    });

    it('un ajuste fija la cantidad final y borra el faltante', function () {
        expect(RegistradorInventario::fijar(10))->toBe([10, 0]);
    });
});

describe('servicio', function () {
    it('el ajuste da de alta el artículo y registra el movimiento con sus resultantes', function () {
        $movimiento = $this->inventario->ajustar($this->articulo, 8, MotivoMovimientoInventario::EntradaInicial, 'Conteo de apertura');

        expect(Existencia::sole())->existencia->toBe(8)->faltante_pendiente->toBe(0)
            ->and($movimiento->only(['user_id', 'articulo_id', 'cantidad', 'existencia_resultante', 'faltante_resultante', 'nota']))
            ->toBe(['user_id' => $this->user->id, 'articulo_id' => $this->articulo->id, 'cantidad' => 8, 'existencia_resultante' => 8, 'faltante_resultante' => 0, 'nota' => 'Conteo de apertura'])
            ->and($movimiento->tipo)->toBe(TipoMovimientoInventario::Ajuste)
            ->and($movimiento->documentable_type)->toBeNull();
    });

    it('el ajuste sobre un faltante lo pone en cero', function () {
        marcarExistencia($this->articulo, 0, faltante: 4);

        $this->inventario->ajustar($this->articulo, 6, MotivoMovimientoInventario::ConteoFisico, null);

        expect(Existencia::sole())->existencia->toBe(6)->faltante_pendiente->toBe(0);
    });

    it('dos líneas del mismo artículo suman su total y dejan un solo movimiento', function () {
        agregarLinea($this->orden, $this->articulo, 3);
        agregarLinea($this->orden, $this->articulo, 4);

        $this->inventario->entradaPorDocumento($this->orden, $this->orden->lineas, MotivoMovimientoInventario::RecepcionOrden);

        expect(Existencia::sole()->existencia)->toBe(7)
            ->and(MovimientoInventario::sole())->cantidad->toBe(7)->existencia_resultante->toBe(7)
            ->and(MovimientoInventario::sole()->documentable->is($this->orden))->toBeTrue();
    });

    it('ignora las líneas libres y los artículos borrados', function () {
        $borrado = articuloFacturable($this->user);
        $borrado->delete();
        agregarLinea($this->orden, null, 5);
        agregarLinea($this->orden, $borrado, 2);

        $this->inventario->entradaPorDocumento($this->orden, $this->orden->lineas, MotivoMovimientoInventario::RecepcionOrden);

        expect(Existencia::count())->toBe(0)
            ->and(MovimientoInventario::count())->toBe(0);
    });

    it('una salida sin crear fila no mueve un artículo que no está en existencias', function () {
        agregarLinea($this->orden, $this->articulo, 2);

        $this->inventario->salidaPorDocumento($this->orden, $this->orden->lineas, MotivoMovimientoInventario::VentaFactura, creaFila: false);

        expect(Existencia::count())->toBe(0)
            ->and(MovimientoInventario::count())->toBe(0);
    });

    it('quitar borra la fila lógicamente y volver a marcar restaura la misma con sus mínimos', function () {
        $fila = marcarExistencia($this->articulo, 5, minimo: 3, maximo: 10);

        $this->inventario->quitar($this->articulo);
        expect(Existencia::count())->toBe(0);

        $this->inventario->ajustar($this->articulo, 2, MotivoMovimientoInventario::ConteoFisico, null);

        expect(Existencia::sole())
            ->id->toBe($fila->id)
            ->existencia->toBe(2)
            ->minimo->toBe(3)
            ->maximo->toBe(10);
    });
});

describe('por pedir', function () {
    it('está por pedir estrictamente bajo el mínimo o con faltante', function (int $existencia, int $faltante, int $minimo, bool $porPedir) {
        $fila = marcarExistencia($this->articulo, $existencia, $faltante, $minimo);

        expect($fila->porPedir())->toBe($porPedir)
            ->and(Existencia::query()->soloPorPedir()->exists())->toBe($porPedir);
    })->with([
        'bajo el mínimo' => [2, 0, 5, true],
        'en el mínimo exacto' => [5, 0, 5, false],
        'sobre el mínimo' => [6, 0, 5, false],
        'mínimo 0 no avisa' => [0, 0, 0, false],
        'con faltante' => [0, 1, 0, true],
    ]);

    it('sugiere rellenar hasta el techo y cubrir el faltante', function (int $existencia, int $faltante, int $minimo, ?int $maximo, int $sugerida) {
        expect(marcarExistencia($this->articulo, $existencia, $faltante, $minimo, $maximo)->cantidadSugerida())->toBe($sugerida);
    })->with([
        'hasta el máximo' => [3, 0, 5, 20, 17],
        'máximo y faltante' => [3, 4, 5, 20, 21],
        'sin máximo, hasta el mínimo' => [2, 0, 5, null, 3],
        'solo faltante' => [0, 2, 0, null, 2],
    ]);
});
