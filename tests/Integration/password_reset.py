"""Password recovery over HTTP: request, e-mail via the worker, new password, single use."""
import json
import os
import re
import subprocess
from http.cookiejar import CookieJar
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import HTTPCookieProcessor, Request, build_opener, urlopen

BASE = os.environ["PUBLIC_BASE_URL"]
INBOX = "http://127.0.0.1:8025/api/v1"
EMAIL = os.environ["INITIAL_ADMIN_EMAIL"]
OLD = os.environ["INITIAL_ADMIN_PASSWORD"]
NEW = "Nova-senha-CI-2026"


def mailbox(path, method="GET"):
    with urlopen(Request(INBOX + path, method=method), timeout=10) as response:
        body = response.read()
        return json.loads(body) if method == "GET" else body


def browser():
    return build_opener(HTTPCookieProcessor(CookieJar()))


def request(client, path, fields=None):
    data = None if fields is None else urlencode(fields).encode()
    try:
        response = client.open(BASE + path, data, timeout=10)
    except HTTPError as error:
        response = error
    with response:
        return response.status, response.read().decode()


def csrf(page):
    return re.search(r'name="csrf_token" value="([^"]+)"', page)[1]


def login(client, password):
    status, page = request(client, "/login")
    assert status == 200 and "Esqueci minha senha" in page
    return request(client, "/login", dict(email=EMAIL, password=password, csrf_token=csrf(page)))


def deliver():
    subprocess.run(["php", "bin/process_mail_queue_mongodb.php"], check=True, capture_output=True)


mailbox("/messages", "DELETE")
user = browser()

# O pedido tem a mesma resposta para conta existente, inexistente e Diretor, e exige CSRF.
status, form = request(user, "/recuperar-senha")
assert status == 200 and "Esqueci minha senha" in form
assert request(user, "/recuperar-senha", {"email": EMAIL})[0] == 400
generic = []
for address in ["nao-existe@example.org", "diretor@example.org", "invalido", EMAIL]:
    status, page = request(user, "/recuperar-senha", {"email": address, "csrf_token": csrf(form)})
    assert status == 200 and "Se o e-mail informado estiver cadastrado" in page, address
    generic.append(re.sub(r'name="csrf_token" value="[^"]+"', "", re.sub(r'value="[^"]*"', "", page)))
assert len(set(generic)) == 1, "respostas diferentes revelam se a conta existe"
assert mailbox("/messages")["messages"] == []  # o e-mail sai pelo worker, não na requisição

deliver()
messages = mailbox("/messages")["messages"]
assert len(messages) == 1 and messages[0]["To"][0]["Address"] == EMAIL, messages
text = mailbox("/message/" + messages[0]["ID"])["Text"]
link = re.search(re.escape(BASE) + r"(/redefinir-senha/[A-Za-z0-9_-]{43})", text)[1]
deliver()
assert len(mailbox("/messages")["messages"]) == 1  # sem reenvio

# Formulário, validações e troca da senha.
assert request(browser(), "/redefinir-senha/" + "x" * 43)[0] == 404
status, page = request(user, link)
assert status == 200 and "Nova senha" in page
token = csrf(page)
assert request(user, link, {"password": NEW, "password_confirmation": NEW})[0] == 400
status, page = request(user, link, {"password": "curta", "password_confirmation": "curta", "csrf_token": token})
assert status == 422 and "de 10 a 72 caracteres" in page
status, page = request(user, link, {"password": NEW, "password_confirmation": NEW + "x", "csrf_token": token})
assert status == 422 and "confirmação não confere" in page
status, page = request(user, link, {"password": NEW, "password_confirmation": NEW, "csrf_token": token})
assert status == 200 and "Senha redefinida" in page and 'action="/login"' in page  # redirecionou ao login

assert 'action="/login"' in login(browser(), OLD)[1]  # senha antiga recusada
status, home = login(browser(), NEW)
assert status == 200 and 'action="/login"' not in home

# O link só vale uma vez.
assert request(browser(), link)[0] == 404
assert request(user, link, {"password": "Outra-senha-2026", "password_confirmation": "Outra-senha-2026", "csrf_token": token})[0] == 404
assert 'action="/login"' not in login(browser(), NEW)[1]
