<?php

declare(strict_types=1);

namespace Magenx\StripePaymentsGraphQl\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use StripeIntegration\Payments\Model\SubscriptionProductFactory;

/**
 * Resolves ProductInterface.stripe_subscription.
 *
 * Reads the product's Stripe subscription options (stored in the
 * stripe_subscription_options table and wrapped by the core module's
 * SubscriptionProduct model) and returns them as a flat array, or null when
 * the product is not configured as a subscription. The SubscriptionProduct
 * model already encapsulates the "is this a subscription?" rules and the
 * currency conversion for the initial fee, so we delegate to it rather than
 * reading the raw table.
 */
class ProductSubscription implements ResolverInterface
{
    private SubscriptionProductFactory $subscriptionProductFactory;

    public function __construct(
        SubscriptionProductFactory $subscriptionProductFactory
    ) {
        $this->subscriptionProductFactory = $subscriptionProductFactory;
    }

    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        if (empty($value['model'])) {
            return null;
        }

        $product = $value['model'];

        // Subscription options only exist for simple/virtual products. Skip the
        // lookup entirely for other types to keep listing/PDP queries cheap.
        $typeId = method_exists($product, 'getTypeId') ? $product->getTypeId() : null;
        if ($typeId !== null && !in_array($typeId, ['simple', 'virtual'], true)) {
            return null;
        }

        try {
            $subscriptionProduct = $this->subscriptionProductFactory->create()
                ->fromProductId($product->getId());

            if (!$subscriptionProduct->isSubscriptionProduct()) {
                return null;
            }

            return [
                'is_subscription' => true,
                'interval' => $subscriptionProduct->getInterval(),
                'interval_count' => (int) $subscriptionProduct->getIntervalCount(),
                'trial_days' => (int) $subscriptionProduct->getTrialDays(),
                'initial_fee' => (float) $subscriptionProduct->getInitialFeeAmount(),
                'formatted_interval' => $subscriptionProduct->getFormattedInterval(),
                'frequency_label' => (string) $subscriptionProduct->getFrequencyLabel(),
            ];
        } catch (\Throwable $e) {
            // A non-subscription product (or any lookup failure) should never
            // break the surrounding product query — degrade to "no subscription".
            return null;
        }
    }
}
