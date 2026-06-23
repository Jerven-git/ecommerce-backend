# Admin Dashboard — Help Knowledge Base

This is the reference the assistant uses to answer admin questions about the shop
dashboard. Keep it accurate and concise — when the dashboard changes, update this file.

## What this dashboard is
A store admin panel for an e-commerce shop. From here an admin manages the catalogue,
content, sales, reports, and store configuration. The main area is at `/admin`.

## Finding your way around
The left sidebar groups everything into sections:
- **Overview** — Dashboard.
- **Catalog** — Products, Categories, Services, Service Categories.
- **Content** — Blog Posts, Blog Categories.
- **Sales** — Orders, Backorders, Discounts, Gift Cards, Commissions.
- **Reports** — Tax Report.
- **Settings** — Site Settings, Shipping, Tax, Payments.
- **Access** — Admin Users, Super Admin.

Some items only appear in certain cases: **Gift Cards** and **Commissions** show only when
their module is turned on in Site Settings → Modules. **Admin Users** and **Super Admin**
are visible to super-admins only.

## Dashboard home (`/admin`)
- **Stats cards** — total products, total orders, pending orders (what needs attention),
  total revenue, and pending revenue. Click a card to jump to Products or Orders.
- **Recent orders** — the latest orders with customer, order number, total, and live status.

---

# Catalog

## Products (`/admin/products`)
- **Add / edit a product** (opens a form): name (required), description, material,
  dimensions, price (required), and stock. Assign one or more **categories** with the tree
  picker. Upload **gallery images** (with alt text) and pick one as the hover preview.
- **Variants** — add options like Size or Colour with their own stock and images.
- **Shipping** — choose "By weight" (kg) or "By dimensions" (L×W×H in cm); the volume is
  worked out automatically. This drives the per-kg / per-CBM shipping rates.
- **Active/Inactive** toggle shows or hides it on the storefront.
- **Search & filter** by name/description and All / Active / Inactive. 15 per page.

## Categories (`/admin/categories`)
- **Add a category**: type a name and press Add. Categories nest to any depth
  (e.g. Watches → Vintage → 1960s) — add a child inline on any row.
- **Category tree**: rename inline, delete (this also removes its children), and
  **drag to reorder**. Expand/collapse with the arrow or the Expand all / Collapse all
  buttons; the search box jumps to a category and opens its branch.
- **Cover image**: "Edit cover" opens a modal to upload an image with an overlay-opacity slider.

## Services (`/admin/services`)
- The catalogue of non-product offerings (repairs, custom work, consultations).
- **List**: search by title/description, filter by All / Published / Draft and by category.
  A gold star marks featured services; drafts stay hidden from the site. 15 per page.
- **New / edit a service** (`/admin/services/new` or `/admin/services/{id}`):
  - Eyebrow, **title** (required), slug (auto from the title, editable), short description.
  - **Body** — a Markdown editor with Write / Split / Preview modes, a formatting toolbar,
    and fullscreen.
  - Optional **call-to-action** button (label + link), and **SEO** fields (title,
    description, social-share image, "hide from search" / noindex).
  - Sidebar: **cover image** + alt text, **Published** and **Featured** toggles, an optional
    **publish date** to schedule it, and the **category** picker. Delete lives here when editing.

## Service Categories (`/admin/service-categories`)
- Group related services. **New / edit** opens a modal: name, two gradient colours, an
  optional image with a colour-overlay slider, and a live preview. Each card shows the
  gradient, slug, and how many services use it. Deleting one leaves its services uncategorised.

---

# Content

## Blog Posts (`/admin/posts`)
- **List**: search by title/excerpt, filter by All / Published / Draft and by category.
  A gold star marks featured posts; drafts stay private. Edit or delete per row.
- **New / edit a post** (`/admin/posts/{id}`):
  - **Title** (required), slug (auto, editable), and a short excerpt for listings.
  - **Body** — Markdown editor with Write / Split / Preview, a toolbar, and fullscreen.
  - **SEO** fields (title, description, social-share image, noindex).
  - Sidebar: **cover image** + alt text, **Published** and **Featured** toggles, an optional
    **publish date** to schedule, **category**, and **author**. Delete lives here when editing.

## Blog Categories (`/admin/post-categories`)
- **New / edit** opens a modal: name, two gradient colours (picker or hex), an optional
  image with a colour-overlay slider, and a live preview. Each card shows the gradient, slug,
  and published-post count. Deleting one leaves its posts uncategorised.

---

# Sales

## Orders (`/admin/orders`)
- **Search** by customer name, email, or order number; **filter** by status (All, Pending,
  Processing, Shipped, Delivered, Cancelled, Backorder) — each shows a count.
- Each order card shows the customer, shipping address, items, and totals (subtotal, tax,
  shipping, any discount, total) plus a payment badge.
- **Actions**: change status via the dropdown; **Confirm / Undo payment** for cash-on-delivery
  orders; **Cancel & refund** a paid online order; **Ship order** to create a shipment (gives a
  tracking number and barcode) and then update the shipment status
  (Label created → In transit → Delivered → Returned).

## Backorders (`/admin/backorders`)
- Handles orders for out-of-stock items and collecting payment once stock returns.
- **Search** by customer; **filter** by status (Awaiting stock, Notified, Expired, Confirmed,
  Paid, Cancelled).
- **Actions** per card: **Send payment link** / **Resend link** to the customer,
  **Mark as paid**, or **Cancel** (the customer is emailed).
