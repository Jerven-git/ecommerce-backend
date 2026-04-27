<?php

namespace Database\Seeders;

use App\Models\Post;
use App\Models\PostCategory;
use Illuminate\Database\Seeder;

class BlogSeeder extends Seeder
{
    public function run(): void
    {
        $customerFeedback = PostCategory::create([
            'name' => 'Customer Feedback',
            'slug' => 'customer-feedback',
            'gradient_from' => '#3B82F6',
            'gradient_to' => '#8B5CF6',
            'sort_order' => 1,
        ]);

        $productManagement = PostCategory::create([
            'name' => 'Product Management',
            'slug' => 'product-management',
            'gradient_from' => '#EC4899',
            'gradient_to' => '#EF4444',
            'sort_order' => 2,
        ]);

        $roadmaps = PostCategory::create([
            'name' => 'Roadmaps',
            'slug' => 'roadmaps',
            'gradient_from' => '#F59E0B',
            'gradient_to' => '#EA580C',
            'sort_order' => 3,
        ]);

        $posts = [
            [
                'title' => 'How to Write SMART Goals: Complete Guide for Product Managers',
                'excerpt' => 'Master SMART goals for product managers: learn to write specific, measurable objectives with proven examples and actionable tips that drive results.',
                'category_id' => $productManagement->id,
                'is_featured' => true,
                'author_name' => 'Alex Turner',
                'body' => <<<MD
Setting goals is easy — setting goals that actually move the needle is hard. SMART goals give product managers a simple, battle-tested framework for writing objectives that teams can actually execute.

## What does SMART stand for?

- **Specific** — answer *what*, *why*, and *who*
- **Measurable** — define how you'll track progress
- **Achievable** — push the team, but stay grounded
- **Relevant** — tie to the bigger business outcome
- **Time-bound** — give it a deadline

## A weak goal vs. a SMART goal

> "Improve the onboarding experience."

That's a wish, not a goal. Here's the SMART version:

> "Reduce time-to-first-value during onboarding from 12 minutes to under 4 minutes by end of Q3, measured by activation analytics."

Notice how much easier this is to plan sprints around, report on in standups, and celebrate when it lands.

## Three tips that make SMART goals stick

1. Write them down where the team can see them daily.
2. Review them in every bi-weekly retro.
3. Kill or rewrite goals that stop being relevant — don't let them quietly expire.

If you take nothing else away: **a goal without a metric is a hope**. Give every objective a number.
MD,
            ],
            [
                'title' => 'Top 14 Feature Voting Tools for SaaS Companies',
                'excerpt' => 'Discover the top 14 feature voting tools for SaaS companies. Learn how to prioritize product features based on real customer feedback.',
                'category_id' => $customerFeedback->id,
                'is_featured' => true,
                'author_name' => 'Morgan Lee',
                'body' => <<<MD
Feature voting is one of the fastest ways to turn a messy backlog into a prioritized roadmap your customers already believe in. Here's a shortlist of tools that do it well.

## What makes a great feature voting tool?

- Public or private boards (or both)
- Automatic deduplication of near-identical requests
- Integrations with your CRM / support stack
- Status updates that close the feedback loop

## The shortlist

| Tool | Best for | Starting price |
| --- | --- | --- |
| Canny | Mid-market SaaS | Free – $400/mo |
| Productboard | Enterprise PMs | Paid tiers only |
| Featureos | Early-stage startups | Generous free tier |
| Upvoty | Bootstrapped teams | $15/mo |

Pick the one whose pricing matches your stage — the workflow is more important than the logo on the landing page.
MD,
            ],
            [
                'title' => '18 UserVoice Alternatives & Competitors for Managing Customer Feedback',
                'excerpt' => 'Top 18 UserVoice alternatives and competitors for managing customer feedback in 2026. See how the pricing, workflow, and integrations stack up.',
                'category_id' => $customerFeedback->id,
                'is_featured' => true,
                'author_name' => 'Jamie Chen',
                'body' => <<<MD
UserVoice has been around for over a decade, but it's not the right fit for every team. Whether you're priced out or just looking for a fresher workflow, there are strong alternatives worth a serious look.

## What to evaluate

1. **Pricing transparency** — can you self-serve, or is it sales-only?
2. **Time-to-first-value** — how fast can a new customer post feedback?
3. **Prioritization workflow** — votes alone aren't enough; you need scoring.
4. **Roadmap publishing** — closing the loop matters.

### Top picks

- **Canny** — polished board + roadmap publishing
- **Productboard** — deep prioritization framework
- **Upvoty** — simplest self-serve setup
- **Feature Upvote** — no-login voting, good for wide audiences

No tool is perfect. Pick the one that matches the messiest part of your current workflow.
MD,
            ],
            [
                'title' => 'Bug vs Feature: Understanding the Difference and Prioritizing Effectively',
                'excerpt' => 'Understand the difference between bugs and features in software development. Learn how to triage, prioritize, and communicate the distinction to stakeholders.',
                'category_id' => $productManagement->id,
                'is_featured' => false,
                'author_name' => 'Sam Rivera',
                'body' => <<<MD
"Is this a bug or a feature?" is one of the oldest debates in software. The honest answer: **it doesn't matter until you agree what you're shipping and why.**

## Working definitions

- **Bug** — the product does something you didn't intend.
- **Feature** — the product doesn't yet do something you intended.

That's it. Everything else is political.

## A useful triage trick

When a report comes in, ask:

1. Does this match the spec we wrote?
2. If yes, it's a feature request — route it to the roadmap.
3. If no, it's a bug — route it to the backlog and estimate by severity.

> Bugs lose you trust. Features earn you love. Triage accordingly.
MD,
            ],
            [
                'title' => '15 Best Upvoty Alternatives: Top Tools for Feedback & Changelog',
                'excerpt' => 'Upvoty alternatives for feedback and changelog management, including SaaS-friendly options with generous free tiers and fast setup.',
                'category_id' => $customerFeedback->id,
                'is_featured' => false,
                'author_name' => 'Priya Shah',
                'body' => <<<MD
Upvoty is a great tool, especially for bootstrapped teams — but it isn't the only option. Here's how its closest competitors stack up, and when to switch.

## When to switch

- You need tighter integrations with your CRM
- You want richer roadmap visualization
- Your audience wants to vote without signing up

## Alternatives worth considering

- **Canny** — the default recommendation for most mid-market teams
- **Nolt** — clean UX, transparent pricing
- **Productboard** — heavier, but bulletproof for enterprise
- **Feature Upvote** — no-login voting, perfect for public boards

There is no one "best" — just the one that fits where you are right now.
MD,
            ],
            [
                'title' => 'Top 15 Free Release Notes Software',
                'excerpt' => 'Top 15 free changelog tools for developers and founders to effectively broadcast product updates.',
                'category_id' => $roadmaps->id,
                'is_featured' => false,
                'author_name' => 'Chris Walker',
                'body' => <<<MD
A great changelog turns silent shipping into a growth channel. Here's a shortlist of free tools that make it effortless.

## Must-have features

1. Markdown support
2. Email / RSS / Slack delivery
3. Tagging (Bug / Feature / Improvement)
4. Public and in-app embeds

## Our shortlist

- **Headway** — minimalist, embeds nicely
- **Beamer** — marketing-friendly
- **ReleaseNotes.io** — self-serve, lightweight
- **Canny Changelog** — if you already use Canny

Pick one and commit. An inconsistent changelog is worse than no changelog at all.
MD,
            ],
            [
                'title' => '10 Best Nolt.io Alternatives for Feedback Management',
                'excerpt' => 'Explore the top Nolt alternatives for product feedback management in 2026, with pricing, integrations, and workflow breakdowns.',
                'category_id' => $customerFeedback->id,
                'is_featured' => false,
                'author_name' => 'Taylor Brooks',
                'body' => <<<MD
Nolt is a solid feedback board, but it's not for everyone. If you've outgrown it — or never quite clicked with it — here are the alternatives worth trying.

## Why teams move off Nolt

- Limited integrations
- Voting model feels too light for enterprise use cases
- Roadmap customization is thin

## Strong alternatives

- **Canny** — the all-rounder
- **Productboard** — for deep prioritization
- **Upvoty** — the cheaper cousin
- **Featureos** — open-source leaning
MD,
            ],
            [
                'title' => '25 Best Product Management Books for 2026',
                'excerpt' => 'Discover the top 25 best product management books for 2026 and gain essential insights for growth, strategy, and delivery.',
                'category_id' => $productManagement->id,
                'is_featured' => false,
                'author_name' => 'Jordan Reese',
                'body' => <<<MD
Books age quickly in tech, but the fundamentals don't. Here are the essential reads every PM should have on their shelf heading into 2026.

## The foundations

1. *Inspired* — Marty Cagan
2. *The Lean Product Playbook* — Dan Olsen
3. *Escaping the Build Trap* — Melissa Perri
4. *Continuous Discovery Habits* — Teresa Torres

## Strategy and leadership

- *Good Strategy, Bad Strategy* — Richard Rumelt
- *The Hard Thing About Hard Things* — Ben Horowitz

## Growth and data

- *Hacking Growth* — Sean Ellis
- *Lean Analytics* — Alistair Croll

Don't try to read all 25. Pick three from the list and finish them this quarter.
MD,
            ],
            [
                'title' => '8 Examples of Product Roadmaps in 2026',
                'excerpt' => 'Uncover product roadmap examples to guide your strategy in 2026, from release plans to Kanban boards, and align your team effectively.',
                'category_id' => $roadmaps->id,
                'is_featured' => false,
                'author_name' => 'Eva Martins',
                'body' => <<<MD
The best roadmap is the one your team actually uses. Here are eight formats that have stood the test of time — pick the one that fits your culture.

## The formats

1. **Now / Next / Later** — lightweight, outcome-focused
2. **Kanban board** — great for continuous delivery teams
3. **Release plan** — when shipping is time-boxed
4. **Theme-based** — when strategy is the story
5. **Objective-based (OKRs)** — when leadership needs alignment
6. **Gantt** — when cross-team dependencies dominate
7. **Story map** — when UX continuity matters
8. **No-dates public roadmap** — when you want to share *direction* without *promises*

> A roadmap is a hypothesis, not a contract.
MD,
            ],
            [
                'title' => 'Types of Feedback: Exploring Feedback Types with Examples',
                'excerpt' => 'Explore the 11 types of feedback, including positive, negative, and constructive, with real-world examples and when each works best.',
                'category_id' => $customerFeedback->id,
                'is_featured' => false,
                'author_name' => 'Noah Park',
                'body' => <<<MD
Not all feedback is equal. Knowing what *kind* you're getting tells you how to act on it.

## Common types

- **Positive** — reinforce what's working
- **Constructive** — specific, actionable improvement
- **Negative (unstructured)** — hardest to use, but often the most honest
- **Quantitative** — survey scores, NPS, CSAT
- **Qualitative** — interview quotes, support tickets

## How to use each

1. Positive — share it publicly; it's fuel for the team.
2. Constructive — route it to the backlog with context.
3. Negative — dig for the root cause before reacting.
4. Quantitative — trend it over time, not in isolation.
5. Qualitative — pair with quantitative to find themes.

> Feedback is a gift. How you unwrap it determines what you get.
MD,
            ],
        ];

        foreach ($posts as $p) {
            Post::create([
                'slug' => Post::generateUniqueSlug($p['title']),
                'title' => $p['title'],
                'excerpt' => $p['excerpt'],
                'body' => $p['body'],
                'author_name' => $p['author_name'],
                'category_id' => $p['category_id'],
                'is_published' => true,
                'is_featured' => $p['is_featured'],
                'published_at' => now()->subDays(random_int(1, 90)),
            ]);
        }
    }
}
