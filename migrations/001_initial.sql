CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    profile TEXT NOT NULL CHECK (profile IN ('AOE', 'COORDENACAO', 'DIRETOR')),
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS classes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    grade TEXT NOT NULL,
    academic_year INTEGER NOT NULL,
    shift TEXT NOT NULL CHECK (shift IN ('Manhã', 'Tarde', 'Noite', 'Integral')),
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS students (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    registration_no TEXT UNIQUE,
    class_id INTEGER NOT NULL REFERENCES classes(id),
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS guardians (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL COLLATE NOCASE,
    relationship TEXT NOT NULL,
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS student_guardians (
    student_id INTEGER NOT NULL REFERENCES students(id),
    guardian_id INTEGER NOT NULL REFERENCES guardians(id),
    PRIMARY KEY (student_id, guardian_id)
);

CREATE TABLE IF NOT EXISTS absences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER NOT NULL REFERENCES students(id),
    absence_date TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'PENDENTE' CHECK (status IN ('PENDENTE', 'RESPONDIDA')),
    registered_by INTEGER NOT NULL REFERENCES users(id),
    created_at TEXT NOT NULL,
    UNIQUE (student_id, absence_date)
);
CREATE INDEX IF NOT EXISTS idx_absences_status_date ON absences(status, absence_date DESC);

CREATE TABLE IF NOT EXISTS communications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    absence_id INTEGER NOT NULL REFERENCES absences(id),
    guardian_id INTEGER NOT NULL REFERENCES guardians(id),
    email_to TEXT NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    status TEXT NOT NULL DEFAULT 'PENDENTE' CHECK (status IN ('PENDENTE', 'ENVIADO', 'FALHA')),
    sent_at TEXT,
    failure_detail TEXT,
    expires_at TEXT,
    retry_of_id INTEGER REFERENCES communications(id),
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_communications_absence ON communications(absence_id, created_at);

CREATE TABLE IF NOT EXISTS mail_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    communication_id INTEGER NOT NULL UNIQUE REFERENCES communications(id),
    recipient TEXT NOT NULL,
    subject TEXT NOT NULL,
    body TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'AGUARDANDO' CHECK (status IN ('AGUARDANDO', 'PROCESSANDO', 'CONCLUIDO', 'FALHA')),
    created_at TEXT NOT NULL,
    started_at TEXT,
    processed_at TEXT
);

CREATE TABLE IF NOT EXISTS justifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    absence_id INTEGER NOT NULL UNIQUE REFERENCES absences(id),
    communication_id INTEGER NOT NULL REFERENCES communications(id),
    guardian_id INTEGER NOT NULL REFERENCES guardians(id),
    reason TEXT NOT NULL CHECK (reason IN ('DOENCA', 'CONSULTA_MEDICA', 'PROBLEMA_FAMILIAR', 'TRANSPORTE', 'OUTRO')),
    explanation TEXT NOT NULL DEFAULT '',
    submitted_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS operation_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER REFERENCES users(id),
    action TEXT NOT NULL,
    entity TEXT NOT NULL,
    entity_id TEXT,
    details TEXT NOT NULL DEFAULT '',
    happened_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_operation_logs_happened_at ON operation_logs(happened_at DESC);
