# SAA — Flask + MongoDB

Base inicial do Sistema de Ausência de Alunos usando **Flask**, **PyMongo**, **MongoDB** e **Plotly**.

## Relação com o guia

O guia autoriza Flask no Exercício 13 do Nível 3: a rota Flask substitui `view_renderer.py` e serve o dashboard dinamicamente. Nesta adaptação, a coleção de vendas foi substituída pelo domínio do SAA.

## Premissas atuais

- Frequência **diária**: um registro por matrícula e data.
- `PRESENT` e `ABSENT` são registros de frequência considerados no cálculo; `NOT_REGISTERED` não entra nos cálculos. Esse denominador é técnico e não aparece como indicador na tela.
- Falta justificada continua sendo ausência para o dashboard.
- O histórico é preservado por `enrollments`; não se deve apagar registros históricos.
- A AOE, a coordenação ou o administrador possuem área autenticada por e-mail e senha; a senha é armazenada somente como hash.
- Pais e responsáveis **não possuem login** nesta arquitetura. Eles recebem um link único e temporário junto com o comunicado de falta.
- O link público é validado por hash, expiração e uso único; ele permite apenas enviar a justificativa da ocorrência vinculada.
- O percentual mínimo obrigatório será configurado em `attendance_policies` quando a regra da escola for confirmada.

## Execução

```bash
cd saa_flask_mongodb
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
export MONGO_URI='mongodb://localhost:27017/'
export MONGO_DB='saa'
python app.py
```

Acesse `http://localhost:5000/dashboard`.

## Execução com Docker e VS Code

Requisitos: Docker Desktop com Docker Compose e VS Code com as extensões recomendadas em `.vscode/extensions.json`.

Na raiz do projeto, copie o arquivo de exemplo e ajuste os segredos locais:

```bash
cp .env.example .env
```

Suba a aplicação e o MongoDB:

```bash
docker compose up --build
```

O Flask ficará disponível em `http://localhost:5000/login` e o MongoDB será persistido no volume Docker `saa_flask_mongodb_mongo_data`.

Para criar o primeiro usuário interno em outro terminal:

```bash
docker compose exec app python scripts/create_user.py --name "AOE da Escola" --email "aoe@escola.com" --profile AOE
```

Para acompanhar os logs:

```bash
docker compose logs -f app
```

Para parar os containers sem apagar os dados:

```bash
docker compose down
```

Para apagar também o banco persistido, use somente se quiser reiniciar o ambiente:

```bash
docker compose down -v
```

O container expõe a porta `5678` para o depurador do VS Code. Com `docker compose up`, abra o projeto no VS Code, pressione `F5` e selecione `Flask no Docker`. Os breakpoints funcionarão nas rotas Python enquanto a aplicação continua disponível na porta `5000`.

No VS Code, o terminal integrado pode ser usado para executar os mesmos comandos Docker. A extensão Docker permite visualizar os containers `saa-app`, `saa-mongo`, logs e o volume do MongoDB.

## Estrutura MVC

- `models/saa_model.py`: acesso ao MongoDB, índices e pipelines.
- `controllers/dashboard_controller.py`: valida filtros e coordena consultas.
- `app.py`: rotas Flask e orquestração.
- `views/templates/`: dashboard, alunos e formulário de justificativa.
- `CONTEXTO_CHAT_COMPARTILHADO.md`: decisões, personas e requisitos consolidados do link fornecido.
- `MODELAGEM_BANCO_MONGODB.md`: documentação técnica das collections, relacionamentos, índices, regras e agregações.
- `modelo_er_saa.mmd` / `modelo_er_saa.png`: diagrama de entidades e relacionamentos.
- `SPRINT_2.md`: requisitos não funcionais, requisitos da modelagem, critérios de aceitação e plano da sprint.
- `diagrama_classes_saa.mmd` / `diagrama_classes_saa.png`: diagrama UML de classes do domínio e da arquitetura MVC.
- `PROTOTIPO_LOGIN_SPRINT_2.md`: especificação e wireframe do protótipo de login.
- `review.md`: revisão da Sprint 2 no formato solicitado pelo modelo da equipe.
- `SPRINT_3.md`: planejamento, backlog, critérios de aceitação e fluxo de validação do Sprint 3.
- `review_sprint_3.md`: modelo de revisão do Sprint 3 no mesmo formato da equipe.
- `SPRINT_4.md`: planejamento do Sprint 4 com responsáveis, prioridades e critérios de aceitação.
- `review_sprint_4.md`: modelo de revisão do Sprint 4 no formato da equipe.
- `MAPA_SPRINTS.md`: sequência oficial das Sprints 1 a 4 e relação entre os itens do backlog.
- `review.md`: review consolidado das Sprints 1 a 4 em Markdown.
- `sprints/`: organização dos reviews individuais por Sprint para o GitHub.

O dashboard também apresenta gráficos de linha com a **média mensal** e a **média anual de ausências por aluno**. A média é calculada como `ocorrências de ausência do período ÷ alunos distintos faltantes no período`; os filtros de data, turma e turno são aplicados antes da agregação.

