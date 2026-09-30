{{-- Formulario de ejemplo: method="dialog" solo cierra la ventana, no envía nada. --}}
<dialog id="bandeja-redactar" class="ficha dialogo" aria-labelledby="bandeja-redactar-titulo">
    <h2 id="bandeja-redactar-titulo"><x-icono nombre="pencil-square" />Nuevo mensaje</h2>

    <form method="dialog">
        <x-campo nombre="para" etiqueta="Para" tipo="email" id="redactar-para" />
        <x-campo nombre="asunto" etiqueta="Asunto" id="redactar-asunto" />

        <div class="campo">
            <label for="redactar-mensaje">Mensaje</label>
            <textarea id="redactar-mensaje" name="mensaje" rows="8"></textarea>
        </div>

        <div class="acciones">
            <x-boton icono="send" value="enviar" formnovalidate>Enviar</x-boton>
            <x-boton variante="secundario" icono="x-lg" value="cancelar" formnovalidate>Cancelar</x-boton>
        </div>
    </form>
</dialog>
