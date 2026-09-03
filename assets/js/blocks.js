(function () {
	'use strict';
	var register = window.wc.wcBlocksRegistry.registerPaymentMethod;
	var settings = window.wc.wcSettings.getSetting('cybersource_rest_data', {});
	var el = window.wp.element.createElement;
	var useState = window.wp.element.useState;
	var useEffect = window.wp.element.useEffect;
	var decode = window.wp.htmlEntities.decodeEntities;
	if (!register) return;

	function Content(props) {
		var cardNumber = useState(''), expiry = useState(''), cvc = useState(''), status = useState('');
		var onPaymentProcessing = props.eventRegistration.onPaymentProcessing;
		useEffect(function () {
			return onPaymentProcessing(async function () {
				try {
					var digits = cardNumber[0].replace(/\D/g, '');
					var parsedExpiry = window.WCCybs3DS.parseExpiry(expiry[0]);
					var code = cvc[0].replace(/\D/g, '');
					if (digits.length < 12 || !parsedExpiry || code.length < 3) throw new Error('Revisa los datos de la tarjeta y usa MM / AA para el vencimiento.');
					var card = {number:digits, expirationMonth:parsedExpiry.month, expirationYear:parsedExpiry.year};
					var address = (props.billing && props.billing.billingAddress) || {};
					var authToken = settings.frontend.enable3ds ? await window.WCCybs3DS.authenticate(card, address, status[1], window.WCCybs3DS.checkoutTransaction()) : '3ds-disabled';
					status[1]('');
					return {type:props.emitResponse.responseTypes.SUCCESS, meta:{paymentMethodData:{
						cybs_card_number: digits,
						cybs_card_expiry: parsedExpiry.digits,
						cybs_card_cvc: code,
						cybs_auth_token: authToken,
						cybs_checkout_binding: window.WCCybs3DS.checkoutBinding,
						cybs_device_fingerprint_id: settings.frontend.deviceId
					}}};
				} catch (error) {
					status[1]('');
					return {type:props.emitResponse.responseTypes.ERROR, message:error.message || settings.frontend.messages.failed};
				}
			});
		}, [onPaymentProcessing, props.emitResponse.responseTypes.SUCCESS, props.emitResponse.responseTypes.ERROR, props.billing, cardNumber[0], expiry[0], cvc[0]]);

		return el('div', {className:'wc-cybs-block-fields'},
			el('p', null, decode(settings.description || '')),
			el('label', null, 'Numero de tarjeta', el('input', {type:'text', inputMode:'numeric', autoComplete:'cc-number', value:cardNumber[0], onChange:function(e){cardNumber[1](e.target.value);}})),
			el('div', {style:{display:'grid',gridTemplateColumns:'1fr 1fr',gap:'12px'}},
				el('label', null, 'Vencimiento', el('input', {type:'text', inputMode:'numeric', autoComplete:'cc-exp', maxLength:7, pattern:'[0-9 /]*', placeholder:'MM / AA', value:expiry[0], onChange:function(e){expiry[1](window.WCCybs3DS.formatExpiry(e.target.value));}})),
				el('label', null, 'Codigo de seguridad', el('input', {type:'password', inputMode:'numeric', autoComplete:'cc-csc', value:cvc[0], onChange:function(e){cvc[1](e.target.value);}}))
			),
			el('div', {role:'status', 'aria-live':'polite'}, status[0])
		);
	}

	const labelText = decode(settings.title || 'Tarjeta de credito o debito');
	function Label(props) { return el(props.components.PaymentMethodLabel, {text:labelText}); }

	register({
		name:'cybersource_rest',
		label:el(Label),
		ariaLabel:labelText,
		content:el(Content),
		edit:el('div', null, decode(settings.description || 'Pago seguro con CyberSource.')),
		canMakePayment:function(){return true;},
		supports:{features:settings.supports || ['products']}
	});
})();
