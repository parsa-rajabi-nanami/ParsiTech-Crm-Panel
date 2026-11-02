(function ($) {
	'use strict';

	$(function () {
		const $connBtn = $('#parsireach-btn-test-connection');
		const $connRes = $('#parsireach-connection-result');

		$connBtn.on('click', function (e) {
			e.preventDefault();
			$connBtn.prop('disabled', true);
			$connRes
				.removeClass('status-ok status-err')
				.text(parsireachSettings.i18n.testing);

			$.post(parsireachSettings.ajaxUrl, {
				action: 'parsireach_test_connection',
				nonce: parsireachSettings.nonce
			})
				.done(function (res) {
					if (res && res.success) {
						$connRes.addClass('status-ok').text(res.data.message);
					} else {
						const msg = (res && res.data && res.data.message) ? res.data.message : parsireachSettings.i18n.error;
						$connRes.addClass('status-err').text(msg);
					}
				})
				.fail(function () {
					$connRes.addClass('status-err').text(parsireachSettings.i18n.error);
				})
				.always(function () {
					$connBtn.prop('disabled', false);
				});
		});

		const $smsBtn = $('#parsireach-btn-send-test-sms');
		const $smsRes = $('#parsireach-test-sms-result');

		$smsBtn.on('click', function (e) {
			e.preventDefault();
			$smsBtn.prop('disabled', true);
			$smsRes
				.removeClass('status-ok status-err')
				.text(parsireachSettings.i18n.sending);

			$.post(parsireachSettings.ajaxUrl, {
				action: 'parsireach_send_test_sms',
				nonce: parsireachSettings.nonce,
				phone: $('#parsireach_test_phone').val(),
				pattern_code: $('#parsireach_test_pattern').val(),
				pattern_values: $('#parsireach_test_pattern_values').val()
			})
				.done(function (res) {
					if (res && res.success) {
						$smsRes.addClass('status-ok').text(res.data.message);
					} else {
						const msg = (res && res.data && res.data.message) ? res.data.message : parsireachSettings.i18n.error;
						$smsRes.addClass('status-err').text(msg);
					}
				})
				.fail(function () {
					$smsRes.addClass('status-err').text(parsireachSettings.i18n.error);
				})
				.always(function () {
					$smsBtn.prop('disabled', false);
				});
		});
	});
})(jQuery);
