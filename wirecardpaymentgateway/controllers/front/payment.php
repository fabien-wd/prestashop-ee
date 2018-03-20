<?php
/**
 * Shop System Plugins - Terms of Use
 *
 * The plugins offered are provided free of charge by Wirecard AG and are explicitly not part
 * of the Wirecard AG range of products and services.
 *
 * They have been tested and approved for full functionality in the standard configuration
 * (status on delivery) of the corresponding shop system. They are under General Public
 * License version 3 (GPLv3) and can be used, developed and passed on to third parties under
 * the same terms.
 *
 * However, Wirecard AG does not provide any guarantee or accept any liability for any errors
 * occurring when used in an enhanced, customized shop system configuration.
 *
 * Operation in an enhanced, customized configuration is at your own risk and requires a
 * comprehensive test phase by the user of the plugin.
 *
 * Customers use the plugins at their own risk. Wirecard AG does not guarantee their full
 * functionality neither does Wirecard AG assume liability for any disadvantages related to
 * the use of the plugins. Additionally, Wirecard AG does not guarantee the full functionality
 * for customized shop systems or installed plugins of other vendors of plugins within the same
 * shop system.
 *
 * Customers are responsible for testing the plugin's functionality before starting productive
 * operation.
 *
 * By installing the plugin into the shop system the customer agrees to these terms of use.
 * Please do not use the plugin if you do not agree to these terms of use!
 *
 * @author Wirecard AG
 * @copyright Wirecard AG
 * @license GPLv3
 */

use Wirecard\PaymentSdk\Entity\Amount;
use Wirecard\PaymentSdk\Entity\CustomField;
use Wirecard\PaymentSdk\Entity\CustomFieldCollection;
use Wirecard\PaymentSdk\Entity\Redirect;
use Wirecard\PaymentSdk\Response\FailureResponse;
use Wirecard\PaymentSdk\Response\FormInteractionResponse;
use Wirecard\PaymentSdk\Response\InteractionResponse;
use Wirecard\PaymentSdk\Transaction\Transaction;
use Wirecard\PaymentSdk\TransactionService;
use WirecardEE\Prestashop\Helper\AdditionalInformation;
use WirecardEE\Prestashop\Helper\OrderManager;
use WirecardEE\Prestashop\Helper\Logger as WirecardLogger;

/**
 * Class WirecardPaymentGatewayPaymentModuleFrontController
 *
 * @extends ModuleFrontController
 * @property WirecardPaymentGateway module
 *
 * @since 1.0.0
 */
class WirecardPaymentGatewayPaymentModuleFrontController extends ModuleFrontController
{
    /**
     * Process payment via transaction service
     *
     * @since 1.0.0
     */
    public function postProcess()
    {
        $cart = $this->context->cart;
        $cartId = $cart->id;
        $additionalInformation = new AdditionalInformation();

        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 ||
            !$this->module->active
        ) {
            $this->errors = 'An error occured during the checkout process. Please try again.';
            $this->redirectWithNotifications($this->context->link->getPageLink('order'));
        }

        $paymentType = Tools::getValue('paymentType');
        $orderId = $this->createOrder($cart, $paymentType);

