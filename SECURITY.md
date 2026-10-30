# Seguridad

Reporte vulnerabilidades de forma privada al responsable tecnico de la tienda. No
publique credenciales, PAN, CVV, JWT, CAVV ni respuestas completas en tickets publicos.

El plugin nunca debe modificarse para guardar el PAN o CVV. Los metadatos permitidos de
la orden se limitan al ID de transaccion, ambiente, ECI, PARes status y transaction ID de
autenticacion.

Antes de produccion:

1. Validar el alcance PCI DSS con el adquirente/QSA.
2. Forzar HTTPS y HSTS.
3. Deshabilitar logs de depuracion del servidor que capturen cuerpos POST.
4. Proteger WordPress y `/wp-json/cybersource-rest/v1/*` con WAF/rate limiting.
5. Probar todos los escenarios 3DS entregados por CyberSource.
6. Verificar expresamente que ECI 0 y ECI 7 (o ECI ausente) nunca generan `/pts/v2/payments`.
7. Rotar llaves CyberSource y restringir acceso al administrador.
8. Migrar HTTP Signature a JWT + MLE antes del retiro anunciado para marzo de 2027.
9. Habilitar Transaction Search y Transaction Details en Business Center para la reconciliacion.