- **Settings** (top-right button): a master **Enable backorders** toggle and the
  **payment-link expiry** in hours.

## Discounts (`/admin/discounts`)
- Promo codes used at checkout. **Search** by code/description; filter by All / Active / Inactive.
- **Add / edit a discount**: code, description, **type** (percentage or fixed amount) and value,
  optional minimum order amount, maximum uses, and expiry date. Toggle **Active/Inactive** to
  turn it on or off at checkout. The table shows uses against the limit.

## Gift Cards (`/admin/gift-cards`)
- Only visible when the **Gift Cards** module is enabled (Site Settings → Modules).
- **Denominations** — the preset amounts customers can buy; add/edit amount + label and
  enable/disable each.
- **Issued gift cards** — every card issued, with code, recipient, value, remaining balance,
  and status (Pending payment, Active, Partially used, Fully used, Void). Search/filter, and
  **Create manually** to issue a card to a recipient.

## Commissions (`/admin/commissions`)
- Only visible when the **Commissions** module is enabled (Site Settings → Modules).
- Custom-work requests sent from the storefront. **Filter** by status (Pending, Reviewing,
  Quoted, Accepted, In progress, Delivered, Cancelled) and change status inline.
- **View** a request to see the customer's details, brief, budget, medium/size, deadline, and
  any reference image; add internal **admin notes**; or delete the request.

---

# Reports

## Tax Report (`/admin/tax-report`)
- Pick a **date range** and an optional order-status filter, then **Generate**.
- Shows tax collected **by region** (country/state): order count, ex-tax subtotal, tax
  collected, total, and the rate applied, with an overall totals row. **Export to CSV** for
  bookkeeping.

---

# Settings — set up once

## Site Settings (`/admin/settings`)
Open it from the sidebar — the page is titled **Site Settings** ("Manage your store's
appearance and content"). It controls how the storefront looks and reads, across five tabs:

- **General** — site name, logo, site icon (favicon), cart icon and footer logo (each with a
  size), shop currency, a header call-to-action button (link to a site page or custom URL), an
  above-footer banner, and social media links. Default SEO title/description, canonical URL and
  logo alt text live here too.
- **Appearance** — the theme: brand colours (primary, secondary, accent, and the "In stock"
  badge colour), heading and body fonts, and a background texture. Pick a ready-made **theme
  preset** to apply a whole colour-and-image look in one click.
- **Pages** — the wording and images on each public page, split into sub-tabs: **Home, About,
  Shop, Blog, Services, Contact**. The **Home** sub-tab is the biggest — hero banner, the
  Showcase, Watch & Shop and Best Sellers sections, plus the "How it works" steps, features,
  stats, a statement quote and the newsletter block. Each page also has a cover image and its
  own SEO fields (title, description, social-share image, cover alt text, and a "hide from
  search engines" toggle). (Showcase and Watch & Shop used to be their own tabs — they now live
  under **Pages → Home**.)
- **Popup** — the welcome popup shown to new visitors: turn it on, set a heading and body. You
  must choose a **Discount** to attach before you can enable it.
- **Modules** — switch whole areas of the site on or off: Shop, Blog, Services, About, Contact,
  Commissions, Gift cards. Turning a module off hides that page and redirects visitors to the
  homepage.

**Saving:** one **Save Changes** button at the bottom saves *all five tabs at once* — not just
the tab you're on. A confirmation dialog appears first. Use **Discard** to undo unsaved edits,
and you'll be warned if you try to leave with unsaved changes.

## Shipping (`/admin/shipping`)
- **General options**: a carrier preset (Australia Post or Generic) to pre-fill the rest, then
  Express delivery (flat or weight-tiered), Tracked/Registered mail, optional insurance (% of
  order with a minimum), and a **free-shipping threshold**.
- **Zones & rates** for four zones — Own city, Own state/province, Own country, and Other
  country (international). Each has a base rate, a per-kg rate, and a per-CBM rate, and can be
  switched on/off.
- **Store location** (country / state / city) is what decides which zone an order falls into.

## Tax (`/admin/tax-settings`)
- **Enable tax**, set the **rate** and a **label** (e.g. GST, Sales Tax), and choose
  **inclusive** (prices already include tax) or **exclusive** (added at checkout).
- Optional **regional rules**: an all-regions catch-all plus per-country rates, each with
  per-state overrides.
- A **calculation preview** shows how a sample price works out inclusive vs. exclusive.

## Payments (`/admin/payment-settings`)
- Enable/disable each method and enter its keys: **Cash** (on delivery/pickup), **Stripe**,
  **PayPal**, and **Square**. Card providers have a Sandbox/Live mode and a copy-only webhook
  URL to paste into the provider's dashboard.
- Secret keys are encrypted and never shown again — a saved one reads as "Configured", and
  leaving a secret blank keeps the existing value. This is multi-admin safe.

---

# Access

## Admin Users (`/admin/users`) — super-admins only
- Create, edit, and delete admin accounts. Set name, email, **role** (Admin or Super Admin),
  and password. You can't delete your own account.

## Super Admin (`/super-admin`) — super-admins only
- The elevated area for cross-store/super-admin tasks.

---

## General behaviour
- Drafts (posts/services) are private until published; a publish date can schedule them.
- Inactive products are hidden from the storefront but kept in the catalogue.
- Changes in Site Settings (branding, theme, page content, modules) affect the live storefront
  as soon as you save.
- Turning off a module in Site Settings → Modules both hides its admin/storefront page and
  redirects visitors who reach it to the homepage.
