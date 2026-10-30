=== CyberSource REST para WooCommerce ===
Contributors: soydiaz
Tags: woocommerce, cybersource, payments, 3ds, visa
Author: www.soydiaz.com
Author URI: https://www.soydiaz.com
Requires at least: 6.4
Requires PHP: 7.4
Stable tag: 1.2.0
WC tested up to: 11.2
License: GPLv2 or later

Pasarela CyberSource REST con Payer Authentication 3-D Secure, device fingerprint,
ambientes separados y logs sanitizados.

== Funciones ==

* Ambientes Desarrollo (apitest.cybersource.com) y Produccion (api.cybersource.com).
* Credenciales independientes por ambiente: Merchant ID, Key ID y Shared Secret.
* Firma HTTP HMAC-SHA256 de solicitudes REST.
* Payer Authentication 3-D Secure 2.x: Setup, device data collection, Enrollment,
  challenge (step-up), Validation y Authorization.
* Device fingerprint ThreatMetrix con Org ID configurable.
* Regla obligatoria: solo se autoriza con ECI 05/06 (Visa, Amex, JCB, Discover) o 02/01
  (Mastercard). ECI 07/00 o ausente bloquea la autorizacion.
* 3-D Secure obligatorio en Produccion (solo puede desactivarse en Desarrollo).
* Indicador e-commerce (commerceIndicator) tomado del resultado 3-D Secure.
* Liberacion automatica (reversa y, si no, void) de autorizaciones que no deben quedar.
* Reconciliacion automatica de cobros indeterminados o en revision, sin volver a cobrar.
* Bloqueo por pedido contra cobros simultaneos.
* Mensajes al cliente segun el motivo de rechazo de CyberSource.
* Checkout clasico y Cart/Checkout Blocks.
* Autorizacion con captura configurable.
* Reembolsos desde la orden WooCommerce.
* Compatibilidad declarada con HPOS.
* Logs de solicitudes/respuestas con PAN, CVV, CAVV, XID, JWT, llaves, tokens y datos personales eliminados.
* No guarda el numero completo de tarjeta ni el CVV en pedidos, metadatos o logs.
* Limite basico de intentos en endpoints de autenticacion para reducir card testing.

== Trabajo realizado en 1.2.0 ==

Se cubrieron los escenarios que faltaban:

1. AUTHORIZED_RISK_DECLINED (el banco aprueba y Decision Manager rechaza): antes el
   pedido quedaba "en espera" con el dinero retenido. Ahora se libera la autorizacion
   (reversa /reversals y, si falla, void /voids), el pedido pasa a Fallido y el cliente
   ve que la retencion se libero.
2. Timeout, corte de red, error 5xx o respuesta sin estado: antes se mostraba un error y
   el cliente podia reintentar (riesgo de doble cobro). Ahora el resultado queda
   "indeterminado": el pedido pasa a En espera, no se permite reintentar y el cron lo
   reconcilia.
3. Cobros simultaneos del mismo pedido (doble clic, dos pestanas): bloqueo por pedido
   con GET_LOCK de MySQL y relectura del estado directo de la base de datos.
4. 3-D Secure obligatorio en Produccion: aunque la casilla este desmarcada, en
   Produccion nunca se autoriza sin autenticacion.
5. commerceIndicator: se envia el que devuelve 3-D Secure (ecommerceIndicator en
   enrollment / indicator en validation). Si no viene, se deduce por marca y ECI
   (p. ej. Visa ECI 06 = vbv_attempted). Antes era fijo vbv/spa.
6. Liberacion de autorizaciones (aprobacion parcial, diferencia de importe, rechazo por
   riesgo): primero reversa y luego void. Antes solo void.
7. AUTHORIZED_PENDING_REVIEW / PENDING_REVIEW: el pedido queda En espera y el cron
   consulta el resultado final de la revision.
8. Mensajes al cliente por motivo (fondos insuficientes, CVV incorrecto, tarjeta
   vencida, rechazo por riesgo, etc.) en lugar de un mensaje generico.
9. 3-D Secure: la decision se toma por ECI (05/06/02/01); ya no se
   exige paresStatus Y/A/I (solo se rechaza N o R explicitos).
10. Un HTTP 400 con AUTHENTICATION_FAILED en enrollment/validation muestra el mensaje del
    banco en lugar de un error tecnico.
11. Las URL de device data collection y de desafio deben ser https de
    cardinalcommerce.com.
