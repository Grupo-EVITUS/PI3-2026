"""Exercise the staff workflow through the PHP router on the CI test server."""
import os
import re
from http.cookiejar import CookieJar
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import build_opener, HTTPCookieProcessor

BASE = "http://127.0.0.1:8765"
password = os.environ["INITIAL_ADMIN_PASSWORD"]


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


def login(client, email):
    status, page = request(client, "/login")
    assert status == 200
    return request(client, "/login", dict(email=email, password=password, csrf_token=csrf(page)))


coord = browser()
status, page = login(coord, os.environ["INITIAL_ADMIN_EMAIL"])
assert status == 200 and 'action="/cadastros/usuario"' in page
fields = dict(name="Equipe", email="aoe@example.org", profile="AOE", password=password)
assert request(coord, "/cadastros/usuario", fields)[0] == 400
status, result = request(coord, "/cadastros/usuario", dict(fields, csrf_token=csrf(page)))
assert status == 200 and "Funcionário cadastrado." in result
aoe = browser()
status, page = login(aoe, fields["email"])
assert status == 403
assert request(aoe, "/cadastros/usuario", dict(fields, email="denied@example.org", csrf_token=csrf(page)))[0] == 403

assert 'action="/cadastros/turma"' in result
status, result = request(coord, "/cadastros/turma", dict(
    name="5º ano A", grade="5º ano", academic_year="2026", shift="Manhã", csrf_token=csrf(result)))
assert status == 200 and "5º ano A" in result
class_id = re.search(r'<option value="([a-f0-9]{24})">5º ano A</option>', result)[1]
status, result = request(coord, "/cadastros/aluno", dict(
    name="Aluno CI", registration_no="CI-001", class_id=class_id, csrf_token=csrf(result)))
assert status == 200 and "Aluno CI" in result
student_id = re.search(r'value="([a-f0-9]{24})"> Aluno CI', result)[1]
status, result = request(coord, "/cadastros/responsavel", {
    "name": "Responsável CI", "email": "guardian@example.org", "relationship": "Mãe",
    "student_ids[]": student_id, "csrf_token": csrf(result),
})
assert status == 200 and "Responsável CI" in result
assert request(aoe, "/cadastros/turma", dict(
    name="Negada", grade="5º ano", academic_year="2026", shift="Tarde", csrf_token=csrf(page)))[0] == 403
