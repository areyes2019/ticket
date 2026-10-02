<?php

use App\Models\Cuenta;
use App\Models\User;

it('muestra activas e inactivas con el total global, solo del usuario', function () {
    $user = User::factory()->create();
    $caja = Cuenta::factory()->for($user)->conSaldo('1500.25')->create(['nombre' => 'Caja']);
    Cuenta::factory()->for($user)->conSaldo('300.00')->inactiva()->create(['nombre' => 'Banamex']);
    Cuenta::factory()->conSaldo('9999.00')->create(['nombre' => 'Ajena']);

    $this->actingAs($user)->get('/tesoreria/saldos')
        ->assertOk()
        ->assertSeeInOrder(['Banamex', 'Inactiva', '$300.00', 'Caja', 'Activa', '$1,500.25'])
        ->assertSee('<td class="numero" data-total-global>$1,800.25</td>', false)
        ->assertSee(route('tesoreria.movimientos.index', ['cuenta_id' => $caja->id]), false)
        ->assertDontSee('Ajena');
});

it('sin cuentas invita a crear una', function () {
    $this->actingAs(User::factory()->create())->get('/tesoreria/saldos')
        ->assertSee('Todavía no tienes cuentas')
        ->assertDontSee('Total global');
});
