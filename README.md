# kubo-kolibri

**Curriculum-mapped bridge between KUBO and Kolibri — one interface, content comes to the student.**

## The Problem

Schools in low-resource contexts that use both a school management system and a content platform end up with two disconnected interfaces. Teachers must manually find content that matches their lesson plan. Students must navigate a content library designed for self-directed learners, not for a classroom following a curriculum.

## The Vision

KUBO knows *what* is being taught — subjects, topics, terms, assessments, which student is in which class. Kolibri knows *how* to teach it — exercises, videos, interactive content, all working offline on a Raspberry Pi.

**kubo-kolibri** connects them so that:

- A **student** opens KUBO and sees exercises and videos matched to what they're studying this week — no second login, no navigating Kolibri's menu
- A **teacher** assigns a topic and the relevant Kolibri content is already there — exercises for practice, videos for explanation
- **Progress flows back** into KUBO's reporting — the teacher sees one unified view of scores, attendance, and content mastery
- The **adaptive difficulty** engine serves the right exercise at the right level (the binary-search approach from the [original 2017 prototype](https://blog.learningequality.org/curriculum-mapping-ka-lite-in-the-gambia-aligning-content-to-facilitate-adoption-in-a-primary-f53f1dceed28))

## How It Works

```
┌─────────────────────────────────────────────────┐
│                  KUBO (Laravel)                  │
│                                                  │
│  Student Dashboard / Teacher View / Reports      │
│       ┌──────────────────────────────┐           │
│       │     kubo-kolibri bridge      │           │
│       │                              │           │
│       │  Curriculum ←→ Content Map   │           │
│       │  Embedded Renderer (iframe)  │           │
│       │  Progress Sync               │           │
│       │  Adaptive Engine             │           │
│       └──────────┬───────────────────┘           │
└──────────────────┼───────────────────────────────┘
                   │ REST API (localhost)
┌──────────────────┼───────────────────────────────┐
│              Kolibri (Django)                     │
│                                                  │
│  ContentNode API    Channel API    Progress API   │
│  Perseus Renderer   Video Player   HTML5 Apps     │
│                                                  │
│  All running on the same Raspberry Pi / server    │
└──────────────────────────────────────────────────┘
```

### What we build vs. what we reuse

**We reuse from Kolibri** (not reinvented):
- Content renderers — Perseus for Khan-style exercises, video player, HTML5 app container, PDF viewer
- Content packaging — channels, content nodes, metadata, difficulty tagging
- Offline content distribution — importing channels without internet
- The entire content library — Khan Academy, CK-12, PhET, and hundreds of openly licensed channels

**We build in kubo-kolibri:**
- **Curriculum mapping model** — links KUBO topics/subjects to Kolibri content nodes
- **Embedded content renderer** — serves Kolibri's renderers inside KUBO's UI via iframe with message passing
- **Progress sync** — reads Kolibri attempt/mastery logs, writes them into KUBO's assessment/reporting pipeline
- **Adaptive content engine** — given a topic and a student's level, selects the right content node
- **Teacher tools** — assign content to a class/topic, preview, override the automatic mapping

### Kolibri API surface used

| Kolibri Endpoint | Purpose |
|---|---|
| `ContentNodeViewset` | Browse and filter content by topic, kind, difficulty |
| `ContentNodeTreeViewset` | Navigate content hierarchy (topic → subtopic → exercise) |
| `ContentNodeSearchViewset` | Full-text search when mapping curriculum |
| `ChannelMetadataViewSet` | List available content channels |
| `ContentNodeProgressViewset` | Read student mastery and progress |
| `UserContentNodeViewset` | Personalized recommendations (next steps, resume) |

### Data model

```
curriculum_maps
├── id
├── school_id          → KUBO school
├── subject_id         → KUBO subject
├── topic_id           → KUBO topic (nullable — subject-level mapping)
├── kolibri_channel_id → Kolibri channel UUID
├── kolibri_node_id    → Kolibri content node UUID
├── content_kind       → exercise | video | html5 | document
├── display_order
└── mapped_by          → user who created the mapping

content_progress
├── id
├── user_id            → KUBO student
├── curriculum_map_id  → which mapping
├── kolibri_log_id     → reference to Kolibri attempt log
├── score              → normalized 0-100
├── completed          → boolean
├── time_spent         → seconds
└── synced_at
```

## Origin

This project continues work started in 2017 at The Swallow school in The Gambia, where KA Lite content was manually mapped to the Gambian Math curriculum to facilitate adoption in a primary school. The original approach — aligning content with lesson plans so teachers don't need to learn a new tool — proved effective. Nine years later, both platforms have matured:

- **KUBO** now supports multi-school deployments, configurable assessment types, grading scales, and school-level tenancy
- **Kolibri** (KA Lite's successor by Learning Equality) has a rich REST API, plugin architecture, content renderers, and hundreds of openly licensed content channels

The missing piece has always been the bridge.

## Tech Stack

- **KUBO side**: Laravel package, installable in any KUBO instance
- **Kolibri side**: Standard Kolibri installation, accessed via its REST API on localhost
- **Communication**: HTTP between Laravel and Django on the same machine (both on the Pi)
- **Content rendering**: Kolibri's built-in renderers served via iframe in KUBO's Blade/Vue templates
- **Progress sync**: Cron or event-driven polling of Kolibri's progress API

## Status

Early development. Architecture and curriculum mapping model in progress.

## License

MIT
