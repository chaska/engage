# Denunciar comentarios (0.9.14)

Esto es documentación técnica, no asesoramiento legal.

## Qué hace
Los usuarios **registrados con sesión** (nunca los invitados) ven un botón **«Denunciar»** en los comentarios publicados que no son suyos. Abre un formulario pequeño con un **motivo de una lista cerrada** (spam o publicidad, ofensivo o abusivo, datos personales, otro) y una nota opcional de hasta 200 caracteres. Sin JavaScript también funciona (es un formulario normal con `<details>`).

**Una denuncia nunca oculta, despublica ni borra un comentario.** Solo aparece en la cola **«Denuncias»** del administrador (vista `reports`, enlazada desde el panel de control con el número de abiertas), donde una persona con permiso decide: *Descartar*, o *Despublicar comentario* (queda marcada como atendida). Los datos de quien denuncia (nombre, usuario, nota) solo se muestran allí.

## Protección contra abuso (todo se decide en el servidor)
| Defensa | Detalle |
|---|---|
| Solo con sesión | Un invitado recibe el mismo 403 que cualquier tarea prohibida; el botón ni se imprime. |
| Token CSRF | `report.submit` solo acepta POST con el token de la sesión; se rechazan GET, tokens ausentes y cabeceras `Sec-Fetch-Site: cross-site`. |
| Una por persona y comentario | Clave única `(comment_id, user_id)`. |
| Límite por usuario | Por defecto **5 por hora** (1 a 20) y **20 por día** (1 a 100). |
| Antigüedad de la cuenta | Por defecto **24 horas** (0 a 720). Frena a los robots que se registran y denuncian de inmediato. |
| Tope global de abiertas | Por defecto **500** (50 a 5000). Al llegar, no se aceptan más hasta que se resuelvan algunas (consulta de un `COUNT`, mensaje claro): muchas cuentas no pueden desbordar la cola. |
| Qué se puede denunciar | Solo comentarios publicados, con toda la cadena de padres publicada y de un contenido que quien denuncia puede ver. Un comentario inexistente y uno no visible dan **la misma respuesta**. No se puede denunciar el propio. |
| Motivo y nota | Motivo de lista cerrada (cualquier otro valor se rechaza). La nota se limpia: sin etiquetas, sin caracteres de control, espacios colapsados, 200 caracteres como máximo. |
| Configuración | Los seis ajustes (activar, tres límites, tope, aviso) solo los cambia un superadministrador (`core.admin`); los rangos están cerrados en el formulario y también al leerlos (un valor guardado fuera de rango vuelve al defecto, no abre el grifo). |
| Acciones de la cola | POST + token + `core.edit.state` comprobados en el servidor; el acceso a la lista exige entrar en el componente. |

## Aviso por correo (opcional)
Desactivado por defecto. Si se activa (`reports_notify`), los moderadores (grupos con `core.edit.state` en el componente o `core.admin`) reciben **un único correo agrupado** como mucho cada 8 horas (el mismo reloj que la limpieza de spam), con el **número** de denuncias nuevas y, si hay una dirección de confianza configurada, el enlace a la cola. No lleva nombres, notas ni texto de comentarios. Máximo 50 destinatarios.

## Datos y plazos
Tabla `#__engage_reports` (se crea sola al instalar o actualizar; solo `CREATE TABLE IF NOT EXISTS`). Las denuncias de una cuenta se borran al borrar la cuenta; las de comentarios que ya no existen, y las resueltas con más de 180 días, se limpian con el reloj de 8 horas (mantener 180 días evita que la misma persona vuelva a denunciar el mismo comentario nada más resolverlo).

## Qué no hace
No oculta nada por número de denuncias, no avisa al autor, no bloquea cuentas, no tiene vista pública de denuncias.

Pruebas: `tests/61-denuncias.php`, `tests/joomla-live/lib/denuncias-0914.php` (abuso por HTTP, cola, aviso agrupado, borrado de cuenta) y `denuncias-0914-navegador.js` (Chromium).
