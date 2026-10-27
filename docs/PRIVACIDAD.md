# Privacidad: datos que guarda Engage y plazos (0.9.13)

Esto es documentación técnica, no asesoramiento legal.

## Qué se guarda de cada comentario
Nombre y correo (o los de la cuenta), el texto, la **dirección IP** y los **datos del navegador** (User-Agent), y la fecha. La IP y el navegador sirven para el antispam y la moderación.

## Retención de IP y navegador
- Opción **«Conservar IP y navegador de los comentarios (meses)»** (`retention_months`): entero de 1 a 12, **por defecto 12, máximo 12** (no existe «para siempre»). Solo la cambia un superadministrador de Engage (`core.admin`); cualquier valor no válido equivale a 12.
- Pasado el plazo, la IP se pone a vacío y el navegador a cadena vacía; **el comentario no se borra**. La purga es automática y periódica (el mismo mecanismo de cada 8 horas que limpia el spam; se dispara con las visitas), por lotes de 500 comentarios y hasta 20 lotes por pasada, con consultas con parámetros ligados. Si hay mucho histórico, lo terminan las pasadas siguientes.
- El panel de control muestra el plazo vigente.
- Una copia de seguridad de la base de datos hecha antes de la purga conserva esos datos hasta que se borre la copia.

## Información en el formulario
Bajo el formulario se muestra siempre un bloque breve (es-ES / en-GB) que dice qué se guarda, para qué, el plazo configurado y que los avisos por correo llevan enlace de baja. Es **información**, no depende de ninguna casilla (la casilla de aceptación de condiciones, si está activada, funciona igual que antes). Con la opción **«Enlace a la política de privacidad»** (`privacy_url`; vacía por defecto; ruta del propio sitio con una sola «/» o dirección `https://`; solo superadministrador) se añade el enlace.

## Enlace de consulta de IP
`iplookup` queda **vacío por defecto en las instalaciones nuevas** (sin enlace). Las instalaciones existentes conservan el valor que ya tenían (la actualización no lo toca). Si se configura, **al hacer clic el navegador de quien modera envía la IP del comentarista a ese servicio de terceros**; el enlace solo lo ven moderadores y administradores, nunca los visitantes.

## Adopción de comentarios de invitado al iniciar sesión (plugin «User»)
Opción `own_comments` (activada por defecto). Desde la 0.9.13 solo se atribuyen a una cuenta los comentarios de invitado cuyo correo coincide con el correo que el propietario **verificó al activar su cuenta con el enlace del correo**:
- al registrarse con activación por correo se guarda el correo al que se envió el enlace; al pulsar el enlace (activación del sitio, no desde administración) pasa a «verificado». Se guarda en `#__user_profiles` (claves `engageadopt.pending` / `engageadopt.verified`), solo por el servidor;
- al iniciar sesión se adopta solo si el correo **actual** de la cuenta es idéntico (sin distinguir mayúsculas) al verificado. Si el usuario cambió su correo, no se adopta nada con el nuevo;
- cuentas creadas por un administrador, sitios sin activación por correo y **cuentas activadas antes de actualizar a esta versión no adoptan** por esta vía (lado seguro); no hace falta ninguna acción manual;
- cada comentario se adopta una sola vez (después ya no es de invitado).
