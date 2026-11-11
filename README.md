# AI Course Builder

Release: 1.0.6
Numeric version: 2026111104

AI Course Builder helps teachers turn curriculum material into a reviewed Moodle course plan. Teachers choose the country, level, subject, delivery schedule and learning tools, then review the generated plan before approving course and activity creation.

## Summary

Prepare structured Moodle courses from curriculum text or supported PDF and DOCX files, with teacher review before the course is created.

## Description

The plugin stores each teacher's plans and curriculum sources in Moodle, creates the approved course structure, and sends activity generation to each selected Moodle plugin's own native workflow. It estimates the selected tools' credit prices before generation; the Course Builder plugin itself has no unlock price.

## Features

- Plan courses from curriculum text or supported PDF and DOCX files.
- Review outcomes, units, lessons and learning-tool choices before approval.
- Create Moodle categories, courses, sections and native activity instances.
- Track each activity's generation status and result in the build report.
- Preserve each activity plugin's own pricing, AI route, storage and retry safeguards.

## Requirements

- Moodle 4.4 or later.
- A teacher account with the Course Builder capability and permission to create courses.
- Install the Moodle activity plugins required by the selected plan.
- LMS Labs service access for planning and any selected generation tools; each tool's own server-side price applies.

## Installation

- Install the ZIP through Moodle's plugin installer or place the extracted `aicoursebuilder` directory in `local/aicoursebuilder`.
- Complete the Moodle database upgrade.
- Grant the Course Builder capability to the teacher roles that need to create course plans.
- Install and configure the activity plugins that teachers will select.

## Licence

GNU GPL v3 or later.