## Direção visual

O frontend usa uma linguagem visual inspirada nas referências públicas da Sala do Futuro: azul-marinho como base, azul vivo para ações, superfícies claras, cards arredondados, navegação lateral e acentos verdes, roxos e vermelhos para estados e indicadores. O código não utiliza logotipo ou asset proprietário da Sala do Futuro; trata-se de uma adaptação visual para o SAA.

## Comunicação e confirmação de abertura

A tela `/email-dispatch` coloca a comunicação em `PENDING` e mostra o histórico. A API não pode descobrir uma abertura apenas pelo SMTP: a confirmação de abertura depende de um provedor com rastreamento e webhook. O endpoint `POST /api/webhooks/email` recebe eventos `SENT`, `DELIVERED`, `OPENED` ou `FAILED`, autenticados por `X-Email-Webhook-Secret`, e atualiza `status`, `opened_at`, `open_count` e `failure_detail`. Portanto, o sistema diferencia corretamente “enviado”, “entregue” e “aberto”; “aberto” só será preenchido quando o provedor confirmar o evento.

## Acesso interno e formulário dos responsáveis

Rotas disponíveis:

- `GET/POST /login`: autenticação da AOE, coordenação ou administração.
- `POST /logout`: encerra a sessão interna.
- `GET /dashboard`: dashboard protegido para usuários internos.
- `GET /justify/<token>`: formulário público recebido pelo responsável no comunicado.
- `POST /justify/<token>`: envia a justificativa usando o token, sem conta ou senha.

### CRUD protegido da AOE

Todos os endpoints abaixo exigem sessão interna autenticada:

| Recurso | Listar | Criar | Consultar | Atualizar | Arquivar |
|---|---|---|---|---|---|
| Alunos | `GET /api/students` | `POST /api/students` | `GET /api/students/{id}` | `PATCH /api/students/{id}` | `DELETE /api/students/{id}` |
| Turmas | `GET /api/classes` | `POST /api/classes` | `GET /api/classes/{id}` | `PATCH /api/classes/{id}` | `DELETE /api/classes/{id}` |
| Responsáveis | `GET /api/guardians` | `POST /api/guardians` | `GET /api/guardians/{id}` | `PATCH /api/guardians/{id}` | `DELETE /api/guardians/{id}` |
| Matrículas | `GET /api/enrollments` | `POST /api/enrollments` | `GET /api/enrollments/{id}` | `PATCH /api/enrollments/{id}` | Não apagar histórico |
| Frequência | `GET /api/attendance` | `POST /api/attendance` | `GET /api/attendance/{id}` | `PATCH /api/attendance/{id}` | Não apagar histórico |

Os `DELETE` de cadastros são arquivamentos (`active=false`). Frequências e matrículas não são apagadas para preservar o histórico escolar.

Exemplo para criar um aluno:

```bash
curl -b cookies.txt -X POST http://localhost:5000/api/students \
  -H 'Content-Type: application/json' \
  -d '{"name":"Aluno Fictício","school_id":"MAT-001"}'
```

A collection `users` deve conter `email_normalized`, `password_hash`, `profile`, `active`, `created_at` e `updated_at`. Para criar uma conta interna, use o mesmo padrão de hash do Model; nunca grave senha em texto puro.

### Cadastrar AOE, coordenação e administrador

Com o MongoDB ativo e o ambiente virtual ativado, execute o comando abaixo na raiz do projeto:

```bash
python scripts/create_user.py --name "Nome da AOE" --email "aoe@escola.com" --profile AOE
```

O comando solicitará a senha duas vezes e armazenará somente o hash. Para os outros perfis:

```bash
python scripts/create_user.py --name "Coordenação" --email "coordenacao@escola.com" --profile COORDINATION
python scripts/create_user.py --name "Administrador" --email "admin@escola.com" --profile ADMIN
```

Perfis disponíveis:

- `AOE`: lançamento e consulta operacional das frequências.
- `COORDINATION`: dashboard, justificativas e acompanhamento gerencial.
- `ADMIN`: administração geral do sistema.

O e-mail é único. O cadastro não deve ser exposto como rota pública; ele deve ser executado por administrador ou durante a implantação.

A collection `justification_links` deve conter `token_hash`, `attendance_id`, `expires_at`, `active` e, após o uso, `used_at`. O token original deve ser enviado somente no e-mail e nunca armazenado no banco.

## Pendências para a entrega completa do prompt

Ainda precisam ser conectados ao projeto real:

1. seed completo das collections do SAA;
2. CRUD completo de alunos, turmas, matrículas, responsáveis e frequência;
3. gerador de token e integração de envio de e-mail;
4. notificações de e-mail e registro de tentativas;
5. autenticação e perfis AOE/coordenação/admin;
6. configuração do percentual mínimo por período;
7. testes de integração com MongoDB.

Esses pontos dependem das decisões de negócio que ainda não foram informadas e não devem ser inventados. Consulte `AUDITORIA_ENTREGA.md` para a matriz completa do que está implementado, parcial ou ausente.
