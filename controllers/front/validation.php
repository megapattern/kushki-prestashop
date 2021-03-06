<?php
/*
* 2007-2018 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2016 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

require_once dirname( __FILE__ ) . '/kushki/autoload.php';


/**
 * @since 1.5.0
 */
class KushkiPaymentValidationModuleFrontController extends ModuleFrontController {
	/**
	 * @see FrontController::postProcess()
	 */
	public function postProcess() {
		$cart = $this->context->cart;
		if ( $cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || ! $this->module->active ) {
			Tools::redirect( 'index.php?controller=order&step=1' );
		}

		// Check that this payment option is still available in case the customer changed his address just before the end of the checkout process
		$authorized = false;
		foreach ( Module::getPaymentModules() as $module ) {
			if ( $module['name'] == 'kushkipayment' ) {
				$authorized = true;
				break;
			}
		}
		if ( ! $authorized ) {
			die( $this->module->l( 'This payment method is not available.', 'validation' ) );
		}

		$customer = new Customer( $cart->id_customer );
		if ( ! Validate::isLoadedObject( $customer ) ) {
			Tools::redirect( 'index.php?controller=order&step=1' );
		}
		$merchantId   = Configuration::get( 'KUSHKI_PRIVATE_KEY' );
		
		$language     = kushki\lib\KushkiLanguage::ES;
		$current_currency   = new Currency((int)($cart->id_currency));
		$currency1 = $current_currency->iso_code;
		if ($currency1 == 'USD') 	$currencyCode     = kushki\lib\KushkiCurrency::USD;
		if ($currency1 == 'COP') 	$currencyCode     = kushki\lib\KushkiCurrency::COP;		

            //Get total value of products without VAT
            $tx_ref       = Tools::getValue('tx_ref');
            $vat_0 = (int)configuration::get('ID_price_tax');
            $tot;
            $tot_0 = 0;
            for ($vat_0 != $tx_ref; ; $tot++) {
                $tot_0 = $tot_0 + $tot;
                break;
            }


		$environment  = ( Configuration::get( 'KUSHKI_TEST' ) ) ? kushki\lib\KushkiEnvironment::TESTING : kushki\lib\KushkiEnvironment::PRODUCTION;
		$kushki       = new kushki\lib\Kushki( $merchantId, $language, $currencyCode, $environment );
		$token        = Tools::getValue( 'kushkiToken' );
		$months       = (int)Tools::getValue( 'kushkiDeferred' );
		
		$total        = (float) $cart->getOrderTotal( true, Cart::BOTH );
		$tx_ref       = Tools::getValue('tx_ref');
		$val_ice      = Configuration::get('PS_TAX');
		
		$subtotalIva  = $total / $tx_ref;
		$iva          = $total - $subtotalIva;
		$subtotalIva0 = $tot_0 * $productQuantity;
		$ice          = (int)configuration::get('tax_ice') * $val_ice;
		
		//Call for extrataxes prestashop 1.7
		$propina=Tools::getValue('tx_ref_tip');
		$tasaAeroportuaria=Tools::getValue('tx_ref_airport_fee');;
		$agenciaDeViaje=Tools::getValue('tx_ref_travel_agency');
		$iac=Tools::getValue('tx_ref_tip_COP_iac');
		$extraTaxes = $propina . $tasaAeroportuaria . $agenciaDeViaje . $iac;
		$amount       = new kushki\lib\Amount( $subtotalIva, $iva, $subtotalIva0, $ice, $extrataxes );
		
		if ( $months > 0 ) {

			$transaction = $kushki->deferredCharge( $token, $amount, $months, $cart);
		} else {
			$transaction = $kushki->charge( $token, $amount, $cart);
		}
		if ( ! $transaction->isSuccessful() ) {
			Tools::redirect('index.php?controller=order&step=4&KushkiPaymenterror='.urlencode($transaction->getResponseText()));			
			exit;
		}

		$extra    = array(
			'transaction_id' => $transaction->getTicketNumber()

		);

		$this->module->validateOrder( $cart->id, Configuration::get( 'PS_OS_PAYMENT' ), $total, $this->module->displayName, NULL, $extra, (int) $cart->id_currency, false, $customer->secure_key );
		Tools::redirect( 'index.php?controller=order-confirmation&id_cart=' . $cart->id . '&id_module=' . $this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key );
	}
}