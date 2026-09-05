// WooCommerce exposes these modules as browser globals during Blocks checkout.
// eslint-disable-next-line import/no-unresolved
import { getSetting } from '@woocommerce/settings';
// eslint-disable-next-line import/no-unresolved
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { decodeEntities } from '@wordpress/html-entities';
import { createElement } from '@wordpress/element';

const settings = getSetting( 'skypay_data', {} );
const title = decodeEntities( settings.title || 'SkyPay' );
const description = decodeEntities( settings.description || '' );
const Content = () => createElement( 'span', null, description );

registerPaymentMethod( {
	name: 'skypay',
	label: title,
	ariaLabel: title,
	content: createElement( Content ),
	edit: createElement( Content ),
	canMakePayment: () => true,
	supports: {
		features: settings.supports || [ 'products' ],
	},
} );
