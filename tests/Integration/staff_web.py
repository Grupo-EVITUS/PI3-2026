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
assert status == 200 and "Olá" in page and 'href="/cadastros?aba=alunos"' in page  # o login leva ao Início
assert 'action="/cadastros/usuario"' in request(coord, "/cadastros?aba=funcionarios")[1]
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
assert status == 200 and "Olá" in frequency_home and 'href="/frequencia"' in frequency_home
assert request(director, "/frequencia")[0] == 200 and "Frequência escolar" in request(director, "/frequencia")[1]
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

# Filtros por turma, aluno e período; entradas inválidas são recusadas antes do banco.
status, filtered = request(director, "/ocorrencias?status=PENDENTE&turma_id=%s&aluno_id=%s&de=2026-11-15&ate=2026-11-15" % (
    attendance_class, first_id))
assert status == 200 and filtered.count('href="/ausencias/') == 1 and "1 ocorrência(s)" in filtered
assert "Resultado da busca" in filtered and 'href="/ocorrencias">Limpar' in filtered
status, none = request(director, "/ocorrencias?de=2026-11-16")
assert status == 200 and '/ausencias/' not in none and "Ajuste ou limpe os filtros" in none
status, none = request(director, "/ocorrencias?turma_id=" + class_id)
assert status == 200 and '/ausencias/' not in none
for bad in ["turma_id=%24ne", "aluno_id=abc", "de=2026-11-20&ate=2026-11-01", "de=2026-02-30", "de%5B%5D=x"]:
    assert request(director, "/ocorrencias?" + bad)[0] == 422, bad

# Data de nascimento do aluno e telefone do responsável pela tela de cadastros.
status, directory = request(coord, "/cadastros")
assert status == 200 and 'name="birth_date"' in directory and 'name="phone"' in directory
token = csrf(directory)
base_student = dict(name="Aluno Nascimento", registration_no="NS-001", class_id=attendance_class, csrf_token=token)
for bad in ["05/03/2014", "2014-02-30", "1899-12-31", "2999-01-01"]:
    status, result = request(coord, "/cadastros/aluno", dict(base_student, birth_date=bad))
    assert status == 422 and "Data de nascimento inválida" in result, bad
status, result = request(coord, "/cadastros/aluno", dict(base_student, birth_date="2014-03-05"))
assert status == 200 and "Aluno Nascimento · nasc. 05/03/2014" in result
birth_student = re.search(r'value="([a-f0-9]{24})"> Aluno Nascimento', result)[1]
base_guardian = {"name": "Responsável Fone", "email": "fone@example.org", "relationship": "Pai",
                 "student_ids[]": birth_student, "csrf_token": csrf(result)}
for bad in ["abc", "12345", "(01) 91234-5678", "11 81234-5678"]:
    status, result = request(coord, "/cadastros/responsavel", dict(base_guardian, phone=bad))
    assert status == 422 and "Telefone inválido" in result, bad
status, result = request(coord, "/cadastros/responsavel", dict(base_guardian, phone="+55 (11) 91234-5678"))
assert status == 200 and "Responsável Fone · fone@example.org · (11) 91234-5678" in result
assert request(aoe, "/cadastros/aluno", dict(base_student, birth_date="2014-03-05", csrf_token=csrf(attendance_page)))[0] == 403

# Listagem de funcionários: só a Coordenação; nunca expõe senha; busca e filtros validados.
status, staff_page = request(coord, "/funcionarios")
assert status == 200 and "3 funcionário(s)" in staff_page and 'href="/funcionarios"' in staff_page
for address in ["coord@example.org", "aoe@example.org", "diretor@example.org"]:
    assert address in staff_page, address
assert "password" not in staff_page.lower().replace('type="password"', "") and "$2y$" not in staff_page
status, found = request(coord, "/funcionarios?busca=DIRETOR")
assert status == 200 and "1 funcionário(s)" in found and "diretor@example.org" in found and "aoe@example.org" not in found
status, found = request(coord, "/funcionarios?perfil=AOE")
assert status == 200 and "1 funcionário(s)" in found and "aoe@example.org" in found
status, found = request(coord, "/funcionarios?situacao=inativos")
assert status == 200 and "Nenhum funcionário encontrado" in found
status, found = request(coord, "/funcionarios?busca=.*")
assert status == 200 and "Nenhum funcionário encontrado" in found
for bad in ["perfil=ADMIN", "situacao=talvez", "busca%5B%5D=x", "perfil=%24ne"]:
    assert request(coord, "/funcionarios?" + bad)[0] == 422, bad
assert request(aoe, "/funcionarios")[0] == 403
assert request(director, "/funcionarios")[0] == 403
assert request(browser(), "/funcionarios")[0] == 200  # Redirect para login.
assert request(coord, "/funcionarios", {"csrf_token": csrf(staff_page)})[0] == 405

# Chamada: todos começam presentes e só os ausentes são marcados (campo oculto + caixa com o mesmo nome).
status, directory = request(coord, "/cadastros")
status, result = request(coord, "/cadastros/turma", dict(
    name="Turma Padrão", grade="7º ano", academic_year="2026", shift="Manhã", csrf_token=csrf(directory)))
