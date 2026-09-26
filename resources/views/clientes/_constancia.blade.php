{{--
    Carga de la Constancia de Situación Fiscal (specs/006). Queda fuera del
    formulario del cliente para que el archivo nunca se envíe al guardar, y
    oculta hasta que carga constancia-fiscal.js: sin JavaScript no aparece.
--}}
<div hidden data-constancia="{{ route('clientes.constancia') }}" @isset($cliente) data-cliente-id="{{ $cliente->id }}" @endisset>
    <x-card titulo="Constancia de Situación Fiscal">
        <div class="constancia-zona" data-constancia-zona>
            <p>
                <x-icono nombre="file-earmark-arrow-up" />
                Arrastra aquí la Constancia de Situación Fiscal, o haz clic para elegir el archivo.
            </p>
            <x-campo nombre="constancia" etiqueta="Archivo de la constancia" tipo="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" ayuda="PDF, JPG o PNG · máximo 10 MB" data-constancia-archivo />
        </div>

        <p class="constancia-estado" aria-live="polite" data-constancia-estado></p>

        <x-alerta tipo="error" hidden data-constancia-error>
            <p data-constancia-error-texto></p>
        </x-alerta>

        <x-alerta tipo="advertencia" hidden data-constancia-existente>
            <p>Ya tienes registrado a <strong data-constancia-existente-nombre></strong> con este RFC.</p>
            <div class="acciones">
                <x-boton href="#" variante="secundario" icono="box-arrow-up-right" data-constancia-abrir>Abrir su ficha</x-boton>
                <x-boton tipo="button" variante="secundario" icono="arrow-down-square" data-constancia-precargar>Precargar de todos modos</x-boton>
            </div>
        </x-alerta>

        <x-alerta tipo="advertencia" hidden data-constancia-aviso>
            <p data-constancia-aviso-texto></p>
            <ul data-constancia-advertencias></ul>
        </x-alerta>
    </x-card>
</div>
