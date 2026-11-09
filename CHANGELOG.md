# Changelog

## 0.1.16 (2026-11-09)
- Complete the reviewed Course Builder planning and build flow, including consistent lesson normalisation and completion tracking for generated courses.
- Keep generated activity creation aligned with the selected plugin's native workflow and the current unit outcomes.

## 0.1.15 (2026-11-07)
- Normalize framework identifiers for LMS Labs services and scope Course Information and Mapping to each course unit's pasted outcomes.
- Replace retired Slides with AI Slide Flow, tidy non-VET lesson titles and sections, and preserve the existing VET completion defaults.

## 0.1.14 (2026-11-07)
- Enable Moodle activity completion for non-VET AI Course Builder courses so Course Report can show learner progress; keep VET defaults unchanged.

## 0.1.13 (2026-11-07)
- Load Moodle's HTTP library in scheduled tasks so native adapters can poll their asynchronous jobs.

## 0.1.12 (2026-11-07)
- Include stored curriculum context in non-VET activity-generation briefs so short lesson titles satisfy native tool input requirements; preserve the VET brief unchanged.

## 0.1.11 (2026-11-07)
- Accept native adapter save hooks that validate by throwing and return no value; still fail on an explicit false result.

## 0.1.10 (2026-11-07)
- Add privacy metadata, user-data export, and deletion for saved program plans and their plugin-owned records.
- Add Moodle Marketplace licensing and package documentation; make the adapter pass release validation.

## 0.1.9 (2026-11-07)
- Keep price-estimate failures safely retryable; mark a job uncertain only after the paid generation call begins.
- Preserve a short, redacted adapter error for diagnosis instead of replacing it with a generic uncertainty message.

## 0.1.8 (2026-11-07)
- Run queued native adapters as the teacher who approved the course, not Moodle's guest task user.

## 0.1.7 (2026-11-07)
- Call Moodle's `plugin_supports()` with its type, plugin name, and feature arguments so activity creation works on Moodle 5.2/5.3.

## 0.1.6 (2026-11-07)
- Include the user-entered program title in the planner request so a single-unit course receives the intended course name.
- Split explicit non-VET `Lesson topics:` lists into separate outcomes and lessons.
- Give non-VET lessons the selected teach-practise-check path by default; retain the existing VET tool-selection behavior.

## 0.1.5 (2026-11-06)
- Keep sample content optional in the course planner; only curriculum is required.

## 0.1.4 (2026-11-06)

- Enable Moodle view-completion only for generated lesson activities whose native module explicitly supports it, so Course Report can show real activity completion without treating course-level setup activities as learner work.

## 0.1.3 (2026-11-06)

- Add PDF/DOCX curriculum text extraction with format validation, size/page limits, explicit failures, and no AI-credit charge.
- Add country-, sector-, and state-aware curriculum level/framework suggestions while keeping values editable.
- Show build completion, failures, course/activity links, outcome coverage, and automatic progress refresh on the report page.

## 0.1.2 (2026-11-06)

- Create generated courses in AI Course Format and add all Moodle activities through Moodle's `add_moduleinfo()` API.
- Create one Course Information activity before lesson sections and one Mapping activity after them, rather than duplicating course-level tools per lesson.
- Attach native course-module IDs to jobs and sequence Course Information after lesson generation and Mapping after Course Information.
- Add a server-side API transport for native adapters that uses configured credentials and the central API URL.

## 0.1.1 (2026-11-05)

- Tag extracted outcomes for workplace safety and anatomy/physiology so the planner can select the matching native teaching activity.
- Store the outcome tags with the approved plan and reject Safety Hazards or AI Anatomy assignments when the lesson has no matching tagged outcome.
- Add AI Safety Hazards and AI Anatomy to the selectable Course Builder tool list. Their adapters remain unavailable until their native generation paths are implemented and verified.
