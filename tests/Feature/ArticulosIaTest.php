<?php

use App\Models\Articulo;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    // articuloFacturable(): venta $135 sin IVA.
    $this->articulo = articuloFacturable($this->user, ['modelo' => 'S-100', 'nombre' => 'Sello redondo']);
});

describe('acceso', function () {
    it('pide iniciar sesión', function (string $ruta) {
        $this->get($ruta)->assertRedirect(route('login'));
    })->with(['/articulos-ia', '/articulos-ia/agregar']);

    it('muestra Artículos IA en el grupo Inventario del menú', function () {
        $this->actingAs($this->user)->get('/dashboard')
            ->assertSee('Artículos IA')
            ->assertSee(route('articulos-ia.index'));
    });

    it('no agrega ni quita artículos de otra cuenta', function (string $metodo, bool $marcado) {
        $ajeno = articuloFacturable(User::factory()->create(), ['para_ia' => $marcado]);

        $this->actingAs($this->user)->call($metodo, "/articulos-ia/{$ajeno->id}", ['_token' => csrf_token()])->assertNotFound();

        expect($ajeno->fresh()->para_ia)->toBe($marcado);
    })->with([
        'agregar' => ['POST', false],
        'quitar' => ['DELETE', true],
    ]);
});

describe('agregar y quitar', function () {
    it('agrega y quita un artículo con sus mensajes', function () {
        $this->actingAs($this->user)->post(route('articulos-ia.store', $this->articulo))
            ->assertRedirect(route('articulos-ia.index'))
            ->assertSessionHas('exito', 'S-100 se agregó a Artículos IA.');

        expect($this->articulo->fresh()->para_ia)->toBeTrue();

        $this->actingAs($this->user)->delete(route('articulos-ia.destroy', $this->articulo))
            ->assertRedirect(route('articulos-ia.index'))
            ->assertSessionHas('exito', 'S-100 se quitó de Artículos IA.');

        expect($this->articulo->fresh()->para_ia)->toBeFalse();
    });

    it('repetir agregar o quitar no da error', function () {
        $this->actingAs($this->user);

        $this->post(route('articulos-ia.store', $this->articulo));
        $this->post(route('articulos-ia.store', $this->articulo))->assertSessionHas('exito');
        expect($this->articulo->fresh()->para_ia)->toBeTrue();

        $this->delete(route('articulos-ia.destroy', $this->articulo));
        $this->delete(route('articulos-ia.destroy', $this->articulo))->assertSessionHas('exito');
        expect($this->articulo->fresh()->para_ia)->toBeFalse();
    });

    it('no recalcula ni cambia el precio al marcar', function () {
        $antes = $this->articulo->fresh()->only(['precio_unitario_sin_iva', 'costo_con_descuento', 'updated_at']);

        $this->actingAs($this->user)->post(route('articulos-ia.store', $this->articulo));

        expect($this->articulo->fresh()->only(['precio_unitario_sin_iva', 'costo_con_descuento', 'updated_at']))->toEqual($antes);
    });

    it('el buscador de Agregar solo muestra artículos propios sin marcar', function () {
        articuloFacturable($this->user, ['modelo' => 'S-200', 'nombre' => 'Sello marcado', 'para_ia' => true]);
        articuloFacturable(User::factory()->create(), ['modelo' => 'S-300', 'nombre' => 'Sello ajeno']);

        $this->actingAs($this->user)->get('/articulos-ia/agregar?q=sello')
            ->assertOk()
            ->assertSee('S-100')
            ->assertDontSee('S-200')
            ->assertDontSee('S-300');
    });

    it('al clonar, la copia sale sin marca', function () {
        $this->articulo->forceFill(['para_ia' => true])->save();

        $this->actingAs($this->user)->post(route('articulos.clonar', $this->articulo));

        expect(Articulo::where('id', '!=', $this->articulo->id)->sole()->para_ia)->toBeFalse()
            ->and($this->articulo->fresh()->para_ia)->toBeTrue();
    });
});

describe('listado', function () {
    it('muestra solo los marcados de la cuenta con precio y disponibilidad', function () {
        $conPiezas = articuloFacturable($this->user, ['modelo' => 'A-1', 'para_ia' => true]);
        $sinPiezas = articuloFacturable($this->user, ['modelo' => 'B-1', 'para_ia' => true]);
        marcarExistencia($conPiezas, 4);
        articuloFacturable(User::factory()->create(), ['modelo' => 'Z-9', 'para_ia' => true]);

        $respuesta = $this->actingAs($this->user)->get('/articulos-ia')->assertOk();

        $respuesta->assertSeeInOrder(['A-1', '$'.number_format($conPiezas->precio_unitario_con_iva, 2), 'En existencia', 'B-1', 'Sobre pedido'])
            ->assertDontSee('S-100')
            ->assertDontSee('Z-9');
    });

    it('avisa cuando la lista está vacía', function () {
        $this->actingAs($this->user)->get('/articulos-ia')
            ->assertSee('Mientras la lista esté vacía, la IA busca en todo el catálogo.');
    });

    it('filtra por nombre o modelo en la búsqueda dinámica', function () {
        articuloFacturable($this->user, ['modelo' => 'A-1', 'nombre' => 'Fechador', 'para_ia' => true]);
        articuloFacturable($this->user, ['modelo' => 'B-1', 'nombre' => 'Foliador', 'para_ia' => true]);

        $this->actingAs($this->user)->get('/articulos-ia/buscar?q=fecha')
            ->assertOk()
            ->assertSee('A-1')
            ->assertDontSee('B-1');
    });
});
