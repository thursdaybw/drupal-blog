# Homepage Refactor Brief

This site should feel like a workshop entrance, not a default blog feed.

Right now the front page is still functioning like Drupal's promoted-content listing. That makes the site feel accidental. A first-time visitor should understand who Bevan is, what happens here, and where to go next within a few seconds.

The goal is not to make the site sound corporate or polished in a generic way. The goal is to make it feel deliberate, personal, and technically grounded.

## Core Positioning

Bevan's Bench is:

1. A builder's workshop
2. A public experiment log
3. A place for writing and media about building a more independent life

What it is not:

1. A generic personal blog
2. A content marketing funnel
3. A pile of post teasers with no editorial framing

## Site Structure Direction

The site should separate these concerns clearly:

1. `/`
   Custom homepage with curated sections
2. `/blog`
   Blog archive or article listing
3. `/linktree`
   Compact social and links landing page

The homepage should not be the full article feed.

## Tone Direction

Use a tone that is:

1. Personal
2. Thoughtful
3. Technically grounded
4. Slightly philosophical in moderation

Avoid:

1. Marketing hype
2. Startup language
3. Empty grand statements
4. Over-explaining the philosophy before explaining the work

The site should feel like a workshop run by a thoughtful builder.

## Homepage Priorities

The homepage needs to answer these questions in order:

1. Who is this?
2. What gets made here?
3. What should I click first?
4. Where can I follow along?

Chronological content should come after those answers, not before.

## Recommended Homepage Structure

### 1. Hero

This is the clearest possible statement of identity.

Suggested title:

Bevan's Bench

Suggested tagline:

Systems, experiments, and tools for a more independent life.

Suggested intro:

Bevan's Bench is where I build and document tools, workflows, media experiments, and small ventures aimed at greater independence.

Primary actions:

1. Start Here
2. Watch on YouTube
3. My Links

Notes:

1. Keep this short
2. Do not open with abstract philosophy
3. The hero should explain the work before it explains the worldview

### 2. What Happens at the Bench

This gives visitors a mental map of the site.

Use three columns or cards, not four.

Suggested sections:

1. Systems
   Tools, automation, workflows, and software experiments
2. Experiments
   Reselling, income systems, process testing, and practical trials
3. Writing and Media
   Blog posts, videos, conversations, and project updates

Notes:

1. Philosophy can be present in the copy, but it does not need its own top-level card yet
2. Three areas are easier to scan and feel more intentional than four

### 3. Start Here

This should replace the raw blog-feed feeling.

Purpose:

Show 3 to 6 curated entries that represent the best starting points.

These can be:

1. Featured blog posts
2. Key projects
3. Important experiments
4. Evergreen pieces that explain the site

Notes:

1. Use real featured content, not placeholder categories
2. This section should feel curated, not auto-dumped

### 4. Current Work

This section makes the site feel alive.

Suggested title:

Current Work

Example content types:

1. eBay listing automation
2. AI-assisted media tooling
3. Reselling systems
4. Open-source utilities

Notes:

1. Keep each item brief
2. This should read like active bench notes, not product marketing

### 5. About the Bench

Keep this short and human.

Suggested copy direction:

Bevan's Bench is named after the idea of a workshop bench: a place where things get built, tested, and improved. This site is where I share the systems I'm building, the experiments I'm running, and what they teach me.

Notes:

1. This section should reinforce the name
2. It should not become a manifesto

### 6. Follow Along

This is the homepage version of the link page.

Suggested destinations:

1. YouTube
2. Facebook
3. Blog
4. GitHub, later if it becomes a meaningful public destination

Notes:

1. Keep this compact
2. This should support `/linktree`, not replace it

## Linktree Direction

`/linktree` should remain a focused social landing page, not a second homepage.

It needs:

1. A stronger title treatment
2. A short tagline
3. A short intro
4. Cleaner visual identity
5. Tight vertical spacing

Suggested copy:

Title:

Bevan's Bench

Tagline:

Experiments in systems, tools, and creative independence.

Intro:

The quick way to find what I'm working on: videos, writing, experiments, and updates.

Suggested links:

1. YouTube Channel
2. Facebook Page
3. Blog
4. Podcast, if it becomes active

## Visual Direction

The current site has a usable palette, but it does not yet feel intentionally designed.

The refresh should:

1. Reduce empty header space
2. Improve contrast and hierarchy
3. Use stronger headline typography
4. Make cards feel editorial rather than generic
5. Make the homepage and link page feel related but not identical

The visual mood should be:

1. Warm
2. Slightly dusty
3. Thoughtful
4. Structured

Not:

1. Flat
2. Default-theme looking
3. Overly pastel without contrast

## Implementation Direction

This should be built in stages.

### Stage 1

1. Move the current frontpage listing behavior to `/blog`
2. Replace `/` with a custom homepage
3. Keep `/linktree` as a separate destination

### Stage 2

1. Add curated featured content to the homepage
2. Add a current-work section
3. Tighten the linktree page styling and copy

### Stage 3

1. Add analytics to `/linktree`
2. Add stronger editorial curation tools for homepage sections
3. Add richer follow destinations if they become real

## Content Guidance

When writing homepage copy:

1. Prefer concrete nouns over abstract values
2. Lead with work, then worldview
3. Keep sentences shorter than the current draft
4. Avoid repeating the same identity statement in multiple sections

Good:

I build tools, run experiments, and document what works.

Less good:

This is a space for sovereignty, creativity, intuition, and building a life outside conventional systems.

The second idea can still appear, but only after the visitor understands what the site actually contains.

## Immediate Next Step

The next implementation move should be:

1. Stop using Drupal's default frontpage view as the homepage
2. Create a real homepage
3. Make the article feed live at `/blog`

That change will do more for the site's perceived quality than any amount of styling applied to the existing front page feed.
