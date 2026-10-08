<?php

declare(strict_types=1);

namespace Magenx\StripePaymentsGraphQl\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use StripeIntegration\Payments\Helper\Convert;
use StripeIntegration\Payments\Helper\Currency;
use StripeIntegration\Payments\Helper\Generic;
use StripeIntegration\Payments\Helper\Subscriptions;

/**
 * Resolves Customer.stripe_subscriptions.
 *
 * The core module already loads the Stripe customer for the authenticated
 * Magento customer through Helper\Generic::getCustomerModel() (which resolves
 * the customer id from the GraphQL/REST user context, NOT the session — see
 * Helper\Generic::getCustomerId()), so the same code path works in a headless
 * GraphQL request. We map each \Stripe\Subscription into the storefront shape.
 */
class CustomerSubscriptions implements ResolverInterface
{
    private Generic $helper;
    private Subscriptions $subscriptionsHelper;
    private Currency $currencyHelper;
    private Convert $convertHelper;

    public function __construct(
        Generic $helper,
        Subscriptions $subscriptionsHelper,
        Currency $currencyHelper,
        Convert $convertHelper
    ) {
        $this->helper = $helper;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->currencyHelper = $currencyHelper;
        $this->convertHelper = $convertHelper;
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

        return $this->getSubscriptions();
    }

    /**
     * @return array[]
     */
    public function getSubscriptions(): array
    {
        try {
            $stripeCustomer = $this->helper->getCustomerModel();

            if (!$stripeCustomer || !$stripeCustomer->getStripeId()) {
                return [];
            }

            $subscriptions = $stripeCustomer->getAllSubscriptions();
            $result = [];

            foreach ($subscriptions as $subscription) {
                $mapped = $this->mapSubscription($subscription);
                if ($mapped !== null) {
                    $result[] = $mapped;
                }
            }

            return $result;
        } catch (\Throwable $e) {
            // Never fail the whole customer query because Stripe is unreachable.
            return [];
        }
    }

    private function mapSubscription($subscription): ?array
    {
        if (empty($subscription->id)) {
            return null;
        }

        $status = $subscription->status ?? 'unknown';
        $isActive = !in_array($status, ['canceled', 'incomplete', 'incomplete_expired'], true);

        [$rawAmount, $currency, $interval, $intervalCount] = $this->extractPlan($subscription);

        $amount = null;
        $formattedAmount = null;
        if ($rawAmount !== null && $currency) {
            $amount = $this->convertHelper->stripeAmountToMagentoAmount($rawAmount, $currency);
            $formattedAmount = $this->currencyHelper->formatStripePrice($rawAmount, $currency);
        }

        return [
            'subscription_id' => $subscription->id,
            'label' => (string) $this->subscriptionsHelper->generateSubscriptionName($subscription),
            'status' => $status,
            'status_label' => (string) $this->getStatusLabel($status),
            'is_active' => $isActive,
            'amount' => $amount,
            'currency' => $currency ? strtoupper($currency) : null,
            'formatted_amount' => $formattedAmount,
            'interval' => $interval,
            'interval_count' => $intervalCount,
            'current_period_end' => $this->getCurrentPeriodEnd($subscription),
            'created_at' => $this->getCreatedAt($subscription),
            'can_cancel' => $isActive,
            'order_increment_id' => $this->getMetadata($subscription, 'Order #'),
        ];
    }

    /**
     * Returns [rawStripeAmount, currency, intervalUnit, intervalCount].
     *
     * @return array{0: ?int, 1: ?string, 2: ?string, 3: ?int}
     */
    private function extractPlan($subscription): array
    {
        // Prefer the (deprecated but still populated) plan object; fall back to
        // the first subscription item's price for newer multi-price subs.
        if (!empty($subscription->plan) && !empty($subscription->plan->amount)) {
            return [
                (int) $subscription->plan->amount,
                $subscription->plan->currency ?? null,
                $subscription->plan->interval ?? null,
                isset($subscription->plan->interval_count) ? (int) $subscription->plan->interval_count : null,
            ];
        }

        if (!empty($subscription->items->data[0]->price)) {
            $price = $subscription->items->data[0]->price;
            $recurring = $price->recurring ?? null;

            return [
                isset($price->unit_amount) ? (int) $price->unit_amount : null,
                $price->currency ?? null,
                $recurring->interval ?? null,
                isset($recurring->interval_count) ? (int) $recurring->interval_count : null,
            ];
        }

        return [null, null, null, null];
    }

    private function getCurrentPeriodEnd($subscription): ?int
    {
        if (isset($subscription->current_period_end)) {
            return (int) $subscription->current_period_end;
        }

        if (!empty($subscription->items->data)) {
            foreach ($subscription->items->data as $item) {
                if (isset($item->current_period_end)) {
                    return (int) $item->current_period_end;
                }
            }
        }

        return null;
    }

    /**
     * Creation timestamp of the subscription at Stripe.
     *
     * `created` is set on every \Stripe\Subscription; `start_date` is the
     * fallback for older API versions that only carried the billing start.
     */
    private function getCreatedAt($subscription): ?int
    {
        if (isset($subscription->created)) {
            return (int) $subscription->created;
        }

        if (isset($subscription->start_date)) {
            return (int) $subscription->start_date;
        }

        return null;
    }

    private function getMetadata($subscription, string $key): ?string
    {
        if (isset($subscription->metadata->{$key})) {
            return (string) $subscription->metadata->{$key};
        }

        return null;
    }

    private function getStatusLabel(string $status): \Magento\Framework\Phrase
    {
        switch ($status) {
            case 'trialing':
            case 'active':
                return __('Active');
            case 'past_due':
                return __('Past Due');
            case 'unpaid':
                return __('Unpaid');
            case 'canceled':
                return __('Canceled');
            default:
                return __(ucwords(str_replace('_', ' ', $status)));
        }
    }
}
