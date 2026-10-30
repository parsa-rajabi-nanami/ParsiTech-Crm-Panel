(function ($) {
	'use strict';

	$(function () {
		const $connBtn = $('#parsishop-btn-test-connection');
		const $connRes = $('#parsishop-connection-result');

		$connBtn.on('click', function (e) {
			e.preventDefault();
			$connBtn.prop('disabled', true);
			$connRes
				.removeClass('status-ok status-err')
				.text(parsishopCrmSettings.i18n.testing);

			$.post(parsishopCrmSettings.ajaxUrl, {
				action: 'parsishop_crm_test_connection',
				nonce: parsishopCrmSettings.nonce
			})
				.done(function (res) {
					if (res && res.success) {
						$connRes.addClass('status-ok').text(res.data.message);
					} else {
						const msg = (res && res.data && res.data.message) ? res.data.message : parsishopCrmSettings.i18n.error;
						$connRes.addClass('status-err').text(msg);
					}
				})
				.fail(function () {
					$connRes.addClass('status-err').text(parsishopCrmSettings.i18n.error);
				})
				.always(function () {
					$connBtn.prop('disabled', false);
				});
		});

		const $smsBtn = $('#parsishop-btn-send-test-sms');
		const $smsRes = $('#parsishop-test-sms-result');

		$smsBtn.on('click', function (e) {
			e.preventDefault();
			$smsBtn.prop('disabled', true);
			$smsRes
				.removeClass('status-ok status-err')
				.text(parsishopCrmSettings.i18n.sending);

			$.post(parsishopCrmSettings.ajaxUrl, {
				action: 'parsishop_crm_send_test_sms',
				nonce: parsishopCrmSettings.nonce,
				phone: $('#parsishop_test_phone').val(),
				pattern_code: $('#parsishop_test_pattern').val(),
				var_key: $('#parsishop_test_var_key').val(),
				var_val: $('#parsishop_test_var_val').val()
			})
				.done(function (res) {
					if (res && res.success) {
						$smsRes.addClass('status-ok').text(res.data.message);
					} else {
						const msg = (res && res.data && res.data.message) ? res.data.message : parsishopCrmSettings.i18n.error;
						$smsRes.addClass('status-err').text(msg);
					}
				})
				.fail(function () {
					$smsRes.addClass('status-err').text(parsishopCrmSettings.i18n.error);
				})
				.always(function () {
					$smsBtn.prop('disabled', false);
				});
		});
	});
})(jQuery);
