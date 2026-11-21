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
assert status == 200 and 'href="/chamada"' in page
assert request(aoe, "/cadastros/usuario", dict(fields, email="denied@example.org", csrf_token=csrf(page)))[0] == 403
status, result = request(coord, "/cadastros/usuario", dict(
    fields, email="diretor@example.org", profile="DIRETOR", csrf_token=csrf(result)))
assert status == 200
director = browser()
status, frequency_home = login(director, "diretor@example.org")
assert status == 200 and "Frequência escolar" in frequency_home
assert request(director, "/chamada")[0] == 403
assert request(aoe, "/frequencia")[0] == 403
assert request(browser(), "/frequencia")[0] == 200  # Redirect para login.
assert request(coord, "/frequencia?periodo=invalido")[0] == 422
assert request(coord, "/frequencia?turma_id=%24ne")[0] == 422
status, empty_frequency = request(coord, "/frequencia?periodo=semana&referencia=2026-11-15")
assert status == 200 and "Sem dados" in empty_frequency and "09/11/2026" in empty_frequency and "15/11/2026" in empty_frequency
status, semester = request(coord, "/frequencia?periodo=semestre&referencia=2026-11-15")
assert status == 200 and "01/07/2026" in semester and "31/12/2026" in semester

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

status, result = request(coord, "/cadastros/aluno", {
    "id": student_id, "name": "Aluno CI Atualizado", "registration_no": "CI-001",
    "class_id": class_id, "csrf_token": csrf(result),
})
assert status == 200 and "Aluno CI Atualizado" in result
status, result = request(coord, "/cadastros/arquivar", {
    "id": class_id, "entity": "turma", "csrf_token": csrf(result),
})
assert status == 422 and "alunos ativos" in result
status, result = request(coord, "/cadastros/arquivar", {
    "id": student_id, "entity": "aluno", "csrf_token": csrf(result),
})
assert status == 200 and "Aluno CI Atualizado" not in result
status, result = request(coord, "/cadastros/arquivar", {
    "id": class_id, "entity": "turma", "csrf_token": csrf(result),
})
assert status == 200 and "5º ano A" not in result

# A rota Mongo de chamada fica disponível apenas no modo de homologação da CI.
status, result = request(coord, "/cadastros/turma", dict(
    name="Turma Chamada CI", grade="6º ano", academic_year="2026", shift="Tarde", csrf_token=csrf(result)))
assert status == 200
attendance_class = re.search(r'<option value="([a-f0-9]{24})">Turma Chamada CI</option>', result)[1]
for name, registration in [("Aluno Chamada A", "CH-001"), ("Aluno Chamada B", "CH-002")]:
    status, result = request(coord, "/cadastros/aluno", dict(
        name=name, registration_no=registration, class_id=attendance_class, csrf_token=csrf(result)))
    assert status == 200 and name in result
first_id = re.search(r'value="([a-f0-9]{24})"> Aluno Chamada A', result)[1]
status, result = request(coord, "/cadastros/responsavel", {
    "name": "Contato da chamada", "email": "chamada@example.org", "relationship": "Mãe",
    "student_ids[]": first_id, "csrf_token": csrf(result),
})
assert status == 200
guest_status, guest_page = request(browser(), "/chamada")
assert guest_status == 200 and 'action="/login"' in guest_page
status, attendance_page = request(aoe, "/chamada?turma_id=" + attendance_class)
assert status == 200 and "O envio depende da execução do worker SMTP" in attendance_page
student_ids = re.findall(r'name="attendance\[([a-f0-9]{24})\]" value="PRESENTE"', attendance_page)
assert len(student_ids) == 2 and first_id in student_ids
partial = {"class_id": attendance_class, "absence_date": "2026-11-15",
           "attendance[" + first_id + "]": "AUSENTE", "csrf_token": csrf(attendance_page)}
assert request(aoe, "/chamada", {k: v for k, v in partial.items() if k != "csrf_token"})[0] == 400
status, invalid = request(aoe, "/chamada", partial)
assert status == 422 and "todos os alunos ativos" in invalid
full = dict(partial, **{"attendance[" + next(x for x in student_ids if x != first_id) + "]": "PRESENTE"})
status, summary = request(aoe, "/chamada", full)
assert status == 200 and "Chamada registrada" in summary and "não envia avisos diretamente" in summary and "0 por notificação push" in summary
assert "1 comunicação(ões) aguardam" in summary
status, duplicate = request(aoe, "/chamada", full)
assert status == 422 and "Já existe uma chamada" in duplicate
status, frequency = request(coord, "/frequencia?periodo=mes&referencia=2026-11-15&turma_id=" + attendance_class)
assert status == 200 and "Frequência por aluno" in frequency
assert "50,0%" in frequency and "Aluno Chamada A" in frequency
assert "chamada@example.org" not in frequency

# Ocorrências: o Diretor consulta; o reenvio é restrito à AOE/Coordenação e valida a comunicação.
status, occurrences = request(director, "/ocorrencias")
assert status == 200 and "Aluno Chamada A" in occurrences and "Pendente" in occurrences
assert "Fazer chamada" not in occurrences
assert request(director, "/ocorrencias?status=RESPONDIDA")[0] == 200
assert "Nenhuma ocorrência nesta lista" in request(director, "/ocorrencias?status=RESPONDIDA")[1]
assert request(director, "/ocorrencias?status=x")[0] == 422
assert request(browser(), "/ocorrencias")[0] == 200  # Redirect para login.
absence_id = re.search(r'href="/ausencias/([a-f0-9]{24})"', occurrences)[1]
status, detail = request(director, "/ausencias/" + absence_id)
assert status == 200 and "Aluno Chamada A" in detail and "Aguardando resposta" in detail
assert "Contato da chamada" in detail and "Reenviar" not in detail
assert request(director, "/ausencias/" + "0" * 24)[0] == 404
assert request(director, "/ausencias/nao-e-um-id")[0] == 404
assert request(director, "/comunicacoes/reenvio", {"communication_id": "0" * 24})[0] == 400
assert request(director, "/comunicacoes/reenvio", dict(
    communication_id="0" * 24, csrf_token=csrf(occurrences)))[0] == 403
status, retried = request(aoe, "/comunicacoes/reenvio", dict(
    communication_id="0" * 24, csrf_token=csrf(attendance_page)))
assert status == 200 and "Comunicação não encontrada." in retried
