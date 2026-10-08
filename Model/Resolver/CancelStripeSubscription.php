<?php

declare(strict_types=1);

namespace Magenx\StripePaymentsGraphQl\Model\Resolver;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use StripeIntegration\Payments\Helper\Generic;
use StripeIntegration\Payments\Model\SubscriptionFactory;

/**
 * Resolves the cancelStripeSubscription mutation.
 *
 * Mirrors the Luma StripeIntegration\Payments\Controller\Subscriptions\Cancel
 * controller: it verifies the authenticated customer owns the subscription
 * (ownsSubscriptionId) before cancelling it through the same SubscriptionModel
 * ->cancel() call, then returns the refreshed subscription list.
 */
class CancelStripeSubscription implements ResolverInterface
{
    private Generic $helper;
    private SubscriptionFactory $subscriptionFactory;
    private CustomerSubscriptions $customerSubscriptions;

    public function __construct(
        Generic $helper,
        SubscriptionFactory $subscriptionFactory,
        CustomerSubscriptions $customerSubscriptions
    ) {
        $this->helper = $helper;
        $this->subscriptionFactory = $subscriptionFactory;
        $this->customerSubscriptions = $customerSubscriptions;
    }

    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        if (!$context->getExtensionAttributes()->getIsCustomer()) {
            throw new GraphQlAuthorizationException(__('The current customer isn\'t authorized.'));
        }

        $subscriptionId = isset($args['subscription_id']) ? trim((string) $args['subscription_id']) : '';
        if ($subscriptionId === '') {
            throw new GraphQlInputException(__('A subscription ID is required.'));
        }

        $stripeCustomer = $this->helper->getCustomerModel();

        if (!$stripeCustomer || !$stripeCustomer->getStripeId()) {
            throw new GraphQlInputException(
                __('Sorry, the subscription could not be canceled. Please contact us for assistance.')
            );
        }

        if (!$stripeCustomer->ownsSubscriptionId($subscriptionId)) {
            // Do not disclose whether the subscription exists for another account.
            throw new GraphQlAuthorizationException(
                __('Sorry, the subscription could not be canceled. Please contact us for assistance.')
            );
        }

        try {
            $subscriptionModel = $this->subscriptionFactory->create()->fromSubscriptionId($subscriptionId);
            $subscriptionModel->cancel();
        } catch (LocalizedException $e) {
            throw new GraphQlInputException(__($e->getMessage()));
        } catch (\Throwable $e) {
            throw new GraphQlInputException(
                __('Sorry, the subscription could not be canceled. Please contact us for assistance.')
            );
        }

        return [
            'success' => true,
            'message' => (string) __('The subscription has been canceled successfully.'),
            'subscriptions' => $this->customerSubscriptions->getSubscriptions(),
        ];
    }
}
