-- Preserva a turma em que a ausência foi registrada, mesmo após transferência.
ALTER TABLE absences ADD COLUMN class_id INTEGER REFERENCES classes(id);

-- Registros anteriores recebem a turma atual do aluno; mudanças históricas
-- anteriores a esta migração não podem ser reconstruídas sem dados de origem.
UPDATE absences
SET class_id = (SELECT students.class_id FROM students WHERE students.id = absences.student_id)
WHERE class_id IS NULL;

CREATE INDEX IF NOT EXISTS idx_absences_class_date
    ON absences(class_id, absence_date DESC);

CREATE TRIGGER IF NOT EXISTS trg_absences_require_class_id
BEFORE INSERT ON absences
WHEN NEW.class_id IS NULL
BEGIN
    SELECT RAISE(ABORT, 'class_id is required for a new absence');
END;

CREATE TRIGGER IF NOT EXISTS trg_absences_class_id_immutable
BEFORE UPDATE OF class_id ON absences
WHEN OLD.class_id IS NOT NEW.class_id
BEGIN
    SELECT RAISE(ABORT, 'class_id is an immutable historical snapshot');
END;
