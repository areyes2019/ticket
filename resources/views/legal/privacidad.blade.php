@extends('layouts.publico')

@section('title', 'Aviso de privacidad · '.($emisor->nombre ?: config('negocio.nombre')))
{{-- Meta revisa esta página para publicar la app de WhatsApp (041): debe poder leerla cualquiera. --}}
@section('robots', 'index, follow')

@section('content')
    @php
        $negocio = $emisor->nombre ?: config('negocio.nombre');
        $contacto = collect([$emisor->correo, $emisor->telefono])->filter()->implode(' · ');
        $responsable = $negocio.($emisor->rfc ? ' (RFC '.$emisor->rfc.')' : '').($emisor->domicilio ? ', con domicilio en '.$emisor->domicilio : '');
        $medio = $contacto !== '' ? ' a '.$contacto : ' por el mismo WhatsApp';
    @endphp

    <x-card titulo="Aviso de privacidad" nivel="1">
        <p><strong>{{ $responsable }}</strong> es responsable del tratamiento de los datos personales que nos proporcionas, conforme a la Ley Federal de Protección de Datos Personales en Posesión de los Particulares.</p>

        <h2>Qué datos usamos</h2>
        <ul>
            <li>Datos de contacto: nombre, número de teléfono y correo electrónico.</li>
            <li>Lo que nos escribes o envías por WhatsApp: mensajes, imágenes y documentos (por ejemplo, el texto o el logo de un sello, o un comprobante de pago).</li>
            <li>Datos fiscales, cuando pides una factura: RFC, nombre o razón social, régimen fiscal, código postal y uso del CFDI, incluidos los que aparecen en tu constancia de situación fiscal si nos la envías.</li>
            <li>Datos de tus compras: productos, cotizaciones, pagos y facturas.</li>
        </ul>
        <p>No pedimos datos personales sensibles.</p>

        <h2>Para qué los usamos</h2>
        <ul>
            <li>Atender tus preguntas y darte precios y cotizaciones.</li>
            <li>Elaborar, cobrar y entregar tu pedido.</li>
            <li>Emitir y enviarte tus facturas (CFDI), como lo exige la ley fiscal.</li>
            <li>Darte seguimiento cuando lo pidas o cuando sea necesario para tu pedido.</li>
        </ul>

        <h2>Atención por WhatsApp con asistentes automáticos</h2>
        <p>Nuestro número de WhatsApp puede responderte con un asistente automático de inteligencia artificial, que consulta nuestro catálogo, prepara cotizaciones y emite facturas. En cualquier momento puedes escribir <strong>"asesor"</strong> para que te atienda una persona de nuestro equipo, y una persona revisa las conversaciones cuando hace falta.</p>

        <h2>Con quién compartimos tus datos</h2>
        <p>No vendemos tus datos. Solo los compartimos con proveedores que nos ayudan a prestar el servicio, y únicamente para ese fin:</p>
        <ul>
            <li><strong>Meta Platforms</strong> (WhatsApp Business), para enviar y recibir los mensajes.</li>
            <li><strong>Anthropic</strong>, que procesa el contenido de la conversación para que el asistente automático pueda responderte.</li>
            <li>Nuestro proveedor autorizado de certificación (PAC) y el <strong>SAT</strong>, para timbrar tus facturas.</li>
            <li>Nuestro proveedor de alojamiento, donde se guarda el sistema.</li>
        </ul>

        <h2>Tus derechos</h2>
        <p>Puedes pedir acceso a tus datos, corregirlos, cancelarlos u oponerte a su uso (derechos ARCO), así como revocar tu consentimiento o pedir que borremos tu conversación de WhatsApp, escribiéndonos{{ $medio }}. Te responderemos en un plazo máximo de 20 días hábiles. Algunos datos, como los de tus facturas, debemos conservarlos el tiempo que marca la ley fiscal.</p>

        <h2>Cambios a este aviso</h2>
        <p>Si cambiamos este aviso, publicaremos la nueva versión en esta misma página.</p>

        <p class="ayuda">Última actualización: 23 de noviembre de 2026.</p>
    </x-card>
@endsection
