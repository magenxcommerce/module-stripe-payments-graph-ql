<?php

declare(strict_types=1);

namespace Magenx\StripePaymentsGraphQl\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\BatchRequestItemInterface;
use Magento\Framework\GraphQl\Query\Resolver\BatchResolverInterface;
use Magento\Framework\GraphQl\Query\Resolver\BatchResponse;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use StripeIntegration\Payments\Model\SubscriptionProductFactory;

/**
 * Resolves CartItemInterface.stripe_subscription.
 *
 * Mirrors ProductSubscription but keyed off the quote item's product, so the
 * cart drawer / cart page / checkout summary can label recurring lines and
 * show the trial / initial-fee that will be charged. Returns null for ordinary
 * (non-subscription) lines.
 *
 * A BATCH resolver on purpose: this field rides CART_ITEM_FIELDS, so a plain
 * ResolverInterface ran one `fromProductId()` subscription-option lookup PER
 * cart line on every mini-cart open / checkout — the same per-product fan-out
 * the storefront avoids on grids (custom_attributesV2 removal), but on a hot,
 * latency-sensitive interaction. As a BatchResolverInterface Magento hands it
 * every line in one call, so the lookup is memoized per DISTINCT product id
 * (same product on multiple lines resolves once) and the resolver is invoked
 * once total instead of once per line. Do NOT revert it to ResolverInterface.
 */
class CartItemSubscription implements BatchResolverInterface
{
    private SubscriptionProductFactory $subscriptionProductFactory;

    public function __construct(
        SubscriptionProductFactory $subscriptionProductFactory
    ) {
        $this->subscriptionProductFactory = $subscriptionProductFactory;
    }

    /**
     * @param ContextInterface $context
     * @param Field $field
     * @param BatchRequestItemInterface[] $requests
     * @return BatchResponse
     */
    public function resolve(ContextInterface $context, Field $field, array $requests): BatchResponse
    {
        $response = new BatchResponse();

        // Memoize the resolved subscription payload per distinct product id, so
        // the same product on multiple cart lines is looked up only once and the
        // whole batch pays at most one lookup per distinct product.
        $byProduct = [];

        foreach ($requests as $request) {
            $productId = $this->productIdOf($request);

            if ($productId === null) {
                $response->addResponse($request, null);
                continue;
            }

            if (!array_key_exists($productId, $byProduct)) {
                $byProduct[$productId] = $this->subscriptionPayload($productId);
            }

            $response->addResponse($request, $byProduct[$productId]);
        }

        return $response;
    }

    /**
     * The product id of the quote item carried on a batch request, or null.
     *
     * @param BatchRequestItemInterface $request
     * @return int|null
     */
    private function productIdOf(BatchRequestItemInterface $request): ?int
    {
        $value = $request->getValue();
        if (empty($value['model'])) {
            return null;
        }

        $quoteItem = $value['model'];
        $productId = method_exists($quoteItem, 'getProductId') ? $quoteItem->getProductId() : null;
        if (!$productId && method_exists($quoteItem, 'getProduct') && $quoteItem->getProduct()) {
            $productId = $quoteItem->getProduct()->getId();
        }

        return $productId ? (int) $productId : null;
    }

    /**
     * Subscription option payload for a product id, or null when it is not a
     * subscription product (or the lookup fails).
     *
     * @param int $productId
     * @return array<string, mixed>|null
     */
    private function subscriptionPayload(int $productId): ?array
    {
        try {
            $subscriptionProduct = $this->subscriptionProductFactory->create()
                ->fromProductId($productId);

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
            return null;
        }
    }
}
