<?php

use App\Models\FormatoEtiqueta;
use App\Models\OrdenTrabajo;
use App\Models\Pedido;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();

    // Medidas de un formulario de formato: 66.7 × 25.4, 3 × 10 = 30 por hoja.
    $this->datos = fn (array $cambios = []) => [
        'nombre' => 'Avery 5160',
        'ancho' => '66.7',
        'alto' => '25.4',
        'separacion_horizontal' => '3.2',
        'separacion_vertical' => '0',
        'margen_superior' => '12.7',
        'margen_izquierdo' => '4.8',
        ...$cambios,
    ];

    $this->formato = function (array $atributos = [], ?User $dueno = null): FormatoEtiqueta {
        $formato = new FormatoEtiqueta([
            'nombre' => 'Formato',
            'ancho_mm' => '50.0',
            'alto_mm' => '25.0',
            'separacion_horizontal_mm' => '5.0',
            'separacion_vertical_mm' => '5.0',
            'margen_superior_mm' => '10.0',
            'margen_izquierdo_mm' => '10.0',
            ...$atributos,
        ]);
        $formato->user_id = ($dueno ?? $this->user)->id;
        $formato->es_predeterminado = $atributos['es_predeterminado'] ?? false;
        $formato->save();

        return $formato;
    };

    $this->ventas = function (int $cuantas) {
        foreach (range(1, $cuantas) as $i) {
            $pedido = Pedido::factory()->for($this->user)->conLinea()->create();
            (new OrdenTrabajo)->forceFill(['user_id' => $this->user->id, 'pedido_id' => $pedido->id, 'estado' => 'en_proceso'])->save();
        }
    };

    $this->pagina = fn (string $consulta = '') => $this->actingAs($this->user)->get('/pedidos/produccion/etiquetas'.$consulta)->assertOk();
});

it('pide sesión en las rutas de formatos', function (string $metodo, string $url) {
    $formato = ($this->formato)();

    $this->call($metodo, str_replace('{id}', (string) $formato->id, $url))->assertRedirect('/login');
})->with([
    ['POST', '/pedidos/produccion/etiquetas'],
    ['POST', '/formatos-etiqueta'],
    ['POST', '/formatos-etiqueta/{id}'],
    ['POST', '/formatos-etiqueta/{id}/duplicar'],
    ['DELETE', '/formatos-etiqueta/{id}'],
]);

it('sin formatos usa las medidas de fábrica', function () {
    ($this->ventas)(1);

    ($this->pagina)()
        ->assertSee('3 × 8 = 24 por hoja')
        ->assertSee('--margen-sup: 19.7mm; --margen-izq: 17.9mm', false)
        ->assertSee('<option value="fabrica" selected>Medidas de fábrica</option>', false);
});

it('guarda un formato nuevo y vuelve con él elegido', function () {
    $this->actingAs($this->user)->post('/formatos-etiqueta', ($this->datos)(['es_predeterminado' => '1']))
        ->assertRedirect(route('pedidos.produccion.etiquetas', ['formato' => FormatoEtiqueta::first()->id]))
        ->assertSessionHas('exito', 'Formato guardado.');

    $formato = FormatoEtiqueta::first();

    expect($formato->user_id)->toBe($this->user->id)
        ->and($formato->nombre)->toBe('Avery 5160')
        ->and($formato->ancho_mm)->toBe('66.7')
        ->and($formato->separacion_vertical_mm)->toBe('0.0')
        ->and($formato->es_predeterminado)->toBeTrue();

    ($this->pagina)("?formato={$formato->id}")->assertSee('3 × 10 = 30 por hoja');
});

