# NotaPix

Emissor de NF-e modelo 55 com cobrança por nota emitida (créditos pré-pagos via Pix).

Etapa atual: **emissão de NF-e em homologação**.

## O que já existe

- Laravel 13 + PostgreSQL, padrão MVC (Controllers, Models, Rotas, Views em Blade)
- 16 tabelas do modelo de dados (migrations em `database/migrations`)
- Três perfis: **Administrador**, **Escritório Contábil** e **Empresa**
- Login, sair, cadastro de Empresa ou Escritório (com validação de CNPJ e aceite dos termos), "esqueci a senha"
- Painel inicial de cada perfil, com acesso bloqueado entre perfis
- Bloqueio de usuário pelo administrador (desconecta na hora)
- Limite de tentativas de login (5 por minuto)
- Certificado digital e senha gravados **criptografados** no banco
- Extrato de créditos que não pode ser alterado nem apagado
- **Dados da empresa** (`/empresa/dados`): endereço preenchido pelo CEP (ViaCEP), código IBGE do
  município, inscrição estadual validada (ES), regime Simples Nacional ou MEI, série e numeração da NF-e
- **Certificado A1** (`/empresa/certificado`): envio do .pfx/.p12, leitura de titular, CNPJ e validade,
  conferência da raiz do CNPJ, conversão automática de certificados em formato antigo
- Painel da empresa com a lista do que falta para emitir
- **Pessoas** (`/empresa/pessoas`): pessoa física e jurídica na mesma tela, CPF/CNPJ validados,
  situação da inscrição estadual, endereço pelo CEP
- **Produtos** (`/empresa/produtos`): tributação sugerida para o Simples (CFOP 5.102, CSOSN 102,
  origem 0, PIS/COFINS 49), 16 unidades comerciais, preço com máscara de moeda, código de barras
  validado, NCM digitado ou pesquisado na tabela oficial (só códigos de 8 dígitos em vigor),
  CFOP digitado ou pesquisado na tabela oficial completa, campos de IBS/CBS
- **Tabela CFOP**: os 619 códigos do Informe Técnico 2023.002 v2.10 (set/2026) vêm em
  `database/data/cfop.json` e são carregados pelo `php artisan migrate`. Para uma versão nova da
  tabela, troque o arquivo e rode `php artisan cfop:importar`
- Testes automatizados (`php artisan test`)

## Emissão de NF-e

Fluxo: **Notas → Nova nota** → escolher a pessoa e os produtos → **Emitir nota**.

- O XML é montado com a NFePHP (`nfephp-org/sped-nfe`, leiaute 4.00 / PL_010), assinado com o
  certificado da empresa, validado no schema oficial e enviado à SEFAZ em modo síncrono.
- **Crédito:** reservado ao enviar; consumido se a nota for autorizada (ou denegada);
  devolvido se for rejeitada. A nota rejeitada pode ser corrigida e enviada de novo com o mesmo número.
- **Sem resposta da SEFAZ:** a nota fica "Processando" e o botão **Consultar situação** resolve.
- Autorizada: o XML com protocolo e o DANFE (PDF) ficam em `storage/app/private/notas/` e são
  enviados por e-mail para a empresa e para o destinatário.
- Operações suportadas nesta versão: venda dentro e fora do estado para contribuinte,
  CSOSN 102, 103, 300, 400 e 500, PIS/COFINS sem cálculo (49, 99, 04 a 09).
  Ainda não: ST (CSOSN 201/202/203), crédito de ICMS (101), DIFAL para consumidor final de
  outro estado, cancelamento e carta de correção.
- IBS/CBS: para Simples Nacional e MEI a exigência começa em 2027 (NT 2025.002-RTC); por enquanto
  o grupo não é enviado.

### Para testar no seu computador

```bash
# Biblioteca da NF-e (uma vez)
php composer.phar require nfephp-org/sped-nfe:^5.2 nfephp-org/sped-da:^1.1

php artisan migrate

# Créditos de teste para a sua empresa (enquanto não há compra por Pix)
php artisan creditos:conceder 11222333000181 10
```

No `.env`:

- `QUEUE_CONNECTION=sync` para a nota ser enviada na hora (sem precisar de `queue:work`)
- `NFE_RESP_TEC_CNPJ`, `NFE_RESP_TEC_CONTATO`, `NFE_RESP_TEC_EMAIL`, `NFE_RESP_TEC_FONE`: dados
  do responsável técnico pelo sistema (você), exigidos pela SEFAZ
