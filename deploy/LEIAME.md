# NotaPix no servidor (VPS Hostinger + GitHub)

O código fica num repositório **privado** no GitHub. A VPS (a mesma do RustDesk, sem conflito de
portas: RustDesk 21115-21119, NotaPix 80/443) baixa o código de lá com uma chave só de leitura.

## Instalação (uma vez)

1. **DNS** (cPanel da HostGator → Editor de Zona de digitabrasil.com.br): registro **A**
   `notapix` → IP da VPS.
2. **No Windows** (na pasta do projeto), mande o seu `.env` para a VPS (ele não vai para o GitHub):
   ```
   scp .env root@IP_DA_VPS:/root/.env.origem
   scp deploy\instalar-servidor.sh root@IP_DA_VPS:/root/
   ```
3. **Na VPS** (`ssh root@IP_DA_VPS`):
   ```
   bash instalar-servidor.sh notapix.digitabrasil.com.br seu@email.com.br git@github.com:USUARIO/notapix.git
   ```
   Na primeira vez ele mostra uma chave: cole em GitHub → repositório → Settings → Deploy keys
   (sem "Allow write access") e tecle Enter. No fim aparece o login do Administrador
   (guardado também em `/root/notapix-admin.txt`).

## Atualizar (a cada nova versão enviada ao GitHub)

```
ssh root@IP_DA_VPS "bash /var/www/notapix/deploy/atualizar.sh"
```

## No dia a dia

| O quê | Comando na VPS |
|---|---|
| Situação da fila | `supervisorctl status notapix-fila` |
| Erros do sistema | `tail -50 /var/www/notapix/storage/logs/laravel-$(date +%F).log` |
| Comandos do NotaPix | `cd /var/www/notapix && sudo -u www-data php artisan ...` |
| Backup manual | `notapix-backup` (automático todo dia às 2h30, 14 dias em `/var/backups/notapix`) |
| Trocar senha | `cd /var/www/notapix && sudo -u www-data php artisan usuario:senha email@x.com` |
