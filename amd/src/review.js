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

        const recalc = () => {
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
