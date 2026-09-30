{{-- Aviso de artículo duplicado (documento-lineas.js). $documento: "la cotización", "la factura"… --}}
<dialog id="aviso-duplicado" class="ficha dialogo" aria-labelledby="aviso-duplicado-titulo">
    <form method="dialog">
        <h2 id="aviso-duplicado-titulo">Este artículo ya está en {{ $documento }}</h2>
        <p>Línea <strong data-duplicado-numero></strong>: <span data-duplicado-descripcion></span> · Modelo <span data-duplicado-modelo></span> · Cantidad actual <strong data-duplicado-cantidad></strong></p>
        <x-campo nombre="cantidad_a_sumar" etiqueta="Cantidad a sumar" tipo="number" valor="1" min="1" step="1" inputmode="numeric" data-duplicado-sumar />

        <div class="acciones">
            <x-boton value="sumar" icono="plus-lg">Sumar a la línea existente</x-boton>
            <x-boton value="cancelar" variante="secundario" icono="x-lg">Cancelar</x-boton>
        </div>
    </form>
</dialog>
