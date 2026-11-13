CREATE TABLE IF NOT EXISTS attendance_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    class_id INTEGER NOT NULL REFERENCES classes(id),
    attendance_date TEXT NOT NULL,
    completed_by INTEGER NOT NULL REFERENCES users(id),
    completed_at TEXT NOT NULL,
    UNIQUE (class_id, attendance_date)
);
CREATE INDEX IF NOT EXISTS idx_attendance_sessions_date_class
    ON attendance_sessions(attendance_date DESC, class_id);

CREATE TABLE IF NOT EXISTS attendance_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER NOT NULL REFERENCES attendance_sessions(id) ON DELETE CASCADE,
    student_id INTEGER NOT NULL REFERENCES students(id),
    status TEXT NOT NULL CHECK (status IN ('PRESENTE', 'AUSENTE')),
    created_at TEXT NOT NULL,
    UNIQUE (session_id, student_id)
);
CREATE INDEX IF NOT EXISTS idx_attendance_records_student_status
    ON attendance_records(student_id, status, session_id);

-- Mantém a ligação dos novos registros à chamada completa. Registros antigos
-- permanecem sem sessão, pois não há dados confiáveis para reconstruir presentes.
ALTER TABLE absences ADD COLUMN attendance_session_id INTEGER REFERENCES attendance_sessions(id);
CREATE INDEX IF NOT EXISTS idx_absences_attendance_session
    ON absences(attendance_session_id);