it('valida el nombre y las medidas del formato', function (array $cambios, string $campo) {
    ($this->formato)(['nombre' => 'Repetido']);

    $this->actingAs($this->user)->from('/pedidos/produccion/etiquetas')
        ->post('/formatos-etiqueta', ($this->datos)($cambios))
        ->assertRedirect('/pedidos/produccion/etiquetas')
        ->assertSessionHasErrors($campo);

    expect(FormatoEtiqueta::count())->toBe(1);
})->with([
    'nombre vacío' => [['nombre' => '  '], 'nombre'],
    'nombre repetido' => [['nombre' => 'Repetido'], 'nombre'],
    'nombre largo' => [['nombre' => str_repeat('a', 61)], 'nombre'],
    'ancho chico' => [['ancho' => '9.9'], 'ancho'],
    'alto mayor que la hoja' => [['alto' => '279.5'], 'alto'],
    'dos decimales' => [['margen_superior' => '12.75'], 'margen_superior'],
    'separación negativa' => [['separacion_horizontal' => '-1'], 'separacion_horizontal'],
]);

it('el mismo nombre en otro usuario sí se permite', function () {
    ($this->formato)(['nombre' => 'Avery 5160'], User::factory()->create());

    $this->actingAs($this->user)->post('/formatos-etiqueta', ($this->datos)())->assertSessionHasNoErrors();
});

it('guardar actualiza nombre y medidas; marcar predeterminado desmarca el anterior', function () {
    $anterior = ($this->formato)(['nombre' => 'Anterior', 'es_predeterminado' => true]);
    $formato = ($this->formato)(['nombre' => 'Otro']);

    $this->actingAs($this->user)->post("/formatos-etiqueta/{$formato->id}", ($this->datos)(['nombre' => 'Otro', 'es_predeterminado' => '1']))
        ->assertRedirect(route('pedidos.produccion.etiquetas', ['formato' => $formato->id]));

    expect($formato->fresh()->ancho_mm)->toBe('66.7')
        ->and($formato->fresh()->es_predeterminado)->toBeTrue()
        ->and($anterior->fresh()->es_predeterminado)->toBeFalse();

    // Sin la casilla deja de ser predeterminado.
    $this->actingAs($this->user)->post("/formatos-etiqueta/{$formato->id}", ($this->datos)(['nombre' => 'Otro']));

    expect($formato->fresh()->es_predeterminado)->toBeFalse();
});

it('al abrir usa el predeterminado; con formato, ese; con fabrica, ninguno', function () {
    $predeterminado = ($this->formato)(['nombre' => 'Pred', 'es_predeterminado' => true]);
    $otro = ($this->formato)(['nombre' => 'Otro', 'ancho_mm' => '100.0']);

    ($this->pagina)()->assertSee('--ancho: 50.0mm', false)->assertSee("<option value=\"{$predeterminado->id}\" selected>Pred ★</option>", false);
    ($this->pagina)("?formato={$otro->id}")->assertSee('--ancho: 100.0mm', false);
    ($this->pagina)('?formato=fabrica')->assertSee('--ancho: 60.0mm', false);
});

it('las medidas de la dirección mandan sobre el formato y las inválidas se ignoran', function () {
    $formato = ($this->formato)();

    ($this->pagina)("?formato={$formato->id}&ancho=70&alto=abc")
        ->assertSee('--ancho: 70.0mm; --alto: 25.0mm', false);
});

it('duplicar crea copias con nombre libre y no predeterminadas', function () {
    $formato = ($this->formato)(['nombre' => 'Base', 'es_predeterminado' => true]);

    $this->actingAs($this->user)->post("/formatos-etiqueta/{$formato->id}/duplicar")->assertSessionHas('exito', 'Formato duplicado.');
    $this->actingAs($this->user)->post("/formatos-etiqueta/{$formato->id}/duplicar");

    $copias = FormatoEtiqueta::whereKeyNot($formato->id)->orderBy('id')->get();

    expect($copias->pluck('nombre')->all())->toBe(['Base (copia)', 'Base (copia 2)'])
        ->and($copias->pluck('es_predeterminado')->unique()->all())->toBe([false])
        ->and($copias->first()->ancho_mm)->toBe('50.0');
});

