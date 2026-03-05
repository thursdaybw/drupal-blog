Where You Are Still Coupled

Now let’s be honest.

You still have coupling here:

if product_type === 'book'

But:

It is isolated.

It is contained.

It is inside one private method.

It is not leaking into domain.

That is acceptable at current scale.

The Real Future Steps

Not now.
Not tomorrow.
But when pressure appears.

When You Add a Second Product Type

You will:

Extract buildAspects() into strategy classes.

Introduce EbayProductAspectMapperInterface.

Remove the conditional.

Register mappers via tagged services.

That refactor will be trivial because you isolated the conditional.

You prepared the soil correctly.

When You Add a Second Marketplace

You will:

Implement MarketplacePublisherInterface for Etsy.

Add a publisher registry in application layer.

Route by marketplace key.

Your current interface already supports that.

When You Add Marketplace Selection

You’ll modify:

ListingPublishRequest
to include:

target_marketplace

Or your application use case will select the publisher.

The interface already supports this.

Now The Most Important Question

What is your next architectural move?

Not micro.

Macro.

Given your ambition,
what is the most important structural risk remaining?

It is not polymorphism.

It is this:

Where does product categorization live?

Right now:

Category ID is hardcoded in eBay adapter.

Policies are hardcoded.

Merchant location is hardcoded.

Those are marketplace configuration concerns.

That is the next real axis of volatility.

Not product type polymorphism.

The Correct Next Large Move

Do not touch aspect polymorphism.

Instead:

Extract marketplace configuration.

Create:

EbayMarketplaceConfiguration

Containing:

Default category ID

Policy IDs

Merchant location

Currency

Marketplace ID

Inject it into the publisher.

Remove magic constants.

That will:

Remove environment coupling

Make testing easier

Prevent config fossilization

Allow per-marketplace variations later

That is a higher-value architectural move than aspect plugins.

Summary

Your architecture now:

Clean layering

Proper dependency direction

DTO boundary

Interface boundary

Product semantics in application

Marketplace mapping isolated

The smell is controlled.

The system is evolvable.

The next meaningful refactor is configuration extraction, not product-type polymorphism.

## TODO

- Split review UI by listing bundle.
  - Current state: `generic` listings render through the book review form and
    show book-centric inputs (title/author/isbn/publisher).
  - Data risk: low, because save logic guards writes with `setFieldIfExists`.
  - UX debt: high enough to track, because the form is noisy and confusing for
    non-book listings.
  - Target state: bundle-specific review forms (or display-driven assembly) so
    each bundle only shows relevant fields.
