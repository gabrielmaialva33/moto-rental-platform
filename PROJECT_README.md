# Moto Rental Platform

Sistema de locação de motos desenvolvido com Laravel 11 e PHP 8.4, seguindo as melhores práticas de 2025.

## Características Principais

### Stack Tecnológico
- **Backend**: Laravel 11 (PHP 8.4+)
- **Database**: MySQL 8+
- **Cache**: Redis (opcional)
- **Queue**: Laravel Horizon (opcional)

### Funcionalidades

#### Gestão de Motos
- Cadastro completo de motos (marca, modelo, ano, placa, cilindrada)
- Controle de status (disponível, alugada, manutenção, inativa)
- Upload de múltiplas imagens
- Histórico de manutenções
- Cálculo automático de próxima manutenção

#### Sistema de Locações
- Reservas online com disponibilidade em tempo real
- Cálculo automático de valores (diária, desconto, taxas adicionais)
- Controle de caução
- Sistema de multas por atraso
- Histórico completo de locações

#### Gestão de Clientes
- Cadastro com validação de documentos (CPF, RG, CNH)
- Verificação de CNH válida
- Sistema de crédito/limite
- Histórico de locações
- Avaliações e feedback

#### Processamento de Pagamentos
- Múltiplos métodos (Cartão, PIX, Boleto)
- Integração com gateways de pagamento
- Controle de status de pagamento
- Sistema de reembolso
- Histórico de transações

#### Manutenção e Controle
- Registro de manutenções preventivas e corretivas
- Controle de quilometragem
- Alertas de manutenção
- Histórico de peças trocadas
- Custos de manutenção

## Estrutura do Banco de Dados

### Tabelas Principais

#### `users`
- Informações pessoais e de contato
- Documentação (CPF, RG, CNH)
- Controle de roles (admin, employee, customer)
- Status de verificação

#### `motorcycles`
- Dados completos da moto
- Features e características
- Status de disponibilidade
- Controle de manutenção

#### `rentals`
- Relacionamento user/motorcycle
- Datas de início/fim
- Valores e taxas
- Status da locação
- Quilometragem inicial/final

#### `payments`
- Transações financeiras
- Gateway de pagamento
- Status do pagamento
- Histórico de reembolsos

#### `maintenance_records`
- Registro de manutenções
- Peças trocadas
- Custos
- Próxima manutenção

## Instalação

### Requisitos
- PHP 8.4+
- Composer 2.8+
- MySQL 8+
- Node.js 20+ (para assets frontend)

### Passos de Instalação

1. **Clone o repositório**
```bash
git clone [url-do-repositorio]
cd moto-rental-platform
```

2. **Instale as dependências**
```bash
composer install
npm install
```

3. **Configure o ambiente**
```bash
cp .env.example .env
php artisan key:generate
```

4. **Configure o banco de dados**
Edite o arquivo `.env` com suas credenciais:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=moto_rental
DB_USERNAME=seu_usuario
DB_PASSWORD=sua_senha
```

5. **Execute as migrações**
```bash
php artisan migrate
```

6. **Crie um usuário admin (opcional)**
```bash
php artisan tinker
>>> User::create([
    'name' => 'Admin',
    'email' => 'admin@example.com',
    'password' => bcrypt('password'),
    'role' => 'admin'
]);
```

7. **Inicie o servidor**
```bash
php artisan serve
```

## Uso da API

### Endpoints Principais

#### Motos
- `GET /api/motorcycles` - Listar motos disponíveis
- `POST /api/motorcycles` - Cadastrar nova moto (admin)
- `PUT /api/motorcycles/{id}` - Atualizar moto (admin)
- `DELETE /api/motorcycles/{id}` - Remover moto (admin)

#### Locações
- `GET /api/rentals` - Minhas locações
- `POST /api/rentals` - Criar nova locação
- `PUT /api/rentals/{id}/complete` - Finalizar locação
- `PUT /api/rentals/{id}/cancel` - Cancelar locação

#### Pagamentos
- `POST /api/payments` - Processar pagamento
- `GET /api/payments/{id}` - Status do pagamento
- `POST /api/payments/{id}/refund` - Solicitar reembolso

## Arquitetura e Padrões

### Design Patterns Implementados
- **Repository Pattern**: Para abstração de dados (opcional)
- **Service Layer**: Lógica de negócios complexa
- **Action Classes**: Operações específicas
- **Form Requests**: Validação de dados
- **Policies**: Autorização granular
- **Observers**: Eventos de modelo
- **Scopes**: Queries reutilizáveis

### Boas Práticas Laravel 2025
- ✅ PHP 8.4 features (typed properties, match expressions)
- ✅ Laravel 11 streamlined structure
- ✅ Eloquent com relationships otimizados
- ✅ Migrations com índices apropriados
- ✅ Casts e accessors/mutators modernos
- ✅ Form Requests para validação
- ✅ Policies para autorização
- ✅ Laravel Sanctum para API auth
- ✅ Queue jobs para tarefas pesadas
- ✅ Cache estratégico com Redis

## Segurança

### Medidas Implementadas
- Autenticação com Laravel Sanctum
- Validação de CNH e documentos
- Rate limiting em APIs
- Prepared statements (Eloquent)
- CSRF protection
- XSS protection
- Hashing de senhas (Argon2)
- Validação de uploads
- Controle de roles e permissions

## Performance

### Otimizações
- Eager loading de relationships
- Índices de banco otimizados
- Cache de queries pesadas
- Queue para processamento assíncrono
- Paginação de resultados
- Lazy loading de imagens
- CDN para assets (produção)

## Testing

### Executar Testes
```bash
php artisan test
```

### Cobertura
- Unit tests para models
- Feature tests para controllers
- Integration tests para pagamentos
- Browser tests com Laravel Dusk

## Deploy

### Produção
1. Configure variáveis de ambiente
2. Otimize o aplicativo:
```bash
composer install --optimize-autoloader --no-dev
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

3. Configure supervisor para queues
4. Configure SSL/HTTPS
5. Configure backup automático

## Manutenção

### Comandos Úteis
```bash
# Limpar cache
php artisan cache:clear

# Reprocessar queues falhadas
php artisan queue:retry all

# Backup do banco
php artisan backup:run

# Verificar status do sistema
php artisan health:check
```

## Roadmap

### Próximas Features
- [ ] Aplicativo mobile (React Native)
- [ ] Sistema de GPS tracking
- [ ] Integração com WhatsApp
- [ ] Dashboard analytics avançado
- [ ] Sistema de fidelidade
- [ ] Múltiplas filiais
- [ ] Integração com seguradoras
- [ ] Sistema de avaliações
- [ ] Relatórios personalizados
- [ ] PWA para clientes

## Contribuindo

1. Fork o projeto
2. Crie sua feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit suas mudanças (`git commit -m 'Add some AmazingFeature'`)
4. Push para a branch (`git push origin feature/AmazingFeature`)
5. Abra um Pull Request

## Licença

Proprietary - Todos os direitos reservados

## Suporte

Para suporte, envie email para suporte@motorental.com

## Autores

- **Equipe de Desenvolvimento** - *Trabalho Inicial* - [MotoRental]

## Agradecimentos

- Laravel Community
- PHP Community
- Todos os contribuidores