it('eliminar el predeterminado deja las medidas de fábrica', function () {
    $formato = ($this->formato)(['es_predeterminado' => true]);

    $this->actingAs($this->user)->delete("/formatos-etiqueta/{$formato->id}")
        ->assertRedirect(route('pedidos.produccion.etiquetas'))
        ->assertSessionHas('exito', 'Formato eliminado.');

    expect(FormatoEtiqueta::count())->toBe(0);
    ($this->pagina)()->assertSee('--ancho: 60.0mm', false);
});

it('un formato ajeno responde 404 y se ignora en la página', function () {
    $ajeno = ($this->formato)(['nombre' => 'Ajeno', 'ancho_mm' => '100.0'], User::factory()->create());

    $this->actingAs($this->user)->post("/formatos-etiqueta/{$ajeno->id}", ($this->datos)())->assertNotFound();
    $this->actingAs($this->user)->post("/formatos-etiqueta/{$ajeno->id}/duplicar")->assertNotFound();
    $this->actingAs($this->user)->delete("/formatos-etiqueta/{$ajeno->id}")->assertNotFound();

    expect(FormatoEtiqueta::count())->toBe(1);
    ($this->pagina)("?formato={$ajeno->id}")->assertSee('--ancho: 60.0mm', false)->assertDontSee('Ajeno');
});

it('si no cabe ninguna etiqueta lo avisa y no pinta la planilla', function () {
    ($this->ventas)(1);

    $respuesta = ($this->pagina)('?ancho=200&margen_izquierdo=20')
        ->assertSee('0 × 8 = 0 por hoja');

    expect($respuesta->getContent())->toContain('Con estas medidas no cabe ninguna etiqueta')
        ->not->toContain('class="planilla-hoja"')
        ->not->toMatch('/id="planilla-aviso"[^>]*hidden/');
});

it('reparte en hojas según el formato y limita inicio a lo que cabe', function () {
    ($this->ventas)(31);

    // 3 × 10 = 30 por hoja.
    $consulta = '?ancho=66.7&alto=25.4&separacion_horizontal=3.2&margen_superior=12.7&margen_izquierdo=4.8';

    expect(substr_count(($this->pagina)($consulta)->getContent(), 'class="planilla-hoja"'))->toBe(2);

    $respuesta = ($this->pagina)($consulta.'&inicio=30');
    expect(substr_count($respuesta->getContent(), 'class="planilla-vacia"'))->toBe(29);

    $respuesta = ($this->pagina)($consulta.'&inicio=31');
    expect(substr_count($respuesta->getContent(), 'class="planilla-vacia"'))->toBe(0);
});

it('la hoja de prueba numera las casillas sin datos, aun sin órdenes, y se imprime sola', function () {
    $respuesta = ($this->pagina)('?prueba=1')
        ->assertSee('Hoja de prueba')
        ->assertSee('js/imprimir-al-cargar.js');

    expect(substr_count($respuesta->getContent(), 'class="planilla-prueba"'))->toBe(24)
        ->and($respuesta->getContent())->toContain('<div class="planilla-prueba">24</div>')
        ->not->toContain('class="planilla-etiqueta"');
});

it('aplicar valida y regresa al GET con las medidas, centradas si se pide', function () {
    $formato = ($this->formato)();

    $this->actingAs($this->user)->post('/pedidos/produccion/etiquetas', [
        ...($this->datos)(),
        'formato' => (string) $formato->id,
        'inicio' => '3',
        'centrar' => '1',
    ])->assertRedirect(route('pedidos.produccion.etiquetas', [
        'formato' => $formato->id,
        'ancho' => '66.7',
        'alto' => '25.4',
        'separacion_horizontal' => '3.2',
        'separacion_vertical' => '0.0',
        'margen_superior' => '12.7',
        'margen_izquierdo' => '4.7',
        'inicio' => '3',
    ]));

    $this->actingAs($this->user)->post('/pedidos/produccion/etiquetas', [...($this->datos)(), 'prueba' => '1'])
        ->assertRedirectContains('prueba=1');

    $this->actingAs($this->user)->from('/pedidos/produccion/etiquetas')
        ->post('/pedidos/produccion/etiquetas', ($this->datos)(['ancho' => '']))
        ->assertSessionHasErrors('ancho');
});
