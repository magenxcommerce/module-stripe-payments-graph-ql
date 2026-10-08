# Magenx_StripePaymentsGraphQl

Companion GraphQL module for the **Stripe Payments** module
(`stripe/module-payments`). It surfaces Stripe's **subscription** data over
GraphQL for a headless storefront, leaving the upgradeable core module
untouched — the same split Magento uses for `Catalog` vs `CatalogGraphQl`.

The stock Stripe module's own `schema.graphqls` only covers payment
configuration and saved payment methods. Everything related to subscriptions
(product subscription options, a customer's subscriptions, cancellation) was
exposed to the Luma frontend through PHP blocks/controllers only. This module
adds it to the schema.

## What it adds

| GraphQL | Backing logic | Resolver |
| --- | --- | --- |
| `ProductInterface.stripe_subscription` | `Model\SubscriptionProduct` (reads `stripe_subscription_options`) | `ProductSubscription` |
| `CartItemInterface.stripe_subscription` | same, keyed off the quote item's product | `CartItemSubscription` |
| `Customer.stripe_subscriptions` | `Helper\Generic::getCustomerModel()->getAllSubscriptions()` | `CustomerSubscriptions` |
| `Mutation.cancelStripeSubscription(subscription_id)` | `Model\Subscription::cancel()` (with `ownsSubscriptionId` guard) | `CancelStripeSubscription` |

No database or core-schema change: subscription product options and customer
subscriptions are read from the existing tables / Stripe API exactly as the
Luma blocks do.

## Notes / constraints

- `stripe_subscription` is intentionally **not** added to lean listing
  fragments by the storefront — the resolver loads the product's subscription
  options, so it is used on the PDP and cart only (not category grids).
- `Customer.stripe_subscriptions` and the cancel mutation are gated on
  `$context->getExtensionAttributes()->getIsCustomer()`. `getCustomerModel()`
  resolves the customer id from the GraphQL/REST **user context** (not a PHP
  session), so it works for headless requests authenticated with a customer
  token.
- Subscription products are simple/virtual only (Magento + Stripe constraint),
  so the product resolver skips other product types.

## Install

```bash
composer require magenxcommerce/module-stripe-payments-graph-ql
bin/magento module:enable Magenx_StripePaymentsGraphQl
bin/magento setup:upgrade
bin/magento cache:flush
```

## Migrating from `StripeIntegration_PaymentsGraphQl`

This module used to ship from the storefront repository as `StripeIntegration_PaymentsGraphQl`
(PHP namespace `StripeIntegration\PaymentsGraphQl`). It was renamed so our code no
longer sits in Stripe's vendor namespace. The GraphQL schema (types, fields) is unchanged and
the module has no config or tables of its own, so nothing else carries over. On an existing install:

```bash
rm -rf app/code/StripeIntegration/PaymentsGraphQl     # or: composer remove <old package>
composer require magenxcommerce/module-stripe-payments-graph-ql
# drop any leftover 'StripeIntegration_PaymentsGraphQl' line from app/etc/config.php
bin/magento module:enable Magenx_StripePaymentsGraphQl
bin/magento setup:upgrade
bin/magento cache:flush
```
