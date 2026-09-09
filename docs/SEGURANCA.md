# Segurança - TechBell 0.1.1

## Modelo de acesso

A identidade vem de `Session::getLoginUserID()`. Os endpoints de leitura não recebem um destinatário. O usuário informado em `user_rules` serve exclusivamente para o administrador editar a politica individual; não altera o destinatário de `test`.

Antes de entregar dados, `Ticket::can(id, READ)` e `Session::haveAccessToEntity()` são reavaliados. Acompanhamentos passam também por `ITILFollowup::can()`, verificação do itemtype, do chamado pai e do atributo privado. Nunca alterar a sessão para simular outro usuário durante a distribuicao.

Os controles administrativos dependem de privilégios de configuração e perfis, mais escopo raiz. Não dependem de `profiles_id = 4` nem do texto "Super-Admin". Perfis clonados com esses mesmos privilégios podem administrar o plugin.

## Escritas e entradas

GET não marca leitura, não envia teste e não salva configurações. A rota `/Action` declara GET/POST apenas pela compatibilidade do roteador nas primeiras versões 11.0; `Access::post()` rejeita GET antes de qualquer escrita.

Forms e chamadas POST carregam o token nativo do GLPI e um token adicional exclusivo do plugin, vinculado a sessão. O plugin não desativa o listener nativo. Não reutilizar um token nativo depois de consumido. Origem externa não recebe CORS.

Cores são restritas a `#RRGGBB`. Tipos são uma lista fechada. IDs são inteiros. Consultas usam a API do banco GLPI; SQL literal aparece apenas no esquema de instalação/desinstalação, sem valores do usuário. Textos do servidor são exibidos com `textContent` no navegador ou `htmlspecialchars` no formulario. Não são interpretados como HTML.

O teste sempre grava `tickets_id=0`, `is_test=1` e o usuário autenticado. O endpoint não permite transmissao para grupo nem destinatário arbitrario. Não existe funcao de anúncio em massa nesta versão.

## Dados e ciclo de vida

O plugin armazena IDs, tipo de evento, status anterior/novo, datas e estado de leitura/exibição. Não replica descricao do chamado, corpo do comentário, senha, token API nem anexos. O navegador não persiste o histórico em localStorage.

O histórico depende do acesso atual. Ao mudar de entidade/perfil, a proxima consulta limpa o contexto da interface. Como em qualquer interface web, informacao já entregue e visualizada anteriormente não pode ser "desvista"; a revogacao e aplicada nas proximas consultas. Chamados excluídos e acompanhamentos privados não voltam no histórico.

Retenção logica e imediata nas consultas; exclusão física depende da ação automática Purge. Desinstalação remove as tabelas. Desativação não remove nada.

## Limites

Hooks capturam exceções para não impedir a gravacao do chamado. Uma falha de banco/plugin pode impedir aquele aviso; não há reconciliacao ou garantia de entrega exatamente uma vez. O log permite detectar falhas. Não usar como único canal para incidentes críticos.

A protecao de duplicação por `toast_at` prioriza evitar repeticao; uma aba fechada entre reivindicação e exibição pode perder o popup. O histórico fica preservado. Abas diferentes podem emitir consultas simultaneas; nenhum teste de carga ou auditoria externa foi realizado.

Antes de mudanças relevantes: revisar o código no seu fluxo de mudança e conferir backup, perfis, entidades, cache, logs e carga.
