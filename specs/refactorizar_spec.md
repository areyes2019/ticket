Actúa como arquitecto senior del proyecto y revisa el siguiente SPEC (004) para determinar si está completamente alineado con la arquitectura actual del sistema.

## CONTEXTO

Este proyecto está migrando de una arquitectura:

* Vue 3 como frontend independiente
* Laravel como backend/API
* Comunicación constante entre Vue y Laravel mediante API

a una arquitectura más simple y centralizada:

* Laravel es el Backend y también el Frontend principal.
* Las vistas principales se renderizan desde Laravel.
* Blade será la capa principal de interfaz.
* JavaScript se utilizará únicamente donde sea necesario para interactividad.
* Axios/AJAX se utilizará únicamente en módulos que realmente necesiten comunicación dinámica con el servidor.
* No se debe crear una SPA independiente con Vue para los módulos normales del sistema.
* La lógica de negocio debe permanecer en Laravel.
* La base de datos debe ser accedida exclusivamente desde Laravel.
* Se busca reducir al mínimo la complejidad, los problemas de sincronización entre frontend/backend, pantallas en blanco, builds innecesarios y problemas derivados de una SPA independiente.
* Electron puede existir como cliente de escritorio, pero NO debe provocar cambios innecesarios en la arquitectura web ni convertirse en una segunda arquitectura paralela.

La regla principal es:

> Laravel debe resolver directamente la mayor parte del sistema. JavaScript solo debe utilizarse cuando aporte una interacción que realmente lo justifique.

## TU TAREA

Analiza el SPEC completo que te proporcionaré y realiza una auditoría arquitectónica.

### 1. Detecta incompatibilidades

Identifica todo lo que no esté alineado con la arquitectura actual.

Presta especial atención a:

* Vue
* SPA
* componentes Vue
* rutas del frontend
* APIs innecesarias
* endpoints creados únicamente para alimentar vistas
* Axios innecesario
* stores
* estado global del frontend
* autenticación separada entre frontend y backend
* comunicación frontend/backend innecesaria
* procesos que puedan resolverse directamente con Laravel + Blade
* JavaScript que pueda simplificarse
* estructuras heredadas de la arquitectura anterior
* dependencias o paquetes que ya no tengan sentido
* procesos duplicados entre frontend y backend
* cualquier decisión que vuelva a introducir la complejidad que estamos tratando de eliminar.

No asumas que algo debe conservarse solamente porque aparece en el SPEC actual.

### 2. Propón la corrección

Para cada problema encontrado, indica:

* Qué dice actualmente el SPEC.
* Por qué entra en conflicto con la arquitectura actual.
* Cómo debería plantearse con la nueva arquitectura.
* Qué tecnología debería utilizarse en su lugar.

No modifiques todavía el SPEC.

### 3. Expón tus asunciones

Indica claramente cualquier decisión que estés asumiendo porque el SPEC no proporciona suficiente información.

Formato:

**Asunción 1**

* Supuesto:
* Motivo:
* Impacto:

No inventes requisitos de negocio.

### 4. Propón adiciones técnicas

Identifica aspectos técnicos importantes que deberían agregarse al SPEC y que actualmente no están contemplados.

Pueden incluir, por ejemplo:

* estructura Laravel
* Controllers
* Form Requests
* Services
* Policies/Gates
* validaciones
* rutas web
* Blade
* JavaScript modular
* Axios/AJAX únicamente cuando sea necesario
* manejo de errores
* autorización
* seguridad
* transacciones
* auditoría
* manejo de estados
* rendimiento
* caché
* logs
* pruebas
* migraciones
* índices de base de datos

Pero solamente propón aquello que realmente tenga sentido para este SPEC.

### 5. No hagas cambios

ESTO ES MUY IMPORTANTE:

NO MODIFIQUES EL ARCHIVO.

NO sobrescribas el SPEC.

NO cambies código.

NO cambies nombres.

NO elimines contenido.

NO agregues contenido directamente al documento.

Primero quiero revisar tus propuestas.

Tu respuesta debe ser únicamente una auditoría y propuesta de cambios.

## FORMATO DE RESPUESTA

Utiliza esta estructura:

# Auditoría arquitectónica

## Estado general

Indica si el SPEC está:

* Alineado
* Parcialmente alineado
* No alineado

Explica brevemente por qué.

## 1. Problemas detectados

Para cada problema:

### Problema X

**Ubicación:** archivo/sección/línea si está disponible.

**Actual:**
Qué plantea actualmente el SPEC.

**Problema:**
Por qué no está alineado con la arquitectura actual.

**Propuesta:**
Cómo debería plantearse.

**Prioridad:**
Crítica / Alta / Media / Baja

## 2. Asunciones

Lista todas las asunciones necesarias.

## 3. Adiciones técnicas propuestas

Lista las mejoras técnicas que deberían agregarse al SPEC.

Para cada una explica brevemente:

* Qué agregar.
* Por qué.
* En qué sección del SPEC debería colocarse.

## 4. Elementos que deben conservarse

Identifica qué partes del SPEC actual siguen siendo válidas y NO deberían modificarse.

## 5. Resumen de cambios propuestos

Presenta una lista concreta de los cambios que propones realizar posteriormente.

IMPORTANTE:

En esta etapa NO realices ninguno de esos cambios.

Espera mi autorización explícita antes de modificar el SPEC.