12. Se retiro riskInformation.deviceFingerprintId (no existe en el API REST); el
    fingerprint se envia solo en deviceInformation.fingerprintSessionId.

Reconciliacion:
* Cada intento usa una referencia unica "<pedido>-<intento>" en
  clientReferenceInformation.code y se guarda antes de enviar el cobro.
* Cron "wc_cybs_rest_reconcile" cada 15 minutos (20 pedidos por corrida, desde los 5
  minutos del intento). Busca el Request ID con Transaction Search (/tss/v2/searches)
  y consulta el detalle (/tss/v2/transactions/{id}).
* Aprobado: completa el pedido. Rechazado: Fallido. Aprobado y rechazado por riesgo:
  libera y Fallido. Sin registro tras 2 horas: Fallido (no hubo cargo, el cliente puede
  reintentar). Sin resultado tras 7 dias: revision manual.
* Accion manual en el pedido: "Consultar estado en CyberSource".
* Requiere que el Merchant ID tenga habilitados Transaction Search y Transaction
  Details en Business Center.

Metadatos nuevos del pedido: _cybs_attempt, _cybs_attempt_reference, _cybs_reconcile,
_cybs_reconcile_since, _cybs_release_type, _cybs_release_id, _cybs_release_status.

Pendiente (no incluido): capturar la tarjeta con Microform para
que el PAN y el CVV no pasen por el servidor y reducir el alcance PCI DSS.

== Instalacion ==

1. En WordPress vaya a Plugins > Agregar plugin > Subir plugin.
2. Seleccione el ZIP y active el plugin.
3. Vaya a WooCommerce > Ajustes > Pagos > CyberSource REST.
4. Configure primero el ambiente Desarrollo y sus credenciales HTTP Signature.
5. Confirme que Payer Authentication / 3-D Secure este habilitado para su Merchant ID.
6. Use HTTPS, aun durante pruebas cuando sea posible.
7. Ejecute todos los casos de prueba 3-D Secure entregados por CyberSource antes de
   cambiar el ambiente activo a Produccion.

== Configuracion ==

Desarrollo:
* Host: apitest.cybersource.com
* Org ID por defecto: 1snn5n9w

Produccion:
* Host: api.cybersource.com
* Org ID por defecto: k8vif92e

CyberSource requiere llaves distintas para pruebas y produccion. Los campos del panel
quedan vacios en el paquete; no se incluyen credenciales reales.

== Logs ==

Los registros se consultan en WooCommerce > Estado > Registros, fuente
"cybersource-rest". El sanitizador reemplaza el PAN por BIN + asteriscos + ultimos 4 y
elimina CVV, CAVV, XID, JWT, tokens, datos personales, firma, Key ID y secretos. Los logs dependen de la politica de
retencion configurada en WooCommerce.

== Seguridad y PCI DSS ==

Esta version es una integracion directa: los datos de tarjeta viajan desde el navegador
al servidor WooCommerce y se transmiten inmediatamente a CyberSource; no se persisten.
La tienda debe operar exclusivamente sobre HTTPS, aplicar actualizaciones, WAF/rate
limiting y cumplir el alcance PCI DSS que determine su adquirente/QSA.

HTTP Signature sigue disponible en 2026, pero CyberSource anuncia su deprecacion para
marzo de 2027. Antes de esa fecha se debe planificar una version con JWT y Message-Level
Encryption (MLE).

== Casos de prueba incluidos en la documentacion entregada ==

La hoja adjunta por el cliente contiene escenarios Visa y Mastercard para 3-D Secure
2.x, incluyendo frictionless exitoso/fallido, autenticacion no disponible, timeout y
step-up exitoso/fallido. Por seguridad, los numeros de prueba no se copian en este
paquete ni deben utilizarse en Produccion.

== Referencias de implementacion ==

* CyberSource (REST) - Manual de Integracion.pdf
* Manual Payer Autentication REST API.pdf
* Implementacion Device Fingerprint.pdf
* guia Implementacion de device fingerprint pasos.pdf
* TARJETAS DE PRUEBA 3DS 1.xlsx

== Changelog ==

