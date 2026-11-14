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
