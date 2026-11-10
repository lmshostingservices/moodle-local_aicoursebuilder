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
 * Plan review: keeps the editable plan, the JSON and the credit estimate in step.
 *
 * @module     local_aicoursebuilder/review
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
/* global define */
define([], function() {
    "use strict";

    const fmt = n => Number(n).toLocaleString();

    const init = () => {
        const root = document.querySelector(".aicb-review");
        const form = document.getElementById("aicb-review-form");
        const json = document.getElementById("id_planjson");
        if (!root || !form || !json) {
            return;
        }
        let plan;
        try {
            plan = JSON.parse(json.value);
        } catch (e) {
            return;
        }
        let jsonEdited = false;
        json.addEventListener("input", () => {
            jsonEdited = true;
        });
        const courselevel = JSON.parse(root.dataset.courselevel || "{}");
        const unitCount = Number(root.dataset.units || 0);

        const sync = () => {
            if (!jsonEdited) {
                json.value = JSON.stringify(plan);
            }
        };

        const strings = JSON.parse(root.dataset.strings || "{}");
        const fill = (text, values) => Object.keys(values).reduce((t, k) => t.split("{$a->" + k + "}").join(values[k]), text || "");
        // Compare each lesson's activity time with its session, so a thin lesson is easy to spot.
        const lessonTimes = () => {
            form.querySelectorAll("[data-lesson-time]").forEach(box => {
                const session = Number(box.dataset.lessonTime) || 0;
                let minutes = 0;
                box.closest("[data-lesson]").querySelectorAll(".aicb-pill-check:checked").forEach(input => {
                    minutes += Number(input.dataset.minutes) || 0;
                });
                box.textContent = fill(strings.time, {minutes: minutes, session: session});
                const short = session > 0 && minutes < session / 2;
                box.classList.toggle("is-short", short);
                if (short) {
                    box.textContent += " " + (strings.short || "");
                }
            });
        };
        const recalc = () => {
            lessonTimes();
            const counts = {};
            const credits = {};
            const add = (component, price) => {
                counts[component] = (counts[component] || 0) + 1;
                credits[component] = (credits[component] || 0) + (Number(price) || 0);
            };
            form.querySelectorAll(".aicb-pill-check:checked").forEach(input => add(input.dataset.tool, input.dataset.price));
            Object.keys(courselevel).forEach(component => {
                for (let i = 0; i < unitCount; i++) {
                    add(component, courselevel[component]);
                }
            });
            let totalCount = 0;
            let totalCredits = 0;
            form.querySelectorAll("[data-row]").forEach(row => {
                const component = row.dataset.row;
                const count = counts[component] || 0;
                row.hidden = count === 0;
                row.querySelector("[data-count]").textContent = fmt(count);
                row.querySelector("[data-credits]").textContent = fmt(credits[component] || 0);
                totalCount += count;
                totalCredits += credits[component] || 0;
            });
            form.querySelector('[data-aicb="total-count"]').textContent = fmt(totalCount);
            form.querySelector('[data-aicb="total-credits"]').textContent = fmt(totalCredits);
            const stat = root.querySelector('[data-aicb-stat="credits"]');
            if (stat) {
                stat.textContent = fmt(totalCredits);
            }
        };

        form.querySelectorAll("[data-unit-title]").forEach(input => {
            input.addEventListener("input", () => {
                plan.units[Number(input.dataset.unitTitle)].title = input.value;
                sync();
            });
        });
        form.querySelectorAll("[data-lesson-title]").forEach(input => {
            input.addEventListener("input", () => {
                plan.lessons[Number(input.dataset.lessonTitle)].title = input.value;
                sync();
            });
        });
        form.querySelectorAll(".aicb-pill-check").forEach(input => {
            input.addEventListener("change", () => {
                const lesson = plan.lessons[Number(input.closest("[data-lesson]").dataset.lesson)];
                const tools = new Set(lesson.tools || []);
                if (!input.checked && tools.size <= 1) {
                    // A lesson needs at least one activity.
                    input.checked = true;
                    input.closest(".aicb-pill").classList.add("aicb-shake");
                    window.setTimeout(() => input.closest(".aicb-pill").classList.remove("aicb-shake"), 500);
                    return;
                }
                if (input.checked) {
                    tools.add(input.dataset.tool);
                } else {
                    tools.delete(input.dataset.tool);
                }
                lesson.tools = Array.from(tools);
                input.closest(".aicb-pill").classList.toggle("is-on", input.checked);
                sync();
                recalc();
            });
        });
        // Structural edits are saved straight away and the page reloads with fresh prices.
        const current = () => {
            if (jsonEdited) {
                try {
                    return JSON.parse(json.value);
                } catch (e) {
                    return plan;
                }
            }
            return plan;
        };
        const saveAndReload = (edited, anchor) => {
            json.value = JSON.stringify(edited);
            jsonEdited = true;
            form.querySelector('[data-aicb="action"]').value = "save";
            form.querySelector('[data-aicb="anchor"]').value = anchor || "";
            form.setAttribute("action", form.getAttribute("action").replace(/approve\.php.*$/, "review.php"));
            form.submit();
        };
        const lessonOf = el => plan.lessons[Number(el.closest("[data-lesson]").dataset.lesson)];
        const findLesson = (edited, number) => edited.lessons.find(l => Number(l.number) === Number(number));

        form.querySelectorAll("[data-lesson-notes]").forEach(input => {
            input.addEventListener("input", () => {
                lessonOf(input).notes = input.value;
                sync();
            });
        });
        const planNotes = form.querySelector("[data-plan-notes]");
        if (planNotes) {
            planNotes.addEventListener("input", () => {
                plan.teacherNotes = planNotes.value;
                sync();
            });
        }
        form.querySelectorAll("[data-remove-outcome]").forEach(button => {
            button.addEventListener("click", () => {
                const number = lessonOf(button).number;
                const edited = current();
                const lesson = findLesson(edited, number);
                lesson.outcomes = (lesson.outcomes || []).filter(k => k !== button.dataset.removeOutcome);
                saveAndReload(edited, "lesson-" + number);
            });
        });
        form.querySelectorAll("[data-add-outcome]").forEach(select => {
            select.addEventListener("change", () => {
                if (!select.value) {
                    return;
                }
                const number = lessonOf(select).number;
                const edited = current();
                const lesson = findLesson(edited, number);
                lesson.outcomes = Array.from(new Set([...(lesson.outcomes || []), select.value]));
                saveAndReload(edited, "lesson-" + number);
            });
        });
        form.querySelectorAll("[data-remove-lesson]").forEach(button => {
            button.addEventListener("click", () => {
                const number = Number(button.dataset.removeLesson);
                const edited = current();
                edited.lessons = edited.lessons.filter(l => Number(l.number) !== number);
                edited.units.forEach(u => {
                    u.lessons = (u.lessons || []).filter(n => Number(n) !== number);
                });
                saveAndReload(edited, "");
            });
        });
        form.querySelectorAll("[data-add-lesson]").forEach(button => {
            button.addEventListener("click", () => {
                const edited = current();
                const unit = edited.units[Number(button.dataset.addLesson)];
                const number = edited.lessons.reduce((max, l) => Math.max(max, Number(l.number) || 0), 0) + 1;
                const last = findLesson(edited, (unit.lessons || []).slice(-1)[0]);
                edited.lessons.push({
                    number: number,
                    title: button.dataset.newTitle || "New lesson",
                    outcomes: [],
                    tools: last ? (last.tools || []).filter(t => t !== "mod_aianatomy") : ["mod_visuallearning"]
                });
                unit.lessons = [...(unit.lessons || []), number];
                saveAndReload(edited, "");
            });
        });
        form.querySelectorAll("[data-delete-outcome]").forEach(button => {
            button.addEventListener("click", () => {
                const key = button.dataset.deleteOutcome;
                const edited = current();
                edited.outcomes = edited.outcomes.filter(o => (o.code || o.text) !== key);
                edited.lessons.forEach(l => {
                    l.outcomes = (l.outcomes || []).filter(k => k !== key);
                });
                saveAndReload(edited, "");
            });
        });
        const addNew = form.querySelector("[data-add-outcome-new]");
        if (addNew) {
            addNew.addEventListener("click", () => {
                const text = form.querySelector("[data-new-outcome-text]").value.trim();
                const code = form.querySelector("[data-new-outcome-code]").value.trim();
                if (!text) {
                    form.querySelector("[data-new-outcome-text]").focus();
                    return;
                }
                const edited = current();
                edited.outcomes.push({code: code, text: text, strand: "General", weight: 1, tags: []});
                saveAndReload(edited, "");
            });
        }
        const saveButton = form.querySelector('[data-aicb="save"]');
        if (saveButton) {
            saveButton.addEventListener("click", () => saveAndReload(current(), ""));
        }

        form.addEventListener("submit", () => {
            sync();
            const button = form.querySelector('[data-aicb="approve"]');
            if (button) {
                button.classList.add("is-busy");
            }
        });
        recalc();
    };

    return {init};
});