- A extensão `soap` do PHP precisa estar ativa (o Herd já traz)

## Certificados em formato antigo

Muitos certificados A1 usam criptografia antiga (RC2/3DES), que o PHP com OpenSSL 3 não abre.
O sistema converte esses arquivos automaticamente usando o programa `openssl` do computador.

- **Linux (servidor):** já funciona, o `openssl` vem instalado.
- **Windows:** informe no `.env` o caminho do `openssl.exe`. Quem tem o Git instalado já tem um:

```
OPENSSL_BIN='C:\Program Files\Git\usr\bin\openssl.exe'
```

Sem isso, certificados novos funcionam normalmente; só os antigos mostram uma mensagem pedindo
para exportar o certificado de novo com criptografia AES256-SHA256.

## Requisitos

- PHP 8.3 ou superior, com as extensões `pdo_pgsql`, `openssl`, `mbstring`, `dom`, `curl`, `zip`
  (para a NF-e, mais adiante: `soap` e `gd`)
- Composer 2
- PostgreSQL 14 ou superior
- Node.js 20 ou superior (para compilar o CSS)

## Instalação

```bash
# 1. Dependências PHP
composer install

# 2. Configuração
cp .env.example .env          # no Windows: copy .env.example .env
php artisan key:generate
```

Edite o `.env`:

- `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` do seu PostgreSQL (crie antes o banco `nfeexpress`)
- `ADMIN_EMAIL` e `ADMIN_SENHA` do seu usuário administrador

```bash
# 3. Banco de dados (cria as tabelas, o administrador, pacotes e dados de teste)
php artisan migrate --seed

# 4. Tabela oficial NCM (Siscomex), usada na pesquisa de NCM dos produtos
php artisan ncm:importar

# 5. CSS e JavaScript
npm install
npm run build

# 6. Rodar
php artisan serve
```

Acesse http://localhost:8000.

### Tabela NCM

`php artisan ncm:importar` baixa a tabela do Siscomex e guarda só os códigos de 8 dígitos,
os únicos aceitos na NF-e. Rode de novo quando quiser atualizar; no servidor, o agendador
do Laravel atualiza sozinho todo mês.

Se o download falhar, baixe o arquivo pelo navegador no endereço que o comando mostra e rode:

```
php artisan ncm:importar --arquivo="C:\Users\usuario\Downloads\tabela-ncm.json"
```

Sem a tabela importada o sistema continua funcionando: o NCM pode ser digitado, mas a pesquisa
por descrição fica desabilitada e só o tamanho (8 dígitos) é conferido.

## Usuários de teste

Criados pelo `db:seed` fora de produção:

| Perfil | E-mail | Senha |
| --- | --- | --- |
| Administrador | o `ADMIN_EMAIL` do `.env` | o `ADMIN_SENHA` do `.env` |
| Empresa | empresa@teste.local | senha1234 |
| Escritório | escritorio@teste.local | senha1234 |

## Atenção: APP_KEY

Os certificados digitais são criptografados com a `APP_KEY` do `.env`.
**Guarde uma cópia dela em local seguro.** Se ela for perdida ou trocada, os certificados
gravados não poderão mais ser lidos e os clientes terão de enviá-los novamente.

## Testes

```bash
php artisan test
```

Os testes usam um banco SQLite em memória e não mexem no seu PostgreSQL.

## Estrutura principal

```
app/
  Enums/Perfil.php                 perfis de usuário
  Http/Controllers/Auth/           login, cadastro, senha
  Http/Controllers/Admin/          área do administrador
  Http/Controllers/Escritorio/     área do escritório contábil
  Http/Controllers/Empresa/        área da empresa
  Http/Middleware/EnsurePerfil.php libera rotas por perfil (perfil:admin)
  Models/                          16 models do modelo de dados
  Rules/Cnpj.php                   validação de CNPJ
config/nfeexpress.php              versões dos termos, UF padrão
database/migrations/               tabelas
database/seeders/DatabaseSeeder.php administrador, pacotes, dados de teste
lang/pt_BR/                        mensagens em português
resources/views/                   telas (Blade)
routes/web.php                     rotas
```

## Rotas

| URL | Quem acessa |
| --- | --- |
| `/` | público |
| `/entrar`, `/cadastro`, `/esqueci-a-senha` | visitantes |
| `/painel` | logado (redireciona para o painel do perfil) |
| `/admin` | Administrador |
| `/escritorio` | Escritório Contábil |
| `/empresa` | Empresa |