assert status == 200
default_class = re.search(r'<option value="([a-f0-9]{24})">Turma Padrão</option>', result)[1]
for name, registration in [("Padrão A", "PD-001"), ("Padrão B", "PD-002")]:
    status, result = request(coord, "/cadastros/aluno", dict(
        name=name, registration_no=registration, class_id=default_class, csrf_token=csrf(result)))
    assert status == 200 and name in result
status, page = request(aoe, "/chamada?turma_id=" + default_class)
assert status == 200 and "Marque somente quem faltou" in page and 'type="radio"' not in page
assert ('href="/chamada?turma_id=%s"' % default_class) in page and 'class="steps"' in page and "data-absent-count" in page
assert page.count('type="checkbox" name="attendance[') == 2 and " checked" not in page
default_ids = re.findall(r'type="hidden" name="attendance\[([a-f0-9]{24})\]" value="PRESENTE"', page)
assert len(default_ids) == 2


def attendance_form(date, absent=(), include=None):
    fields = [("class_id", default_class), ("absence_date", date), ("csrf_token", csrf(page))]
    for student in (default_ids if include is None else include):
        fields.append(("attendance[%s]" % student, "PRESENTE"))
        if student in absent:
            fields.append(("attendance[%s]" % student, "AUSENTE"))
    return fields


status, result = request(aoe, "/chamada", attendance_form("2026-11-16"))  # ninguém marcado: todos presentes
assert status == 200 and "2 presente(s) e" in result and "0 ausente(s)" in result and "0 comunicação(ões) aguardam" in result
status, result = request(aoe, "/chamada", attendance_form("2026-11-17", absent=[default_ids[0]]))
assert status == 200 and "1 presente(s) e" in result and "1 ausente(s)" in result
assert "Sem responsável ativo para: Padrão A." in result
assert "Ocorrências desta data" in result and "/ocorrencias?de=2026-11-17&amp;ate=2026-11-17" in result
status, result = request(aoe, "/chamada", attendance_form("2026-11-18", include=[default_ids[0]], absent=[default_ids[0]]))
assert status == 422 and "todos os alunos ativos" in result  # a chamada continua exigindo a turma inteira
assert re.search(r'value="AUSENTE"\s+checked', result)  # a marcação é mantida após o erro


# Início e navegação: atalhos por perfil, área atual destacada, caminho de navegação e abas de Cadastros.
status, home = request(coord, "/inicio")
assert status == 200 and "Olá" in home and 'aria-current="page"' in home
for link in ['href="/chamada"', 'href="/ocorrencias?status=PENDENTE"', 'href="/ocorrencias"', 'href="/frequencia"',
             'href="/cadastros?aba=turmas"', 'href="/cadastros?aba=alunos"', 'href="/cadastros?aba=responsaveis"',
             'href="/funcionarios"']:
    assert link in home, link
status, aoe_home = request(aoe, "/inicio")
assert status == 200 and 'href="/chamada"' in aoe_home and 'href="/ocorrencias?status=PENDENTE"' in aoe_home
for hidden_link in ['href="/cadastros', 'href="/frequencia"', 'href="/funcionarios"']:
    assert hidden_link not in aoe_home, hidden_link
status, director_home = request(director, "/inicio")
assert status == 200 and 'href="/frequencia"' in director_home and 'href="/ocorrencias?status=PENDENTE"' in director_home
assert 'href="/chamada"' not in director_home and 'href="/cadastros' not in director_home
assert request(browser(), "/inicio")[0] == 200  # Redirect para login.
assert request(coord, "/inicio", {"csrf_token": csrf(home)})[0] == 405
status, root = request(coord, "/")
assert status == 200 and "Olá" in root  # a raiz leva ao Início
assert re.search(r'href="/ocorrencias" aria-current="page"', occurrences) and 'class="breadcrumb"' in occurrences
assert 'class="breadcrumb"' in detail and 'filters-card" open' not in occurrences
assert 'href="/ocorrencias?status=PENDENTE"' in occurrences
assert "status=RESPONDIDA&amp;turma_id=" + attendance_class in filtered and 'filters-card" open' in filtered


def panel_tag(html, name):
    return re.search(r'<section[^>]*id="aba-%s"[^>]*>' % name, html)[0]


status, tab_page = request(coord, "/cadastros?aba=responsaveis")
assert status == 200 and " hidden" not in panel_tag(tab_page, "responsaveis") and " hidden" in panel_tag(tab_page, "alunos")
assert re.search(r'id="tab-responsaveis"[^>]*aria-selected="true"', tab_page)
status, tab_page = request(coord, "/cadastros?aba=invalida")  # valor inválido cai na aba padrão
assert status == 200 and " hidden" not in panel_tag(tab_page, "alunos") and " hidden" in panel_tag(tab_page, "turmas")
status, tab_page = request(coord, "/cadastros?aba[]=x")
assert status == 200 and " hidden" not in panel_tag(tab_page, "alunos")
# Depois de salvar (ou errar), a aba do cadastro continua aberta, e o formulário volta aberto no erro.
status, result = request(coord, "/cadastros/aluno", dict(base_student, birth_date="2999-01-01"))
assert status == 422 and " hidden" not in panel_tag(result, "alunos") and '<details class="new-item" open>' in result
status, result = request(coord, "/cadastros/responsavel", dict(base_guardian, phone="abc"))
assert status == 422 and " hidden" not in panel_tag(result, "responsaveis") and " hidden" in panel_tag(result, "alunos")
