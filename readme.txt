=== CyberSource REST para WooCommerce ===
Contributors: soydiaz
Tags: woocommerce, cybersource, payments, 3ds, visa
Author: www.soydiaz.com
Author URI: https://www.soydiaz.com
Requires at least: 6.4
Requires PHP: 7.4
Stable tag: 1.1.4
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
* Regla obligatoria: ECI 0 o ECI 7 bloquea la autorizacion.
* Checkout clasico y Cart/Checkout Blocks.
* Autorizacion con captura configurable.
* Reembolsos desde la orden WooCommerce.
* Compatibilidad declarada con HPOS.
* Logs de solicitudes/respuestas con PAN, CVV, CAVV, XID, JWT, llaves, tokens y datos personales eliminados.
* No guarda el numero completo de tarjeta ni el CVV en pedidos, metadatos o logs.
* Limite basico de intentos en endpoints de autenticacion para reducir card testing.

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
