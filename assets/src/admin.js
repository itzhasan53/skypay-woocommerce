const button = document.getElementById( 'skypay-test-connection' );
const result = document.getElementById( 'skypay-test-result' );

if ( button && result && window.skypayWooAdmin ) {
	button.addEventListener( 'click', async () => {
		button.disabled = true;
		result.textContent = window.skypayWooAdmin.testing;

		const body = new URLSearchParams( {
			action: 'skypay_wc_test_connection',
			nonce: window.skypayWooAdmin.nonce,
		} );

		try {
			const response = await fetch( window.skypayWooAdmin.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body,
			} );
			const payload = await response.json();
			result.textContent =
				payload?.data?.message || window.skypayWooAdmin.failed;
		} catch {
			result.textContent = window.skypayWooAdmin.failed;
		} finally {
			button.disabled = false;
		}
	} );
}
