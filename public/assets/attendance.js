'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const summary = document.querySelector('[data-attendance-summary]');
    if (!summary) return;

    const form = summary.closest('form');
    const absentBoxes = Array.from(form.querySelectorAll('input[name="absent_student_ids[]"]'));
    const total = Number(summary.dataset.total);
    const counts = () => {
        const absent = absentBoxes.filter(box => box.checked).length;
        return { absent, present: total - absent };
    };
    const update = () => {
        const { absent, present } = counts();
        summary.textContent = `Confira a chamada: ${present} presente(s) e ${absent} ausente(s), de ${total} aluno(s). Os não marcados serão registrados como presentes.`;
    };
    form.addEventListener('change', event => {
        if (absentBoxes.includes(event.target)) update();
    });
    form.addEventListener('submit', event => {
        const { absent, present } = counts();
        if (!window.confirm(`Concluir a chamada com ${present} presente(s) e ${absent} ausente(s)?`)) {
            event.preventDefault();
        }
    });
    update();
});
