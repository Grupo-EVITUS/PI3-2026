"""Check Mongo outbox SMTP delivery and the guardian's justification via HTTP."""
import json
import os
import re
from http.cookiejar import CookieJar
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import HTTPCookieProcessor, build_opener, urlopen

BASE = os.environ["PUBLIC_BASE_URL"]
INBOX = "http://127.0.0.1:8025/api/v1"


def mailbox(path):
    with urlopen(INBOX + path, timeout=10) as response:
        return json.load(response)


guardian = build_opener(HTTPCookieProcessor(CookieJar()))


def page(path, fields=None):
    data = None if fields is None else urlencode(fields).encode()
    try:
        response = guardian.open(BASE + path, data, timeout=10)
    except HTTPError as error:
        response = error
    with response:
        return response.status, response.read().decode()


messages = mailbox("/messages")["messages"]
assert len(messages) == 1, messages
assert messages[0]["To"][0]["Address"] == "chamada@example.org"
body = mailbox("/message/" + messages[0]["ID"])["Text"]
path = re.search(re.escape(BASE) + r"(/justificar/[A-Za-z0-9_-]+)", body)[1]
status, form = page(path)
assert status == 200 and "Aluno Chamada A" in form and "Aluno Chamada B" not in form
csrf = re.search(r'name="csrf_token" value="([^"]+)"', form)[1]
assert page(path, {"reason": "DOENCA", "explanation": ""})[0] == 400
status, response = page(path, {"csrf_token": csrf, "reason": "DOENCA", "explanation": ""})
assert status == 200 and "Justificativa enviada" in response
status, response = page(path)
assert status == 200 and "já foi respondida" in response
assert page(path, {"csrf_token": csrf, "reason": "DOENCA", "explanation": ""})[0] == 409
assert page("/justificar/" + "x" * 43)[0] == 404
