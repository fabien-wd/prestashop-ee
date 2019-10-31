<?php
/**
 * Shop System Extensions:
 * - Terms of Use can be found at:
 * https://github.com/wirecard/prestashop-ee/blob/master/_TERMS_OF_USE
 * - License can be found under:
 * https://github.com/wirecard/prestashop-ee/blob/master/LICENSE
 */

namespace WirecardEE\Prestashop\Classes\Service;

use Wirecard\PaymentSdk\BackendService;
use WirecardEE\Prestashop\Classes\Config\PaymentConfigurationFactory;
use WirecardEE\Prestashop\Classes\Finder\OrderFinder;
use WirecardEE\Prestashop\Classes\Finder\TransactionFinder;
use WirecardEE\Prestashop\Classes\Response\ProcessablePaymentResponseFactory;
use WirecardEE\Prestashop\Classes\Transaction\Builder\PostProcessingTransactionBuilder;
use WirecardEE\Prestashop\Helper\PaymentProvider;
use WirecardEE\Prestashop\Helper\Service\ShopConfigurationService;
use WirecardEE\Prestashop\Helper\Logger as WirecardLogger;

class TransactionPostProcessingService implements ServiceInterface
{
    /** @var string */
    private $operation;
    /** @var int */
    private $transaction_id;
    /** @var array  */
    private $errors = [];

    /**
     * TransactionPostProcessingService constructor.
     * @param string $opertion
     * @param int $transaction_id
     */
    public function __construct($opertion, $transaction_id)
    {
        $this->operation = $opertion;
        $this->transaction_id = $transaction_id;
    }

    /**
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }

    public function process()
    {
        $operation = $this->operation;
        $transaction_id = $this->transaction_id;

        try {
            $parentTransaction = (new TransactionFinder())->getTransactionById($transaction_id);

            $postProcessingTransactionBuilder = new PostProcessingTransactionBuilder(
                PaymentProvider::getPayment($parentTransaction->getPaymentMethod()),
                $parentTransaction
            );

            $transaction = $postProcessingTransactionBuilder
                ->setOperation($operation)
                ->build();

            $shop_config_service = new ShopConfigurationService($parentTransaction->getPaymentMethod());
            $payment_config = (new PaymentConfigurationFactory($shop_config_service))->createConfig();
            $backend_service = new BackendService($payment_config, new WirecardLogger());

            $response = $backend_service->process($transaction, $operation);
            $order = (new OrderFinder())->getOrderByReference($parentTransaction->getOrderNumber());

            $response_factory = new ProcessablePaymentResponseFactory(
                $response,
                $order,
                ProcessablePaymentResponseFactory::PROCESS_BACKEND
            );

            $processing_strategy = $response_factory->getResponseProcessing();
            $processing_strategy->process();
        } catch (\Exception $e) {
            $this->errors[] = $e->getMessage();
            $logger = new WirecardLogger();
            $logger->error(
                'Error in class:' . __CLASS__ .
                ' method:' . __METHOD__ .
                ' exception: ' . $e->getMessage() . "(" . get_class($e) . ")"
            );
        }
    }
}