<?php

use Illuminate\Support\Facades\Schedule;

// Atualiza a tabela NCM todo mês (precisa do agendador rodando no servidor:
// "php artisan schedule:run" a cada minuto via cron)
Schedule::command('ncm:importar')->monthlyOn(5, '03:00');
