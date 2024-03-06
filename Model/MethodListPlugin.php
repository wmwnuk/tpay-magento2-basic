<?php

namespace tpaycom\magento2basic\Model;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Payment\Helper\Data;
use Magento\Payment\Model\MethodInterface;
use Magento\Payment\Model\MethodList;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use tpaycom\magento2basic\Api\TpayInterface;
use tpaycom\magento2basic\Model\ApiFacade\Transaction\TransactionApiFacade;
use tpaycom\magento2basic\Model\Config\Source\OnsiteChannels;

class MethodListPlugin
{
    private const CONFIG_PATH = 'payment/tpaycom_magento2basic/openapi_settings/onsite_channels';

    /** @var Data */
    private $data;

    /** @var ScopeConfigInterface */
    private $scopeConfig;

    /** @var OnsiteChannels */
    private $onsiteChannels;

    /** @var StoreManagerInterface */
    private $storeManager;

    /** @var Tpay */
    private $tpay;

    /** @var Session */
    private $checkoutSession;

    /** @var TransactionApiFacade */
    private $transactions;

    /** @var ConstraintValidator */
    private $constraintValidator;

    public function __construct(
        Data $data,
        ScopeConfigInterface $scopeConfig,
        OnsiteChannels $onsiteChannels,
        StoreManagerInterface $storeManager,
        Tpay $tpay,
        Session $checkoutSession,
        TransactionApiFacade $transactions,
        ConstraintValidator $constraintValidator
    ) {
        $this->data = $data;
        $this->scopeConfig = $scopeConfig;
        $this->onsiteChannels = $onsiteChannels;
        $this->storeManager = $storeManager;
        $this->tpay = $tpay;
        $this->checkoutSession = $checkoutSession;
        $this->transactions = $transactions;
        $this->constraintValidator = $constraintValidator;
    }

    public function afterGetAvailableMethods(MethodList $compiled, $result)
    {
        $onsiteChannels = $this->scopeConfig->getValue(self::CONFIG_PATH, ScopeInterface::SCOPE_STORE);
        $channelList = $onsiteChannels ? explode(',', $onsiteChannels) : [];
        $channels = $this->transactions->channels();

        if ($this->constraintValidator->isClientCountryValid($this->tpay->isAllowSpecific(), $this->checkoutSession->getQuote()->getBillingAddress()->getCountryId(), $this->tpay->getSpecificCountry())) {
            return [];
        }

        if (!$this->tpay->isCartValid($this->checkoutSession->getQuote()->getGrandTotal())) {
            return $result;
        }

        $result = $this->addCardMethod($result);
        $result = $this->filterResult($result);

        if (!$this->transactions->isOpenApiUse() || !$this->isPlnPayment()) {
            return $result;
        }

        foreach ($channelList as $onsiteChannel) {
            $channel = $channels[$onsiteChannel];

            if (!empty($channel->constraints) && !$this->constraintValidator->validate($channel->constraints)) {
                continue;
            }

            $title = $this->onsiteChannels->getLabelFromValue($onsiteChannel);
            $result[] = $this->getMethodInstance(
                $title,
                "generic-{$onsiteChannel}"
            );
        }

        return $result;
    }

    public function getMethodInstance(string $title, string $code): MethodInterface
    {
        $method = $this->data->getMethodInstance(TpayInterface::CODE);
        $method->setTitle($title);
        $method->setCode($code);

        return $method;
    }

    private function addCardMethod(array $result): array
    {
        if ($this->tpay->isCardEnabled()) {
            $result[] = $this->getMethodInstance($this->tpay->getCardTitle(), 'tpaycom_magento2basic_cards');
        }

        return $result;
    }

    private function filterResult(array $result): array
    {
        if ($this->isPlnPayment()) {
            return $result;
        }

        return array_filter($result, function ($method) {
            return 'tpaycom_magento2basic' !== $method->getCode();
        });
    }

    private function isPlnPayment(): bool
    {
        return 'PLN' === $this->storeManager->getStore()->getCurrentCurrencyCode();
    }
}