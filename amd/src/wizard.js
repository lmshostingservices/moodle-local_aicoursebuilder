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
 * AI Course Builder wizard: steps, suggestions and the live summary.
 *
 * @module     local_aicoursebuilder/wizard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
/* global define */
define([], function() {
    "use strict";

    const countryOptions = [
        "Australia", "Canada", "India", "Ireland", "Malaysia", "New Zealand", "Philippines", "Singapore", "South Africa",
        "Thailand", "United Arab Emirates", "United Kingdom", "United States", "International"
    ];
    const sectorOptions = ["School", "Tutoring centre", "VET / RTO", "College / further education", "Higher education",
        "Workplace", "Professional development (CPD)", "Short course", "Community / general interest"];
    const stateOptions = {
        Australia: ["National curriculum", "ACT", "New South Wales", "Northern Territory", "Queensland", "South Australia",
            "Tasmania", "Victoria", "Western Australia"],
        Canada: ["National curriculum", "Alberta", "British Columbia", "Manitoba", "New Brunswick", "Newfoundland and Labrador",
            "Nova Scotia", "Ontario", "Quebec", "Saskatchewan"],
        "New Zealand": ["National curriculum", "Auckland", "Canterbury", "Waikato", "Wellington"],
        Singapore: ["National curriculum"],
        "United Kingdom": ["National curriculum", "England", "Northern Ireland", "Scotland", "Wales"],
        "United States": ["National curriculum", "Alabama", "California", "Florida", "New York", "Texas"],
        International: ["National curriculum"]
    };
    const schoolLevels = {
        Australia: ["Foundation", ...Array.from({length: 12}, (_, index) => `Year ${index + 1}`)],
        Canada: ["Kindergarten", ...Array.from({length: 12}, (_, index) => `Grade ${index + 1}`)],
        "New Zealand": Array.from({length: 13}, (_, index) => `Year ${index + 1}`),
        Singapore: [
            ...Array.from({length: 6}, (_, index) => `Primary ${index + 1}`),
            ...Array.from({length: 5}, (_, index) => `Secondary ${index + 1}`),
            "Junior College 1",
            "Junior College 2"
        ],
        "United Kingdom": ["Reception", ...Array.from({length: 13}, (_, index) => `Year ${index + 1}`)],
        "United States": ["Kindergarten", ...Array.from({length: 12}, (_, index) => `Grade ${index + 1}`)],
        International: ["Primary", "Lower secondary", "Upper secondary", "Senior secondary"]
    };
    const sectorLevels = {
        "VET / RTO": ["Certificate I", "Certificate II", "Certificate III", "Certificate IV", "Diploma", "Advanced Diploma",
            "Graduate Certificate", "Graduate Diploma"],
        "Higher education": ["Foundation", "Undergraduate", "Postgraduate", "Doctoral"],
        Workplace: ["Induction", "Entry level", "Experienced practitioner", "Supervisor", "Manager", "Professional development"],
        "College / further education": ["Foundation", "Level 1", "Level 2", "Level 3", "Level 4", "Level 5", "Access to higher education"],
        "Professional development (CPD)": ["Introductory", "Intermediate", "Advanced", "Specialist"],
        "Short course": ["Beginner", "Intermediate", "Advanced", "All levels"],
        "Community / general interest": ["Beginner", "Intermediate", "Advanced", "All ages", "Adults", "Seniors"]
    };

    const fill = (list, values) => {
        if (!list) {
            return;
        }
        list.replaceChildren(...values.map(value => {
            const option = document.createElement("option");
            option.value = value;
            return option;
        }));
    };
    const val = id => {
        const el = document.getElementById(id);
        return el ? el.value.trim() : "";
    };
    const num = (id, fallback) => {
        const n = parseFloat(val(id));
        return Number.isFinite(n) ? n : fallback;
    };

    /**
     * Lesson estimate shown while the user types; the planner makes the final call.
     *
     * @returns {{lessons: number, hours: number}}
     */
    const pacing = () => {
        const minutes = Math.max(15, num("id_minutespersession", 60));
        const reserve = Math.min(40, Math.max(0, num("id_reservepercent", 15)));
        const selfpaced = document.getElementById("id_selfpaced");
        let sessions;
        if (selfpaced && selfpaced.checked && num("id_totallearnerhours", 0) > 0) {
            sessions = Math.floor(num("id_totallearnerhours", 0) * 60 / minutes);
        } else {
            sessions = Math.max(1, num("id_weeks", 8)) * Math.max(1, num("id_sessionsperweek", 2));
        }
        const lessons = Math.max(1, Math.round(sessions * (1 - reserve / 100)));
        return {lessons, hours: Math.round(sessions * minutes / 6) / 10};
    };

    const init = () => {
        const form = document.getElementById("aicb-wizard-form");
        if (!form) {
            return;
        }
        const country = document.getElementById("id_country");
        const sector = document.getElementById("id_sector");
        const framework = document.getElementById("id_framework");
        fill(document.getElementById("country_options"), countryOptions);
        fill(document.getElementById("sector_options"), sectorOptions);

        const updateLists = () => {
            const countryName = val("id_country");
            const sectorName = val("id_sector");
            fill(document.getElementById("state_options"), stateOptions[countryName] || ["National curriculum"]);
            fill(document.getElementById("level_options"), sectorLevels[sectorName] || schoolLevels[countryName] || []);
            const school = !sectorName || sectorName === "School" || sectorName === "Tutoring centre";
            let frameworks = ["custom"];
            if (countryName === "Singapore" && school) {
                frameworks = ["sg_moe", "custom"];
            } else if (countryName === "Australia" && school) {
                frameworks = ["au_curriculum", "custom"];
            } else if (countryName === "Australia" && sectorName === "VET / RTO") {
                frameworks = ["vet", "custom"];
            } else if (sectorName === "Higher education") {
                frameworks = ["university", "custom"];
            } else if (sectorName === "Workplace" || sectorName === "Professional development (CPD)") {
                frameworks = ["workplace", "custom"];
            }
            if (framework && framework.tagName === "SELECT") {
                const previous = framework.value;
                const labels = JSON.parse(framework.dataset.labels || "{}");
                framework.replaceChildren(...frameworks.map(value => {
                    const option = document.createElement("option");
                    option.value = value;
                    option.textContent = labels[value] || value;
                    return option;
                }));
                framework.value = frameworks.includes(previous) && previous !== "custom" ? previous : frameworks[0];
            }
            const vet = document.getElementById("aicb-vet-fields");
            if (vet && framework) {
                vet.hidden = framework.value !== "vet";
            }
        };

        if (framework) {
            framework.addEventListener("change", () => {
                const vet = document.getElementById("aicb-vet-fields");
                if (vet) {
                    vet.hidden = framework.value !== "vet";
                }
            });
        }
        const lessonsEl = form.querySelector('[data-aicb="lessons"]');
        const sum = (key, text) => {
            const el = form.querySelector(`[data-aicb-sum="${key}"]`);
            if (el) {
                el.textContent = text || "—";
            }
        };
        const updateSummary = () => {
            sum("where", [val("id_country"), val("id_state"), val("id_sector"), val("id_level")].filter(Boolean).join(" · "));
            sum("subject", val("id_subject"));
            const file = document.getElementById("id_curriculumfile");
            const words = val("id_curriculum").split(/\s+/).filter(Boolean).length;
            const parts = [];
            if (val("id_name")) {
                parts.push(val("id_name"));
            }
            if (words) {
                parts.push(`${words} words pasted`);
            }
            if (file && file.files && file.files.length) {
                parts.push(file.files[0].name);
            }
            const samplewords = val("id_samplecontent").split(/\s+/).filter(Boolean).length;
            if (samplewords) {
                parts.push(`${samplewords} words of sample content`);
            }
            sum("curriculum", parts.join(" · "));
            const selfpaced = document.getElementById("id_selfpaced");
            sum("time", selfpaced && selfpaced.checked
                ? `Self-paced · ${val("id_totallearnerhours") || "?"} hours`
                : `${val("id_weeks")} weeks · ${val("id_sessionsperweek")} × ${val("id_minutespersession")} min`);
            const p = pacing();
            sum("lessons", `About ${p.lessons}`);
            sum("hours", `${p.hours}`);
            if (lessonsEl) {
                lessonsEl.textContent = `About ${p.lessons} lessons`;
            }
            // A VET unit planned for far fewer hours than its nominal hours is an amount-of-
            // training risk: say so while the teacher can still change the time.
            const nominal = num("id_nominalhours", 0);
            const fw = document.getElementById("id_framework");
            const note = form.querySelector('[data-aicb="hoursnote"]');
            if (note) {
                note.hidden = !(fw && fw.value === "vet" && nominal > 0 && p.hours < nominal / 2);
                note.textContent = `You have planned about ${p.hours} learner hours for a unit with ${nominal} nominal hours.`
                    + " Check the amount of training is enough for your learners.";
            }
            const tools = Array.from(form.querySelectorAll(".aicb-tool-check:checked"))
                .map(input => input.closest(".aicb-tool").querySelector(".aicb-tool-name").firstChild.textContent.trim());
            sum("tools", tools.join(", "));
        };

        // Steps.
        let current = 1;
        const steps = Array.from(form.querySelectorAll(".aicb-step"));
        const stepper = Array.from(form.querySelectorAll(".aicb-stepper-item"));
        const back = form.querySelector('[data-aicb="back"]');
        const next = form.querySelector('[data-aicb="next"]');
        const submit = form.querySelector('[data-aicb="submit"]');
        const required = {
            1: ["id_level", "id_subject"],
            2: ["id_name"],
            3: [],
            4: []
        };
        const valid = step => {
            let ok = true;
            (required[step] || []).forEach(id => {
                const el = document.getElementById(id);
                const empty = !el || !el.value.trim();
                if (el) {
                    el.classList.toggle("is-invalid", empty);
                }
                ok = ok && !empty;
            });
            if (step === 2) {
                const file = document.getElementById("id_curriculumfile");
                const hasfile = file && file.files && file.files.length;
                const text = document.getElementById("id_curriculum");
                const empty = !val("id_curriculum") && !hasfile;
                text.classList.toggle("is-invalid", empty);
                ok = ok && !empty;
            }
            if (step === 4) {
                ok = ok && form.querySelectorAll(".aicb-tool-check:checked").length > 0;
            }
            return ok;
        };
        const show = step => {
            current = step;
            steps.forEach(section => {
                const on = Number(section.dataset.step) === step;
                section.hidden = !on;
                section.classList.toggle("is-current", on);
            });
            stepper.forEach(item => {
                const n = Number(item.dataset.step);
                item.classList.toggle("is-current", n === step);
                item.classList.toggle("is-done", n < step);
            });
            back.hidden = step === 1;
            next.hidden = step === 4;
            submit.hidden = step !== 4;
        };
        next.addEventListener("click", () => {
            if (valid(current)) {
                show(Math.min(4, current + 1));
            }
        });
        back.addEventListener("click", () => show(Math.max(1, current - 1)));
        stepper.forEach(item => item.addEventListener("click", () => {
            const target = Number(item.dataset.step);
            if (target < current || valid(current)) {
                show(target);
            }
        }));
        form.addEventListener("submit", event => {
            for (let step = 1; step <= 4; step++) {
                if (!valid(step)) {
                    event.preventDefault();
                    show(step);
                    return;
                }
            }
            submit.disabled = true;
            submit.classList.add("is-busy");
        });
        form.addEventListener("keydown", event => {
            if (event.key === "Enter" && event.target.tagName === "INPUT" && current < 4) {
                event.preventDefault();
                next.click();
            }
        });

        [country, sector].forEach(input => {
            if (input) {
                input.addEventListener("input", updateLists);
                input.addEventListener("change", updateLists);
            }
        });
        form.addEventListener("input", updateSummary);
        form.addEventListener("change", updateSummary);
        updateLists();
        updateSummary();
        show(1);
    };

    return {init};
});
