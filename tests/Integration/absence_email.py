"""Exercise the SQLite web flow against an isolated Mailpit SMTP inbox."""
import datetime
import json
import os
import re
import sqlite3
import subprocess
from http.cookiejar import CookieJar
from urllib.parse import urlencode
from urllib.request import build_opener, HTTPCookieProcessor, urlopen

base = os.environ["PUBLIC_BASE_URL"]
inbox = "http://127.0.0.1:8025/api/v1"

def browser():
    return build_opener(HTTPCookieProcessor(CookieJar()))

def page(client, path, fields=None):
    data = urlencode(fields).encode() if fields is not None else None
    with client.open(base + path, data, timeout=10) as response:
        return response.read().decode()

def csrf(html):
    return re.search(r'name="csrf_token" value="([^"]+)"', html)[1]

def mailbox(path):
    with urlopen(inbox + path, timeout=10) as response:
        return json.load(response)

school = browser()
page(school, "/login", dict(csrf_token=csrf(page(school, "/login")),
                            email="aoe@escola.local", password="AOE12345!"))
form = page(school, "/ausencias/registrar?turma_id=1")
page(school, "/ausencias/registrar", {
    "csrf_token": csrf(form), "class_id": 1,
    "absence_date": datetime.datetime.now(datetime.timezone.utc).date().isoformat(),
    "attendance[1]": "AUSENTE", "attendance[2]": "AUSENTE", "attendance[3]": "PRESENTE",
})
subprocess.run(["php", "bin/process_mail_queue.php"], check=True)
messages = mailbox("/messages")["messages"]
assert len(messages) == 2
by_recipient = {msg["To"][0]["Address"]: msg["ID"] for msg in messages}
assert set(by_recipient) == {"maria.almeida@example.test", "joao.souza@example.test"}
body = mailbox("/message/" + by_recipient["maria.almeida@example.test"])["Text"]
path = re.search(re.escape(base) + r"(/justificar/[A-Za-z0-9_-]+)", body)[1]
guardian = browser()
form = page(guardian, path)
assert "Lucas Almeida" in form and "Marina Souza" not in form
page(guardian, path, dict(csrf_token=csrf(form), reason="DOENCA", explanation=""))
with sqlite3.connect(os.environ["DATABASE_PATH"]) as db:
    rows = db.execute("""SELECT a.student_id,a.status,j.reason,c.status
        FROM absences a JOIN communications c ON c.absence_id=a.id
        LEFT JOIN justifications j ON j.absence_id=a.id ORDER BY a.student_id""").fetchall()
    assert rows == [(1, "RESPONDIDA", "DOENCA", "ENVIADO"), (2, "PENDENTE", None, "ENVIADO")], rows
