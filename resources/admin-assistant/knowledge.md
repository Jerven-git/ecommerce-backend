# Admin Dashboard — Help Knowledge Base

This is the reference the assistant uses to answer admin questions about the shop
dashboard. Keep it accurate and concise — when the dashboard changes, update this file.

## What this dashboard is
A store admin panel for an e-commerce shop. From here an admin manages the catalogue,
content, orders, and store settings. The main area is at `/admin`.

## Dashboard home (`/admin`)
- **Stats cards** — products count, incoming orders, what needs attention, and revenue.
  Click a card to jump to that section.
- **Recent orders** — the 5 latest orders with live status. "View all" opens the full list.

## Sidebar — day-to-day
- **Products & Categories** — the product catalogue.
- **Blog Posts & Blog Categories** — articles and their topics.
- **Services & Service Categories** — non-product offerings (repairs, custom work, consultations).
- **Orders & Backorders** — sales and out-of-stock follow-ups.
- **Discounts** — promo codes.
- **Commissions** — custom artwork/work requests from customers.

## Settings — set up once
Branding, page content, shipping, tax, and payments. Worth a pass before going live.

## Products (`/admin/products`)
- **Add a product**: name, price, stock count, photos. Assign categories so customers find it.
  Toggle active/inactive to show/hide it. Allow backorder so customers can buy when stock is 0.
- **Search & filter** by name, or narrow to active/inactive.
- **Catalogue table**: price, stock, category, status. Edit any row; pagination appears at the bottom.

## Categories (`/admin/categories`)
- **Add a category**: type a name and press Add. Categories can be nested
  (e.g. Watches → Vintage → 1960s).
- **Category tree**: rename, delete, or nest sub-categories. Expand/collapse with the arrow;
  search box jumps to one quickly.

## Blog posts (`/admin/posts`)
- **Write a post**: title, short summary, cover image, story. Has a live preview.
  Save as **draft** (private) or **publish** (shows on site). Pick a category; star to feature.
- **Search/filter** by name, draft/published, or category.
- **Posts table**: gold star = featured; drafts stay private.

## Blog categories (`/admin/post-categories`)
- **New topic**: name + two colours for a gradient tile. Posts show a coloured badge for their topic.
- **Topics list**: rename, recolour, remove; shows post count per topic.

## Services (`/admin/services`)
- **Add a service**: title, summary, full description, cover image. Save as draft until ready.
  Group under a service category.
- **Find a service**: search by name, filter by status/category. Drafts stay hidden until published.
- **Services table**: edit/delete per row; drafts don't appear on the site.

## Service categories (`/admin/service-categories`)
- **New group**: group related services under a heading. Edit/remove; shows service count per group.

## Orders (`/admin/orders`)
- **Filter orders** by what needs attention: pending payment, ready to ship, or awaiting customer.

## General behaviour
- Drafts (posts/services) are private until published.
- Inactive products are hidden from the storefront but kept in the catalogue.
- Changes in Settings (branding, shipping, tax, payments) affect the live storefront.
