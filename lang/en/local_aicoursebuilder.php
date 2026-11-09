<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * local_aicoursebuilder file.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

$string['activitycreationfailed'] = 'Moodle could not create the {$a} activity through its module API.';
$string['approvebuild'] = 'Approve plan and build course structure';
$string['buildblocked'] = 'Course activity generation is not ready for every selected plugin. No courses or activities were created.';
$string['buildprogress'] = 'Completed {$a->complete} of {$a->total} generation activities. {$a->failed} failed or blocked.';
$string['buildprogresslabel'] = 'Course build progress';
$string['country'] = 'Country';
$string['curriculumupload'] = 'Or upload a curriculum file';
$string['curriculumupload_help'] = 'PDF or DOCX, up to 5 MB. Extracted text is added to the curriculum field above.';
$string['curriculumuploadfailed'] = 'The curriculum file could not be read. Use a text-based PDF or DOCX file up to 5 MB.';
$string['emptygeneratorresult'] = 'The plugin reported completion without a result.';
$string['framework'] = 'Curriculum framework';
$string['generationfailed'] = 'The plugin did not return a valid generation job.';
$string['generatornotready'] = 'Course building is blocked because these plugin adapters are not ready: {$a}. No courses, activities, or credits were changed.';
$string['invalidapiurl'] = 'The AI Course Builder API URL must use HTTPS (HTTP is allowed only for localhost testing).';
$string['invalidestimate'] = 'A plugin returned an invalid credit estimate.';
$string['invalidjobstatus'] = 'The plugin returned an invalid generation status.';
$string['invalidplan'] = 'The plan is incomplete or invalid. Nothing was created.';
$string['language'] = 'Language';
$string['learnerprofile'] = 'Learner profile';
$string['learnerprofile_help'] = 'Describe the learners, accessibility needs, and prior knowledge. Do not include student-identifying information.';
$string['lessonsection'] = 'Lesson {$a->number} · {$a->title}';
$string['level'] = 'Year, grade, or qualification level';
$string['minutespersession'] = 'Minutes per session';
$string['missingcredentials'] = 'Configure LMS Labs Central Config with a Site ID and API key before planning.';
$string['newprogram'] = 'Start a new course plan';
$string['noresults'] = 'No course plans have been created yet.';
$string['notcreated'] = 'Not created yet';
$string['notyourprogram'] = 'You do not have access to this course plan.';
$string['openactivity'] = 'Open activity';
$string['plan'] = 'Plan course';
$string['planjson'] = 'Editable course plan (JSON)';
$string['planningfailed'] = 'Course planning failed: {$a}';
$string['planningnote'] = 'Planning does not use LMS Labs credits. Plugin-specific generation costs are separate and will be shown before activity generation is enabled.';
$string['pluginname'] = 'AI Course Builder';
$string['privacy:metadata:attempts'] = 'The number of generation attempts.';
$string['privacy:metadata:cmid'] = 'The Moodle course-module reference for a generated activity.';
$string['privacy:metadata:component'] = 'The Moodle plugin selected to generate an activity.';
$string['privacy:metadata:country'] = 'The selected country or education system.';
$string['privacy:metadata:courseid'] = 'The Moodle course created for a unit.';
$string['privacy:metadata:delivery'] = 'The requested delivery schedule and course-building preferences.';
$string['privacy:metadata:error'] = 'A safe error description associated with a build.';
$string['privacy:metadata:externaljobid'] = 'The generation job identifier returned by the activity plugin.';
$string['privacy:metadata:framework'] = 'Stores the curriculum framework and learner context submitted for a program.';
$string['privacy:metadata:jobs'] = 'Stores generation status and results associated with a program.';
$string['privacy:metadata:language'] = 'The requested language for generated materials.';
$string['privacy:metadata:learnerprofile'] = 'The learner profile submitted for planning.';
$string['privacy:metadata:lessons'] = 'Stores lesson titles, outcome links, tool choices, and build state.';
$string['privacy:metadata:level'] = 'The learner level selected for the program.';
$string['privacy:metadata:name'] = 'The name given to the program.';
$string['privacy:metadata:outcomecode'] = 'The curriculum code associated with an outcome.';
$string['privacy:metadata:outcomes'] = 'Stores the learning outcomes extracted from a program curriculum.';
$string['privacy:metadata:outcomesjson'] = 'The outcomes associated with a lesson.';
$string['privacy:metadata:outcometext'] = 'The text of a learning outcome.';
$string['privacy:metadata:plan'] = 'The generated or approved course plan.';
$string['privacy:metadata:program'] = 'Stores the creator-owned AI Course Builder program plan and its Moodle course build state.';
$string['privacy:metadata:resultjson'] = 'The result details returned by the activity plugin.';
$string['privacy:metadata:sectionid'] = 'The Moodle course section associated with a lesson.';
$string['privacy:metadata:sector'] = 'The selected education or training sector.';
$string['privacy:metadata:sortorder'] = 'The display order of the record.';
$string['privacy:metadata:sourcehash'] = 'A fingerprint used to detect duplicate source text.';
$string['privacy:metadata:sourcename'] = 'The name of a curriculum source.';
$string['privacy:metadata:sources'] = 'Stores curriculum and sample text submitted for program planning.';
$string['privacy:metadata:sourcetext'] = 'The curriculum or sample text submitted for planning.';
$string['privacy:metadata:spelling'] = 'The requested spelling convention.';
$string['privacy:metadata:state'] = 'The selected state or region.';
$string['privacy:metadata:status'] = 'The current planning or generation status.';
$string['privacy:metadata:strand'] = 'The curriculum strand associated with an outcome.';
$string['privacy:metadata:subject'] = 'The subject selected for the program.';
$string['privacy:metadata:tags'] = 'The tool-selection tags associated with an outcome.';
$string['privacy:metadata:timecreated'] = 'The time the record was created.';
$string['privacy:metadata:timemodified'] = 'The time the record was last changed.';
$string['privacy:metadata:title'] = 'The title of a lesson or unit.';
$string['privacy:metadata:toolsjson'] = 'The learning tools selected for a lesson.';
$string['privacy:metadata:unitnumber'] = 'The sequence number of a course unit.';
$string['privacy:metadata:units'] = 'Stores the course-unit titles and Moodle course references for a program.';
$string['privacy:metadata:userid'] = 'The Moodle account that created and owns the program.';
$string['privacy:metadata:weight'] = 'The importance weight assigned to an outcome.';
$string['programnotapprovable'] = 'This plan is not awaiting approval or has already been built.';
$string['programs'] = 'Your course plans';
$string['reportrefresh'] = 'This page refreshes every 10 seconds while the build is running.';
$string['reservepercent'] = 'Reserve for consolidation (%)';
$string['reviewplan'] = 'Review and edit plan';
$string['samplesource'] = 'Optional sample content';
$string['sector'] = 'Education sector';
$string['selfpaced'] = 'Self-paced';
$string['sessionsperweek'] = 'Sessions per week';
$string['sourcecurriculum'] = 'Curriculum or learning outcomes';
$string['sourcename'] = 'Source name';
$string['spelling'] = 'Spelling preference';
$string['state'] = 'State, territory, or National curriculum';
$string['status'] = 'Status';
$string['subject'] = 'Subject';
$string['summary'] = 'The approved plan creates hidden courses for review. It does not publish the Moodle site.';
$string['title'] = 'Program title';
$string['toolrequiresoutcometag'] = '{$a} can only be assigned to a lesson linked to a matching tagged outcome.';
$string['totallearnerhours'] = 'Total learner hours';
$string['weeks'] = 'Weeks';
// Redesigned builder pages.
$string['herotitle'] = 'Build a whole term of Moodle courses from your curriculum';
$string['herotext'] = 'Paste or upload your curriculum, set the time you have, and AI Course Builder plans the units and lessons. You check the plan and the credit estimate, then it builds one course per unit with ready-to-teach activities.';
$string['herofree'] = 'AI Course Builder is free. Planning uses no credits. The activities it creates use LMS Labs credits, and you see the estimate before you build.';
$string['yourplans'] = 'Your course plans';
$string['noplansyet'] = 'No course plans yet. Start your first plan to see it here.';
$string['openplan'] = 'Review plan';
$string['openprogress'] = 'Build progress';
$string['opencourse'] = 'Open course';
$string['coursescount'] = '{$a} courses';
$string['lessonscount'] = '{$a} lessons';
$string['outcomescount'] = '{$a} outcomes';
$string['updated'] = 'Updated {$a}';
$string['step1'] = 'Where you teach';
$string['step1desc'] = 'Country, sector and level shape the language, examples and curriculum codes.';
$string['step2'] = 'Your curriculum';
$string['step2desc'] = 'Paste the syllabus or learning outcomes, or upload a PDF or Word file.';
$string['step3'] = 'Time and pacing';
$string['step3desc'] = 'Tell us how much time you have. The lesson count follows from it.';
$string['step4'] = 'Activities';
$string['step4desc'] = 'Choose which LMS Labs activities the builder may use.';
$string['next'] = 'Next';
$string['back'] = 'Back';
$string['planmycourse'] = 'Plan my course';
$string['planningfree'] = 'Planning is free. No credits are used until you approve the build.';
$string['summarycard'] = 'Your plan so far';
$string['aboutlessons'] = 'About {$a} lessons';
$string['lessonsfromtime'] = 'Worked out from the weeks, sessions and reserve you set.';
$string['learnerhours'] = 'Learner hours';
$string['estimatedlessons'] = 'Estimated lessons';
$string['taggedonly'] = 'Only where an outcome needs it';
$string['default'] = 'Default';
$string['notinstalled'] = 'Not installed';
$string['softskillsmode'] = 'Soft Skills and Branched Scenario';
$string['scenariosmode'] = 'Safety Hazards and Anatomy';
$string['mode_auto'] = 'When an outcome needs it';
$string['mode_always'] = 'In every lesson';
$string['mode_never'] = 'Never';
$string['example_title'] = 'e.g. Secondary 1 Geography, Term 2';
$string['example_subject'] = 'e.g. Geography';
$string['example_curriculum'] = 'Paste the syllabus, unit outline or learning outcomes here.';
$string['example_sample'] = 'Optional: paste a worksheet or notes in the style you want the lessons to follow.';
$string['reviewintro'] = 'Check the plan before anything is built. You can rename lessons and switch activities on or off. Nothing is created until you approve.';
$string['outcomes'] = 'Learning outcomes';
$string['courses'] = 'Courses';
$string['lessons'] = 'Lessons';
$string['estimatedcredits'] = 'Estimated credits';
$string['creditestimate'] = 'Credit estimate';
$string['creditestimatedesc'] = 'What each LMS Labs plugin will use to create its activities. The final charge is never more than the approved price.';
$string['plugin'] = 'Plugin';
$string['activities'] = 'Activities';
$string['credits'] = 'Credits';
$string['total'] = 'Total';
$string['perunit'] = 'Once per course';
$string['coursecontents'] = 'Each course also gets Course Information first and Mapping last.';
$string['editjson'] = 'Advanced: edit the plan as JSON';
$string['approvenote'] = 'Approving creates a hidden category and one hidden course per unit for you to review. Students see nothing until you show the courses.';
$string['unitn'] = 'Course {$a}';
$string['lessonn'] = 'Lesson {$a}';
$string['statuslabel'] = 'Status';
$string['done'] = 'Done';
$string['working'] = 'Working';
$string['waitingtostart'] = 'Waiting';
$string['needsattention'] = 'Needs attention';
$string['buildheading'] = 'Building your courses';
$string['buildcomplete'] = 'Your courses are ready';
$string['buildcompletedesc'] = 'Every activity has been created. The courses are hidden until you show them to students.';
$string['buildrunning'] = 'Activities are being created in the background. You can leave this page; the build carries on.';
$string['outcomecoverage'] = 'Outcome coverage';
$string['taughtin'] = 'Taught in';
$string['assessedby'] = 'Assessed by';
$string['notmapped'] = 'Not mapped';
$string['notassessed'] = 'Not assessed yet';
$string['backtoplans'] = 'All course plans';
$string['courselevel'] = 'Course-level';
$string['status_draft'] = 'Draft';
$string['status_planned'] = 'Ready to review';
$string['status_building'] = 'Building';
$string['status_complete'] = 'Complete';
$string['status_failed'] = 'Needs attention';
$string['status_blocked'] = 'Blocked';
$string['status_queued'] = 'Waiting';
$string['status_generating'] = 'Creating';
$string['status_polling'] = 'Creating';
$string['status_waiting'] = 'Waiting';
$string['status_retry'] = 'Retrying';
$string['status_other'] = 'In progress';
$string['tool_mod_visuallearning'] = 'Visual Learning';
$string['tool_mod_contentcreator'] = 'Content Creator';
$string['tool_mod_productexplainer'] = 'Slide Flow';
$string['tool_mod_aiactivities'] = 'Learning Activities';
$string['tool_mod_aiknowledgecheck'] = 'Knowledge Check';
$string['tool_mod_aisoftskills'] = 'Soft Skills';
$string['tool_mod_aibranchedscenario'] = 'Branched Scenario';
$string['tool_mod_aisafetyhazards'] = 'Safety Hazards';
$string['tool_mod_aianatomy'] = 'Anatomy';
$string['tool_mod_courseinfo'] = 'Course Information';
$string['tool_mod_learningmapping'] = 'Mapping';
$string['tooldesc_mod_visuallearning'] = 'Illustrated lesson pages with diagrams and narration. The default teaching activity.';
$string['tooldesc_mod_contentcreator'] = 'Interactive reading for lessons with a lot of text.';
$string['tooldesc_mod_productexplainer'] = 'AI Slide Flow: structured training slides with optional voiceover.';
$string['tooldesc_mod_aiactivities'] = 'Drag-and-drop, matching and practice tasks after each lesson.';
$string['tooldesc_mod_aiknowledgecheck'] = 'A quick quiz on the lesson\'s outcomes, with feedback.';
$string['tooldesc_mod_aisoftskills'] = 'Role-play practice for communication and teamwork outcomes.';
$string['tooldesc_mod_aibranchedscenario'] = 'Decision scenarios for judgement calls.';
$string['tooldesc_mod_aisafetyhazards'] = 'Spot-the-hazard scenes for safety outcomes.';
$string['tooldesc_mod_aianatomy'] = 'Labelled body diagrams for anatomy outcomes.';
$string['mappingsectionname'] = 'Course mapping';