        /** @var Payment $payment */
        $payment = $this->module->getPaymentFromType($paymentType);
        if ($payment) {
            $config = $payment->createPaymentConfig($this->module);
            $amount = round($cart->getOrderTotal(), 2);
            $currency = new Currency($cart->id_currency);
            $operation = $this->module->getConfigValue($paymentType, 'payment_action');
            $redirectUrls = new Redirect(
                $this->module->createRedirectUrl($cartId, $paymentType, 'success'),
                $this->module->createRedirectUrl($cartId, $paymentType, 'cancel'),
                $this->module->createRedirectUrl($cartId, $paymentType, 'failure')
            );

            /** @var Transaction $transaction */
            $transaction = $payment->createTransaction();
            $transaction->setNotificationUrl($this->module->createNotificationUrl($cartId, $paymentType));
            $transaction->setRedirect($redirectUrls);
            $transaction->setAmount(new Amount($amount, $currency->iso_code));

            $customFields = new CustomFieldCollection();
            $customFields->add(new CustomField('orderId', $cartId));
            $transaction->setCustomFields($customFields);

            if ($transaction instanceof  \Wirecard\PaymentSdk\Transaction\CreditCardTransaction) {
                $transaction->setTokenId(Tools::getValue('tokenId'));
                $transaction->setTermUrl($this->module->createRedirectUrl($cartId, $paymentType, 'success'));
            }

            if ($transaction instanceof \Wirecard\PaymentSdk\Transaction\SepaTransaction) {
                $account_holder = new \Wirecard\PaymentSdk\Entity\AccountHolder();
                $account_holder->setFirstName(Tools::getValue('sepaFirstName'));
                $account_holder->setLastName(Tools::getValue('sepaLastName'));

                $transaction->setAccountHolder($account_holder);
                $transaction->setIban(Tools::getValue('sepaIban'));

                if ($this->module->getConfigValue('sepa', 'enable_bic')) {
                    $transaction->setBic(Tools::getValue('sepaBic'));
                }

                $mandate = new \Wirecard\PaymentSdk\Entity\Mandate($this->generateMandateId($orderId));
                $transaction->setMandate($mandate);
            }

            if ($transaction instanceof \Wirecard\PaymentSdk\Transaction\IdealTransaction) {
                $transaction->setBic(Tools::getValue('idealBankBic'));
            }

            if ($this->module->getConfigValue($paymentType, 'shopping_basket')) {
                $transaction->setBasket($additionalInformation->createBasket($cart, $transaction, $currency->iso_code));
            }

            if ($this->module->getConfigValue($paymentType, 'descriptor')) {
                $transaction->setDescriptor($additionalInformation->createDescriptor($cartId));
            }

            if ($this->module->getConfigValue($paymentType, 'send_additional')) {
                $transaction = $additionalInformation->createAdditionalInformation(
                    $cart,
                    $cartId,
                    $transaction,
                    $currency->iso_code
                );
            }

            return $this->executeTransaction($transaction, $config, $operation);
        }
    }

    /**
     * Execute transactions with operation pay and reserve
     *
     * @param Transaction $transaction
     * @param \Wirecard\PaymentSdk\Config\Config $config
     * @param string $operation
     * @since 1.0.0
     */
    public function executeTransaction($transaction, $config, $operation)
    {
        $transactionService = new TransactionService($config, new WirecardLogger());
        try {
            /** @var \Wirecard\PaymentSdk\Response\Response $response */
            $response = $transactionService->process($transaction, $operation);
        } catch (Exception $exception) {
            $this->errors = $exception->getMessage();
            $this->redirectWithNotifications($this->context->link->getPageLink('order'));
        }

        if ($response instanceof InteractionResponse) {
            $redirect = $response->getRedirectUrl();
            Tools::redirect($redirect);
        } elseif ($response instanceof FormInteractionResponse) {
            $data                = null;
            $data['url']         = $response->getUrl();
            $data['method']      = $response->getMethod();
            $data['form_fields'] = $response->getFormFields();

            echo $this->createPostForm($data);
            die();
        } elseif ($response instanceof FailureResponse) {
            $errors = '';
            foreach ($response->getStatusCollection()->getIterator() as $item) {
                $errors .= $item->getDescription();
            }
            $this->errors = $errors;
            $this->redirectWithNotifications($this->context->link->getPageLink('order'));
        }
        $this->errors = 'An error occured during the checkout process. Please try again.';
        $this->redirectWithNotifications($this->context->link->getPageLink('order'));
    }

    /**
     * Create post form for credit card
     *
     * @param Array $data
     * @return string
     * @since 1.0.0
     */
    private function createPostForm($data)
    {
        $html  = '';
        $html .= '<script>window.setInterval( function() {
                    var wait = document.getElementById( "wait" );
    				if ( wait.innerHTML.length > 3 ) 
       					 wait.innerHTML = "";
    				else 
        				wait.innerHTML += ".";
    		}, 200); 
    		</script>
			<div style="display: flex; justify-content: center; font-size: 20px;">' .
            'You are being redirected. Please wait' . '
			<span id="wait" style="font-size: 20px; width: 50px;">.</span></div>';
        $html .= '<form id="credit_card_form" method="' . $data['method'] . '" action="' . $data['url'] . '">';
        foreach ($data['form_fields'] as $key => $value) {
            $html .= '<input type="hidden" name="' . $key . '" value="' . $value . '">';
        }
        $html .= '</form>';
        $html .= '<script>document.getElementsByTagName("form")[0].submit();</script>';

        return $html;
    }

    /**
     * Create order
     *
     * @param Cart $cart
     * @param string $paymentMethod
     * @since 1.0.0
     */
    private function createOrder($cart, $paymentMethod)
    {
        $orderManager = new OrderManager($this->module);

        $order = new Order($orderManager->createOrder(
            $cart,
            OrderManager::WIRECARD_OS_AWAITING,
            $paymentMethod
        ));

        return $order->id;
    }

    /**
     * Generate the mandate id for SEPA
     *
     * @param $order_id
     * @return string
     * @since 1.0,0
     */
    private function generateMandateId($orderId)
    {
        return $this->module->getConfigValue('sepa', 'creditor_id') . '-' .
            $orderId . '-' . strtotime(date('Y-m-d H:i:s'));
    }
}
