# Changelog

## 1.0.5 (2026-11-11)
- Fix: Soft Skills, Safety Hazards and AI Anatomy activities need the plugin to be unlocked on the site (in its settings, "Check access"). Until then learners only see "not activated", but the builder still offered these tools and paid for activities nobody could play. A locked plugin now shows "Needs setup" with how to unlock it. If a plugin becomes locked after approval, that activity stops before anything is charged.

## 1.0.4 (2026-11-11)
- Fix: Visual Learning, Slide Flow and Learning Activities used Australian English narration and Australian pictures for every course. They now follow the course: Australia, the UK, the US and India use their own English, and their pictures reflect that country. Other countries get English in the chosen spelling with neutral, international pictures.

## 1.0.3 (2026-11-10)
- Fix: the build now never charges more than the price the teacher approved. Each activity remembers its approved price. If the plugin's price has gone up since approval, nothing is charged and the activity says so; "Try again" builds it at the new price.
- New: every activity is told how long the lesson's session is (or, for self-paced courses, the learner hours shared across the lessons), so tools can size their activities. The review page prices activities for that session length.

## 1.0.2 (2026-11-10)
More control for the teacher, courses for every sector, and a safe fallback for older activity plugins.
- Fix: an activity plugin that is installed but has no AI Course Builder support yet (for example Knowledge Check 1.6.0) shows "Needs update" and stays unticked. Before this, the teacher could pick it and saving the plan failed with "plugin adapters are not ready".
- New: on the review page the teacher can add or remove lessons, add or remove an outcome on any lesson, add their own learning outcomes and delete ones they don't want, and save changes before approving. No more editing the plan as JSON.
- New: "Your instructions for the AI" (for the whole build) and "Instructions for this lesson". Every activity receives them.
- New: any activity can be added to any lesson, including Soft Skills, Branched Scenario, Safety Hazards and Anatomy, which were only offered on lessons with a tagged outcome.
- New: the wizard remembers each teacher's chosen activities and ticks them next time.
- New: the teacher picks the date the courses start and the course format (AI Course Format, Topics, Weeks or any enabled format). Before this, courses always started on the day of approval in AI Course Format.
- New: each activity can go "In every lesson" or "Where it fits". Before this, an activity the teacher ticked, such as Slide Flow, was only used if the planner chose it.
- New: sectors for College / further education, Professional development (CPD), Short course and Community / general interest, with matching levels, plus more countries. The sector, level and framework are free to type for anything else.
- Fix: activity plugins now receive the course's framework, so Learning Activities uses its general route for school, university, workplace and short courses instead of always the VET route.
- A lesson always keeps at least one activity.
- Fix (critical): approving a plan with more than one course failed with a database error, because the second course's sections were numbered after the first course's lessons. Each course now numbers its lessons from 1.
- New: teachers with a category-level course creator role can build. They choose the category the courses go in; a new private category is made only where they may manage categories. The program's own category is now hidden.
- New: the plan is checked against the teacher's curriculum before review. Outcome codes (performance criteria, content descriptions, LO numbers) and the teacher's own wording are kept; coded outcomes replaced by topic names are restored; any outcome the plan left out is added to a lesson; lesson titles cut off with "…" are completed; and the course takes the name the teacher typed. The review page lists every change.
- New: a long sample adds Content Creator only to the lessons it matches, unless the teacher chose it for every lesson.
- New: lesson cards in AI Course Format get a picture from the lesson's generated content. A card image the teacher set is never replaced.
- New: each lesson on the review page shows its activity time against the session length, with a hint when it is less than half.
- New: AI Anatomy shows "Needs setup" when the site's last check says it is locked.
- Fix: an error inside Moodle before anything is sent to LMS Labs is reported as "nothing was sent and no credits were used", not as "uncertain".
- Fix: the build report numbers lessons per course, as the courses do.

## 1.0.1 (2026-11-10)
Quality release from a full test build on Moodle 5.3 with every activity type, plus regression on 4.5 and 5.2.
- Fix: approving a plan no longer fails when an Anatomy lesson names no single body part; that lesson uses Visual Learning instead.
- Fix: one activity that cannot be generated (not unlocked, not set up, service error) no longer stops Course Information and Mapping. Failed activities are hidden from learners and left out of both.
- New: "Try again" on any failed activity in the build report. Course Information and Mapping are re-run so they include it.
- New: each lesson activity completes on its own learning rule (all correct, finished, listened to every page, viewed all slides, all scenes) instead of on a view.
- New: courses get real start and end dates, and lesson activities are due at the end of their teaching week, so Course Report and parent reports can show who is behind.
- New: VET programs record the unit code, unit title and nominal hours and pass them to Course Information and Mapping. The wizard warns when planned hours are far below the nominal hours.
- New: the review page warns about learning outcomes that are not in any lesson.
- New: tools that need setup (for example AI scene drafts switched off) show "Needs setup" in the wizard instead of failing during the build.
- Fix: the plan keeps only the tools the teacher chose; it no longer adds Visual Learning, Learning Activities or Knowledge Check that were switched off.
- Fix: Course mapping is hidden from learners (teachers see it); uses the Moodle 5.2+ section API.
- Fix: Content Creator now receives the teacher's sample content; the schedule is sent to Course Information.
- Fix: a service job that never finishes is stopped after an hour and retried; timeouts and provider outages are retried.
- Fix: plain messages when a tool is not unlocked or set up ("nothing was generated or charged").
- Fix: page context and URL set on the approve, plan, progress and review pages (no "Coding problem" warnings).
- Fix: wizard labels for the Soft Skills and Branched Scenario settings; curriculum framework is a named dropdown with University and Workplace presets; larger sample box; summary shows sample word count.
- Fix: wording ("no unlock price"), singular counts, and a note that new courses stay hidden until the teacher shows them.

## 1.0.0 (2026-11-09)
First stable release, tested end to end on Moodle 4.5, 5.2 and 5.3 with the live LMS Labs service.
- Course Information is written against the curriculum outcomes the teacher pasted, quoted word for word, with the teaching context.
- Learning Outcome Mapping links each pasted outcome to the activities that teach it, marked introduced, practised or assessed, using the approved plan.
- Slide Flow replaces the retired Slides activity for short intro text.
- Curriculum frameworks map to the service's frameworks (training package to VET, Australian Curriculum to au_curriculum, anything else to custom); ASQA options appear only for VET.
- Duplicate lesson titles in a plan are merged and renumbered before building.
- Fix: the LMS Labs API client loads Moodle's HTTP library before use ("Class curl not found").
- Fix: a build that succeeds on retry clears the earlier error banner.

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