= 1.2.0 =
* Libera automaticamente (reversa y luego void) las autorizaciones AUTHORIZED_RISK_DECLINED y PARTIAL_AUTHORIZED.
* Timeout, error de red, 5xx o estado no reconocido quedan como indeterminados (sin reintento) y se reconcilian por cron.
* Reconciliacion cada 15 minutos con Transaction Search y Transaction Details; accion manual en el pedido.
* Referencia unica por intento en clientReferenceInformation.code.
* Bloqueo por pedido contra cobros simultaneos.
* 3-D Secure obligatorio en Produccion.
* commerceIndicator tomado del resultado 3-D Secure (vbv_attempted, spa, etc.).
* Decision 3-D Secure por ECI 05/06/02/01; ya no exige paresStatus.
* HTTP 400 con AUTHENTICATION_FAILED muestra el mensaje del banco.
* Valida que las URL de 3-D Secure sean de Cardinal Commerce.
* Mensajes al cliente segun el motivo de rechazo.
* Firma HTTP Signature para GET (sin Digest) y Accept application/json para Transaction Search (con hal+json responde 406).
* Se retira riskInformation.deviceFingerprintId (agregado en 1.1.3).

= 1.1.4 =
* Autoria del plugin actualizada a www.soydiaz.com.
* El codigo de seguridad ahora se identifica como CVV en checkout clasico y Blocks (campo cybs_card_cvv; se sigue aceptando cybs_card_cvc por compatibilidad).
* El CVV se captura enmascarado: los digitos no se muestran mientras se escriben.

= 1.1.3 =
* Acepta promociones explicitas devueltas por CyberSource y registra el descuento en el pedido para que el total coincida con el importe cobrado.
* Cuando una diferencia sin promocion es anulada, mantiene el checkout abierto y muestra que no se realizo el cobro.
* Conserva en las notas y metadatos el estado original y la anulacion de CyberSource.
* Envia el identificador tanto en Payments REST como en el campo de compatibilidad de Decision Manager usado por integraciones Visanet.

= 1.1.2 =
* Desactiva el boton de finalizar compra y muestra un indicador animado durante la autenticacion 3-D Secure.
* Envia totalAmount junto al detalle y desactiva autorizaciones parciales para exigir el total exacto del pedido.
* Solicita una anulacion automatica cuando CyberSource responde o captura un importe distinto al pedido.

= 1.1.1 =
* Envia el identificador del profiler en deviceInformation.fingerprintSessionId, que es el campo de Payments REST mostrado por Business Center.
* Genera un identificador nuevo en cada carga completa del checkout y lo conserva durante sus actualizaciones AJAX.
* Envia IP y User-Agent junto con la informacion del dispositivo para Decision Manager.

= 1.1.0 =
* Vincula el Device Fingerprint ID al token 3-D Secure y reutiliza exactamente ese valor al autorizar.
* Envia productos, envio y cargos como lineItems cuya suma coincide con el total de WooCommerce.
* Valida el importe y la moneda respondidos antes de completar el pago.
* Reconoce ACCEPTED con aprobacion del procesador y deja estados pendientes o importes distintos en espera de revision.
* Evita reintentos de cobros ya aceptados o pendientes.
* Guarda Request ID, Network Transaction ID, Reconciliation ID, Approval Code, RRN, STAN y datos 3-D Secure en metadatos y notas privadas del pedido.
* Exige los campos minimos de facturacion antes de consultar Payer Authentication.
* Refuerza la sanitizacion de CAVV, XID, datos personales y datos del navegador en logs.

= 1.0.5 =
* Completa billTo con la direccion de envio cuando el checkout no solicita direccion de facturacion.
* Limpia el mensaje de verificacion al terminar la autenticacion o cuando WooCommerce devuelve un error.

= 1.0.4 =
* Corrige el importe 0.00 en Payer Authentication cuando el carrito no se restaura dentro de la solicitud REST.
* Toma el total vigente del checkout y mantiene una doble validacion contra el carrito disponible y la orden final.
* Descarta el token consumido cuando WooCommerce devuelve un error para permitir un nuevo intento limpio.

= 1.0.3 =
* Corrige el enlace del controlador 3-D Secure al evento no propagable del checkout clasico de WooCommerce.
* Asegura que el token de autenticacion se inserte dentro del formulario que WooCommerce serializa.

= 1.0.2 =
* Corrige la perdida del resultado 3-D Secure entre los endpoints REST y el procesamiento final de WooCommerce.
* Agrega almacenamiento temporal de un solo uso independiente de la sesion, ligado criptograficamente al checkout, tarjeta, monto y moneda.
* Agrega diagnostico sanitizado para distinguir token ausente, sesion perdida o vinculacion invalida.

= 1.0.1 =
* Corrige el campo de vencimiento con mascara automatica MM / AA.
* Rechaza fechas incompletas y digitos adicionales antes de iniciar 3-D Secure.

= 1.0.0 =
* Primera version instalable.
